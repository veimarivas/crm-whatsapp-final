<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lee la oferta académica vigente de `esam_datos` y la redacta como texto
 * para que la IA responda sobre eso y solo sobre eso.
 *
 * Por qué existe separado del comando: el texto se arma en dos piezas con
 * papeles distintos, y confundirlas fue lo que hacía alucinar al bot.
 *
 *  - `catalogo()` es el documento FIJO: la lista completa de programas con
 *    inscripciones abiertas y su resumen. Entra entero en cada prompt, sin
 *    pasar por la búsqueda. Es lo que permite afirmar "esto es todo lo que
 *    ofrecemos" — si el catálogo dependiera del retrieval, el día que la
 *    búsqueda no lo encuentra el modelo se inventa el programa.
 *  - `programa()` es el detalle de uno: módulos, docentes y TODOS sus
 *    horarios. Es voluminoso y se recupera solo cuando preguntan por él.
 *
 * La consulta a la BD académica NO se hace por mensaje: corre en horarios
 * fijos (ver `wacrm:sync-oferta-academica` en el scheduler) y lo que queda
 * guardado es este texto ya redactado.
 */
class OfertaAcademica
{
    /** Estado "Inscripciones" en `programas.estado_id`. */
    public const ESTADO_INSCRIPCIONES = 4;

    public const DOC_PREFIX = '[OFERTA] ';

    public const DOC_CATALOGO = '[OFERTA] Catálogo de programas vigentes';

    public const DOC_INDICE = '[OFERTA] Índice de programas vigentes';

    /**
     * Lo ÚNICO que viaja en todas las consultas: los nombres.
     *
     * Es la corrección de un error de diseño propio. Yo fijaba el catálogo
     * entero —fichas, módulos, docentes, resúmenes de horarios— en cada
     * pregunta: unos 14.000 caracteres, ~4.700 tokens, que en un servidor sin
     * GPU son ~80 segundos de lectura ANTES de empezar a pensar. Y encima el
     * modelo, viendo esas fichas, contestaba copiándolas.
     *
     * Para lo que el catálogo fijo tiene que garantizar —que no invente
     * programas y que sepa listarlos— alcanza con los nombres. Todo lo demás
     * (precios, fechas, módulos, horarios) se trae solo cuando preguntan por
     * un programa concreto.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $programas
     */
    public function indice($programas): string
    {
        $ahora = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $total = $programas->count();

        if ($total === 0) {
            return implode("\n", [
                'OFERTA ACADÉMICA VIGENTE (actualizado '.$ahora->format('d/m/Y H:i').')',
                '',
                'En este momento NO hay ningún programa con inscripciones abiertas.',
                'Si preguntan por programas, decí que por ahora no hay inscripciones abiertas y ofrecé pasar con un asesor.',
            ]);
        }

        $lineas = [
            'OFERTA ACADÉMICA VIGENTE (actualizado '.$ahora->format('d/m/Y H:i').')',
            '',
            "Lista COMPLETA y ÚNICA de programas con inscripciones abiertas ({$total}).",
            'Lo que no está en esta lista NO se ofrece.',
            '',
        ];

        $porArea = $programas->groupBy(fn ($p) => trim($p->area_nombre ?? '') ?: 'Sin área asignada');
        $agrupar = $porArea->count() > 1 || ! $porArea->has('Sin área asignada');

        if ($agrupar) {
            $n = 0;
            foreach ($porArea->sortKeys() as $area => $delArea) {
                $lineas[] = "ÁREA: {$area}";
                foreach ($delArea as $p) {
                    $lineas[] = (++$n).'. '.trim($p->nombre);
                }
                $lineas[] = '';
            }
        } else {
            foreach ($programas->values() as $i => $p) {
                $lineas[] = ($i + 1).'. '.trim($p->nombre);
            }
            $lineas[] = '';
        }

        $lineas[] = 'Precios, fechas de inicio, módulos, docentes y horarios de cada programa NO están en esta lista: te los paso aparte cuando el cliente pregunte por un programa concreto. Si te preguntan un dato que no tenés a la vista, pedile al cliente que te diga de qué programa habla.';

        return implode("\n", $lineas);
    }

    /**
     * De dónde sale el área del programa.
     *
     * `esam_datos` es una BD externa que este proyecto no controla ni migra, y
     * el área puede estar como tabla relacionada (`areas` + `programas.area_id`)
     * o como columna suelta. Se detecta en vez de asumir: si se asume mal, la
     * consulta revienta y la sincronización nocturna deja de correr — y como
     * conserva el conocimiento anterior, el fallo es silencioso durante días.
     *
     * @return array{modo: 'tabla'|'columna'|'ninguno', columna: string|null}
     */
    private function fuenteArea(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $schema = Schema::connection('esam_datos');

        try {
            // Un programa puede estar en VARIAS áreas: la relación vive en la
            // tabla puente `areas_programas`, no en una columna de `programas`.
            if ($schema->hasTable('areas_programas') && $schema->hasTable('areas')) {
                $nombre = collect(['nombre', 'descripcion', 'name', 'titulo'])
                    ->first(fn ($c) => $schema->hasColumn('areas', $c));

                if ($nombre) {
                    return $cache = ['modo' => 'pivote', 'columna' => $nombre];
                }
            }

            if ($schema->hasColumn('programas', 'area_id') && $schema->hasTable('areas')) {
                $nombre = collect(['nombre', 'descripcion', 'name', 'titulo'])
                    ->first(fn ($c) => $schema->hasColumn('areas', $c));

                if ($nombre) {
                    return $cache = ['modo' => 'tabla', 'columna' => $nombre];
                }
            }

            foreach (['area', 'area_nombre', 'nombre_area'] as $columna) {
                if ($schema->hasColumn('programas', $columna)) {
                    return $cache = ['modo' => 'columna', 'columna' => $columna];
                }
            }
        } catch (\Throwable) {
            // BD inaccesible: el que llame ya maneja el error.
        }

        return $cache = ['modo' => 'ninguno', 'columna' => null];
    }

    /** ¿Cada sesión tiene su propio docente, o el docente es del módulo? */
    private function horarioTieneDocente(): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            return $cache = Schema::connection('esam_datos')->hasColumn('horarios', 'docente_id');
        } catch (\Throwable) {
            return $cache = false;
        }
    }

    /**
     * ¿Se puede leer la BD académica ahora mismo?
     *
     * Cacheado corto: se pregunta en cada mensaje entrante y una BD caída no
     * puede costar una conexión fallida por consulta.
     */
    public function disponible(): bool
    {
        return Cache::remember('esam_datos:disponible', 60, function () {
            try {
                DB::connection('esam_datos')->select('SELECT 1');

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * La lista de programas, cacheada unos minutos.
     *
     * La consulta es barata (una decena de filas) pero se hace en CADA mensaje
     * entrante; unos minutos de caché no envejecen nada —la oferta cambia
     * cuando alguien mueve un programa de estado, no cada segundo— y evitan
     * castigar a la BD académica en una ráfaga de mensajes.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function programasCacheadas()
    {
        $segundos = (int) config('services.ai_context.oferta_cache_seconds', 300);

        return Cache::remember('esam_datos:programas', $segundos, fn () => $this->programas());
    }

    /**
     * El contexto que necesita ESTA pregunta, consultado a la BD en el momento.
     *
     * Es la vuelta al modelo anterior —consulta directa— pero con lo que se
     * aprendió armando el catálogo: solo programas en inscripciones (antes se
     * colaban los concluidos y los que estaban en desarrollo), redactado con
     * área, módulos, docentes y horarios, y sobre todo **acotado a lo que se
     * preguntó**.
     *
     * Fijar el catálogo entero en cada prompt costaba ~80 s de lectura por
     * mensaje en este servidor. Consultar la BD cuesta milisegundos: lo caro
     * nunca fue leer la base, era hacerle leer al modelo lo que no necesitaba.
     *
     * @return array{indice: string, detalle: string}
     */
    public function contextoPara(?string $query): array
    {
        $programas = $this->programasCacheadas();

        $indice = $this->indice($programas);
        $query = trim((string) $query);

        if ($query === '' || $programas->isEmpty()) {
            return ['indice' => $indice, 'detalle' => ''];
        }

        // ¿Nombró un programa? Entonces va su detalle completo: módulos,
        // docentes y todas las sesiones.
        $elegido = $this->buscarPrograma($programas, $query);

        if ($elegido) {
            return ['indice' => $indice, 'detalle' => $this->programa($elegido)];
        }

        // Pregunta genérica sobre la oferta (precios, fechas, duración): el
        // resumen de todos, sin el detalle de horarios.
        if ($this->preguntaPorDatosGenerales($query)) {
            return ['indice' => $indice, 'detalle' => $this->resumen($programas)];
        }

        return ['indice' => $indice, 'detalle' => ''];
    }

    /**
     * El programa del que habla el cliente, si lo nombró.
     *
     * Compara por palabras significativas y no por igualdad: nadie escribe
     * «Diplomado en Auditoría Médica y Gestión de Calidad en Salud» completo,
     * escribe «el de auditoría médica».
     */
    public function buscarPrograma($programas, string $query): ?object
    {
        $normalizar = fn (string $s) => mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s));
        $consulta = ' '.$normalizar($query).' ';

        // Palabras que están en casi todos los títulos y no distinguen nada.
        $vacias = ['maestria', 'maestría', 'diplomado', 'curso', 'programa', 'especialidad',
            'para', 'con', 'del', 'las', 'los', 'una', 'este', 'sobre', 'quiero', 'informacion', 'información',
            'modulos', 'módulos', 'horarios', 'docentes', 'precio', 'costo'];

        $palabrasPorPrograma = [];

        foreach ($programas as $p) {
            $palabrasPorPrograma[$p->id] = collect(preg_split('/\s+/', $normalizar($p->nombre)))
                ->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $vacias, true))
                ->unique()
                ->values();
        }

        // Cuántos programas usa cada palabra: «banca» identifica uno solo,
        // «gestión» puede estar en tres y no identifica nada.
        $frecuencia = collect($palabrasPorPrograma)->flatten()->countBy();

        $puntajes = [];

        foreach ($programas as $p) {
            $propias = $palabrasPorPrograma[$p->id]->filter(fn ($w) => $frecuencia[$w] === 1);

            // Se cuentan solo las palabras que identifican a ESTE programa. Con
            // el criterio anterior —dos palabras del título— «los módulos del
            // diplomado en banca» no matcheaba (falta «finanzas») y el cliente
            // recibía un "no tengo esos datos" con la información en la base.
            $puntajes[$p->id] = $propias->filter(fn ($w) => str_contains($consulta, ' '.$w))->count();
        }

        $maximo = max($puntajes ?: [0]);

        if ($maximo === 0) {
            return null;
        }

        $ganadores = array_keys($puntajes, $maximo, true);

        // Empate = ambigüedad real: elegir uno sería adivinar, y contestar los
        // horarios del programa equivocado es peor que pedir que aclare.
        if (count($ganadores) > 1) {
            return null;
        }

        return collect($programas)->firstWhere('id', $ganadores[0]);
    }

    /** Resumen de todos: lo que se necesita para hablar de precios y fechas. */
    public function resumen($programas): string
    {
        $lineas = ['DATOS DE LOS PROGRAMAS CON INSCRIPCIONES ABIERTAS:'];

        foreach ($programas as $p) {
            $datos = array_filter([
                trim($p->area_nombre ?? '') !== '' ? "área {$p->area_nombre}" : null,
                $p->duracion_meses ? "{$p->duracion_meses} meses" : null,
                $p->n_modulos > 0 ? "{$p->n_modulos} módulos" : null,
                $p->fecha_inicio ? 'inicia '.$this->fecha($p->fecha_inicio) : null,
                (float) $p->matricula > 0 ? 'matrícula Bs '.number_format((float) $p->matricula, 2) : null,
                (float) $p->colegiatura > 0 ? 'colegiatura Bs '.number_format((float) $p->colegiatura, 2) : null,
            ]);

            $lineas[] = '- '.trim($p->nombre).': '.implode(', ', $datos).'.';
        }

        return implode("\n", $lineas);
    }

    /** ¿Pregunta por precios, fechas o duración de la oferta en general? */
    private function preguntaPorDatosGenerales(string $query): bool
    {
        $texto = mb_strtolower($query);

        foreach ([
            'precio', 'costo', 'cuesta', 'cuestan', 'valor', 'invers', 'pago', 'cuota', 'financ',
            'matricula', 'matrícula', 'colegiatura', 'beca', 'descuento',
            'fecha', 'inicio', 'empieza', 'comienza', 'duracion', 'duración', 'dura', 'meses',
            'modulo', 'módulo', 'area', 'área', 'requisito', 'certificad', 'ceub',
        ] as $pista) {
            if (str_contains($texto, $pista)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Programas con inscripciones abiertas, con su tipo y su área.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function programas()
    {
        $area = $this->fuenteArea();

        $query = DB::connection('esam_datos')
            ->table('programas as p')
            ->leftJoin('tipos as t', 't.id', '=', 'p.tipo_id')
            ->where('p.estado_id', self::ESTADO_INSCRIPCIONES)
            ->select([
                'p.id', 'p.nombre', 'p.codigo', 'p.gestion',
                'p.duracion_meses', 'p.fecha_inicio', 'p.fecha_conclusion',
                'p.hora_inicio', 'p.hora_fin',
                'p.matricula', 'p.colegiatura', 'p.n_modulos',
                'p.moodle_link', 'p.ceub', 'p.inscripciones_habilitadas',
                'p.cantidad_inscritos_minimo',
                't.nombre as tipo_nombre', 't.descripcion as tipo_descripcion',
            ]);

        if ($area['modo'] === 'tabla') {
            $query->leftJoin('areas as a', 'a.id', '=', 'p.area_id')
                ->addSelect("a.{$area['columna']} as area_nombre");
        } elseif ($area['modo'] === 'columna') {
            $query->addSelect("p.{$area['columna']} as area_nombre");
        }

        $programas = $query->orderBy('p.nombre')->get();

        // Con tabla puente el área se resuelve aparte: una sola consulta para
        // todos los programas, no una por programa.
        if ($area['modo'] === 'pivote') {
            $areas = $this->areasPorPrograma($programas->pluck('id')->all(), $area['columna']);

            $programas->each(fn ($p) => $p->area_nombre = $areas[$p->id] ?? null);
        }

        return $programas;
    }

    /**
     * Áreas de cada programa, desde la tabla puente.
     *
     * Los nombres de las columnas del puente se detectan igual que el resto:
     * es una BD que este proyecto no controla, y asumir mal significa que la
     * consulta revienta y la sincronización deja de correr en silencio.
     *
     * @param  array<int, mixed>  $programaIds
     * @return array<mixed, string>  programa_id => "Salud, Gestión"
     */
    private function areasPorPrograma(array $programaIds, string $columnaNombre): array
    {
        if (empty($programaIds)) {
            return [];
        }

        $schema = Schema::connection('esam_datos');

        $colPrograma = collect(['programa_id', 'programas_id', 'id_programa'])
            ->first(fn ($c) => $schema->hasColumn('areas_programas', $c));

        $colArea = collect(['area_id', 'areas_id', 'id_area'])
            ->first(fn ($c) => $schema->hasColumn('areas_programas', $c));

        if (! $colPrograma || ! $colArea) {
            return [];
        }

        return DB::connection('esam_datos')
            ->table('areas_programas as ap')
            ->join('areas as a', 'a.id', '=', "ap.{$colArea}")
            ->whereIn("ap.{$colPrograma}", $programaIds)
            ->get(["ap.{$colPrograma} as programa_id", "a.{$columnaNombre} as area"])
            ->groupBy('programa_id')
            // Un programa en varias áreas se nombra en todas: elegir una sola
            // lo escondería de las demás.
            ->map(fn ($filas) => $filas->pluck('area')->filter()->unique()->join(', '))
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object> módulos con docente
     */
    public function modulos(int|string $programaId)
    {
        return DB::connection('esam_datos')
            ->table('modulos as m')
            ->leftJoin('docentes as d', 'd.id', '=', 'm.docente_id')
            ->where('m.programa_id', $programaId)
            ->select(['m.id', 'm.nombre', 'd.nombres as docente_nombres', 'd.apellidos as docente_apellidos'])
            ->orderBy('m.id')
            ->get();
    }

    /**
     * Horarios confirmados de un módulo. Un módulo tiene VARIAS sesiones (una
     * por semana, normalmente), y cada una puede dictarla un docente distinto:
     * si la tabla `horarios` trae su propio `docente_id`, se usa ese.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function horarios(int|string $moduloId)
    {
        $query = DB::connection('esam_datos')
            ->table('horarios as h')
            ->where('h.modulo_id', $moduloId)
            ->where('h.estado', 'Confirmado')
            ->orderBy('h.fecha_desarrollo')
            ->orderBy('h.hora_inicio')
            ->limit(50) // un semestre de clases semanales ≈ 16
            ->select(['h.fecha_desarrollo', 'h.hora_inicio', 'h.hora_fin']);

        if ($this->horarioTieneDocente()) {
            $query->leftJoin('docentes as hd', 'hd.id', '=', 'h.docente_id')
                ->addSelect(['hd.nombres as docente_nombres', 'hd.apellidos as docente_apellidos']);
        }

        return $query->get();
    }

    /** Nombre completo del docente de una fila, o cadena vacía. */
    private function docente(?object $fila): string
    {
        return trim(($fila->docente_nombres ?? '').' '.($fila->docente_apellidos ?? ''));
    }

    /**
     * Resumen de las sesiones de un módulo para el catálogo: cuántas son, entre
     * qué fechas y en qué franja horaria.
     *
     * En el catálogo va el resumen y no la lista completa por una razón de
     * tamaño: veinte programas × ocho módulos × dieciséis sesiones no entra en
     * el contexto del modelo, y lo que se pasa se trunca en silencio. La lista
     * sesión por sesión vive en el documento de detalle del programa, que se
     * inyecta entero apenas el cliente lo nombra.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $horarios
     */
    private function resumenHorarios($horarios): string
    {
        if ($horarios->isEmpty()) {
            return 'sin fechas confirmadas';
        }

        $primera = $this->fecha($horarios->first()->fecha_desarrollo);
        $ultima = $this->fecha($horarios->last()->fecha_desarrollo);
        $total = $horarios->count();

        $franjas = $horarios
            ->map(fn ($h) => substr($h->hora_inicio, 0, 5).' a '.substr($h->hora_fin, 0, 5))
            ->unique()
            ->values();

        $horario = $franjas->count() === 1 ? $franjas->first() : 'horarios variables';

        $sesiones = $total === 1 ? '1 sesión' : "{$total} sesiones";
        $rango = $primera === $ultima ? "el {$primera}" : "del {$primera} al {$ultima}";

        return "{$sesiones} {$rango}, {$horario}";
    }

    /**
     * Documento fijo: la lista cerrada de lo que se ofrece hoy.
     *
     * Se redacta afirmando que la lista es COMPLETA. Sin esa frase el modelo
     * trata la lista como "algunos ejemplos" y completa con programas viejos
     * que recuerda de otras partes del contexto.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $programas
     * @param  int  $nivel  2 = con resumen de horarios · 1 = módulos y docentes
     *                      · 0 = solo la ficha de cada programa
     */
    public function catalogo($programas, int $nivel = 2): string
    {
        $ahora = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $total = $programas->count();

        if ($total === 0) {
            return implode("\n", [
                'OFERTA ACADÉMICA VIGENTE',
                'Actualizado: '.$ahora->format('d/m/Y H:i'),
                '',
                'En este momento NO hay ningún programa con inscripciones abiertas.',
                'Ante cualquier consulta sobre programas, responde que por ahora no hay inscripciones abiertas y ofrece pasar con un asesor.',
            ]);
        }

        $lineas = [
            'OFERTA ACADÉMICA VIGENTE',
            'Actualizado: '.$ahora->format('d/m/Y H:i'),
            '',
            "Esta es la lista COMPLETA y ÚNICA de programas con inscripciones abiertas: son {$total}.",
            'Si un programa no figura en esta lista, NO se ofrece y no existe información sobre él.',
            '',
            'PROGRAMAS DISPONIBLES:',
        ];

        // Agrupados por área: "¿qué tienen en el área de salud?" es una de las
        // preguntas más comunes, y con la lista plana el modelo tenía que
        // deducir el área del nombre del programa — o sea, adivinarla.
        $porArea = $programas->groupBy(fn ($p) => trim($p->area_nombre ?? '') ?: 'Sin área asignada');

        if ($porArea->count() > 1 || ! $porArea->has('Sin área asignada')) {
            $n = 0;
            foreach ($porArea->sortKeys() as $area => $delArea) {
                $lineas[] = '';
                $lineas[] = "ÁREA: {$area} ({$delArea->count()})";
                foreach ($delArea as $p) {
                    $lineas[] = (++$n).'. '.trim($p->nombre);
                }
            }
        } else {
            foreach ($programas->values() as $i => $p) {
                $lineas[] = ($i + 1).'. '.trim($p->nombre);
            }
        }

        $lineas[] = '';
        $lineas[] = 'RESUMEN DE CADA PROGRAMA:';

        foreach ($programas as $p) {
            $lineas[] = '';
            $lineas[] = '• '.trim($p->nombre);

            $ficha = array_filter([
                trim($p->area_nombre ?? '') !== '' ? "Área: {$p->area_nombre}" : null,
                $p->tipo_nombre ? "Tipo: {$p->tipo_nombre}" : null,
                $p->gestion ? "Gestión: {$p->gestion}" : null,
                $p->duracion_meses ? "Duración: {$p->duracion_meses} meses" : null,
                $p->n_modulos > 0 ? "Módulos: {$p->n_modulos}" : null,
                $p->fecha_inicio ? 'Inicio: '.$this->fecha($p->fecha_inicio) : null,
                $p->fecha_conclusion ? 'Conclusión: '.$this->fecha($p->fecha_conclusion) : null,
                (float) $p->matricula > 0 ? 'Matrícula: Bs '.number_format((float) $p->matricula, 2) : null,
                (float) $p->colegiatura > 0 ? 'Colegiatura: Bs '.number_format((float) $p->colegiatura, 2) : null,
                $p->ceub ? 'Certificación CEUB: sí' : null,
            ]);

            foreach ($ficha as $dato) {
                $lineas[] = '  '.$dato;
            }

            // Cada módulo con su docente y un RESUMEN de sus sesiones. La lista
            // sesión por sesión no entra acá (ver resumenHorarios): vive en el
            // documento de detalle, que se inyecta entero apenas el cliente
            // nombra el programa.
            $modulos = $nivel >= 1 ? $this->modulos($p->id) : collect();

            if ($modulos->isNotEmpty()) {
                $lineas[] = '  Módulos:';
                foreach ($modulos->values() as $j => $m) {
                    $horarios = $nivel >= 2 ? $this->horarios($m->id) : collect();

                    // El docente del módulo; si las sesiones traen el suyo y es
                    // otro, se nombran todos — un módulo puede repartirse entre
                    // dos docentes y decir solo uno es un dato equivocado.
                    $docentes = collect([$this->docente($m)])
                        ->merge($horarios->map(fn ($h) => $this->docente($h)))
                        ->filter()
                        ->unique()
                        ->values();

                    $linea = '    '.($j + 1).'. '.trim($m->nombre);

                    if ($docentes->isNotEmpty()) {
                        $linea .= ' — '.($docentes->count() === 1 ? 'Docente: ' : 'Docentes: ').$docentes->join(', ');
                    }

                    $lineas[] = $linea;

                    if ($nivel >= 2) {
                        $lineas[] = '       Horarios: '.$this->resumenHorarios($horarios);
                    }
                }
            }
        }

        $lineas[] = '';
        $lineas[] = 'Las fechas de clase de arriba son un resumen (cuántas sesiones y entre qué fechas). El listado sesión por sesión está en el documento de detalle de cada programa. Si el cliente pide las fechas exactas y no las tienes a la vista, dilo y ofrece pasar con un asesor en lugar de estimarlas.';

        return implode("\n", $lineas);
    }

    /**
     * El catálogo más completo que entre en el presupuesto de caracteres.
     *
     * Con muchos programas, el texto con horarios por módulo se pasa del
     * contexto del modelo. Truncarlo por el final sería lo peor posible: se
     * perderían programas ENTEROS y la IA diría que no existen. Así que se
     * recorta por DETALLE — primero se van los horarios, después los módulos —
     * y la lista de programas queda completa siempre, que es lo único que no
     * puede faltar.
     */
    public function catalogoAjustado($programas, int $limite = 14000): string
    {
        foreach ([2, 1, 0] as $nivel) {
            $texto = $this->catalogo($programas, $nivel);

            if (mb_strlen($texto) <= $limite) {
                return $texto;
            }
        }

        // Ni la lista pelada entra: mejor eso truncado que nada, y el detalle
        // de cada programa sigue disponible por separado.
        return mb_substr($this->catalogo($programas, 0), 0, $limite);
    }

    /**
     * Detalle de un programa: todo lo del resumen más los horarios de cada
     * módulo, sesión por sesión.
     */
    public function programa(object $p): string
    {
        $lineas = [
            "Programa: {$p->nombre}",
            "Código: {$p->codigo}",
            'Tipo: '.$p->tipo_nombre.($p->tipo_descripcion ? " ({$p->tipo_descripcion})" : ''),
            "Gestión: {$p->gestion}",
            'Estado: Inscripciones abiertas',
        ];

        if (trim($p->area_nombre ?? '') !== '') {
            $lineas[] = "Área: {$p->area_nombre}";
        }

        if ($p->duracion_meses) {
            $lineas[] = "Duración: {$p->duracion_meses} meses";
        }
        if ($p->fecha_inicio) {
            $lineas[] = 'Fecha inicio: '.$this->fecha($p->fecha_inicio);
        }
        if ($p->fecha_conclusion) {
            $lineas[] = 'Fecha conclusión: '.$this->fecha($p->fecha_conclusion);
        }
        if ($p->hora_inicio && $p->hora_fin && $p->hora_inicio !== '00:00:00') {
            $lineas[] = "Horario general: {$p->hora_inicio} a {$p->hora_fin}";
        }
        if ((float) $p->matricula > 0) {
            $lineas[] = 'Matrícula: '.number_format((float) $p->matricula, 2).' Bs';
        }
        if ((float) $p->colegiatura > 0) {
            $lineas[] = 'Colegiatura: '.number_format((float) $p->colegiatura, 2).' Bs';
        }
        if ($p->n_modulos > 0) {
            $lineas[] = "Cantidad total de módulos: {$p->n_modulos}";
        }
        if ($p->ceub) {
            $lineas[] = 'Certificación CEUB: Sí';
        }
        if ($p->cantidad_inscritos_minimo > 0) {
            $lineas[] = "Cupo mínimo para iniciar: {$p->cantidad_inscritos_minimo} inscritos";
        }

        $modulos = $this->modulos($p->id);

        if ($modulos->isNotEmpty()) {
            $lineas[] = '';
            $lineas[] = 'MÓDULOS DEL PROGRAMA:';

            foreach ($modulos->values() as $i => $m) {
                $lineas[] = ($i + 1).'. '.trim($m->nombre);

                $horarios = $this->horarios($m->id);
                $docenteModulo = $this->docente($m);

                // Docente(s) del módulo. Solo el nombre: el correo es interno.
                $docentes = collect([$docenteModulo])
                    ->merge($horarios->map(fn ($h) => $this->docente($h)))
                    ->filter()
                    ->unique()
                    ->values();

                if ($docentes->count() === 1) {
                    $lineas[] = "   Docente: {$docentes->first()}";
                } elseif ($docentes->count() > 1) {
                    $lineas[] = '   Docentes: '.$docentes->join(', ');
                }

                if ($horarios->isNotEmpty()) {
                    $lineas[] = "   Este módulo tiene {$horarios->count()} sesión(es). Todos sus horarios, en orden cronológico:";
                    foreach ($horarios as $h) {
                        $linea = '     - '.$this->fecha($h->fecha_desarrollo)." de {$h->hora_inicio} a {$h->hora_fin}";

                        // El docente de la sesión solo se repite si el módulo
                        // se reparte entre varios: si es siempre el mismo,
                        // ponerlo en cada línea es ruido.
                        $docenteSesion = $this->docente($h);
                        if ($docenteSesion !== '' && $docentes->count() > 1) {
                            $linea .= " (docente: {$docenteSesion})";
                        }

                        $lineas[] = $linea;
                    }
                } else {
                    // Decirlo explícitamente evita que el modelo llene el
                    // hueco: un módulo "sin horarios" se lee como un dato,
                    // uno en blanco se lee como una invitación a inventar.
                    $lineas[] = '   Horarios: todavía no hay fechas confirmadas para este módulo.';
                }

                $lineas[] = '';
            }
        }

        return implode("\n", $lineas);
    }

    /** Las fechas de la BD vienen ISO; en el chat se leen dd/mm/aaaa. */
    private function fecha(?string $valor): string
    {
        if (! $valor) {
            return '';
        }

        try {
            return Carbon::parse($valor)->format('d/m/Y');
        } catch (\Throwable) {
            return $valor;
        }
    }
}

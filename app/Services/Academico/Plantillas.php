<?php

namespace App\Services\Academico;

use App\Services\Ai\OfertaAcademica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Genera plantillas de automatizaciones y chatbots **con la oferta real**
 * de `esam_datos` — la misma fuente que alimenta la base de conocimiento
 * de la IA en `/settings/ai`.
 *
 * Por qué generadas y no escritas a mano: los programas, precios, fechas,
 * módulos y docentes cambian cada gestión. Una plantilla con esos datos
 * escritos a mano nace desactualizada y nadie se entera hasta que un
 * cliente recibe un precio viejo.
 *
 * Lo que se genera es un **punto de partida**: al aplicarla, los textos
 * quedan congelados en la automatización. Si la oferta cambia, hay que
 * volver a aplicarla (a diferencia de la IA, que sí lee el conocimiento
 * actualizado en cada mensaje). Eso se dice en la propia pantalla.
 *
 * Si `esam_datos` no responde, `disponible()` devuelve false y no se
 * ofrece ninguna: es preferible no mostrar plantillas a mostrar una con
 * la oferta vacía.
 */
class Plantillas
{
    /** Máximo de filas de una lista de WhatsApp. */
    private const MAX_FILAS = 10;

    /** Tope de áreas con receta propia: más que esto satura la galería. */
    private const MAX_AREAS_RECETA = 6;

    private ?Collection $programas = null;

    /** Motivo del último fallo al leer la oferta, para mostrarlo en pantalla. */
    private ?string $fallo = null;

    public function __construct(private readonly OfertaAcademica $oferta)
    {
    }

    /** ¿Hay oferta que ofrecer? BD accesible Y al menos un programa abierto. */
    public function disponible(): bool
    {
        return $this->programas()->isNotEmpty();
    }

    /** Para el aviso de la UI: cuántos programas y áreas hay detrás. */
    public function resumen(): array
    {
        $programas = $this->programas();

        return [
            'disponible' => $programas->isNotEmpty(),
            'programas' => $programas->count(),
            'areas' => $programas->isEmpty() ? 0 : $this->porArea()->count(),
            'error' => $this->fallo,
            'actualizado' => Carbon::now(config('app.timezone', 'America/La_Paz'))->format('d/m/Y H:i'),
        ];
    }

    /* ------------------------------------------------------------ datos */

    /**
     * La oferta, o una colección vacía si no se pudo leer.
     *
     * `esam_datos` es una BD externa que este proyecto no controla ni
     * migra: una columna que cambió de nombre allá no puede tumbar
     * `/automations` y `/flows` acá. Mismo criterio que
     * `SyncOfertaAcademica`, que ante un fallo conserva lo anterior en
     * vez de dejar a la IA muda.
     *
     * `OfertaAcademica::disponible()` no alcanza como guarda: hace un
     * `SELECT 1` que pasa aunque la consulta real reviente.
     */
    private function programas(): Collection
    {
        if ($this->programas !== null) {
            return $this->programas;
        }

        try {
            // Guarda barata primero: `disponible()` cachea 60 s, así una BD
            // caída no cuesta una conexión fallida por cada carga.
            if (! $this->oferta->disponible()) {
                return $this->programas = collect();
            }

            return $this->programas = collect($this->oferta->programasCacheadas());
        } catch (\Throwable $e) {
            $this->fallo = $e->getMessage();

            Log::warning('No se pudieron generar plantillas con la oferta académica', [
                'error' => $e->getMessage(),
            ]);

            return $this->programas = collect();
        }
    }

    /** @return Collection<string, Collection> área => programas */
    private function porArea(): Collection
    {
        return $this->programas()
            ->groupBy(fn ($p) => trim($p->area_nombre ?? '') ?: 'Otros programas')
            ->sortKeys();
    }

    /** Clave válida para node_key / id de opción: sin acentos ni espacios. */
    private function clave(string $texto, string $prefijo = ''): string
    {
        $slug = Str::slug($texto, '_') ?: 'x';

        return mb_substr($prefijo.$slug, 0, 60);
    }

    /** Título que entra en una fila de lista de WhatsApp (24 caracteres). */
    private function titulo(string $texto): string
    {
        $texto = trim($texto);

        return mb_strlen($texto) <= 24 ? $texto : mb_substr($texto, 0, 23).'…';
    }

    private function moneda(?float $valor): ?string
    {
        return $valor > 0 ? 'Bs '.number_format($valor, 2) : null;
    }

    private function fecha(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }

        try {
            return Carbon::parse($valor)->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Una línea con lo esencial: duración, módulos, inicio e inversión. */
    private function fichaCorta(object $p): string
    {
        return collect([
            $p->duracion_meses ? "{$p->duracion_meses} meses" : null,
            $p->n_modulos > 0 ? "{$p->n_modulos} módulos" : null,
            $this->fecha($p->fecha_inicio) ? 'inicia '.$this->fecha($p->fecha_inicio) : null,
            $this->moneda((float) $p->colegiatura) ? 'colegiatura '.$this->moneda((float) $p->colegiatura) : null,
        ])->filter()->join(' · ');
    }

    /** Ficha completa para el cuerpo de un mensaje. */
    private function ficha(object $p): string
    {
        $lineas = ['*'.trim($p->nombre).'*'];

        if (trim($p->area_nombre ?? '') !== '') {
            $lineas[] = 'Área: '.$p->area_nombre;
        }
        if ($p->duracion_meses) {
            $lineas[] = "Duración: {$p->duracion_meses} meses";
        }
        if ($p->n_modulos > 0) {
            $lineas[] = "Módulos: {$p->n_modulos}";
        }
        if ($this->fecha($p->fecha_inicio)) {
            $lineas[] = 'Inicio: '.$this->fecha($p->fecha_inicio);
        }
        if ($this->moneda((float) $p->matricula)) {
            $lineas[] = 'Matrícula: '.$this->moneda((float) $p->matricula);
        }
        if ($this->moneda((float) $p->colegiatura)) {
            $lineas[] = 'Colegiatura: '.$this->moneda((float) $p->colegiatura);
        }
        if ($p->ceub) {
            $lineas[] = 'Certificación CEUB: sí';
        }

        $lineas[] = '';
        $lineas[] = '¿Te gustaría que un asesor te dé los detalles?';

        return implode("\n", $lineas);
    }

    /**
     * Módulos con su docente y el resumen de sesiones confirmadas.
     *
     * Se corta a 8 módulos: un mensaje de WhatsApp con veinte módulos y
     * sus fechas no se lee, y el objetivo es que el cliente pida hablar
     * con un asesor, no leer el plan de estudios completo en el chat.
     */
    private function modulos(object $p): string
    {
        $modulos = collect($this->oferta->modulos($p->id));

        if ($modulos->isEmpty()) {
            return '*'.trim($p->nombre)."*\n\nTodavía no tenemos publicado el detalle de módulos. Un asesor te lo hace llegar.";
        }

        $lineas = ['*'.trim($p->nombre).'* — plan de estudios', ''];

        foreach ($modulos->take(8)->values() as $i => $m) {
            $docente = trim(($m->docente_nombres ?? '').' '.($m->docente_apellidos ?? ''));
            $linea = ($i + 1).'. '.trim($m->nombre);

            if ($docente !== '') {
                $linea .= " — Docente: {$docente}";
            }

            $lineas[] = $linea;

            $sesiones = collect($this->oferta->horarios($m->id));

            if ($sesiones->isNotEmpty()) {
                $desde = $this->fecha($sesiones->first()->fecha_desarrollo);
                $hasta = $this->fecha($sesiones->last()->fecha_desarrollo);
                $franja = substr($sesiones->first()->hora_inicio, 0, 5).' a '.substr($sesiones->first()->hora_fin, 0, 5);

                $lineas[] = '   '.$sesiones->count().' sesiones'
                    .($desde === $hasta ? " el {$desde}" : " del {$desde} al {$hasta}")
                    .", {$franja}";
            }
        }

        if ($modulos->count() > 8) {
            $lineas[] = '';
            $lineas[] = '…y '.($modulos->count() - 8).' módulos más.';
        }

        $lineas[] = '';
        $lineas[] = 'Las fechas son las confirmadas a hoy y pueden ajustarse.';

        return implode("\n", $lineas);
    }

    /** Los programas de un área, listados con su ficha corta. */
    private function listaDeArea(string $area, Collection $programas): string
    {
        $lineas = ["En *{$area}* tenemos inscripciones abiertas en:", ''];

        foreach ($programas as $p) {
            $ficha = $this->fichaCorta($p);
            $lineas[] = '• '.trim($p->nombre).($ficha !== '' ? "\n  {$ficha}" : '');
        }

        $lineas[] = '';
        $lineas[] = '¿Sobre cuál querés que te cuente más?';

        return implode("\n", $lineas);
    }

    /* ------------------------------------------------------------ automatizaciones */

    /** @return array<int, array<string, mixed>> recetas con el formato de Automations\Recipes */
    public function automatizaciones(): array
    {
        if (! $this->disponible()) {
            return [];
        }

        return $this->aSalvo('automatizaciones', function () {
            $programas = $this->programas();
            $porArea = $this->porArea();

            $recetas = [
                $this->recetaCatalogo($porArea),
                $this->recetaPrecios($programas),
                $this->recetaFechas($programas),
                $this->recetaHorariosDocentes(),
            ];

            // Una receta por área: "¿qué tienen en salud?" es de las preguntas
            // más comunes y merece una respuesta directa, no el catálogo entero.
            foreach ($porArea->take(self::MAX_AREAS_RECETA) as $area => $delArea) {
                $recetas[] = $this->recetaArea((string) $area, $delArea);
            }

            return $recetas;
        });
    }

    /**
     * Corre un generador y, si algo revienta, devuelve una lista vacía.
     *
     * Las plantillas son una comodidad; las pantallas de automatizaciones
     * y chatbots tienen que abrir igual. Un dato inesperado de la BD
     * académica no puede costar un 500 en dos secciones del CRM.
     */
    private function aSalvo(string $que, callable $generar): array
    {
        try {
            return $generar();
        } catch (\Throwable $e) {
            $this->fallo = $e->getMessage();

            Log::warning("Fallo generando plantillas de {$que} con la oferta académica", [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return [];
        }
    }

    private function recetaCatalogo(Collection $porArea): array
    {
        $lineas = ['Estos son nuestros programas con inscripciones abiertas:', ''];

        foreach ($porArea as $area => $delArea) {
            $lineas[] = "*{$area}*";
            foreach ($delArea as $p) {
                $lineas[] = '• '.trim($p->nombre);
            }
            $lineas[] = '';
        }

        $lineas[] = 'Decime cuál te interesa y te paso duración, fechas e inversión.';

        return [
            'slug' => 'oferta-catalogo',
            'title' => 'Responder con la oferta completa',
            'summary' => 'Cuando preguntan qué programas hay, envía el catálogo agrupado por área.',
            'why' => 'Es la primera pregunta de casi todos; contestarla sola libera al equipo.',
            'icon' => 'academic',
            'source' => 'oferta',
            'automation' => [
                'name' => 'Oferta académica vigente',
                'description' => 'Catálogo por área generado desde la BD ESAM',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['programas', 'oferta', 'carreras', 'estudiar', 'qué tienen', 'áreas']],
                'steps' => [
                    ['type' => 'send_message', 'config' => ['text' => implode("\n", $lineas)]],
                ],
            ],
        ];
    }

    private function recetaPrecios(Collection $programas): array
    {
        $lineas = ['Te comparto la inversión de nuestros programas:', ''];

        foreach ($programas as $p) {
            $costos = collect([
                $this->moneda((float) $p->matricula) ? 'matrícula '.$this->moneda((float) $p->matricula) : null,
                $this->moneda((float) $p->colegiatura) ? 'colegiatura '.$this->moneda((float) $p->colegiatura) : null,
            ])->filter();

            $lineas[] = '• '.trim($p->nombre).': '.($costos->isNotEmpty() ? $costos->join(', ') : 'consultar con un asesor');
        }

        $lineas[] = '';
        $lineas[] = 'Tenemos planes de pago en cuotas. ¿Querés que un asesor te explique las formas de pago?';

        return [
            'slug' => 'oferta-precios',
            'title' => 'Responder por precios',
            'summary' => 'Detecta «precio», «costo» o «cuánto» y envía la inversión de cada programa.',
            'why' => 'Con los montos reales de la base: nadie recibe un precio de la gestión pasada.',
            'icon' => 'price',
            'source' => 'oferta',
            'automation' => [
                'name' => 'Inversión de los programas',
                'description' => 'Matrícula y colegiatura generadas desde la BD ESAM',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['precio', 'precios', 'costo', 'cuánto cuesta', 'inversión', 'matrícula', 'colegiatura']],
                'steps' => [
                    ['type' => 'send_message', 'config' => ['text' => implode("\n", $lineas)]],
                ],
            ],
        ];
    }

    private function recetaFechas(Collection $programas): array
    {
        $lineas = ['Estas son las fechas de inicio confirmadas:', ''];

        foreach ($programas as $p) {
            $datos = collect([
                $this->fecha($p->fecha_inicio) ? 'inicia '.$this->fecha($p->fecha_inicio) : 'fecha por confirmar',
                $p->duracion_meses ? "dura {$p->duracion_meses} meses" : null,
            ])->filter();

            $lineas[] = '• '.trim($p->nombre).': '.$datos->join(', ');
        }

        $lineas[] = '';
        $lineas[] = '¿Te reservo un cupo en alguno?';

        return [
            'slug' => 'oferta-fechas',
            'title' => 'Responder por fechas de inicio',
            'summary' => 'Detecta «cuándo empieza» o «fecha» y envía el inicio y la duración de cada programa.',
            'why' => 'La segunda pregunta más repetida después del precio.',
            'icon' => 'follow',
            'source' => 'oferta',
            'automation' => [
                'name' => 'Fechas de inicio',
                'description' => 'Inicio y duración generados desde la BD ESAM',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['cuándo empieza', 'cuando empieza', 'fecha de inicio', 'inicio', 'inician', 'duración', 'cuánto dura']],
                'steps' => [
                    ['type' => 'send_message', 'config' => ['text' => implode("\n", $lineas)]],
                ],
            ],
        ];
    }

    /**
     * Horarios y docentes NO se listan: son cientos de sesiones y decenas
     * de nombres, y meterlos todos en un mensaje es ilegible. Se pide el
     * programa y se deja que el chatbot o un asesor den el detalle.
     */
    private function recetaHorariosDocentes(): array
    {
        return [
            'slug' => 'oferta-horarios-docentes',
            'title' => 'Preguntan por horarios o docentes',
            'summary' => 'Pide de qué programa se trata antes de dar fechas de clase o nombres de docentes.',
            'why' => 'Cada programa tiene su propio cronograma: responder sin saber cuál es equivocarse seguro.',
            'icon' => 'agent',
            'source' => 'oferta',
            'automation' => [
                'name' => 'Consulta de horarios o docentes',
                'description' => 'Pide precisar el programa antes de responder',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['horario', 'horarios', 'clases', 'qué días', 'docente', 'docentes', 'profesor', 'plantel']],
                'steps' => [
                    ['type' => 'send_message', 'config' => [
                        'text' => "Con gusto, {name}. Los horarios y el plantel docente cambian según el programa.\n\n¿De cuál te gustaría saber? Escribime el nombre y te paso los módulos, los docentes y las fechas de clase confirmadas.",
                    ]],
                ],
            ],
        ];
    }

    private function recetaArea(string $area, Collection $programas): array
    {
        $palabras = collect(preg_split('/\s+/', mb_strtolower($area)))
            ->filter(fn ($w) => mb_strlen($w) >= 4)
            ->unique()
            ->values();

        return [
            'slug' => 'oferta-area-'.Str::slug($area),
            'title' => "Preguntan por el área de {$area}",
            'summary' => "Responde con los {$programas->count()} programas de esta área y su ficha resumida.",
            'why' => 'Contesta la pregunta concreta en vez de mandar el catálogo entero.',
            'icon' => 'branch',
            'source' => 'oferta',
            'automation' => [
                'name' => "Programas del área de {$area}",
                'description' => 'Generado desde la BD ESAM',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => $palabras->isNotEmpty() ? $palabras->all() : [mb_strtolower($area)]],
                'steps' => [
                    ['type' => 'send_message', 'config' => ['text' => $this->listaDeArea($area, $programas)]],
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------ chatbots */

    /**
     * @return array<int, array<string, mixed>> recetas con el formato de Flows\Recipes
     *
     * Cacheado porque la galería de `/flows` lo pide en cada carga y
     * `flowModulos()` consulta módulos y horarios de cada programa: con
     * diez programas de ocho módulos son ~80 consultas por pantalla.
     *
     * La clave lleva la huella de la oferta, así un cambio en los
     * programas invalida el caché solo — y en tests, cada conjunto de
     * datos tiene su propia entrada.
     */
    public function flows(): array
    {
        if (! $this->disponible()) {
            return [];
        }

        return $this->aSalvo('chatbots', function () {
            $segundos = (int) config('services.ai_context.oferta_cache_seconds', 300);

            return Cache::remember($this->claveCache('flows'), $segundos, fn () => array_values(array_filter([
                $this->flowAreas(),
                $this->flowProgramas(),
                $this->flowModulos(),
            ])));
        });
    }

    private function claveCache(string $tipo): string
    {
        $huella = md5($this->programas()->map(fn ($p) => $p->id.'|'.$p->nombre)->join(','));

        return "plantillas_oferta:{$tipo}:{$huella}";
    }

    /** Menú de áreas → programas de cada una. */
    private function flowAreas(): ?array
    {
        $porArea = $this->porArea()->take(self::MAX_FILAS);

        // Con una sola área el menú sobra: el chatbot de programas ya sirve.
        if ($porArea->count() < 2) {
            return null;
        }

        $filas = [];
        $nodos = [];

        foreach ($porArea as $area => $programas) {
            $key = $this->clave($area, 'area_');

            $filas[] = [
                'id' => $this->clave($area),
                'title' => $this->titulo($area),
                'description' => $programas->count().' '.($programas->count() === 1 ? 'programa' : 'programas'),
                'next' => $key,
            ];

            $nodos[] = ['node_key' => $key, 'node_type' => 'send_message', 'config' => [
                'text' => $this->listaDeArea($area, $programas),
                'next' => 'que_sigue',
            ]];
        }

        return [
            'slug' => 'oferta-areas',
            'title' => 'Menú por áreas de estudio',
            'summary' => 'Muestra las '.$porArea->count().' áreas con inscripciones abiertas y lista los programas de la que elijan.',
            'why' => 'El cliente llega a lo suyo en dos toques, sin leer el catálogo completo.',
            'icon' => 'menu',
            'source' => 'oferta',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keywords' => ['hola', 'información', 'programas', 'estudiar']],
            'entry_node_id' => 'menu_areas',
            'nodes' => [
                ['node_key' => 'menu_areas', 'node_type' => 'send_list', 'config' => [
                    'text' => '¡Hola {name}! 👋 ¿Qué área te interesa?',
                    'button_label' => 'Ver áreas',
                    'rows' => $filas,
                ]],
                ...$nodos,
                ['node_key' => 'que_sigue', 'node_type' => 'send_buttons', 'config' => [
                    'text' => '¿Qué preferís hacer ahora?',
                    'buttons' => [
                        ['id' => 'asesor', 'title' => 'Hablar con asesor', 'next' => 'pasar_asesor'],
                        ['id' => 'otra_area', 'title' => 'Ver otra área', 'next' => 'menu_areas'],
                        ['id' => 'listo', 'title' => 'Eso es todo', 'next' => 'despedida'],
                    ],
                ]],
                ['node_key' => 'pasar_asesor', 'node_type' => 'handoff', 'config' => [
                    'message' => 'Perfecto {name}, un asesor te contacta en unos minutos por este mismo chat. 🙌',
                ]],
                ['node_key' => 'despedida', 'node_type' => 'end', 'config' => [
                    'message' => '¡Gracias por escribirnos, {name}! Cualquier duda, acá estamos.',
                ]],
            ],
        ];
    }

    /** Lista de programas → ficha de cada uno → inscripción o asesor. */
    private function flowProgramas(): array
    {
        $programas = $this->programas()->take(self::MAX_FILAS);

        $filas = [];
        $nodos = [];

        foreach ($programas as $p) {
            $key = $this->clave($p->nombre, 'prog_');

            $filas[] = [
                'id' => $this->clave($p->nombre),
                'title' => $this->titulo($p->nombre),
                'description' => $this->fichaCorta($p),
                'next' => $key,
            ];

            $nodos[] = ['node_key' => $key, 'node_type' => 'send_message', 'config' => [
                'text' => $this->ficha($p),
                'next' => 'que_hacemos',
            ]];
        }

        return [
            'slug' => 'oferta-programas',
            'title' => 'Ficha de cada programa',
            'summary' => 'Lista los programas y, al elegir uno, envía duración, inicio e inversión reales.',
            'why' => 'Contesta las tres preguntas de siempre sin que intervenga nadie.',
            'icon' => 'faq',
            'source' => 'oferta',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keywords' => ['precio', 'costo', 'información', 'programas', 'inscribir']],
            'entry_node_id' => 'lista_programas',
            'nodes' => [
                ['node_key' => 'lista_programas', 'node_type' => 'send_list', 'config' => [
                    'text' => '{name}, elegí el programa y te paso el detalle:',
                    'button_label' => 'Ver programas',
                    'rows' => $filas,
                ]],
                ...$nodos,
                ['node_key' => 'que_hacemos', 'node_type' => 'send_buttons', 'config' => [
                    'text' => '¿Cómo seguimos?',
                    'buttons' => [
                        ['id' => 'inscribir', 'title' => 'Quiero inscribirme', 'next' => 'pedir_correo'],
                        ['id' => 'otro', 'title' => 'Ver otro programa', 'next' => 'lista_programas'],
                        ['id' => 'asesor', 'title' => 'Hablar con asesor', 'next' => 'pasar_asesor'],
                    ],
                ]],
                ['node_key' => 'pedir_correo', 'node_type' => 'collect_input', 'config' => [
                    'text' => '¡Excelente! ¿A qué correo te enviamos los requisitos y el formulario?',
                    'var' => 'correo',
                    'next' => 'confirmar',
                ]],
                ['node_key' => 'confirmar', 'node_type' => 'send_message', 'config' => [
                    'text' => 'Anotado: {correo}. Un asesor te escribe hoy mismo para acompañarte con la inscripción. 📩',
                    'next' => 'pasar_asesor',
                ]],
                ['node_key' => 'pasar_asesor', 'node_type' => 'handoff', 'config' => [
                    'message' => 'Te comunico con un asesor. 🙌',
                ]],
            ],
        ];
    }

    /** Lista de programas → módulos, docentes y fechas de clase. */
    private function flowModulos(): array
    {
        $programas = $this->programas()->take(self::MAX_FILAS);

        $filas = [];
        $nodos = [];

        foreach ($programas as $p) {
            $key = $this->clave($p->nombre, 'plan_');

            $filas[] = [
                'id' => $this->clave($p->nombre),
                'title' => $this->titulo($p->nombre),
                'description' => $p->n_modulos > 0 ? "{$p->n_modulos} módulos" : 'plan de estudios',
                'next' => $key,
            ];

            $nodos[] = ['node_key' => $key, 'node_type' => 'send_message', 'config' => [
                'text' => $this->modulos($p),
                'next' => 'mas_detalle',
            ]];
        }

        return [
            'slug' => 'oferta-modulos',
            'title' => 'Módulos, docentes y horarios',
            'summary' => 'Al elegir un programa envía su plan de estudios con los docentes y las sesiones confirmadas.',
            'why' => 'Es la consulta que más tiempo le come al equipo y la que peor contesta la IA sola.',
            'icon' => 'qualify',
            'source' => 'oferta',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keywords' => ['horario', 'horarios', 'módulos', 'modulos', 'materias', 'docente', 'docentes', 'plan de estudios']],
            'entry_node_id' => 'lista_planes',
            'nodes' => [
                ['node_key' => 'lista_planes', 'node_type' => 'send_list', 'config' => [
                    'text' => '{name}, ¿de qué programa querés ver el plan de estudios?',
                    'button_label' => 'Ver programas',
                    'rows' => $filas,
                ]],
                ...$nodos,
                ['node_key' => 'mas_detalle', 'node_type' => 'send_buttons', 'config' => [
                    'text' => '¿Querés el cronograma completo?',
                    'buttons' => [
                        ['id' => 'asesor', 'title' => 'Sí, con un asesor', 'next' => 'pasar_asesor'],
                        ['id' => 'otro', 'title' => 'Ver otro programa', 'next' => 'lista_planes'],
                        ['id' => 'listo', 'title' => 'Eso es todo', 'next' => 'despedida'],
                    ],
                ]],
                ['node_key' => 'pasar_asesor', 'node_type' => 'handoff', 'config' => [
                    'message' => 'Un asesor te envía el cronograma completo en unos minutos. 🙌',
                ]],
                ['node_key' => 'despedida', 'node_type' => 'end', 'config' => [
                    'message' => '¡Gracias {name}! Cualquier duda, escribinos.',
                ]],
            ],
        ];
    }
}

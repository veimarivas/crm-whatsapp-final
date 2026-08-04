<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Programas con inscripciones abiertas, con su tipo.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function programas()
    {
        return DB::connection('esam_datos')
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
            ])
            ->orderBy('p.nombre')
            ->get();
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
     * @return \Illuminate\Support\Collection<int, object> horarios confirmados
     */
    public function horarios(int|string $moduloId)
    {
        return DB::connection('esam_datos')
            ->table('horarios')
            ->where('modulo_id', $moduloId)
            ->where('estado', 'Confirmado')
            ->orderBy('fecha_desarrollo')
            ->orderBy('hora_inicio')
            ->limit(50) // un semestre de clases semanales ≈ 16
            ->get(['fecha_desarrollo', 'hora_inicio', 'hora_fin']);
    }

    /**
     * Documento fijo: la lista cerrada de lo que se ofrece hoy.
     *
     * Se redacta afirmando que la lista es COMPLETA. Sin esa frase el modelo
     * trata la lista como "algunos ejemplos" y completa con programas viejos
     * que recuerda de otras partes del contexto.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $programas
     */
    public function catalogo($programas): string
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

        foreach ($programas->values() as $i => $p) {
            $lineas[] = ($i + 1).'. '.trim($p->nombre);
        }

        $lineas[] = '';
        $lineas[] = 'RESUMEN DE CADA PROGRAMA:';

        foreach ($programas as $p) {
            $lineas[] = '';
            $lineas[] = '• '.trim($p->nombre);

            $ficha = array_filter([
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

            // Los nombres de los módulos van en el catálogo (son pocos y son
            // lo que más se pregunta). Los horarios NO: son cientos de líneas
            // y harían que el catálogo no entre en el contexto del modelo.
            $modulos = $this->modulos($p->id);

            if ($modulos->isNotEmpty()) {
                $lineas[] = '  Módulos:';
                foreach ($modulos->values() as $j => $m) {
                    $docente = trim(($m->docente_nombres ?? '').' '.($m->docente_apellidos ?? ''));
                    $lineas[] = '    '.($j + 1).'. '.trim($m->nombre).($docente !== '' ? " — Docente: {$docente}" : '');
                }
            }
        }

        $lineas[] = '';
        $lineas[] = 'Las fechas y horas exactas de clase de cada módulo están en el documento de detalle de cada programa. Si no las tienes a la vista, dilo y ofrece pasar con un asesor en lugar de estimarlas.';

        return implode("\n", $lineas);
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

                $docente = trim(($m->docente_nombres ?? '').' '.($m->docente_apellidos ?? ''));
                if ($docente !== '') {
                    // Solo el nombre: el correo del docente es interno.
                    $lineas[] = "   Docente: {$docente}";
                }

                $horarios = $this->horarios($m->id);

                if ($horarios->isNotEmpty()) {
                    $lineas[] = '   Todos los horarios de este módulo (orden cronológico):';
                    foreach ($horarios as $h) {
                        $lineas[] = '     - '.$this->fecha($h->fecha_desarrollo)." de {$h->hora_inicio} a {$h->hora_fin}";
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

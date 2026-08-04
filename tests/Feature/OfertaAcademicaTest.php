<?php

namespace Tests\Feature;

use App\Services\Ai\OfertaAcademica;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Redacción del catálogo que lee la IA.
 *
 * No prueba la consulta a `esam_datos` (es una BD externa) sino el texto que
 * sale de ella, que es lo que decide si el modelo se anima a inventar. Las
 * frases que se fijan acá no son adorno: sin el "lista COMPLETA y ÚNICA" el
 * modelo trata la lista como ejemplos y agrega programas de su memoria.
 */
class OfertaAcademicaTest extends TestCase
{
    private function oferta(array $modulos = []): OfertaAcademica
    {
        // Se saltea la BD académica: acá interesa la redacción.
        return new class($modulos) extends OfertaAcademica
        {
            public function __construct(private array $fakeModulos) {}

            public function modulos(int|string $programaId)
            {
                return collect($this->fakeModulos);
            }
        };
    }

    private function programa(array $override = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'nombre' => 'Maestría en Gestión Pública',
            'codigo' => 'MGP-01',
            'gestion' => '2026',
            'duracion_meses' => 18,
            'fecha_inicio' => '2026-09-10',
            'fecha_conclusion' => '2028-03-10',
            'hora_inicio' => '19:00:00',
            'hora_fin' => '22:00:00',
            'matricula' => 500,
            'colegiatura' => 7000,
            'n_modulos' => 8,
            'moodle_link' => null,
            'ceub' => 1,
            'inscripciones_habilitadas' => 1,
            'cantidad_inscritos_minimo' => 15,
            'tipo_nombre' => 'Maestría',
            'tipo_descripcion' => null,
        ], $override);
    }

    public function test_afirma_que_la_lista_es_cerrada(): void
    {
        $texto = $this->oferta()->catalogo(collect([$this->programa()]));

        $this->assertStringContainsString('lista COMPLETA y ÚNICA', $texto);
        $this->assertStringContainsString('Si un programa no figura en esta lista, NO se ofrece', $texto);
        $this->assertStringContainsString('son 1', $texto);
    }

    public function test_sin_programas_lo_dice_en_lugar_de_quedarse_mudo(): void
    {
        $texto = $this->oferta()->catalogo(new Collection());

        $this->assertStringContainsString('NO hay ningún programa con inscripciones abiertas', $texto);
    }

    public function test_incluye_los_modulos_con_su_docente(): void
    {
        $oferta = $this->oferta([
            (object) ['id' => 1, 'nombre' => 'Políticas públicas', 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez'],
            (object) ['id' => 2, 'nombre' => 'Presupuesto', 'docente_nombres' => null, 'docente_apellidos' => null],
        ]);

        $texto = $oferta->catalogo(collect([$this->programa()]));

        $this->assertStringContainsString('1. Políticas públicas — Docente: Juan Pérez', $texto);
        $this->assertStringContainsString('2. Presupuesto', $texto);
    }

    public function test_las_fechas_salen_en_formato_local(): void
    {
        $texto = $this->oferta()->catalogo(collect([$this->programa()]));

        $this->assertStringContainsString('Inicio: 10/09/2026', $texto);
        $this->assertStringNotContainsString('2026-09-10', $texto);
    }

    public function test_un_modulo_sin_horarios_lo_dice_en_vez_de_dejar_el_hueco(): void
    {
        $oferta = new class extends OfertaAcademica
        {
            public function modulos(int|string $programaId)
            {
                return collect([(object) ['id' => 1, 'nombre' => 'Módulo sin fechas', 'docente_nombres' => 'Ana', 'docente_apellidos' => 'Luz']]);
            }

            public function horarios(int|string $moduloId)
            {
                return collect();
            }
        };

        $texto = $oferta->programa($this->programa());

        $this->assertStringContainsString('todavía no hay fechas confirmadas', $texto);
    }
}

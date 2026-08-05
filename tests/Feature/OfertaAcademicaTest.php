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
    private function oferta(array $modulos = [], array $horarios = []): OfertaAcademica
    {
        // Se saltea la BD académica: acá interesa la redacción.
        return new class($modulos, $horarios) extends OfertaAcademica
        {
            public function __construct(private array $fakeModulos, private array $fakeHorarios) {}

            public function modulos(int|string $programaId)
            {
                return collect($this->fakeModulos);
            }

            public function horarios(int|string $moduloId)
            {
                return collect($this->fakeHorarios);
            }
        };
    }

    /** @return object una sesión de clase */
    private function sesion(string $fecha, string $inicio = '19:00:00', string $fin = '22:00:00', array $docente = []): object
    {
        return (object) array_merge([
            'fecha_desarrollo' => $fecha,
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
        ], $docente);
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
            'area_nombre' => 'Ciencias Sociales',
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

    public function test_el_area_va_en_la_ficha_y_agrupa_el_indice(): void
    {
        $texto = $this->oferta()->catalogo(collect([
            $this->programa(['nombre' => 'Maestría en Gestión Pública', 'area_nombre' => 'Ciencias Sociales']),
            $this->programa(['id' => 2, 'nombre' => 'Maestría en Salud Familiar', 'area_nombre' => 'Salud']),
        ]));

        $this->assertStringContainsString('ÁREA: Salud (1)', $texto);
        $this->assertStringContainsString('ÁREA: Ciencias Sociales (1)', $texto);
        $this->assertStringContainsString('Área: Salud', $texto, 'También en la ficha del programa.');
    }

    public function test_si_ninguno_tiene_area_la_lista_va_plana(): void
    {
        // Un único encabezado "Sin área asignada" sobre toda la lista no
        // aporta nada y ocupa contexto.
        $texto = $this->oferta()->catalogo(collect([$this->programa(['area_nombre' => null])]));

        $this->assertStringContainsString('1. Maestría en Gestión Pública', $texto);
        $this->assertStringNotContainsString('ÁREA:', $texto);
    }

    public function test_el_programa_sin_area_no_desaparece_cuando_los_demas_si_la_tienen(): void
    {
        $texto = $this->oferta()->catalogo(collect([
            $this->programa(['nombre' => 'Maestría en Salud Familiar', 'area_nombre' => 'Salud']),
            $this->programa(['id' => 2, 'nombre' => 'Diplomado suelto', 'area_nombre' => null]),
        ]));

        $this->assertStringContainsString('ÁREA: Salud (1)', $texto);
        $this->assertStringContainsString('Sin área asignada', $texto);
        $this->assertStringContainsString('Diplomado suelto', $texto);
    }

    public function test_el_catalogo_resume_las_sesiones_de_cada_modulo(): void
    {
        $oferta = $this->oferta(
            [(object) ['id' => 1, 'nombre' => 'Políticas públicas', 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez']],
            [
                $this->sesion('2026-09-10'),
                $this->sesion('2026-09-17'),
                $this->sesion('2026-09-24'),
            ],
        );

        $texto = $oferta->catalogo(collect([$this->programa()]));

        $this->assertStringContainsString('Horarios: 3 sesiones del 10/09/2026 al 24/09/2026, 19:00 a 22:00', $texto);
    }

    public function test_un_modulo_repartido_entre_dos_docentes_los_nombra_a_los_dos(): void
    {
        $oferta = $this->oferta(
            [(object) ['id' => 1, 'nombre' => 'Políticas públicas', 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez']],
            [
                $this->sesion('2026-09-10', docente: ['docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez']),
                $this->sesion('2026-09-17', docente: ['docente_nombres' => 'Rosa', 'docente_apellidos' => 'Vargas']),
            ],
        );

        $catalogo = $oferta->catalogo(collect([$this->programa()]));
        $detalle = $oferta->programa($this->programa());

        $this->assertStringContainsString('Docentes: Juan Pérez, Rosa Vargas', $catalogo);
        $this->assertStringContainsString('Docentes: Juan Pérez, Rosa Vargas', $detalle);
        // En el detalle, cada sesión dice quién la dicta.
        $this->assertStringContainsString('17/09/2026 de 19:00:00 a 22:00:00 (docente: Rosa Vargas)', $detalle);
    }

    public function test_el_detalle_lista_todas_las_sesiones_del_modulo(): void
    {
        $sesiones = collect(range(1, 12))->map(fn ($i) => $this->sesion(sprintf('2026-09-%02d', $i)))->all();

        $oferta = $this->oferta(
            [(object) ['id' => 1, 'nombre' => 'Políticas públicas', 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez']],
            $sesiones,
        );

        $texto = $oferta->programa($this->programa());

        $this->assertStringContainsString('tiene 12 sesión(es)', $texto);
        $this->assertStringContainsString('01/09/2026', $texto);
        $this->assertStringContainsString('12/09/2026', $texto);
    }

    public function test_reconoce_el_programa_por_como_lo_nombra_el_cliente(): void
    {
        $programas = collect([
            $this->programa(['id' => 1, 'nombre' => 'Diplomado en Auditoría Médica y Gestión de Calidad en Salud']),
            $this->programa(['id' => 2, 'nombre' => 'Diplomado en Banca Y Finanzas']),
        ]);

        // Nadie escribe el título completo: escribe "el de auditoría médica".
        $elegido = $this->oferta()->buscarPrograma($programas, 'me pasás info del de auditoría médica?');

        $this->assertSame(1, $elegido->id);
    }

    public function test_una_palabra_propia_alcanza_para_reconocer_el_programa(): void
    {
        $programas = collect([
            $this->programa(['id' => 1, 'nombre' => 'Diplomado en Banca Y Finanzas']),
            $this->programa(['id' => 2, 'nombre' => 'Diplomado en Peritaje y Avaluó de bienes Inmuebles']),
        ]);

        // El caso real que fallaba: exigir DOS palabras del título hacía que
        // «los módulos del diplomado en banca» no matcheara —falta
        // «finanzas»— y el cliente recibía «no tengo esos datos» con la
        // información sentada en la base.
        $elegido = $this->oferta()->buscarPrograma($programas, 'cuáles son los módulos y horarios del diplomado en banca?');

        $this->assertSame(1, $elegido->id);
    }

    public function test_una_palabra_compartida_no_alcanza(): void
    {
        $programas = collect([
            $this->programa(['id' => 1, 'nombre' => 'MAESTRÍA EN INGENIERÍA ELÉCTRICA Y AUTOMATIZACIÓN INDUSTRIAL']),
            $this->programa(['id' => 2, 'nombre' => 'MAESTRÍA EN INGENIERÍA VIAL CON MENCIÓN EN CARRETERAS']),
        ]);

        // «ingeniería» está en los dos: elegir uno sería adivinar, y dar los
        // horarios del programa equivocado es peor que pedir que aclare.
        $this->assertNull($this->oferta()->buscarPrograma($programas, 'info de la maestría en ingeniería'));
    }

    public function test_distingue_dos_programas_que_comparten_una_palabra(): void
    {
        $programas = collect([
            $this->programa(['id' => 1, 'nombre' => 'MAESTRÍA EN INGENIERÍA ELÉCTRICA Y AUTOMATIZACIÓN INDUSTRIAL']),
            $this->programa(['id' => 2, 'nombre' => 'MAESTRÍA EN INGENIERÍA VIAL CON MENCIÓN EN CARRETERAS']),
        ]);

        $elegido = $this->oferta()->buscarPrograma($programas, 'la maestría de ingeniería vial');

        $this->assertSame(2, $elegido->id);
    }

    public function test_las_palabras_de_la_pregunta_no_cuentan_como_nombre(): void
    {
        $programas = collect([$this->programa(['id' => 1, 'nombre' => 'Diplomado en Banca Y Finanzas'])]);

        // «módulos», «horarios» y «docentes» aparecen en las preguntas todo el
        // tiempo: si contaran, cualquier consulta arrastraría un programa.
        $this->assertNull($this->oferta()->buscarPrograma($programas, 'qué módulos y horarios manejan?'));
    }

    public function test_una_palabra_generica_no_elige_programa(): void
    {
        $programas = collect([
            $this->programa(['id' => 1, 'nombre' => 'Diplomado en Gestión Pública']),
            $this->programa(['id' => 2, 'nombre' => 'Diplomado en Gestión Ambiental']),
        ]);

        // "gestión" sola matchearía los dos: elegir uno sería inventar.
        $this->assertNull($this->oferta()->buscarPrograma($programas, 'quiero algo de gestión'));
    }

    public function test_el_resumen_trae_precios_y_fechas_en_una_linea_por_programa(): void
    {
        $texto = $this->oferta()->resumen(collect([$this->programa()]));

        $this->assertStringContainsString('Maestría en Gestión Pública:', $texto);
        $this->assertStringContainsString('matrícula Bs 500.00', $texto);
        $this->assertStringContainsString('inicia 10/09/2026', $texto);
        // Compacto a propósito: es lo que se manda cuando preguntan precios.
        $this->assertLessThan(400, mb_strlen($texto));
    }

    public function test_el_indice_es_chico_y_dice_que_la_lista_es_cerrada(): void
    {
        $programas = collect(range(1, 10))->map(fn ($i) => $this->programa([
            'id' => $i,
            'nombre' => "Diplomado de prueba número {$i}",
        ]));

        $indice = $this->oferta()->indice($programas);

        $this->assertStringContainsString('Lista COMPLETA y ÚNICA', $indice);
        $this->assertStringContainsString('Diplomado de prueba número 10', $indice);
        // Es lo único que viaja en cada consulta: tiene que ser barato de leer.
        $this->assertLessThan(1500, mb_strlen($indice));
        // Y sin precios ni fechas: si están, el modelo los copia en la lista.
        $this->assertStringNotContainsString('Bs', $indice);
    }

    public function test_si_no_entra_se_recorta_el_detalle_y_no_la_lista_de_programas(): void
    {
        $oferta = $this->oferta(
            collect(range(1, 8))->map(fn ($i) => (object) ['id' => $i, 'nombre' => "Módulo número {$i} con un nombre largo", 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez'])->all(),
            [$this->sesion('2026-09-10'), $this->sesion('2026-09-17')],
        );

        $programas = collect(range(1, 30))->map(fn ($i) => $this->programa(['id' => $i, 'nombre' => "Programa de prueba número {$i}"]));

        $texto = $oferta->catalogoAjustado($programas, 6000);

        $this->assertLessThanOrEqual(6000, mb_strlen($texto));
        // Lo que no puede faltar nunca: ningún programa desaparece.
        $this->assertStringContainsString('Programa de prueba número 1', $texto);
        $this->assertStringContainsString('Programa de prueba número 30', $texto);
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

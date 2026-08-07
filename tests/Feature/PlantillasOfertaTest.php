<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Flow;
use App\Models\User;
use App\Services\Academico\Plantillas;
use App\Services\Ai\OfertaAcademica;
use App\Services\Automations\Recipes as AutomationRecipes;
use App\Services\Flows\Recipes as FlowRecipes;
use App\Services\Flows\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plantillas generadas con la oferta académica de `esam_datos`.
 *
 * La BD académica es externa y no está en el entorno de tests, así que
 * `OfertaAcademica` se reemplaza por un doble con datos fijos. Lo que se
 * verifica es la GENERACIÓN: que el grafo resultante sea válido para el
 * editor y para WhatsApp (títulos de 24 caracteres, claves sin acentos,
 * conexiones que existen), no que la consulta SQL funcione.
 */
class PlantillasOfertaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $account = Account::create(['name' => 'ESAM', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $account->id, 'account_role' => 'owner']);
        $this->user->refresh();
    }

    /** Sustituye la BD académica por datos fijos. */
    private function conOferta(bool $disponible = true, array $programas = null): void
    {
        $this->app->instance(OfertaAcademica::class, new OfertaFalsa(
            $disponible,
            collect($programas ?? self::programasDeMuestra()),
        ));
    }

    private static function programasDeMuestra(): array
    {
        return [
            (object) [
                'id' => 1,
                'nombre' => 'Maestría en Auditoría Médica y Gestión de Calidad en Salud',
                'codigo' => 'MAM-1', 'gestion' => '2026', 'area_nombre' => 'Salud',
                'tipo_nombre' => 'Maestría', 'tipo_descripcion' => null,
                'duracion_meses' => 24, 'n_modulos' => 8,
                'fecha_inicio' => '2026-09-15', 'fecha_conclusion' => '2028-09-15',
                'hora_inicio' => '19:00:00', 'hora_fin' => '22:00:00',
                'matricula' => 500.0, 'colegiatura' => 3500.0,
                'ceub' => 1, 'cantidad_inscritos_minimo' => 15,
                'moodle_link' => null, 'inscripciones_habilitadas' => 1,
            ],
            (object) [
                'id' => 2,
                'nombre' => 'Diplomado en Banca y Finanzas',
                'codigo' => 'DBF-1', 'gestion' => '2026', 'area_nombre' => 'Gestión y Negocios',
                'tipo_nombre' => 'Diplomado', 'tipo_descripcion' => null,
                'duracion_meses' => 6, 'n_modulos' => 4,
                'fecha_inicio' => '2026-08-20', 'fecha_conclusion' => '2027-02-20',
                'hora_inicio' => '19:00:00', 'hora_fin' => '22:00:00',
                'matricula' => 300.0, 'colegiatura' => 1800.0,
                'ceub' => 0, 'cantidad_inscritos_minimo' => 10,
                'moodle_link' => null, 'inscripciones_habilitadas' => 1,
            ],
        ];
    }

    public function test_sin_bd_academica_no_se_ofrece_ninguna_plantilla_de_oferta(): void
    {
        $this->conOferta(disponible: false);

        $this->assertSame([], app(Plantillas::class)->automatizaciones());
        $this->assertSame([], app(Plantillas::class)->flows());

        // Las genéricas siguen estando: la pantalla no se queda vacía.
        $this->assertNotEmpty(AutomationRecipes::todas());
        $this->assertNotEmpty(FlowRecipes::todas());

        $this->actingAs($this->user)
            ->get(route('automations.index'))
            ->assertInertia(fn ($page) => $page->where('oferta.disponible', false));
    }

    public function test_con_bd_academica_pero_sin_programas_abiertos_tampoco(): void
    {
        $this->conOferta(programas: []);

        $this->assertSame([], app(Plantillas::class)->automatizaciones());
        $this->assertSame([], app(Plantillas::class)->flows());
    }

    public function test_las_automatizaciones_traen_precios_fechas_y_areas_reales(): void
    {
        $this->conOferta();

        $recetas = collect(app(Plantillas::class)->automatizaciones())->keyBy('slug');

        $precios = $recetas['oferta-precios']['automation']['steps'][0]['config']['text'];
        $this->assertStringContainsString('Bs 3,500.00', $precios);
        $this->assertStringContainsString('Diplomado en Banca y Finanzas', $precios);

        $fechas = $recetas['oferta-fechas']['automation']['steps'][0]['config']['text'];
        $this->assertStringContainsString('15/09/2026', $fechas);

        // El catálogo agrupa por área.
        $catalogo = $recetas['oferta-catalogo']['automation']['steps'][0]['config']['text'];
        $this->assertStringContainsString('*Salud*', $catalogo);
        $this->assertStringContainsString('*Gestión y Negocios*', $catalogo);

        // Una receta por área, con el slug sin acentos.
        $this->assertArrayHasKey('oferta-area-salud', $recetas->all());
        $this->assertArrayHasKey('oferta-area-gestion-y-negocios', $recetas->all());
    }

    public function test_la_receta_de_un_area_solo_lista_los_programas_de_esa_area(): void
    {
        $this->conOferta();

        $receta = collect(app(Plantillas::class)->automatizaciones())->firstWhere('slug', 'oferta-area-salud');
        $texto = $receta['automation']['steps'][0]['config']['text'];

        $this->assertStringContainsString('Auditoría Médica', $texto);
        $this->assertStringNotContainsString('Banca y Finanzas', $texto);
    }

    public function test_una_receta_de_oferta_se_puede_aplicar_desde_el_editor(): void
    {
        $this->conOferta();

        $this->actingAs($this->user)
            ->get(route('automations.create', ['recipe' => 'oferta-precios']))
            ->assertInertia(fn ($page) => $page
                ->where('isDraft', true)
                ->where('automation.trigger_type', 'keyword')
                ->where('steps.0.type', 'send_message'));
    }

    public function test_los_chatbots_generados_son_grafos_validos(): void
    {
        $this->conOferta();

        $flows = app(Plantillas::class)->flows();
        $this->assertNotEmpty($flows);

        foreach ($flows as $flow) {
            $claves = collect($flow['nodes'])->pluck('node_key');

            $this->assertContains($flow['entry_node_id'], $claves->all(), "«{$flow['slug']}»: la entrada no existe");
            $this->assertSame($claves->unique()->count(), $claves->count(), "«{$flow['slug']}»: claves duplicadas");
            $this->assertLessThanOrEqual(50, $claves->count(), "«{$flow['slug']}»: supera el máximo de nodos");

            foreach ($flow['nodes'] as $node) {
                // El validador del editor exige este formato exacto.
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $node['node_key'], "clave inválida: {$node['node_key']}");
                $this->assertContains($node['node_type'], Runner::NODE_TYPES);

                $config = $node['config'];

                foreach (['next', 'next_yes', 'next_no'] as $edge) {
                    if (! empty($config[$edge])) {
                        $this->assertContains($config[$edge], $claves->all(), "«{$node['node_key']}» apunta a un nodo inexistente");
                    }
                }

                foreach ([...$config['buttons'] ?? [], ...$config['rows'] ?? []] as $opcion) {
                    $this->assertContains($opcion['next'], $claves->all(), "opción de «{$node['node_key']}» apunta a un nodo inexistente");
                }
            }
        }
    }

    public function test_los_chatbots_respetan_los_limites_de_whatsapp(): void
    {
        $this->conOferta();

        foreach (app(Plantillas::class)->flows() as $flow) {
            foreach ($flow['nodes'] as $node) {
                $config = $node['config'];

                // Meta trunca en silencio: si el título no entra, el cliente ve
                // el nombre del programa cortado a la mitad.
                foreach ($config['rows'] ?? [] as $row) {
                    $this->assertLessThanOrEqual(24, mb_strlen($row['title']), "fila larga: {$row['title']}");
                    $this->assertLessThanOrEqual(72, mb_strlen($row['description'] ?? ''));
                }

                $this->assertLessThanOrEqual(10, count($config['rows'] ?? []));

                foreach ($config['buttons'] ?? [] as $button) {
                    $this->assertLessThanOrEqual(20, mb_strlen($button['title']), "botón largo: {$button['title']}");
                }

                $this->assertLessThanOrEqual(3, count($config['buttons'] ?? []));
            }
        }
    }

    public function test_el_chatbot_de_modulos_trae_docentes_y_sesiones(): void
    {
        $this->conOferta();

        $flow = collect(app(Plantillas::class)->flows())->firstWhere('slug', 'oferta-modulos');
        $texto = collect($flow['nodes'])->firstWhere('node_type', 'send_message')['config']['text'];

        $this->assertStringContainsString('Docente: Juan Pérez', $texto);
        $this->assertStringContainsString('2 sesiones', $texto);
        $this->assertStringContainsString('19:00 a 22:00', $texto);
    }

    public function test_crear_un_flow_desde_una_receta_de_oferta_siembra_su_grafo(): void
    {
        $this->conOferta();

        $this->actingAs($this->user)
            ->post(route('flows.store'), ['name' => 'Bot oferta', 'recipe' => 'oferta-programas'])
            ->assertRedirect();

        $flow = Flow::first();
        $receta = FlowRecipes::find('oferta-programas');

        $this->assertSame($receta['entry_node_id'], $flow->entry_node_id);
        $this->assertSame(count($receta['nodes']), $flow->nodes()->count());

        // El grafo guardado pasa la validación del editor sin tocar nada.
        $this->actingAs($this->user)
            ->patch(route('flows.update', $flow), [
                'name' => $flow->name,
                'trigger_type' => $flow->trigger_type,
                'trigger_config' => $flow->trigger_config,
                'entry_node_id' => $flow->entry_node_id,
                'fallback_policy' => $flow->fallback_policy,
                'nodes' => $flow->nodes()->get()
                    ->map(fn ($n) => ['node_key' => $n->node_key, 'node_type' => $n->node_type, 'config' => $n->config])
                    ->all(),
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_con_una_sola_area_no_se_genera_el_menu_de_areas(): void
    {
        $unaSola = array_slice(self::programasDeMuestra(), 0, 1);
        $this->conOferta(programas: $unaSola);

        $slugs = array_column(app(Plantillas::class)->flows(), 'slug');

        $this->assertNotContains('oferta-areas', $slugs);
        $this->assertContains('oferta-programas', $slugs);
    }
}

/** Doble de la BD académica: datos fijos, sin tocar `esam_datos`. */
class OfertaFalsa extends OfertaAcademica
{
    public function __construct(
        private readonly bool $accesible,
        private readonly \Illuminate\Support\Collection $lista,
    ) {
    }

    public function disponible(): bool
    {
        return $this->accesible;
    }

    public function programasCacheadas()
    {
        return $this->lista;
    }

    public function programas()
    {
        return $this->lista;
    }

    public function modulos(int|string $programaId)
    {
        return collect([
            (object) ['id' => 10, 'nombre' => 'Fundamentos', 'docente_nombres' => 'Juan', 'docente_apellidos' => 'Pérez'],
            (object) ['id' => 11, 'nombre' => 'Práctica aplicada', 'docente_nombres' => 'Ana', 'docente_apellidos' => 'Quispe'],
        ]);
    }

    public function horarios(int|string $moduloId)
    {
        return collect([
            (object) ['fecha_desarrollo' => '2026-09-15', 'hora_inicio' => '19:00:00', 'hora_fin' => '22:00:00'],
            (object) ['fecha_desarrollo' => '2026-09-22', 'hora_inicio' => '19:00:00', 'hora_fin' => '22:00:00'],
        ]);
    }
}

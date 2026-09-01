<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D4 — el contrato de los GEMELOS, fijado con las mismas fixtures en los dos
 * repos.
 *
 * `ServiceWindow` y `ResponseMetrics` existen en este proyecto y en el Komo con
 * definiciones que DEBEN coincidir. Hasta acá el mecanismo que lo garantizaba
 * era acordarse: la convención está escrita en los dos `CLAUDE_*.md` y en
 * `plan_omnicanal.md` §1, y nada la comprobaba.
 *
 * Ya había pasado con la capa de gráficos, que se creó explícitamente como
 * «una sola» y en un mes tenía dos `format.js` distintos. Nadie lo notó porque
 * nada lo comprobaba.
 *
 * **Qué protege esto y qué no**, dicho explícito para no prometer de más:
 *
 *  - ✅ Cambiar una definición en UN solo repo pone su propia suite en rojo, y
 *    el mensaje dice que hay un gemelo. Es la forma en que la divergencia
 *    aparece de verdad: alguien toca lo que tiene enfrente.
 *  - ❌ Editar las fixtures de los DOS repos de forma inconsistente sigue
 *    siendo posible. Para cerrar eso hace falta que el archivo viva UNA vez,
 *    o sea el paquete compartido de D3. Mientras tanto, el `contract` de cada
 *    archivo y el aviso de la cabecera son lo que queda.
 *
 * La fuente de datos SÍ es distinta a propósito: acá se mide sobre `messages`
 * (la fuente real) y allá sobre `lead_events` (el espejo). Que las fuentes
 * difieran es legítimo; que los NÚMEROS difieran, no.
 */
class TwinContractTest extends TestCase
{
    use RefreshDatabase;

    /** Cómo llama ESTE proyecto a quien tiene la conversación a cargo. */
    private const OWNER_LABEL = 'asignado';

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/twins/{$name}.json");

        $this->assertFileExists($path, "Falta la fixture del gemelo: {$name}.json");

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_las_constantes_de_la_ventana_son_las_declaradas(): void
    {
        $c = $this->fixture('service-window')['constants'];

        // Si alguien cambia una constante acá y no allá, este test lo dice
        // ANTES de que la diferencia se vuelva un número raro en una pantalla.
        $this->assertSame($c['STANDARD_HOURS'], ServiceWindow::STANDARD_HOURS);
        $this->assertSame($c['AD_REFERRAL_HOURS'], ServiceWindow::AD_REFERRAL_HOURS);
        $this->assertSame($c['WARNING_HOURS'], ServiceWindow::WARNING_HOURS);
    }

    public function test_la_ventana_de_servicio_cumple_el_contrato_del_gemelo(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $fixture = $this->fixture('service-window');
        $window = app(ServiceWindow::class);

        foreach ($fixture['cases'] as $case) {
            $result = $window->build(
                $case['inbound_hours_ago'] === null ? null : now()->copy()->subHours($case['inbound_hours_ago']),
                $case['ad_hours_ago'] === null ? null : now()->copy()->subHours($case['ad_hours_ago']),
            );

            foreach ($case['expect'] as $key => $expected) {
                $this->assertSame(
                    $expected,
                    $result[$key],
                    "«{$case['name']}» → {$key}. Si el cambio es intencional, actualizá la fixture "
                    .'en ESTE repo y en el Komo, y la definición en los dos ServiceWindow.',
                );
            }
        }
    }

    public function test_el_sla_es_el_declarado(): void
    {
        $this->assertSame(
            $this->fixture('response-metrics')['constants']['SLA_MINUTES'],
            ResponseMetrics::SLA_MINUTES,
        );
    }

    public function test_las_metricas_de_respuesta_cumplen_el_contrato_del_gemelo(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $fixture = $this->fixture('response-metrics');

        foreach ($fixture['cases'] as $i => $case) {
            $conversation = $this->conversacionConTimeline($case['timeline'], $i);

            $rows = (new ResponseMetrics($this->account->id, now()->copy()->subDays(30)))->build()['conversations'];
            $row = collect($rows)->firstWhere('id', $conversation->id);

            $this->assertNotNull($row, "«{$case['name']}» no produjo fila.");

            foreach ($case['expect'] as $key => $expected) {
                // El único valor que cada proyecto nombra distinto a propósito:
                // acá la conversación tiene un «asignado», allá el lead tiene
                // un «responsable». La diferencia está declarada en la fixture.
                if ($expected === '__owner__') {
                    $expected = self::OWNER_LABEL;
                }

                $this->assertSame(
                    $expected,
                    $row[$key],
                    "«{$case['name']}» → {$key}. Si el cambio es intencional, actualizá la fixture "
                    .'en ESTE repo y en el Komo, y la definición en los dos ResponseMetrics.',
                );
            }
        }
    }

    /**
     * Construye la línea de tiempo con la fuente de ESTE proyecto: `messages`.
     * El Komo arma la misma con `lead_events`.
     *
     * @param  array<int, array<string, mixed>>  $timeline
     */
    private function conversacionConTimeline(array $timeline, int $n): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => "Contacto {$n}",
            // Secuencial y no aleatorio: el único (cuenta, teléfono) de
            // `contacts` colisiona de a ratos con `random_int`, y un test que
            // falla algunas veces es peor que uno que no existe.
            'phone' => '59170'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        ]);

        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'assigned_agent_id' => $this->owner->id,
            'status' => 'open',
        ]);

        foreach ($timeline as $event) {
            $message = Message::create([
                'account_id' => $this->account->id,
                'conversation_id' => $conversation->id,
                'sender_type' => match ($event['who']) {
                    'cliente' => Message::SENDER_CUSTOMER,
                    'ia' => Message::SENDER_BOT,
                    default => Message::SENDER_AGENT,
                },
                'sender_id' => $event['who'] === 'humano' ? $this->owner->id : null,
                'content_type' => 'text',
                'content_text' => 'x',
            ]);

            // `created_at` no es fillable: se pisa después de crear, o todos los
            // mensajes quedan con la hora del test y cualquier medición de
            // tiempo da cero (pasando el test por casualidad).
            $message->forceFill(['created_at' => now()->copy()->subMinutes($event['minutes_ago'])])->save();
        }

        return $conversation;
    }
}

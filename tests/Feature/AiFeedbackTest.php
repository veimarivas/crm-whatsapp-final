<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiFeedback;
use App\Models\AiKnowledgeDocument;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T5 — ciclo de mejora continua de la IA (lado wacrm).
 *
 * Lo que estos tests protegen: que **nada entre al conocimiento sin que un
 * humano lo apruebe**. Enchufar las correcciones directo envenena la base —un
 * agente apurado escribe algo mal y la IA se lo repite a todos los clientes— y
 * es la clase de atajo que se toma «temporalmente» y queda.
 */
class AiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        [, $this->key] = ApiKey::issue($this->account->id, $this->owner->id, 'Komo', ['conversations:read']);
    }

    /** @param array<string, mixed> $payload */
    private function report(array $payload = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->postJson('/api/v1/ai/feedback', [
                'rating' => 'down',
                'external_ref' => 'evt-1',
                'ai_text' => 'El curso cuesta 500 Bs.',
                'question' => 'Cuanto cuesta el diplomado?',
                'correction' => 'El diplomado cuesta 3.500 Bs en 5 cuotas.',
                'reporter' => 'Ana',
                ...$payload,
            ]);
    }

    // ---- La API ----

    public function test_una_correccion_entra_por_la_api_y_queda_pendiente(): void
    {
        $this->report()->assertCreated()->assertJson(['queued_for_review' => true]);

        $feedback = AiFeedback::first();
        $this->assertSame(AiFeedback::PENDING, $feedback->status);
        $this->assertSame($this->account->id, $feedback->account_id);

        // Lo importante: NO se creó conocimiento. Falta que un humano apruebe.
        $this->assertSame(0, AiKnowledgeDocument::count());
    }

    public function test_el_endpoint_es_idempotente(): void
    {
        // Es lo que permite que el job del otro lado reintente sin miedo
        // cuando este servicio estuvo caído.
        $this->report()->assertCreated();
        $this->report(['correction' => 'Texto corregido de nuevo'])->assertCreated();

        $this->assertSame(1, AiFeedback::count());
        $this->assertSame('Texto corregido de nuevo', AiFeedback::first()->correction);
    }

    public function test_cambiar_el_voto_reabre_la_revision(): void
    {
        $this->report();
        AiFeedback::first()->update(['status' => AiFeedback::DISMISSED]);

        $this->report(['correction' => 'Ahora sí importa']);

        $this->assertSame(AiFeedback::PENDING, AiFeedback::first()->status);
    }

    public function test_el_pulgar_arriba_no_entra_a_la_cola(): void
    {
        $this->report(['rating' => 'up', 'correction' => null])
            ->assertCreated()
            ->assertJson(['queued_for_review' => false]);

        // Es señal para la métrica, no trabajo pendiente: mezclarlos
        // convertiría la cola en una bandeja que nadie vacía.
        $this->assertSame(0, AiFeedback::pendingReview()->count());
    }

    public function test_sin_api_key_no_se_reporta_nada(): void
    {
        $this->postJson('/api/v1/ai/feedback', ['rating' => 'down', 'external_ref' => 'x'])
            ->assertUnauthorized();
    }

    public function test_un_rating_invalido_se_rechaza(): void
    {
        $this->report(['rating' => 'meh'])->assertStatus(422);
    }

    // ---- La cola de revisión ----

    public function test_aprobar_crea_un_documento_fijo_y_marca_la_correccion(): void
    {
        $this->report();
        $feedback = AiFeedback::first();

        $this->actingAs($this->owner)->post(route('settings.ai.feedback.apply', $feedback), [
            'title' => 'Precio del diplomado',
            'content' => 'El diplomado cuesta 3.500 Bs en 5 cuotas.',
        ])->assertRedirect();

        $document = AiKnowledgeDocument::first();
        $this->assertNotNull($document);
        // Fijo: la corrección existe porque el retrieval no encontró la
        // respuesta. Dejarla sujeta al retrieval repetiría el error.
        $this->assertTrue((bool) $document->is_pinned);

        $feedback->refresh();
        $this->assertSame(AiFeedback::APPLIED, $feedback->status);
        $this->assertSame($document->id, $feedback->document_id);
        $this->assertNotNull($feedback->reviewed_at);
    }

    public function test_el_revisor_puede_editar_el_texto_antes_de_aprobarlo(): void
    {
        $this->report();

        $this->actingAs($this->owner)->post(route('settings.ai.feedback.apply', AiFeedback::first()), [
            'title' => 'Precio del diplomado',
            'content' => 'Texto revisado por el admin, distinto del que mandó el agente.',
        ])->assertRedirect();

        // Revisar sin poder corregir la corrección sería aprobar a ciegas.
        $this->assertSame(
            'Texto revisado por el admin, distinto del que mandó el agente.',
            AiKnowledgeDocument::first()->content,
        );
    }

    public function test_no_se_aplica_dos_veces(): void
    {
        $this->report();
        $feedback = AiFeedback::first();

        $payload = ['title' => 'T', 'content' => 'C'];
        $this->actingAs($this->owner)->post(route('settings.ai.feedback.apply', $feedback), $payload);
        $this->actingAs($this->owner)->post(route('settings.ai.feedback.apply', $feedback), $payload)
            ->assertSessionHasErrors('content');

        $this->assertSame(1, AiKnowledgeDocument::count());
    }

    public function test_descartar_no_crea_conocimiento(): void
    {
        $this->report();

        $this->actingAs($this->owner)
            ->post(route('settings.ai.feedback.dismiss', AiFeedback::first()))
            ->assertRedirect();

        // No toda queja es un hueco de conocimiento.
        $this->assertSame(AiFeedback::DISMISSED, AiFeedback::first()->status);
        $this->assertSame(0, AiKnowledgeDocument::count());
    }

    public function test_una_correccion_de_otra_cuenta_no_se_toca(): void
    {
        $otro = User::create(['name' => 'Otro', 'email' => 'x@test.com', 'password' => bcrypt('secret')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otro->id]);

        $ajena = AiFeedback::create([
            'account_id' => $otraCuenta->id, 'external_ref' => 'z',
            'rating' => 'down', 'correction' => 'x', 'status' => AiFeedback::PENDING,
        ]);

        $this->actingAs($this->owner)
            ->post(route('settings.ai.feedback.dismiss', $ajena))
            ->assertForbidden();
    }

    public function test_la_pantalla_muestra_la_tasa_de_rechazo(): void
    {
        $this->report(['external_ref' => 'a', 'rating' => 'down']);
        $this->report(['external_ref' => 'b', 'rating' => 'up', 'correction' => null]);
        $this->report(['external_ref' => 'c', 'rating' => 'up', 'correction' => null]);

        $stats = $this->actingAs($this->owner)->get(route('settings.ai.feedback'))
            ->assertOk()->viewData('page')['props']['stats'];

        $this->assertSame(1, $stats['down']);
        $this->assertSame(2, $stats['up']);
        $this->assertSame(33.3, $stats['downRate']);
    }

    public function test_sin_datos_la_tasa_es_null_y_no_cero(): void
    {
        $stats = $this->actingAs($this->owner)->get(route('settings.ai.feedback'))
            ->assertOk()->viewData('page')['props']['stats'];

        // Un 0% se leería como «la IA nunca falla».
        $this->assertNull($stats['downRate']);
    }
}

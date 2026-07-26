<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventana de servicio de WhatsApp — de esto depende que un envío salga
 * gratis o se cobre, así que las reglas quedan fijadas acá.
 */
class ServiceWindowTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $contact = Contact::create(['account_id' => $this->account->id, 'phone' => '59170000000', 'name' => 'Ana']);
        $this->conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    private function inbound(string $at, ?array $referral = null): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_text' => 'Hola',
            'referral' => $referral,
        ])->forceFill(['created_at' => $at])->save();
    }

    private function window(): array
    {
        return app(ServiceWindow::class)->for($this->conversation);
    }

    public function test_un_mensaje_del_cliente_abre_24h(): void
    {
        $this->inbound(now()->subHours(2)->toDateTimeString());

        $w = $this->window();

        $this->assertTrue($w['is_open']);
        $this->assertSame('whatsapp', $w['source']);
        $this->assertSame(24, $w['window_hours']);
        $this->assertEqualsWithDelta(22 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_pasadas_las_24h_la_ventana_se_cierra(): void
    {
        $this->inbound(now()->subHours(25)->toDateTimeString());

        $w = $this->window();

        $this->assertFalse($w['is_open']);
        $this->assertSame(0, $w['remaining_seconds']);
    }

    public function test_cada_mensaje_nuevo_del_cliente_renueva_la_ventana(): void
    {
        $this->inbound(now()->subHours(23)->toDateTimeString());
        $this->inbound(now()->subMinutes(10)->toDateTimeString());

        $this->assertEqualsWithDelta(23.83 * 3600, $this->window()['remaining_seconds'], 120);
    }

    public function test_el_anuncio_de_facebook_da_72h(): void
    {
        $this->inbound(now()->subHours(2)->toDateTimeString(), ['source_id' => 'ad-1', 'source_type' => 'ad']);

        $w = $this->window();

        $this->assertSame('meta_ad', $w['source']);
        $this->assertSame(72, $w['window_hours']);
        $this->assertEqualsWithDelta(70 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_las_72h_del_anuncio_siguen_cubriendo_aunque_el_ultimo_mensaje_sea_viejo(): void
    {
        // Tocó el anuncio hace 30 h y no volvió a escribir desde hace 26 h:
        // las 24 h ya vencieron pero las 72 h del anuncio no.
        $this->inbound(now()->subHours(30)->toDateTimeString(), ['source_id' => 'ad-1']);
        $this->inbound(now()->subHours(26)->toDateTimeString());

        $w = $this->window();

        $this->assertTrue($w['is_open'], 'El free entry point de 72h sigue vigente.');
        $this->assertSame('meta_ad', $w['source']);
        $this->assertEqualsWithDelta(42 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_un_mensaje_reciente_gana_a_un_anuncio_por_vencer(): void
    {
        // El anuncio vence en 1 h, pero el cliente escribió hace 10 min:
        // manda la ventana de 24 h, que vence mucho después.
        $this->inbound(now()->subHours(71)->toDateTimeString(), ['source_id' => 'ad-1']);
        $this->inbound(now()->subMinutes(10)->toDateTimeString());

        $w = $this->window();

        $this->assertSame('whatsapp', $w['source']);
        $this->assertSame(24, $w['window_hours']);
        $this->assertEqualsWithDelta(23.83 * 3600, $w['remaining_seconds'], 120);
    }

    public function test_el_mensaje_de_la_hora_71_deja_24h_mas_al_vencer_el_anuncio(): void
    {
        // El caso que hay que tener claro: el cliente toca el anuncio (72 h
        // gratis) y escribe recién en la hora 71. Cuando el anuncio vence en
        // la hora 72, NO se corta: quedan las 24 h estándar contadas desde su
        // último mensaje, o sea hasta la hora 95.
        $click = now()->subHours(71);
        $this->inbound($click->toDateTimeString(), ['source_id' => 'ad-1']);
        $this->inbound(now()->toDateTimeString()); // mensaje en la hora 71

        // Nos paramos en la hora 73: el anuncio ya vencio.
        $this->travel(2)->hours();

        $w = $this->window();

        $this->assertTrue($w['is_open'], 'Sigue abierta por la ventana estandar.');
        $this->assertSame('whatsapp', $w['source']);
        $this->assertSame(24, $w['window_hours']);
        $this->assertEqualsWithDelta(22 * 3600, $w['remaining_seconds'], 120);
    }

    public function test_las_72h_no_se_reinician_cuando_el_cliente_escribe(): void
    {
        // Regla clave del free entry point: solo un clic NUEVO en el anuncio
        // reabre las 72 h. Que el cliente siga escribiendo no las estira.
        $this->inbound(now()->subHours(50)->toDateTimeString(), ['source_id' => 'ad-1']);
        $this->inbound(now()->subHours(40)->toDateTimeString());
        $this->inbound(now()->subHours(30)->toDateTimeString());

        // 50 h desde el clic → quedan 22 h de las 72, no 72 otra vez.
        $this->assertEqualsWithDelta(22 * 3600, $this->window()['remaining_seconds'], 120);
    }

    public function test_un_clic_nuevo_en_el_anuncio_si_abre_otras_72h(): void
    {
        $this->inbound(now()->subHours(70)->toDateTimeString(), ['source_id' => 'ad-1']);
        $this->inbound(now()->subHour()->toDateTimeString(), ['source_id' => 'ad-1']);

        $w = $this->window();

        $this->assertSame('meta_ad', $w['source']);
        $this->assertEqualsWithDelta(71 * 3600, $w['remaining_seconds'], 120);
    }

    public function test_sin_mensajes_del_cliente_no_hay_ventana(): void
    {
        $w = $this->window();

        $this->assertFalse($w['is_open']);
        $this->assertSame('none', $w['source']);
        $this->assertNull($w['expires_at']);
    }

    public function test_avisa_cuando_queda_poco(): void
    {
        $this->inbound(now()->subHours(21)->toDateTimeString());

        $this->assertTrue($this->window()['is_expiring'], 'Quedan 3h: menos del umbral de aviso.');
    }

    public function test_el_lote_da_el_mismo_resultado_que_el_individual(): void
    {
        $this->inbound(now()->subHours(2)->toDateTimeString(), ['source_id' => 'ad-1']);

        $batch = app(ServiceWindow::class)->forMany([$this->conversation->id]);

        $this->assertSame($this->window()['window_hours'], $batch[$this->conversation->id]['window_hours']);
        $this->assertSame($this->window()['source'], $batch[$this->conversation->id]['source']);
    }
}

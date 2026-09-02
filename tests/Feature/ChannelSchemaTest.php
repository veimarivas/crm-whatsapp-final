<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChannelConfig;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F0/T0.1 — el esquema deja de asumir que todo es WhatsApp.
 *
 * Nada cambia de comportamiento (el `InboundProcessorParityTest` lo prueba);
 * lo que se abre es la posibilidad de que exista otra cosa. Estos tests fijan
 * las cuatro decisiones del esquema y el comando que hace segura la migración.
 */
class ChannelSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    // ── Bloqueante: un contacto sin teléfono ───────────────────────────────

    public function test_un_contacto_puede_existir_sin_telefono(): void
    {
        // Es el bloqueante que impedía cualquier canal sin teléfono: la columna
        // era NOT NULL y `Contact::create` reventaba con un error de SQL.
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana de Telegram',
        ]);

        $this->assertNull($contact->fresh()->phone);
        $this->assertNull($contact->fresh()->phone_normalized);
    }

    public function test_varios_contactos_sin_telefono_conviven(): void
    {
        // El unique `(account_id, phone_normalized)` tolera múltiples NULL en
        // MariaDB, así que NO hay que inventar teléfonos sintéticos — y no hay
        // que inventarlos: uno falso rompería el merge de duplicados y los
        // envíos masivos.
        Contact::create(['account_id' => $this->account->id, 'name' => 'Ana']);
        Contact::create(['account_id' => $this->account->id, 'name' => 'Beto']);

        $this->assertSame(2, Contact::count());
    }

    // ── La conversación es única por canal ─────────────────────────────────

    private function contacto(string $phone = '584125550001'): Contact
    {
        return Contact::create(['account_id' => $this->account->id, 'phone' => $phone, 'name' => 'Ana']);
    }

    public function test_el_mismo_contacto_tiene_un_hilo_por_canal(): void
    {
        $contact = $this->contacto();

        foreach ([ChannelRules::WHATSAPP, ChannelRules::TELEGRAM] as $channel) {
            Conversation::create([
                'account_id' => $this->account->id,
                'contact_id' => $contact->id,
                'channel' => $channel,
                'status' => 'open',
            ]);
        }

        // Sin el canal en la clave, los dos hilos caerían en una sola
        // conversación y su `channel` mentiría.
        $this->assertSame(2, $contact->conversations()->count());
    }

    public function test_dos_conversaciones_del_mismo_canal_ya_no_son_posibles(): void
    {
        $contact = $this->contacto();

        Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => ChannelRules::WHATSAPP,
            'status' => 'open',
        ]);

        // El `firstOrCreate` histórico no tenía índice que lo respaldara: dos
        // peticiones simultáneas podían duplicar el hilo.
        $this->expectException(QueryException::class);

        Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => ChannelRules::WHATSAPP,
            'status' => 'open',
        ]);
    }

    public function test_el_canal_por_defecto_es_whatsapp(): void
    {
        // Todo lo que existía antes de F0 sigue siendo de WhatsApp sin que
        // nadie lo declare.
        $contact = $this->contacto();

        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $this->assertSame('whatsapp', $conversation->fresh()->channel);
    }

    public function test_el_mismo_id_externo_en_dos_canales_no_choca(): void
    {
        $contact = $this->contacto();

        foreach ([ChannelRules::WHATSAPP, ChannelRules::TELEGRAM] as $channel) {
            $conversation = Conversation::create([
                'account_id' => $this->account->id,
                'contact_id' => $contact->id,
                'channel' => $channel,
                'status' => 'open',
            ]);

            Message::create([
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'external_message_id' => 'ID_REPETIDO',
                'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => 'text',
                'content_text' => 'hola',
            ]);
        }

        // El unique es `(channel, external_message_id)`: dos proveedores
        // distintos pueden emitir el mismo identificador y eso no es un error.
        $this->assertSame(2, Message::where('external_message_id', 'ID_REPETIDO')->count());
    }

    // ── Identidades ────────────────────────────────────────────────────────

    public function test_registrar_una_identidad_es_idempotente(): void
    {
        $contact = $this->contacto();

        // Tres mensajes seguidos del mismo remitente no crean tres filas.
        foreach (range(1, 3) as $_) {
            ContactIdentity::registrar($contact, ChannelRules::TELEGRAM, '99887766', 'Ana');
        }

        $this->assertSame(1, ContactIdentity::where('channel', ChannelRules::TELEGRAM)->count());
    }

    public function test_la_identidad_resuelve_el_contacto_sin_pasar_por_el_telefono(): void
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana de Telegram']);

        ContactIdentity::registrar($contact, ChannelRules::TELEGRAM, '99887766', 'Ana');

        $resuelto = ContactIdentity::resolverContacto($this->account->id, ChannelRules::TELEGRAM, '99887766');

        $this->assertSame($contact->id, $resuelto?->id);
        $this->assertNull(ContactIdentity::resolverContacto($this->account->id, ChannelRules::TELEGRAM, 'otro'));
    }

    public function test_la_primera_identidad_es_la_principal(): void
    {
        $contact = $this->contacto();

        $primera = ContactIdentity::registrar($contact, ChannelRules::WHATSAPP, '584125550001');
        $segunda = ContactIdentity::registrar($contact, ChannelRules::TELEGRAM, '99887766');

        $this->assertTrue($primera->is_primary);
        $this->assertFalse($segunda->is_primary);
    }

    public function test_el_nombre_del_perfil_se_completa_pero_no_se_pisa(): void
    {
        $contact = $this->contacto();

        // Meta no siempre manda el nombre en el primer mensaje.
        ContactIdentity::registrar($contact, ChannelRules::WHATSAPP, '584125550001', null);
        $identity = ContactIdentity::registrar($contact, ChannelRules::WHATSAPP, '584125550001', 'Ana');

        $this->assertSame('Ana', $identity->fresh()->display_name);

        // Pero uno que ya está no se toca: alguien pudo corregirlo a mano.
        ContactIdentity::registrar($contact, ChannelRules::WHATSAPP, '584125550001', 'ana pérez sin corregir');

        $this->assertSame('Ana', $identity->fresh()->display_name);
    }

    public function test_la_identidad_es_unica_por_cuenta_canal_e_id(): void
    {
        $contact = $this->contacto();
        ContactIdentity::registrar($contact, ChannelRules::TELEGRAM, '99887766');

        $this->expectException(QueryException::class);

        ContactIdentity::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => ChannelRules::TELEGRAM,
            'external_id' => '99887766',
        ]);
    }

    // ── Configuración por canal ────────────────────────────────────────────

    public function test_las_credenciales_van_cifradas_y_no_se_serializan(): void
    {
        $config = ChannelConfig::create([
            'account_id' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'is_enabled' => true,
            'credentials' => ['bot_token' => '123456:SECRETO'],
        ]);

        // En reposo, cifradas: un bot token da control total sobre las
        // conversaciones de la institución.
        $crudo = DB::table('channel_configs')->where('id', $config->id)->value('credentials');
        $this->assertStringNotContainsString('SECRETO', $crudo);

        // Y no viajan al serializar: basta con que una vez lleguen a una vista
        // para quedar en el historial del navegador.
        $this->assertArrayNotHasKey('credentials', $config->fresh()->toArray());

        $this->assertSame('123456:SECRETO', $config->fresh()->credential('bot_token'));
        $this->assertNull($config->fresh()->credential('inexistente'));
    }

    public function test_solo_devuelve_la_configuracion_habilitada(): void
    {
        ChannelConfig::create([
            'account_id' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'is_enabled' => false,
            'credentials' => ['bot_token' => 'x'],
        ]);

        $this->assertNull(ChannelConfig::activa($this->account->id, ChannelRules::TELEGRAM));
    }

    // ── El comando que hace segura la migración ────────────────────────────

    public function test_el_precheck_no_encuentra_nada_en_una_base_sana(): void
    {
        $this->artisan('wacrm:channel-precheck')
            ->expectsOutputToContain('No hay nada que arreglar')
            ->assertSuccessful();
    }

    public function test_el_precheck_fusiona_conversaciones_duplicadas(): void
    {
        // La migración ya creó el índice único, así que para simular el estado
        // PREVIO hay que quitarlo: es exactamente la base contra la que el
        // comando va a correr en producción.
        Schema::table('conversations', fn ($t) => $t->dropUnique(['account_id', 'contact_id', 'channel']));

        $contact = $this->contacto();
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0]);

        $vieja = Conversation::create([
            'account_id' => $this->account->id, 'contact_id' => $contact->id,
            'status' => 'open', 'unread_count' => 2, 'entry_ad_id' => 'AD_ORIGINAL',
        ]);
        $vieja->forceFill(['created_at' => now()->subDays(10)])->save();

        $nueva = Conversation::create([
            'account_id' => $this->account->id, 'contact_id' => $contact->id,
            'status' => 'open', 'unread_count' => 3, 'entry_ad_id' => 'AD_POSTERIOR',
        ]);

        Message::create([
            'conversation_id' => $nueva->id, 'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text', 'content_text' => 'en la nueva',
        ]);

        Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'contact_id' => $contact->id, 'conversation_id' => $nueva->id, 'title' => 'Ana',
        ]);

        // Sin --fix informa y sale con error: se corre en un deploy y «hay que
        // hacer algo» tiene que poder detectarlo un script.
        $this->artisan('wacrm:channel-precheck')->assertFailed();
        $this->assertSame(2, Conversation::count());

        $this->artisan('wacrm:channel-precheck --fix')->assertSuccessful();

        // Gana la MÁS ANTIGUA: tiene el historial y es a la que apuntan los
        // enlaces que ya se repartieron.
        $this->assertSame(1, Conversation::count());
        $this->assertNotNull($vieja->fresh());

        $vieja->refresh();
        $this->assertSame(5, $vieja->unread_count, 'Los no leídos se suman: son mensajes reales que nadie abrió.');
        $this->assertSame('AD_ORIGINAL', $vieja->entry_ad_id, 'La atribución original se conserva.');

        // Y nada quedó colgando de la conversación borrada.
        $this->assertSame($vieja->id, Message::firstOrFail()->conversation_id);
        $this->assertSame($vieja->id, Deal::firstOrFail()->conversation_id);
    }

    public function test_el_precheck_desduplica_ids_externos_sin_borrar_mensajes(): void
    {
        Schema::table('messages', fn ($t) => $t->dropUnique(['channel', 'external_message_id']));

        $contact = $this->contacto();
        $conversation = Conversation::create([
            'account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open',
        ]);

        foreach (['viejo', 'nuevo'] as $i => $texto) {
            $m = Message::create([
                'conversation_id' => $conversation->id, 'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => 'text', 'content_text' => $texto, 'message_id' => 'wamid.REPETIDO',
            ]);
            $m->forceFill(['created_at' => now()->addSeconds($i)])->save();
        }

        $this->artisan('wacrm:channel-precheck --fix')->assertSuccessful();

        // Los DOS mensajes siguen existiendo: son mensajes reales, y borrar uno
        // cambiaría el historial de la conversación y las métricas de respuesta.
        $this->assertSame(2, Message::count());

        // El más antiguo conserva el id; el otro lo pierde. Perder el id de
        // Meta solo significa que ese mensaje no se puede correlacionar con un
        // webhook de estado, que es un costo mucho menor.
        $this->assertSame('wamid.REPETIDO', Message::where('content_text', 'viejo')->value('message_id'));
        $this->assertNull(Message::where('content_text', 'nuevo')->value('message_id'));
    }

    public function test_el_proceso_de_entrada_estrena_identidad_y_id_externo(): void
    {
        // El camino real: un mensaje entrante deja la identidad registrada y la
        // columna canal-agnóstica llena. Si solo lo hiciera el backfill, el
        // índice de deduplicación quedaría inútil hacia adelante.
        \App\Models\WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        app(\App\Services\WhatsApp\InboundProcessor::class)->process([
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'contacts' => [['wa_id' => '584125550001', 'profile' => ['name' => 'Ana']]],
                'messages' => [[
                    'id' => 'wamid.'.Str::random(8),
                    'from' => '584125550001',
                    'type' => 'text',
                    'text' => ['body' => 'hola'],
                ]],
            ]]]]],
        ]);

        $identity = ContactIdentity::firstOrFail();
        $this->assertSame(ChannelRules::WHATSAPP, $identity->channel);
        $this->assertSame('584125550001', $identity->external_id);
        $this->assertTrue($identity->is_primary);

        $message = Message::firstOrFail();
        $this->assertSame(ChannelRules::WHATSAPP, $message->channel);
        $this->assertSame($message->message_id, $message->external_message_id);

        $conversation = Conversation::firstOrFail();
        $this->assertSame(ChannelRules::WHATSAPP, $conversation->channel);
        $this->assertSame('584125550001', $conversation->channel_conversation_id);
    }
}

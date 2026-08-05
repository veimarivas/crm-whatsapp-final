<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\ReplyGenerator;
use App\Services\Ai\ReplySanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cómo sale escrita la respuesta.
 *
 * El prompt pide "sin markdown" desde el primer día y los modelos chicos lo
 * incumplen igual: contestaban con `**negritas**` y `###títulos`, que en
 * WhatsApp se leen con los asteriscos a la vista. Y al toparse con el tope de
 * tokens terminaban a mitad de palabra ("...Diplomado en Banca Y Finanzas -
 * Tipo: Dipl").
 *
 * Pedirlo más veces no alcanza. Se corrige después, que es determinista.
 */
class ReplyFormatTest extends TestCase
{
    use RefreshDatabase;

    private function limpiar(string $texto): string
    {
        return (new ReplySanitizer())->clean($texto);
    }

    public function test_saca_las_negritas_de_markdown(): void
    {
        $limpio = $this->limpiar('Tenemos el **Diplomado en Banca** disponible.');

        $this->assertSame('Tenemos el Diplomado en Banca disponible.', $limpio);
    }

    public function test_saca_los_titulos_y_los_bloques_de_codigo(): void
    {
        $limpio = $this->limpiar("## Programas\n```\nlista\n```");

        $this->assertStringNotContainsString('#', $limpio);
        $this->assertStringNotContainsString('```', $limpio);
    }

    public function test_normaliza_las_vinetas(): void
    {
        $limpio = $this->limpiar("Programas:\n* Uno\n* Dos");

        $this->assertSame("Programas:\n- Uno\n- Dos", $limpio);
    }

    public function test_detecta_la_respuesta_cortada_a_mitad(): void
    {
        $sanitizer = new ReplySanitizer();

        $this->assertTrue($sanitizer->looksTruncated('5. Diplomado en Banca Y Finanzas - Tipo: Dipl'));
        $this->assertFalse($sanitizer->looksTruncated('¿Sobre cuál te gustaría más información?'));
    }

    public function test_recorta_hasta_la_ultima_linea_completa(): void
    {
        $sanitizer = new ReplySanitizer();

        $cortada = "Estos son los programas:\n1. Diplomado en Banca\n2. Diplomado en Audit";

        $this->assertSame("Estos son los programas:\n1. Diplomado en Banca", $sanitizer->trimToLastComplete($cortada));
    }

    public function test_la_respuesta_del_modelo_llega_limpia_al_cliente(): void
    {
        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $config = AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:3b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'que programas ofrecen?',
        ]);

        Http::fake(['*' => Http::response(['message' => ['content' => "## Programas\n1. **Diplomado en Banca**\n2. **Curso de IA** aplicada al Diplom"]])]);

        $reply = app(ReplyGenerator::class)->generate($config, $conversation);

        $this->assertStringNotContainsString('**', $reply);
        $this->assertStringNotContainsString('##', $reply);
        $this->assertStringNotContainsString('Diplom', mb_substr($reply, -10), 'La línea cortada se descarta.');
    }

    public function test_el_prompt_termina_con_el_ejemplo_de_formato(): void
    {
        $owner = User::create(['name' => 'Admin', 'email' => 'b@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $config = AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:3b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
        ]);

        AiKnowledgeDocument::create([
            'account_id' => $account->id,
            'title' => '[OFERTA] Catálogo de programas vigentes',
            'content' => "OFERTA ACADÉMICA VIGENTE\n1. Diplomado en Banca",
            'is_pinned' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917001', 'phone_normalized' => '5917001']);
        $conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'hola',
        ]);

        Http::fake(['*' => Http::response(['message' => ['content' => 'ok']])]);

        app(ReplyGenerator::class)->generate($config, $conversation);

        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            // Al final del todo: los modelos chicos siguen mejor lo último que
            // leen, y estas reglas estaban sepultadas entre once puntos.
            $this->assertStringContainsString('CÓMO ESCRIBIR LA RESPUESTA', $system);
            $this->assertStringEndsWith('Nunca todo junto.', trim($system));

            return true;
        });
    }
}

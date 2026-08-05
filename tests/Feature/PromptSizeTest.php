<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\OfertaAcademica;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuánto texto viaja en CADA consulta.
 *
 * Este es el test que faltaba cuando anclé el catálogo completo al prompt:
 * ~14.000 caracteres fijos, unos 4.700 tokens, que en este servidor (CPU, sin
 * GPU, ~59 tok/s de lectura) son **80 segundos por pregunta antes de empezar a
 * pensar**. La IA pasó de responder rápido a tardar minutos, y encima
 * contestaba copiando las fichas del catálogo que tenía delante.
 *
 * Lo que va fijo es el ÍNDICE (nombres), que es lo único que tiene que estar
 * siempre: sin él la IA inventa programas. Precios, fechas, módulos y horarios
 * se traen solo cuando la pregunta los pide.
 */
class PromptSizeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private AiConfig $config;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->config = AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);
    }

    private function doc(string $titulo, string $contenido, bool $pinned = false): void
    {
        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->account->id,
            'title' => $titulo,
            'content' => $contenido,
            'is_pinned' => $pinned,
        ]);

        app(\App\Services\Ai\Chunker::class)->reindex($doc);
    }

    private function preguntar(string $texto): string
    {
        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => $texto,
        ]);

        Http::fake(['*' => Http::response(['message' => ['content' => 'ok']])]);
        app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        $todo = '';
        Http::assertSent(function ($request) use (&$todo) {
            foreach ($request['messages'] ?? [] as $m) {
                $todo .= ($m['content'] ?? '')."\n";
            }

            return true;
        });

        return $todo;
    }

    /** El escenario real: índice chico fijo + catálogo grande recuperable. */
    private function sembrarOferta(): void
    {
        $this->doc(OfertaAcademica::DOC_INDICE, "OFERTA ACADÉMICA VIGENTE\n1. Diplomado en Banca Y Finanzas\n2. Diplomado en Auditoría Médica", pinned: true);
        $this->doc(OfertaAcademica::DOC_CATALOGO, 'CATALOGO CON PRECIOS '.str_repeat('Matrícula Bs 390. Colegiatura Bs 5430. ', 300));
        $this->doc('[OFERTA] Diplomado en Banca Y Finanzas', 'DETALLE BANCA '.str_repeat('Módulo con horarios. ', 300));
    }

    public function test_un_saludo_no_arrastra_el_catalogo_entero(): void
    {
        $this->sembrarOferta();

        $prompt = $this->preguntar('hola buenas tardes');

        // Los nombres sí: son lo que evita que invente programas.
        $this->assertStringContainsString('Diplomado en Banca Y Finanzas', $prompt);
        // El detalle no: sería pagar 80 s de lectura por un saludo.
        $this->assertStringNotContainsString('DETALLE BANCA', $prompt);
    }

    public function test_nombrar_un_programa_trae_su_detalle(): void
    {
        $this->sembrarOferta();

        $prompt = $this->preguntar('me pasás los horarios del diplomado en banca y finanzas?');

        $this->assertStringContainsString('DETALLE BANCA', $prompt);
    }

    public function test_una_pregunta_generica_de_precios_trae_el_catalogo(): void
    {
        $this->sembrarOferta();

        // Sin este respaldo, el índice solo tiene nombres y la IA tendría que
        // decir que no sabe los precios teniéndolos a un documento de
        // distancia.
        $prompt = $this->preguntar('cuánto cuestan?');

        $this->assertStringContainsString('CATALOGO CON PRECIOS', $prompt);
    }

    public function test_el_prompt_de_un_saludo_se_mantiene_chico(): void
    {
        $this->sembrarOferta();

        $prompt = $this->preguntar('hola');

        // Referencia: a ~59 tokens/s de lectura, 12.000 caracteres (~4.000
        // tokens) ya son más de un minuto de espera. Si este número se
        // dispara, la IA vuelve a tardar minutos.
        $this->assertLessThan(12000, mb_strlen($prompt),
            'El prompt de una pregunta simple se fue de tamaño: revisá qué se está fijando.');
    }
}

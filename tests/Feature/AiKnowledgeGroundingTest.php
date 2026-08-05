<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Chunker;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Qué contexto recibe el modelo antes de contestar.
 *
 * La IA alucinaba por tres motivos concretos, y cada uno tiene su prueba acá:
 *  - El catálogo de programas dependía de que la búsqueda lo encontrara. El
 *    día que no lo encontraba, el modelo inventaba el programa.
 *  - Cada fragmento recuperado se recortaba a 600 caracteres, así que los
 *    horarios de los últimos módulos nunca llegaban: respuestas a medias.
 *  - Si el retrieval traía el programa equivocado, contestaba los horarios de
 *    otro con total seguridad.
 */
class AiKnowledgeGroundingTest extends TestCase
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
        $owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);

        $this->config = AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
        ]);

        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana',
            'phone' => '59170000000',
            'phone_normalized' => '59170000000',
        ]);

        $this->conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
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

        // Se captura el prompt que sale hacia Ollama: lo que se prueba es el
        // contexto que recibe el modelo, no lo que el modelo responde.
        Http::fake(['*' => Http::response(['message' => ['content' => 'ok']])]);

        app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        // Todo lo que ve el modelo: el prompt de sistema (reglas + catálogo) y
        // los mensajes, donde ahora viaja el detalle recuperado.
        $enviado = '';
        Http::assertSent(function ($request) use (&$enviado) {
            foreach ($request['messages'] ?? [] as $m) {
                $enviado .= ($m['content'] ?? '')."\n";
            }

            return true;
        });

        return $enviado;
    }

    /** Solo el prompt de sistema, que es el que tiene que ser estable. */
    private function promptDeSistema(string $texto): string
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

        $system = '';
        Http::assertSent(function ($request) use (&$system) {
            foreach ($request['messages'] ?? [] as $m) {
                if (($m['role'] ?? '') === 'system') {
                    $system = $m['content'];
                }
            }

            return true;
        });

        return $system;
    }

    private function documento(string $titulo, string $contenido, bool $pinned = false): AiKnowledgeDocument
    {
        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->account->id,
            'title' => $titulo,
            'content' => $contenido,
            'is_pinned' => $pinned,
        ]);

        app(Chunker::class)->reindex($doc);

        return $doc;
    }

    public function test_el_catalogo_va_en_el_prompt_aunque_la_pregunta_no_lo_mencione(): void
    {
        $this->documento(
            '[OFERTA] Catálogo de programas vigentes',
            "OFERTA ACADÉMICA VIGENTE\n\nPROGRAMAS DISPONIBLES:\n1. Maestría en Gestión Pública",
            pinned: true,
        );

        $prompt = $this->preguntar('hola, qué tal?');

        $this->assertStringContainsString('Maestría en Gestión Pública', $prompt);
        $this->assertStringContainsString('lista cerrada', $prompt);
    }

    public function test_el_prompt_prohibe_ofrecer_lo_que_no_esta_en_el_catalogo(): void
    {
        $prompt = $this->preguntar('tienen medicina?');

        $this->assertStringContainsString('No ofrecemos ese programa en este momento.', $prompt);
        $this->assertStringContainsString('lista CERRADA y COMPLETA', $prompt);
    }

    public function test_el_prompt_prohibe_aproximar_un_dato_que_falta(): void
    {
        $prompt = $this->preguntar('cuánto cuesta?');

        // Y le dice qué decir en su lugar: que lo solicita al área académica.
        // «No lo tengo a la mano» y «te paso con un asesor» dejan al cliente
        // con la sensación de que lo derivan; esto dice que alguien se ocupa.
        $this->assertStringContainsString('solicitar esa información al área académica', $prompt);
        $this->assertStringContainsString('NUNCA digas que no lo tenés', $prompt);
    }

    public function test_nombrar_un_programa_trae_su_detalle_completo(): void
    {
        // Dos programas parecidos: el retrieval por palabras puede confundirlos.
        $this->documento('[OFERTA] Maestría en Gestión Pública', "Programa: Maestría en Gestión Pública\nMódulo 1\n     - 10/09/2026 de 19:00 a 22:00");
        $this->documento('[OFERTA] Maestría en Gestión Ambiental', "Programa: Maestría en Gestión Ambiental\nMódulo 1\n     - 11/11/2026 de 08:00 a 12:00");

        $prompt = $this->preguntar('me pasás los horarios de la maestría en gestión pública?');

        $this->assertStringContainsString('10/09/2026', $prompt);
        $this->assertStringNotContainsString('11/11/2026', $prompt, 'No debe colarse el programa que no preguntó.');
    }

    public function test_los_horarios_ya_no_se_cortan_a_600_caracteres(): void
    {
        // 40 sesiones: con el recorte viejo, de la sesión ~15 en adelante no
        // llegaba nada y la IA contestaba media lista.
        $sesiones = collect(range(1, 40))
            ->map(fn ($i) => sprintf('     - %02d/09/2026 de 19:00 a 22:00', ($i % 28) + 1))
            ->join("\n");

        $this->documento('[OFERTA] Diplomado en Tributación', "Programa: Diplomado en Tributación\nMÓDULOS:\n1. Tributación aplicada\n{$sesiones}\nSESION_FINAL_MARCA");

        $prompt = $this->preguntar('horarios del diplomado en tributación por favor');

        $this->assertStringContainsString('SESION_FINAL_MARCA', $prompt, 'El final del documento tiene que llegar al modelo.');
    }

    public function test_el_prompt_de_sistema_no_cambia_entre_preguntas(): void
    {
        // Es lo que hace que el servidor sin GPU sea usable: llama.cpp
        // reutiliza la caché del prefijo común, así que reglas + catálogo se
        // leen una vez y no en cada consulta. Si algo variable se cuela acá
        // (el detalle del programa, la hora, el nombre del cliente en medio),
        // el prefijo deja de coincidir y se releen miles de tokens cada vez.
        $this->documento(
            '[OFERTA] Catálogo de programas vigentes',
            "OFERTA ACADÉMICA VIGENTE\n1. Maestría en Gestión Pública",
            pinned: true,
        );
        $this->documento('[OFERTA] Maestría en Gestión Pública', "Programa: Maestría en Gestión Pública\n- 10/09/2026");

        $primero = $this->promptDeSistema('hola');
        $segundo = $this->promptDeSistema('me pasás los horarios de la maestría en gestión pública?');

        $this->assertSame($primero, $segundo);
    }

    public function test_la_respuesta_se_acota_para_no_tardar_minutos(): void
    {
        $this->preguntar('hola');

        Http::assertSent(function ($request) {
            // 800 tokens a la velocidad de generación de un VPS sin GPU son
            // minutos de espera para un mensaje de WhatsApp — que además nadie
            // quiere leer tan largo.
            $this->assertSame((int) config('services.ai_context.max_tokens'), $request['options']['num_predict']);
            $this->assertLessThanOrEqual(250, $request['options']['num_predict']);

            return true;
        });
    }

    public function test_sin_catalogo_avisa_que_no_hay_de_donde_responder(): void
    {
        $prompt = $this->preguntar('qué programas tienen?');

        $this->assertStringContainsString('BASE DE CONOCIMIENTO', $prompt);
    }
}

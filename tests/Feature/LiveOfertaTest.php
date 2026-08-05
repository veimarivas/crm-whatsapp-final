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
use App\Services\Ai\OfertaAcademica;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La oferta se consulta a la BD en el momento, no se fija al prompt.
 *
 * Es la vuelta al modelo anterior —que era mucho más rápido— conservando lo
 * que se ganó con el catálogo: solo programas en inscripciones, con área,
 * módulos, docentes y horarios. Lo caro nunca fue leer la base (milisegundos):
 * era hacerle leer al modelo, en CADA mensaje, un catálogo entero que casi
 * nunca hacía falta. A ~59 tokens/s eso eran ~80 s por pregunta.
 */
class LiveOfertaTest extends TestCase
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

    /** La BD académica responde y devuelve este contexto. */
    private function ofertaViva(string $indice, string $detalle = ''): void
    {
        $this->mock(OfertaAcademica::class, function ($mock) use ($indice, $detalle) {
            $mock->shouldReceive('disponible')->andReturn(true);
            $mock->shouldReceive('contextoPara')->andReturn(['indice' => $indice, 'detalle' => $detalle]);
        });
    }

    /** @return array{system: string, todo: string} */
    private function preguntar(string $texto): array
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
        $todo = '';
        Http::assertSent(function ($request) use (&$system, &$todo) {
            foreach ($request['messages'] ?? [] as $m) {
                $todo .= ($m['content'] ?? '')."\n";
                if (($m['role'] ?? '') === 'system') {
                    $system = $m['content'];
                }
            }

            return true;
        });

        return ['system' => $system, 'todo' => $todo];
    }

    public function test_la_lista_viva_va_en_el_prompt_de_sistema(): void
    {
        $this->ofertaViva("OFERTA VIGENTE\n1. Diplomado en Banca Y Finanzas");

        $prompt = $this->preguntar('hola');

        // En el system y no en los mensajes: es idéntica entre consultas, así
        // que el modelo reutiliza la caché de ese prefijo.
        $this->assertStringContainsString('Diplomado en Banca Y Finanzas', $prompt['system']);
    }

    public function test_el_detalle_del_programa_llega_como_mensaje_aparte(): void
    {
        $this->ofertaViva('OFERTA VIGENTE', "DETALLE: Diplomado en Banca\nMódulo 1 - 10/09/2026 de 19:00 a 22:00");

        $prompt = $this->preguntar('horarios del diplomado en banca');

        $this->assertStringContainsString('10/09/2026', $prompt['todo']);
        $this->assertStringNotContainsString('10/09/2026', $prompt['system'],
            'El detalle cambia con cada pregunta: dentro del system rompería la caché del prefijo.');
    }

    public function test_un_saludo_no_arrastra_detalle(): void
    {
        $this->ofertaViva('OFERTA VIGENTE', '');

        $prompt = $this->preguntar('buenas noches');

        $this->assertLessThan(8000, mb_strlen($prompt['todo']));
    }

    public function test_si_la_base_academica_no_responde_se_usa_la_foto_indexada(): void
    {
        $this->mock(OfertaAcademica::class, function ($mock) {
            $mock->shouldReceive('disponible')->andReturn(false);
        });

        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->account->id,
            'title' => OfertaAcademica::DOC_INDICE,
            'content' => 'OFERTA DE RESPALDO: Diplomado en Banca',
            'is_pinned' => true,
        ]);
        app(Chunker::class)->reindex($doc);

        $prompt = $this->preguntar('hola');

        // Mejor una foto de hace unas horas que una IA muda.
        $this->assertStringContainsString('OFERTA DE RESPALDO', $prompt['system']);
    }

    public function test_un_detalle_enorme_se_recorta_para_entrar_en_el_presupuesto(): void
    {
        // El bug real: una pregunta por un programa con muchos módulos mandaba
        // decenas de miles de caracteres y el request moría a los 180 s con 0
        // bytes recibidos.
        $this->ofertaViva('OFERTA VIGENTE', str_repeat('Sesión del 10/09/2026 de 19:00 a 22:00. ', 3000));

        $prompt = $this->preguntar('horarios del diplomado en banca');

        $this->assertLessThan(
            (int) config('services.ai_context.total_budget') + 5000,
            mb_strlen($prompt['todo']),
        );
    }

    public function test_el_conocimiento_cargado_a_mano_sigue_llegando(): void
    {
        $this->ofertaViva('OFERTA VIGENTE', '');

        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->account->id,
            'title' => 'Formas de pago',
            'content' => 'Aceptamos transferencia bancaria y pago con tarjeta en cuotas.',
        ]);
        app(Chunker::class)->reindex($doc);

        // Esto no sale de la BD académica: lo escribe el equipo en Ajustes.
        $prompt = $this->preguntar('aceptan pago con tarjeta?');

        $this->assertStringContainsString('tarjeta', $prompt['todo']);
    }
}

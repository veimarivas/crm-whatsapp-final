<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\AiKnowledgeDocument;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Ai\Chunker;
use App\Services\Ai\ReplyGenerator;
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La IA debe poder responder a un mensaje de voz usando su transcripción.
 *
 * Los audios guardan el contenido en `messages.transcript` (content_text es
 * null). Antes `ReplyGenerator` filtraba por content_text y el bot respondía
 * sin "escuchar" el audio — o no respondía nada.
 */
class AiAudioTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        WhatsappConfig::create([
            'account_id' => $account->id,
            'phone_number_id' => '111',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test',
            'system_prompt' => 'Sos un asesor académico.',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 5,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '59170000000']);
        $this->conversation = Conversation::create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Sí, te cuento de los cursos.']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.AI1']]]),
        ]);
    }

    private function runBot(): void
    {
        (new AiAutoReplyJob($this->conversation->id))
            ->handle(app(ReplyGenerator::class), app(Messenger::class));

        $this->conversation->refresh();
    }

    public function test_el_bot_responde_al_audio_usando_la_transcripcion(): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'audio',
            'content_text' => null,
            'transcript' => '¿Qué cursos tienen disponibles?',
        ]);

        $this->runBot();

        $this->assertSame(1, Message::where('sender_type', Message::SENDER_BOT)->count());
        $this->assertSame(1, $this->conversation->ai_reply_count);

        // La transcripción viaja como mensaje del usuario en el prompt.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openai.com')) {
                return false;
            }

            $user = collect($request['messages'])
                ->filter(fn ($m) => ($m['role'] ?? '') === 'user')
                ->pluck('content')
                ->implode(' ');

            return str_contains($user, '¿Qué cursos tienen disponibles?');
        });
    }

    public function test_la_transcripcion_guia_la_busqueda_en_el_knowledge_base(): void
    {
        // Documento indexado (FULLTEXT) con un término que solo aparece en la
        // transcripción del audio.
        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->conversation->account_id,
            'title' => 'Cursos',
            'content' => 'La tecnicatura superior en desarrollo de software dura dos años.',
        ]);
        app(Chunker::class)->reindex($doc);

        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'audio',
            'content_text' => null,
            'transcript' => '¿Cuánto dura la tecnicatura?',
        ]);

        $this->runBot();

        // La recuperación semántica/lexical usó el transcript del audio para
        // traer el fragmento, que llega en el system prompt.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openai.com')) {
                return false;
            }

            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($system, 'tecnicatura superior en desarrollo de software');
        });
    }
}

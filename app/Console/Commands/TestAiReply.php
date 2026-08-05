<?php

namespace App\Console\Commands;

use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\ReplyGenerator;
use App\Services\WhatsApp\Messenger;
use Illuminate\Console\Command;

/**
 * Genera una respuesta de la IA AHORA MISMO y muestra qué pasó.
 *
 * `ai-doctor` dice si algo la bloquea; esto ejecuta el camino real y muestra
 * el resultado. Corre en primer plano, así que descarta de una la variable
 * más difícil de descartar: si acá responde y por WhatsApp no, el problema no
 * es la IA — es que el job no se está ejecutando (worker) o que el envío a
 * Meta falla.
 *
 *   php artisan wacrm:ai-test --phone=59171234567
 *   php artisan wacrm:ai-test --phone=59171234567 --send   (además se lo manda)
 */
class TestAiReply extends Command
{
    protected $signature = 'wacrm:ai-test
        {--conversation= : UUID de la conversación}
        {--phone= : Teléfono del contacto}
        {--ask= : Probar una pregunta concreta sin escribirla en la conversación}
        {--send : Enviar de verdad la respuesta al cliente}';

    protected $description = 'Genera una respuesta de la IA en primer plano y muestra el resultado o el error exacto';

    public function handle(ReplyGenerator $generator, Messenger $messenger): int
    {
        $conversation = $this->resolveConversation();

        if (! $conversation) {
            $this->error('No encontré la conversación. Usá --phone=59171234567 o --conversation=UUID.');

            return self::FAILURE;
        }

        $config = AiConfig::forAccount($conversation->account_id)->first();

        if (! $config) {
            $this->error('La cuenta no tiene IA configurada.');

            return self::FAILURE;
        }

        $this->line('Conversación: '.$conversation->id);
        $this->line('Contacto: '.($conversation->contact?->name ?: $conversation->contact?->phone));
        $this->line('Proveedor: '.$config->provider.' / '.$config->model);
        $this->newLine();

        // Los últimos mensajes: si el último saliente es de un agente, la IA
        // está apagada por eso y volverá a apagarse en cuanto se reactive y
        // alguien conteste de nuevo.
        $this->line('<options=bold>Últimos mensajes</>');

        $ultimos = Message::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->limit(5)
            ->get(['sender_type', 'content_type', 'content_text', 'created_at'])
            ->reverse();

        foreach ($ultimos as $m) {
            $quien = match ($m->sender_type) {
                Message::SENDER_CUSTOMER => '<fg=cyan>cliente</>',
                Message::SENDER_BOT => '<fg=magenta>IA     </>',
                default => '<fg=yellow>agente </>',
            };
            $texto = mb_substr((string) ($m->content_text ?: '['.$m->content_type.']'), 0, 70);
            $this->line('  '.$m->created_at->format('d/m H:i').'  '.$quien.'  '.$texto);
        }

        if ($conversation->ai_autoreply_disabled) {
            $this->newLine();
            $this->warn('La IA está APAGADA en esta conversación'
                .($conversation->ai_disabled_reason ? ': '.$conversation->ai_disabled_reason : '.'));
            $this->line('Se genera igual para ver si funciona, pero por WhatsApp no va a contestar sola.');
            $this->line('Reactivar: php artisan wacrm:ai-doctor --conversation='.$conversation->id.' --reactivate');
        }

        $this->newLine();
        $this->line('<options=bold>Generando respuesta…</> (puede tardar si el modelo está frío)');

        $inicio = microtime(true);

        // `--ask` prueba una pregunta sin ensuciar la conversación real: el
        // mensaje se crea, se genera la respuesta y se borra. Sirve para
        // afinar el prompt sin tener que escribir desde el teléfono cada vez.
        $temporal = null;

        if ($pregunta = $this->option('ask')) {
            $temporal = Message::create([
                'account_id' => $conversation->account_id,
                'conversation_id' => $conversation->id,
                'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => 'text',
                'content_text' => $pregunta,
            ]);
            $this->line('Pregunta de prueba: "'.$pregunta.'"');
        }

        try {
            $reply = $generator->generate($config, $conversation);
        } catch (\Throwable $e) {
            $temporal?->delete();
            $segundos = round(microtime(true) - $inicio, 1);

            $this->newLine();
            $this->error("✗ Falló a los {$segundos}s: ".get_class($e));
            $this->line('  '.$e->getMessage());
            $this->newLine();
            $this->line('  → '.$this->pista($e->getMessage()));

            return self::FAILURE;
        }

        $temporal?->delete();

        $segundos = round(microtime(true) - $inicio, 1);

        if (trim($reply) === '') {
            $this->error("✗ El modelo devolvió una respuesta VACÍA (en {$segundos}s).");
            $this->line('  El job trata esto como "no hay nada que decir" y no envía nada.');
            $this->line('  → Suele ser el prompt demasiado largo para el contexto: revisá el tamaño del catálogo en Ajustes → IA.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✓ Respuesta generada en {$segundos}s:");
        $this->newLine();
        $this->line('  '.str_replace("\n", "\n  ", $reply));
        $this->newLine();

        if ($segundos > 100) {
            $this->warn('Tardó más de 100s: en producción el timeout es 120s, así que está al borde.');
            $this->line('→ Mantené el modelo caliente: php artisan wacrm:ai-warmup (ya corre cada 10 min por el scheduler).');
        }

        if (! $this->option('send')) {
            $this->line('No se envió nada al cliente. Agregá --send para mandarla de verdad.');

            return self::SUCCESS;
        }

        try {
            $messenger->sendText($conversation, $reply);
            $this->info('✓ Enviada al cliente por WhatsApp.');
        } catch (\Throwable $e) {
            // Este caso es clave: la IA anda y el problema es el envío.
            $this->error('✗ La IA respondió pero el envío a WhatsApp falló: '.$e->getMessage());
            $this->line('  → Revisá Ajustes → WhatsApp: token vencido, número no conectado o ventana de 24 h cerrada.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function pista(string $error): string
    {
        return match (true) {
            str_contains($error, 'timed out') || str_contains($error, 'cURL error 28') =>
                'Timeout de Ollama. Casi siempre es el modelo frío: php artisan wacrm:ai-warmup y probá de nuevo.',
            str_contains($error, 'Connection refused') || str_contains($error, 'cURL error 7') =>
                'Ollama no está escuchando. En el servidor: systemctl status ollama',
            str_contains($error, 'model') && str_contains($error, 'not found') =>
                'Ese modelo no está descargado en Ollama. En el servidor: ollama list (y ollama pull <modelo> si falta).',
            str_contains($error, 'HTTP 404') =>
                'El proveedor no encontró ese modelo. Listá los que ofrece y copiá uno tal cual: '
                .'php artisan wacrm:ai-models',
            str_contains($error, 'HTTP 401') || str_contains($error, 'HTTP 403') =>
                'La API key no sirve o no tiene permiso para ese modelo. Probala con: '
                .'php artisan wacrm:ai-models --provider=<proveedor> --key=<clave>',
            str_contains($error, 'límite de uso') =>
                'Cuota del proveedor agotada (plan gratuito). No hay nada roto: se repone en unos minutos. '
                .'En producción el bot reintenta solo cada 70s; acá volvé a probar en un rato.',
            str_contains($error, 'esam_datos') =>
                'La base académica no responde. La IA puede contestar sin ella, pero revisá la conexión.',
            default => 'Error inesperado: pegá este mensaje en el chat para diagnosticarlo.',
        };
    }

    private function resolveConversation(): ?Conversation
    {
        if ($id = $this->option('conversation')) {
            return Conversation::find($id);
        }

        if ($phone = $this->option('phone')) {
            $normalizado = preg_replace('/\D+/', '', $phone);

            $contact = Contact::where(fn ($q) => $q->where('phone_normalized', 'like', "%{$normalizado}%")
                ->orWhere('phone', 'like', "%{$normalizado}%"))
                ->first();

            return $contact
                ? Conversation::where('contact_id', $contact->id)->latest('last_message_at')->first()
                : null;
        }

        return null;
    }
}

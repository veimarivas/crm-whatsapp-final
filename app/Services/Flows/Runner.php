<?php

namespace App\Services\Flows;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\Message;
use App\Models\Tag;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\ChannelRules;
use App\Services\WhatsApp\Messenger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runner de flows: chatbot conversacional con estado por contacto.
 * Equivalente a src/lib/flows/engine.ts del original.
 *
 * Contratos clave (heredados del original):
 *  - Como máximo UN run activo por contacto — lo garantiza el índice
 *    único sobre flow_runs.active_contact_key; el segundo INSERT
 *    concurrente recibe duplicate-key y se descarta.
 *  - Idempotencia: no se avanza dos veces con el mismo wamid (se
 *    registra en flow_run_events y se consulta antes de avanzar).
 *  - Fallback: respuesta no reconocida → reprompt hasta max_reprompts,
 *    después on_exhaust ('handoff' o 'end').
 */
class Runner
{
    private const MAX_TRANSITIONS = 20; // corta ciclos en grafos mal armados

    public const NODE_TYPES = [
        'send_message', 'send_buttons', 'send_list', 'collect_input',
        'condition', 'set_tag', 'http_fetch', 'handoff', 'end',
    ];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly ChannelRouter $router,
    )
    {
    }

    /**
     * Punto de entrada al llegar un mensaje del cliente: primero
     * intenta avanzar un run activo; si no hay, evalúa triggers.
     */
    public function handleInbound(Contact $contact, Conversation $conversation, Message $message): void
    {
        $active = FlowRun::where('account_id', $contact->account_id)
            ->where('contact_id', $contact->id)
            ->where('status', FlowRun::STATUS_ACTIVE)
            ->first();

        if ($active) {
            $this->advance($active, $message);

            return;
        }

        $flow = $this->matchTrigger($contact, $message);

        if ($flow) {
            $this->start($flow, $contact, $conversation, $message);
        }
    }

    private function matchTrigger(Contact $contact, Message $message): ?Flow
    {
        $flows = Flow::forAccount($contact->account_id)
            ->where('status', 'active')
            ->get();

        $text = mb_strtolower($message->content_text ?? '');

        foreach ($flows as $flow) {
            if ($flow->trigger_type === 'keyword') {
                foreach ($flow->trigger_config['keywords'] ?? [] as $keyword) {
                    if ($keyword !== '' && str_contains($text, mb_strtolower($keyword))) {
                        return $flow;
                    }
                }
            }

            if ($flow->trigger_type === 'first_inbound_message') {
                $previous = Message::whereHas('conversation', fn ($q) => $q
                    ->where('contact_id', $contact->id))
                    ->where('sender_type', Message::SENDER_CUSTOMER)
                    ->where('id', '!=', $message->id)
                    ->exists();

                if (! $previous) {
                    return $flow;
                }
            }
        }

        return null;
    }

    public function start(Flow $flow, Contact $contact, Conversation $conversation, ?Message $trigger = null): ?FlowRun
    {
        if (! $flow->entry_node_id) {
            return null;
        }

        try {
            $run = FlowRun::create([
                'flow_id' => $flow->id,
                'account_id' => $flow->account_id,
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id,
                'status' => FlowRun::STATUS_ACTIVE,
                'current_node_key' => $flow->entry_node_id,
                'vars' => [],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro webhook concurrente ya inició un run — contrato del original.
            return null;
        }

        $this->logEvent($run, 'started', $flow->entry_node_id, ['trigger_wamid' => $trigger?->message_id]);

        $flow->increment('execution_count');
        $flow->update(['last_executed_at' => now()]);

        $this->executeFrom($run, $flow->entry_node_id);

        return $run;
    }

    /** Avanza un run activo con la respuesta del cliente. */
    public function advance(FlowRun $run, Message $message): void
    {
        // Idempotencia por wamid (reintentos de Meta).
        if ($message->message_id && $run->events()
            ->where('event_type', 'reply_received')
            ->where('payload->wamid', $message->message_id)
            ->exists()) {
            return;
        }

        $node = $this->node($run, $run->current_node_key);

        if (! $node) {
            $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => 'current node missing']);

            return;
        }

        $this->logEvent($run, 'reply_received', $node->node_key, [
            'wamid' => $message->message_id,
            'text' => mb_substr($message->content_text ?? '', 0, 200),
            'reply_id' => $message->interactive_reply_id,
        ]);

        $config = $node->config;

        $next = match ($node->node_type) {
            'send_buttons' => $this->matchOption($config['buttons'] ?? [], $message),
            'send_list' => $this->matchOption($config['rows'] ?? [], $message),
            'collect_input' => $this->captureInput($run, $config, $message),
            default => null, // el run quedó parado en un nodo no interactivo: fallback
        };

        if ($next === null) {
            $this->fallback($run, $node);

            return;
        }

        $run->update(['reprompt_count' => 0, 'last_advanced_at' => now()]);
        $this->executeFrom($run, $next);
    }

    /** Busca la opción cuyo id coincide con el botón tocado (o el texto exacto). */
    private function matchOption(array $options, Message $message): ?string
    {
        foreach ($options as $option) {
            $matchesId = $message->interactive_reply_id !== null
                && $message->interactive_reply_id === ($option['id'] ?? null);
            $matchesText = $message->content_text !== null
                && mb_strtolower(trim($message->content_text)) === mb_strtolower($option['title'] ?? '');

            if ($matchesId || $matchesText) {
                return $option['next'] ?? null;
            }
        }

        return null;
    }

    private function captureInput(FlowRun $run, array $config, Message $message): ?string
    {
        $text = trim($message->content_text ?? '');

        if ($text === '') {
            return null; // media sin texto → fallback/reprompt
        }

        $varName = $config['var'] ?? 'input';
        $run->update(['vars' => [...$run->vars, $varName => mb_substr($text, 0, 500)]]);

        return $config['next'] ?? null;
    }

    private function fallback(FlowRun $run, FlowNode $node): void
    {
        $policy = $run->flow->fallback_policy ?: Flow::DEFAULT_FALLBACK_POLICY;
        $max = (int) ($policy['max_reprompts'] ?? 2);

        if ($run->reprompt_count < $max) {
            $run->update(['reprompt_count' => $run->reprompt_count + 1, 'last_advanced_at' => now()]);
            $this->logEvent($run, 'fallback_fired', $node->node_key, ['reprompt' => $run->reprompt_count]);
            $this->executeFrom($run, $node->node_key); // re-envía el prompt

            return;
        }

        if (($policy['on_exhaust'] ?? 'handoff') === 'handoff') {
            $this->handoff($run, 'Un agente te atenderá en breve.');
        } else {
            $this->finish($run, FlowRun::STATUS_COMPLETED, 'completed', ['reason' => 'reprompts exhausted']);
        }
    }

    /**
     * Ejecuta la cadena de nodos desde $nodeKey hasta un nodo que
     * espera respuesta (buttons/list/collect_input) o un terminal.
     */
    public function executeFrom(FlowRun $run, string $nodeKey): void
    {
        $conversation = $run->conversation;
        $contact = $run->contact;

        if (! $conversation || ! $contact) {
            $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => 'missing contact/conversation']);

            return;
        }

        for ($i = 0; $i < self::MAX_TRANSITIONS; $i++) {
            $node = $this->node($run, $nodeKey);

            if (! $node) {
                $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => "node not found: {$nodeKey}"]);

                return;
            }

            $this->logEvent($run, 'node_entered', $node->node_key);
            $config = $node->config;

            try {
                switch ($node->node_type) {
                    case 'send_message':
                        $this->router->forConversation($conversation)->sendText($conversation, $this->interpolate($config['text'] ?? '', $run));
                        $this->logEvent($run, 'message_sent', $node->node_key);
                        $nodeKey = $config['next'] ?? null;
                        break;

                    case 'set_tag':
                        $tag = Tag::forAccount($run->account_id)->find($config['tag_id'] ?? '');
                        $tag && $contact->tags()->syncWithoutDetaching([$tag->id]);
                        $nodeKey = $config['next'] ?? null;
                        break;

                    case 'condition':
                        $nodeKey = $this->evaluateCondition($run, $config)
                            ? ($config['next_yes'] ?? null)
                            : ($config['next_no'] ?? null);
                        break;

                    case 'http_fetch':
                        $nodeKey = $this->httpFetch($run, $config);
                        break;

                    case 'send_buttons':
                        // Los interactivos (botones/listas) son de Meta y hoy solo
                        // los sabe hacer el camino de WhatsApp. En otro canal se
                        // manda el TEXTO de la pregunta: perder los botones degrada
                        // la experiencia, pero mandarla por WhatsApp a quien escribió
                        // por otro lado la manda a la persona equivocada.
                        if (($conversation->channel ?? ChannelRules::DEFAULT) !== ChannelRules::WHATSAPP) {
                            $prompt = $this->router->forConversation($conversation)->sendText(
                                $conversation,
                                $this->interpolate($config['text'] ?? '', $run),
                            );
                            $this->await($run, $node, $prompt);

                            return;
                        }

                        $prompt = $this->messenger->sendInteractive(
                            $conversation,
                            $this->interpolate($config['text'] ?? '', $run),
                            fn ($api, $c) => $api->sendButtons(
                                $c->phone_normalized ?? $c->phone,
                                $this->interpolate($config['text'] ?? '', $run),
                                $config['buttons'] ?? [],
                            ),
                        );
                        $this->await($run, $node, $prompt);

                        return;

                    case 'send_list':
                        // Los interactivos (botones/listas) son de Meta y hoy solo
                        // los sabe hacer el camino de WhatsApp. En otro canal se
                        // manda el TEXTO de la pregunta: perder los botones degrada
                        // la experiencia, pero mandarla por WhatsApp a quien escribió
                        // por otro lado la manda a la persona equivocada.
                        if (($conversation->channel ?? ChannelRules::DEFAULT) !== ChannelRules::WHATSAPP) {
                            $prompt = $this->router->forConversation($conversation)->sendText(
                                $conversation,
                                $this->interpolate($config['text'] ?? '', $run),
                            );
                            $this->await($run, $node, $prompt);

                            return;
                        }

                        $prompt = $this->messenger->sendInteractive(
                            $conversation,
                            $this->interpolate($config['text'] ?? '', $run),
                            fn ($api, $c) => $api->sendList(
                                $c->phone_normalized ?? $c->phone,
                                $this->interpolate($config['text'] ?? '', $run),
                                $config['button_label'] ?? 'Opciones',
                                $config['rows'] ?? [],
                            ),
                        );
                        $this->await($run, $node, $prompt);

                        return;

                    case 'collect_input':
                        $prompt = $this->router->forConversation($conversation)->sendText(
                            $conversation,
                            $this->interpolate($config['text'] ?? '', $run),
                        );
                        $this->await($run, $node, $prompt);

                        return;

                    case 'handoff':
                        $this->handoff($run, $config['message'] ?? null);

                        return;

                    case 'end':
                        if (! empty($config['message'])) {
                            $this->router->forConversation($conversation)->sendText($conversation, $this->interpolate($config['message'], $run));
                        }
                        $this->finish($run, FlowRun::STATUS_COMPLETED, 'completed');

                        return;

                    default:
                        $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => "unknown node type: {$node->node_type}"]);

                        return;
                }
            } catch (\Throwable $e) {
                Log::error('Flow runner error', ['run_id' => $run->id, 'node' => $node->node_key, 'error' => $e->getMessage()]);
                $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => $e->getMessage()]);

                return;
            }

            if ($nodeKey === null) {
                // Rama sin continuación: fin implícito.
                $this->finish($run, FlowRun::STATUS_COMPLETED, 'completed', ['reason' => 'no next node']);

                return;
            }
        }

        $this->finish($run, FlowRun::STATUS_FAILED, 'error', ['reason' => 'max transitions exceeded (cycle?)']);
    }

    private function await(FlowRun $run, FlowNode $node, Message $prompt): void
    {
        $run->update([
            'current_node_key' => $node->node_key,
            'last_prompt_message_id' => $prompt->id,
            'last_advanced_at' => now(),
        ]);
        $this->logEvent($run, 'message_sent', $node->node_key, ['awaiting' => true]);
    }

    private function handoff(FlowRun $run, ?string $message): void
    {
        if ($message && $run->conversation) {
            try {
                $this->router->forConversation($run->conversation)->sendText($run->conversation, $this->interpolate($message, $run));
            } catch (\Throwable) {
                // el handoff no debe fallar por un error de envío
            }
        }

        $run->conversation?->update(['status' => Conversation::STATUS_PENDING]);
        $this->finish($run, FlowRun::STATUS_HANDED_OFF, 'handoff');
    }

    private function finish(FlowRun $run, string $status, string $eventType, array $payload = []): void
    {
        $run->update(['status' => $status, 'ended_at' => now()]);
        $this->logEvent($run, $eventType, $run->current_node_key, $payload);
    }

    /** Barre runs activos que superaron el timeout de la política. */
    public function sweepTimeouts(): int
    {
        $swept = 0;

        FlowRun::where('status', FlowRun::STATUS_ACTIVE)
            ->with('flow:id,fallback_policy')
            ->chunkById(100, function ($runs) use (&$swept) {
                foreach ($runs as $run) {
                    $hours = (int) (($run->flow->fallback_policy['on_timeout_hours'] ?? 24) ?: 24);

                    if ($run->last_advanced_at->addHours($hours)->isPast()) {
                        $this->finish($run, FlowRun::STATUS_TIMED_OUT, 'timeout');
                        $swept++;
                    }
                }
            });

        return $swept;
    }

    /** Un agente respondió a mano: el flow cede el control. */
    public function pauseForConversation(Conversation $conversation): void
    {
        FlowRun::where('conversation_id', $conversation->id)
            ->where('status', FlowRun::STATUS_ACTIVE)
            ->get()
            ->each(fn (FlowRun $run) => $this->finish($run, FlowRun::STATUS_PAUSED_BY_AGENT, 'handoff', ['reason' => 'agent replied']));
    }

    private function evaluateCondition(FlowRun $run, array $config): bool
    {
        $actual = mb_strtolower((string) ($run->vars[$config['var'] ?? ''] ?? ''));
        $expected = mb_strtolower((string) ($config['value'] ?? ''));

        return match ($config['operator'] ?? 'equals') {
            'equals' => $actual === $expected,
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'not_empty' => $actual !== '',
            default => false,
        };
    }

    private function httpFetch(FlowRun $run, array $config): ?string
    {
        $url = $config['url'] ?? '';

        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            throw new \RuntimeException('URL de http_fetch inválida.');
        }

        $response = Http::timeout(10)->get($this->interpolate($url, $run));

        $run->update(['vars' => [
            ...$run->vars,
            ($config['var'] ?? 'response') => mb_substr($response->body(), 0, 1000),
        ]]);

        return $config['next'] ?? null;
    }

    /** Tokens {var} de las respuestas capturadas + datos del contacto. */
    private function interpolate(string $text, FlowRun $run): string
    {
        foreach ($run->vars as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $run->contact ? Messenger::interpolate($text, $run->contact) : $text;
    }

    private function node(FlowRun $run, ?string $nodeKey): ?FlowNode
    {
        if ($nodeKey === null) {
            return null;
        }

        return FlowNode::where('flow_id', $run->flow_id)
            ->where('node_key', $nodeKey)
            ->first();
    }

    private function logEvent(FlowRun $run, string $type, ?string $nodeKey = null, array $payload = []): void
    {
        $run->events()->create([
            'event_type' => $type,
            'node_key' => $nodeKey,
            'payload' => $payload,
        ]);
    }
}

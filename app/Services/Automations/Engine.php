<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationLog;
use App\Models\AutomationPendingExecution;
use App\Models\AutomationStep;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\Channels\ChannelRouter;
use App\Services\WhatsApp\Messenger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de automatizaciones. Equivalente a src/lib/automations/engine.ts.
 *
 * Modelo de ejecución (idéntico al original):
 *  - Los pasos raíz (parent_step_id NULL) corren en orden de position.
 *  - Un paso `condition` evalúa y desciende a sus hijos de la rama
 *    'yes' o 'no'; al agotarlos, continúa con el siguiente paso raíz.
 *  - Un paso `wait` crea una fila en automation_pending_executions y
 *    corta la ejecución del scope actual; el comando
 *    `automations:process-pending` la reanuda cuando vence.
 */
class Engine
{
    public const TRIGGERS = ['inbound_message', 'new_contact', 'keyword'];

    public const STEP_TYPES = ['send_message', 'add_tag', 'remove_tag', 'condition', 'wait', 'webhook'];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly ChannelRouter $router,
    )
    {
    }

    /**
     * Dispara un evento. Busca automatizaciones activas de la cuenta
     * cuyo trigger coincida y las ejecuta una a una (un fallo en una
     * no bloquea a las demás).
     */
    public function fire(
        string $event,
        Contact $contact,
        ?Conversation $conversation = null,
        ?string $messageText = null,
    ): void {
        $automations = Automation::forAccount($contact->account_id)
            ->where('is_active', true)
            ->where('trigger_type', $event)
            ->get()
            ->filter(fn (Automation $a) => self::matchesTrigger($a->trigger_type, $a->trigger_config ?? [], $messageText));

        foreach ($automations as $automation) {
            try {
                $this->execute($automation, $contact, $conversation, $messageText);
            } catch (\Throwable $e) {
                Log::error('Automatización falló', [
                    'automation_id' => $automation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ¿El disparador de esta automatización aplica al mensaje dado?
     * Público y estático porque el simulador tiene que responder lo
     * mismo que la ejecución real.
     */
    public static function matchesTrigger(string $triggerType, array $triggerConfig, ?string $messageText): bool
    {
        if ($triggerType !== 'keyword') {
            return true;
        }

        $haystack = mb_strtolower($messageText ?? '');

        foreach ($triggerConfig['keywords'] ?? [] as $keyword) {
            if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    public function execute(
        Automation $automation,
        Contact $contact,
        ?Conversation $conversation,
        ?string $messageText,
    ): void {
        $log = AutomationLog::create([
            'automation_id' => $automation->id,
            'account_id' => $automation->account_id,
            'contact_id' => $contact->id,
            'trigger_event' => $automation->trigger_type,
            'steps_executed' => [],
            'status' => 'success',
        ]);

        $context = [
            'contact_id' => $contact->id,
            'conversation_id' => $conversation?->id,
            'message_text' => $messageText,
        ];

        $steps = $automation->steps()->get();

        $this->runScope($automation, $steps, null, null, 0, $context, $log);

        $automation->increment('execution_count');
        $automation->update(['last_executed_at' => now()]);
    }

    /** Reanuda una ejecución pendiente (paso wait vencido). */
    public function resume(AutomationPendingExecution $pending): void
    {
        $automation = $pending->automation;
        $contact = $pending->contact;

        if (! $automation || ! $automation->is_active || ! $contact) {
            $pending->update(['status' => 'done']);

            return;
        }

        $pending->update(['status' => 'running']);

        try {
            $log = $pending->log ?? AutomationLog::create([
                'automation_id' => $automation->id,
                'account_id' => $automation->account_id,
                'contact_id' => $contact->id,
                'trigger_event' => $automation->trigger_type,
                'steps_executed' => [],
                'status' => 'success',
            ]);

            $this->runScope(
                $automation,
                $automation->steps()->get(),
                $pending->parent_step_id,
                $pending->branch,
                $pending->next_step_position,
                $pending->context ?? [],
                $log,
            );

            $pending->update(['status' => 'done']);
        } catch (\Throwable $e) {
            $pending->update(['status' => 'failed']);
            Log::error('Reanudación de automatización falló', [
                'pending_id' => $pending->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ejecuta los pasos de un scope (raíz o rama de condición) desde
     * una posición dada. Devuelve false si un wait cortó la ejecución.
     */
    private function runScope(
        Automation $automation,
        Collection $allSteps,
        ?string $parentStepId,
        ?string $branch,
        int $fromPosition,
        array $context,
        AutomationLog $log,
    ): bool {
        $scope = $allSteps
            ->filter(fn (AutomationStep $s) => $s->parent_step_id === $parentStepId
                && ($parentStepId === null || $s->branch === $branch))
            ->sortBy('position')
            ->values();

        foreach ($scope as $step) {
            if ($step->position < $fromPosition) {
                continue;
            }

            if ($step->step_type === 'wait') {
                AutomationPendingExecution::create([
                    'automation_id' => $automation->id,
                    'account_id' => $automation->account_id,
                    'contact_id' => $context['contact_id'],
                    'log_id' => $log->id,
                    'parent_step_id' => $parentStepId,
                    'branch' => $branch,
                    'next_step_position' => $step->position + 1,
                    'context' => $context,
                    'status' => 'pending',
                    'run_at' => now()->addMinutes(max(1, (int) ($step->step_config['minutes'] ?? 60))),
                ]);

                $this->appendLog($log, $step, 'wait_scheduled');

                return false;
            }

            if ($step->step_type === 'condition') {
                $result = $this->evaluateCondition($step->step_config, $context);
                $this->appendLog($log, $step, $result ? 'yes' : 'no');

                $finished = $this->runScope(
                    $automation,
                    $allSteps,
                    $step->id,
                    $result ? 'yes' : 'no',
                    0,
                    $context,
                    $log,
                );

                if (! $finished) {
                    return false; // un wait dentro de la rama corta todo
                }

                continue;
            }

            try {
                $this->runAction($step, $context);
                $this->appendLog($log, $step, 'ok');
            } catch (\Throwable $e) {
                $this->appendLog($log, $step, 'error: '.$e->getMessage());
                $log->update(['status' => 'partial', 'error_message' => $e->getMessage()]);
            }
        }

        return true;
    }

    private function runAction(AutomationStep $step, array $context): void
    {
        $contact = Contact::findOrFail($context['contact_id']);
        $config = $step->step_config;

        switch ($step->step_type) {
            case 'send_message':
                $conversation = isset($context['conversation_id'])
                    ? Conversation::find($context['conversation_id'])
                    : null;
                // Sin conversación en el contexto, la del contacto con
                // actividad más reciente — cualquiera sea su canal. Caer
                // directo a `resolveConversation()` abriría un hilo de WhatsApp
                // para alguien que quizá solo escribió por Telegram, y le
                // mandaría el mensaje a un teléfono que no tiene.
                $conversation ??= $contact->conversations()
                    ->orderByDesc('last_message_at')
                    ->first()
                    ?? $this->messenger->resolveConversation($contact);

                // Por el canal de la conversación, no por WhatsApp: una
                // automatización que responde a un contacto de Telegram tiene
                // que salir por Telegram.
                $this->router->forConversation($conversation)->sendText(
                    $conversation,
                    Messenger::interpolate($config['text'] ?? '', $contact),
                );
                break;

            case 'add_tag':
                $tag = Tag::forAccount($contact->account_id)->find($config['tag_id'] ?? '');
                if ($tag) {
                    $contact->tags()->syncWithoutDetaching([$tag->id]);
                }
                break;

            case 'remove_tag':
                $contact->tags()->detach($config['tag_id'] ?? '');
                break;

            case 'webhook':
                $url = $config['url'] ?? '';
                if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
                    throw new \RuntimeException('URL de webhook inválida.');
                }

                Http::timeout(10)->post($url, [
                    'event' => 'automation.step',
                    'contact' => $contact->only(['id', 'phone', 'name', 'email', 'company']),
                    'message_text' => $context['message_text'] ?? null,
                    'fired_at' => now()->toIso8601String(),
                ])->throw();
                break;

            default:
                throw new \RuntimeException("Tipo de paso desconocido: {$step->step_type}");
        }
    }

    private function evaluateCondition(array $config, array $context): bool
    {
        return Conditions::evaluate(
            $config,
            Contact::find($context['contact_id']),
            $context['message_text'] ?? null,
        );
    }

    private function appendLog(AutomationLog $log, AutomationStep $step, string $result): void
    {
        $log->update([
            'steps_executed' => [
                ...$log->steps_executed,
                ['step_id' => $step->id, 'type' => $step->step_type, 'result' => $result, 'at' => now()->toIso8601String()],
            ],
        ]);
    }
}

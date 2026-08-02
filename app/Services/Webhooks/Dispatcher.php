<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookEndpoint;

/**
 * Reparte un evento a todos los endpoints activos de la cuenta que
 * estén suscritos a él. Cada entrega va como job independiente: un
 * endpoint lento no retrasa a los demás.
 */
class Dispatcher
{
    public const EVENTS = [
        'message.received',
        'message.sent',
        'message.transcribed',
        'ai.pending_changed',
        'contact.created',
        'broadcast.completed',
        'deal.stage_changed',
    ];

    public function dispatch(string $accountId, string $event, array $data): void
    {
        WebhookEndpoint::forAccount($accountId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => DeliverWebhookJob::dispatch($endpoint->id, $event, $data));
    }
}

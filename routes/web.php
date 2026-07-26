<?php

use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PipelineStageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WhatsApp\ConfigController;
use App\Http\Controllers\WhatsApp\MediaController;
use App\Http\Controllers\WhatsApp\WebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('login'));

// Webhook de Meta — público, sin CSRF (excluido en bootstrap/app.php),
// autenticado por verify_token (GET) y firma HMAC (POST).
Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify'])->name('webhooks.whatsapp');
Route::post('/webhooks/whatsapp', [WebhookController::class, 'receive'])
    ->middleware('throttle:whatsapp-webhook');

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

// Aceptación pública de invitaciones (el link se comparte fuera de la app).
Route::get('/invite/{token}', [\App\Http\Controllers\TeamController::class, 'acceptForm'])->name('invitations.accept');
Route::post('/invite/{token}', [\App\Http\Controllers\TeamController::class, 'redeem'])->name('invitations.redeem');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Inbox
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
    Route::get('/inbox/conversations', [InboxController::class, 'conversations'])->name('inbox.conversations');
    Route::get('/inbox/search', [InboxController::class, 'search'])->name('inbox.search');
    Route::post('/inbox/bulk-action', [InboxController::class, 'bulkAction'])->name('inbox.bulk-action');
    Route::get('/inbox/conversations/{conversation}/messages', [InboxController::class, 'messages'])->name('inbox.messages');
    Route::post('/inbox/conversations/{conversation}/send', [InboxController::class, 'send'])->name('inbox.send');
    Route::post('/inbox/conversations/{conversation}/send-media', [InboxController::class, 'sendMedia'])->name('inbox.send-media');
    Route::post('/inbox/conversations/{conversation}/read', [InboxController::class, 'markRead'])->name('inbox.read');
    Route::patch('/inbox/conversations/{conversation}/status', [InboxController::class, 'updateStatus'])->name('inbox.status');
    Route::patch('/inbox/conversations/{conversation}/assign', [InboxController::class, 'assign'])->name('inbox.assign');
    Route::post('/inbox/messages/{message}/react', [InboxController::class, 'react'])->name('inbox.react');
    Route::get('/inbox/conversations/{conversation}/notes', [InboxController::class, 'notes'])->name('inbox.notes');
    Route::post('/inbox/conversations/{conversation}/notes', [InboxController::class, 'addNote'])->name('inbox.notes.add');
    Route::post('/inbox/conversations/{conversation}/ai-draft', [InboxController::class, 'aiDraft'])->name('inbox.ai-draft');
    Route::patch('/inbox/conversations/{conversation}/ai-mode', [InboxController::class, 'setAiMode'])->name('inbox.ai-mode');
    Route::get('/inbox/quick-replies', [\App\Http\Controllers\QuickReplyController::class, 'available'])->name('inbox.quick-replies');
    Route::get('/broadcasts-metrics', [\App\Http\Controllers\BroadcastController::class, 'metrics'])->name('broadcasts.metrics');

    // Exportar contactos a CSV
    Route::get('/contacts/export', [\App\Http\Controllers\ContactController::class, 'exportCsv'])->name('contacts.export');

    // Detección y merge de contactos duplicados
    Route::get('/contacts/duplicates', [\App\Http\Controllers\ContactMergeController::class, 'index'])->name('contacts.duplicates');
    Route::post('/contacts/merge', [\App\Http\Controllers\ContactMergeController::class, 'merge'])->name('contacts.merge');

    // Auto-tag rules (settings)
    Route::get('/settings/auto-tags', [\App\Http\Controllers\AutoTagController::class, 'index'])->name('auto-tags.index');
    Route::post('/settings/auto-tags', [\App\Http\Controllers\AutoTagController::class, 'store'])->name('auto-tags.store');
    Route::post('/settings/auto-tags/{rule}/toggle', [\App\Http\Controllers\AutoTagController::class, 'toggle'])->name('auto-tags.toggle');
    Route::delete('/settings/auto-tags/{rule}', [\App\Http\Controllers\AutoTagController::class, 'destroy'])->name('auto-tags.destroy');

    // Plantillas rápidas (settings)
    Route::get('/settings/quick-replies', [\App\Http\Controllers\QuickReplyController::class, 'index'])->name('quick-replies.index');
    Route::post('/settings/quick-replies', [\App\Http\Controllers\QuickReplyController::class, 'store'])->name('quick-replies.store');
    Route::post('/settings/quick-replies/load-suggested', [\App\Http\Controllers\QuickReplyController::class, 'loadSuggested'])->name('quick-replies.load-suggested');
    Route::patch('/settings/quick-replies/{quickReply}', [\App\Http\Controllers\QuickReplyController::class, 'update'])->name('quick-replies.update');
    Route::delete('/settings/quick-replies/{quickReply}', [\App\Http\Controllers\QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');

    // IA
    Route::get('/settings/ai', [\App\Http\Controllers\AiController::class, 'edit'])->name('settings.ai');
    Route::post('/settings/ai', [\App\Http\Controllers\AiController::class, 'update'])->name('settings.ai.update');
    Route::post('/settings/ai/documents', [\App\Http\Controllers\AiController::class, 'storeDocument'])->name('settings.ai.documents.store');
    Route::delete('/settings/ai/documents/{document}', [\App\Http\Controllers\AiController::class, 'destroyDocument'])->name('settings.ai.documents.destroy');
    Route::post('/settings/ai/reindex', [\App\Http\Controllers\AiController::class, 'reindex'])->name('settings.ai.reindex');
    Route::get('/settings/ai/stats', [\App\Http\Controllers\AiController::class, 'stats'])->name('settings.ai.stats');
    Route::get('/settings/response-time', [\App\Http\Controllers\AiController::class, 'responseTime'])->name('settings.response-time');

    // Equipo + API keys
    Route::get('/settings/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('settings.team');
    Route::post('/settings/team/invitations', [\App\Http\Controllers\TeamController::class, 'invite'])->name('team.invite');
    Route::delete('/settings/team/invitations/{invitation}', [\App\Http\Controllers\TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
    Route::patch('/settings/team/members/{member}', [\App\Http\Controllers\TeamController::class, 'updateMember'])->name('team.members.update');
    Route::delete('/settings/team/members/{member}', [\App\Http\Controllers\TeamController::class, 'removeMember'])->name('team.members.remove');
    Route::post('/settings/team/members/{member}/transfer-ownership', [\App\Http\Controllers\TeamController::class, 'transferOwnership'])->name('team.members.transfer');
    Route::post('/settings/team/api-keys', [\App\Http\Controllers\TeamController::class, 'storeApiKey'])->name('team.api-keys.store');
    Route::delete('/settings/team/api-keys/{apiKey}', [\App\Http\Controllers\TeamController::class, 'revokeApiKey'])->name('team.api-keys.revoke');
    Route::post('/settings/team/webhooks', [\App\Http\Controllers\TeamController::class, 'storeWebhook'])->name('team.webhooks.store');
    Route::post('/settings/team/webhooks/{webhook}/toggle', [\App\Http\Controllers\TeamController::class, 'toggleWebhook'])->name('team.webhooks.toggle');
    Route::patch('/settings/team/webhooks/{webhook}', [\App\Http\Controllers\TeamController::class, 'updateWebhook'])->name('team.webhooks.update');
    Route::delete('/settings/team/webhooks/{webhook}', [\App\Http\Controllers\TeamController::class, 'destroyWebhook'])->name('team.webhooks.destroy');

    // Presencia (heartbeat del layout cada 60s; online = visto hace <2 min)
    Route::post('/presence/ping', function (\Illuminate\Http\Request $request) {
        \App\Models\MemberPresence::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['account_id' => $request->user()->account_id, 'status' => 'online', 'last_seen_at' => now()],
        );

        return response()->json(['ok' => true]);
    })->name('presence.ping');

    // Notificaciones
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // Contactos
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::post('/contacts/import', [ContactController::class, 'import'])->name('contacts.import');

    // Etiquetas y campos personalizados
    Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
    Route::patch('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    Route::post('/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

    // Plantillas
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::post('/templates/{template}/submit', [TemplateController::class, 'submit'])->name('templates.submit');
    Route::post('/templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');

    // Automatizaciones
    Route::get('/automations', [\App\Http\Controllers\AutomationController::class, 'index'])->name('automations.index');
    Route::get('/automations/new', [\App\Http\Controllers\AutomationController::class, 'edit'])->name('automations.create');
    Route::post('/automations', [\App\Http\Controllers\AutomationController::class, 'store'])->name('automations.store');
    Route::get('/automations/{automation}/edit', [\App\Http\Controllers\AutomationController::class, 'edit'])->name('automations.edit');
    Route::patch('/automations/{automation}', [\App\Http\Controllers\AutomationController::class, 'update'])->name('automations.update');
    Route::post('/automations/{automation}/toggle', [\App\Http\Controllers\AutomationController::class, 'toggle'])->name('automations.toggle');
    Route::delete('/automations/{automation}', [\App\Http\Controllers\AutomationController::class, 'destroy'])->name('automations.destroy');
    Route::get('/automations/{automation}/logs', [\App\Http\Controllers\AutomationController::class, 'logs'])->name('automations.logs');

    // Flows (chatbot)
    Route::get('/flows', [\App\Http\Controllers\FlowController::class, 'index'])->name('flows.index');
    Route::post('/flows', [\App\Http\Controllers\FlowController::class, 'store'])->name('flows.store');
    Route::get('/flows/{flow}/edit', [\App\Http\Controllers\FlowController::class, 'edit'])->name('flows.edit');
    Route::patch('/flows/{flow}', [\App\Http\Controllers\FlowController::class, 'update'])->name('flows.update');
    Route::post('/flows/{flow}/toggle', [\App\Http\Controllers\FlowController::class, 'toggle'])->name('flows.toggle');
    Route::delete('/flows/{flow}', [\App\Http\Controllers\FlowController::class, 'destroy'])->name('flows.destroy');
    Route::get('/flows/{flow}/runs', [\App\Http\Controllers\FlowController::class, 'runs'])->name('flows.runs');

    // Pipelines (Kanban)
    Route::get('/pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
    Route::post('/pipelines', [PipelineController::class, 'store'])->name('pipelines.store');
    Route::patch('/pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update');
    Route::delete('/pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
    Route::post('/pipelines/{pipeline}/stages', [PipelineStageController::class, 'store'])->name('stages.store');
    Route::patch('/stages/{stage}', [PipelineStageController::class, 'update'])->name('stages.update');
    Route::patch('/stages/{stage}/move', [PipelineStageController::class, 'move'])->name('stages.move');
    Route::delete('/stages/{stage}', [PipelineStageController::class, 'destroy'])->name('stages.destroy');
    Route::post('/deals', [DealController::class, 'store'])->name('deals.store');
    Route::patch('/deals/{deal}', [DealController::class, 'update'])->name('deals.update');
    Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');

    // Broadcasts
    Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
    Route::get('/broadcasts/new', [BroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('/broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
    Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('broadcasts.show');
    Route::delete('/broadcasts/{broadcast}', [BroadcastController::class, 'destroy'])->name('broadcasts.destroy');

    // WhatsApp
    Route::get('/settings/whatsapp', [ConfigController::class, 'edit'])->name('settings.whatsapp');
    Route::post('/settings/whatsapp', [ConfigController::class, 'update'])->name('settings.whatsapp.update');
    Route::get('/whatsapp/media/{mediaId}', [MediaController::class, 'show'])->name('whatsapp.media');
});

// SSO ligero del ecosistema - consume tokens de un solo uso emitidos
// por el Komo Hub (GET publico; valida firma HMAC + nonce anti-replay).
Route::get('/sso/consume', [\App\Http\Controllers\SsoController::class, 'consume'])->name('sso.consume');

require __DIR__.'/auth.php';

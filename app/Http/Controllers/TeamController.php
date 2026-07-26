<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\ApiKey;
use App\Models\MemberPresence;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Equipo: miembros, invitaciones por link y API keys de la cuenta. */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $onlineIds = MemberPresence::forAccount($user->account_id)
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->pluck('user_id');

        return Inertia::render('Settings/Team', [
            'members' => User::where('account_id', $user->account_id)
                ->orderBy('created_at')
                ->get(['id', 'name', 'email', 'account_role', 'created_at'])
                ->map(fn (User $member) => [
                    ...$member->toArray(),
                    'online' => $onlineIds->contains($member->id),
                ]),
            'invitations' => AccountInvitation::forAccount($user->account_id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(['id', 'role', 'label', 'expires_at', 'created_at']),
            'apiKeys' => ApiKey::forAccount($user->account_id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'key_prefix', 'scopes', 'last_used_at', 'revoked_at', 'created_at']),
            'webhooks' => WebhookEndpoint::forAccount($user->account_id)
                ->orderByDesc('created_at')
                ->get(['id', 'url', 'events', 'is_active', 'last_delivery_at', 'failure_count', 'created_at']),
            'webhookEvents' => Dispatcher::EVENTS,
            'isAdmin' => $user->hasRoleAtLeast(User::ROLE_ADMIN),
            'isOwner' => $user->isOwner(),
            'newInviteUrl' => session('invite_url'),
            'newApiKey' => session('api_key_plaintext'),
            'newWebhookSecret' => session('webhook_secret'),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
            'label' => 'nullable|string|max:100',
        ]);

        $token = Str::random(48);

        AccountInvitation::create([
            'account_id' => $request->user()->account_id,
            'token_hash' => hash('sha256', $token),
            'role' => $validated['role'],
            'label' => $validated['label'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        // El link se muestra UNA vez; en BD solo queda el hash.
        return back()->with('invite_url', route('invitations.accept', $token));
    }

    public function revokeInvitation(Request $request, AccountInvitation $invitation): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($invitation->account_id !== $request->user()->account_id, 403);

        $invitation->delete();

        return back()->with('success', 'Invitación revocada.');
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($member->account_id !== $request->user()->account_id, 403);

        if ($member->isOwner()) {
            return back()->withErrors(['member' => 'El rol del owner no se cambia desde aquí.']);
        }

        $member->update($request->validate([
            'account_role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
        ]));

        return back()->with('success', 'Rol actualizado.');
    }

    /** Transfiere la propiedad de la cuenta a otro miembro. */
    public function transferOwnership(Request $request, User $member): RedirectResponse
    {
        $owner = $request->user();

        abort_unless($owner->isOwner(), 403);
        abort_if($member->account_id !== $owner->account_id, 403);

        if ($member->id === $owner->id) {
            return back()->withErrors(['member' => 'Ya eres el owner.']);
        }

        DB::transaction(function () use ($owner, $member) {
            $owner->account->update(['owner_user_id' => $member->id]);
            $member->update(['account_role' => User::ROLE_OWNER]);
            $owner->update(['account_role' => User::ROLE_ADMIN]);
        });

        return back()->with('success', "Ahora {$member->name} es el owner de la cuenta.");
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($member->account_id !== $request->user()->account_id, 403);

        if ($member->isOwner()) {
            return back()->withErrors(['member' => 'No puedes expulsar al owner.']);
        }

        if ($member->id === $request->user()->id) {
            return back()->withErrors(['member' => 'No puedes expulsarte a ti mismo.']);
        }

        // Sale del equipo: recupera una cuenta propia vacía.
        $account = Account::create(['name' => $member->name, 'owner_user_id' => $member->id]);
        $member->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        return back()->with('success', 'Miembro expulsado.');
    }

    // ---- Aceptación pública de invitaciones ----

    public function acceptForm(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation) {
            return Inertia::render('Invitations/Accept', ['invalid' => true]);
        }

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'accountName' => $invitation->account->name,
            'role' => $invitation->role,
            'isLoggedIn' => (bool) $request->user(),
        ]);
    }

    public function redeem(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->withErrors(['email' => 'La invitación no es válida o expiró.']);
        }

        $user = $request->user();

        // Sin sesión: registro exprés desde el propio formulario del invite.
        if (! $user) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|lowercase|email|max:255|unique:users,email',
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            auth()->login($user);
        }

        // Un owner con más miembros no puede abandonar su cuenta.
        if ($user->isOwner() && User::where('account_id', $user->account_id)->where('id', '!=', $user->id)->exists()) {
            return redirect()->route('dashboard')
                ->withErrors(['invite' => 'Transfiere tu cuenta actual antes de unirte a otra.']);
        }

        $user->update([
            'account_id' => $invitation->account_id,
            'account_role' => $invitation->role,
        ]);

        $invitation->update([
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);

        return redirect()->route('dashboard')->with('success', "Te uniste a {$invitation->account->name}.");
    }

    // ---- API keys ----

    public function storeApiKey(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1',
            'scopes.*' => Rule::in([
                'contacts:read', 'contacts:write', 'conversations:read', 'conversations:write',
                'messages:write', 'broadcasts:read', 'broadcasts:write',
                'notifications:read', 'team:write',
            ]),
        ]);

        [, $plaintext] = ApiKey::issue(
            $request->user()->account_id,
            $request->user()->id,
            $validated['name'],
            $validated['scopes'],
        );

        return back()->with('api_key_plaintext', $plaintext);
    }

    public function revokeApiKey(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($apiKey->account_id !== $request->user()->account_id, 403);

        $apiKey->update(['revoked_at' => now()]);

        return back()->with('success', 'API key revocada.');
    }

    // ---- Webhooks salientes ----

    public function storeWebhook(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'url' => 'required|url:https|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => Rule::in(Dispatcher::EVENTS),
        ]);

        $secret = 'whsec_'.Str::random(40);

        WebhookEndpoint::create([
            'account_id' => $request->user()->account_id,
            'created_by' => $request->user()->id,
            'url' => $validated['url'],
            'secret' => $secret, // cast encrypted
            'events' => $validated['events'],
        ]);

        // El secreto de firma se muestra UNA vez.
        return back()->with('webhook_secret', $secret);
    }

    public function toggleWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($webhook->account_id !== $request->user()->account_id, 403);

        // Reactivar resetea el contador de fallos consecutivos.
        $webhook->update([
            'is_active' => ! $webhook->is_active,
            'failure_count' => 0,
        ]);

        return back()->with('success', $webhook->is_active ? 'Webhook activado.' : 'Webhook desactivado.');
    }

    /** Actualiza url y/o eventos del webhook (el secreto NO se cambia). */
    public function updateWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($webhook->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate([
            'url' => 'required|url:https|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => Rule::in(Dispatcher::EVENTS),
        ]);

        $webhook->update([
            'url' => $validated['url'],
            'events' => $validated['events'],
        ]);

        return back()->with('success', 'Webhook actualizado.');
    }

    public function destroyWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->requireAdmin($request);
        abort_if($webhook->account_id !== $request->user()->account_id, 403);

        $webhook->delete();

        return back()->with('success', 'Webhook eliminado.');
    }

    private function findInvitation(string $token): ?AccountInvitation
    {
        return AccountInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('account:id,name')
            ->first();
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRoleAtLeast(User::ROLE_ADMIN), 403);
    }
}

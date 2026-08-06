<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de seguimiento del admin: cómo está atendiendo cada agente.
 *
 * Mide el *proceso* —quién contesta, en cuánto, qué contactos quedaron
 * esperando y cuánta carga tiene cada uno— a diferencia del Dashboard, que
 * mide volumen y resultado.
 *
 * Admin-only: un agente no compara su desempeño con el del resto. Como este
 * proyecto no tiene middleware `admin.only`, el corte va acá.
 */
class SupervisionController extends Controller
{
    /** Ventanas ofrecidas en la UI, en días. */
    private const RANGES = [7, 15, 30, 90];

    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->hasRoleAtLeast(User::ROLE_ADMIN), 403,
            'Solo un administrador puede ver el seguimiento del equipo.');

        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $metrics = (new ResponseMetrics(
            $user->account_id,
            now()->subDays($days)->startOfDay(),
        ))->build();

        return Inertia::render('Supervision/Index', [
            ...$metrics,
            'days' => $days,
            'ranges' => self::RANGES,
            'members' => User::where('account_id', $user->account_id)
                ->orderBy('name')
                ->get(['id', 'name', 'account_role']),
            'currency' => $user->account->default_currency,
        ]);
    }

    /**
     * Ficha de un agente: KPIs, histograma de tiempos, embudo de sus negocios
     * y los pendientes operativos de su bandeja. El drill-down individual del
     * que la nota de alcance decía que solo vivía en el Komo.
     */
    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();

        abort_unless($viewer->hasRoleAtLeast(User::ROLE_ADMIN), 403,
            'Solo un administrador puede ver la ficha de un agente.');

        abort_unless($user->account_id === $viewer->account_id, 403,
            'El agente no pertenece a tu cuenta.');

        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $data = (new ResponseMetrics(
            $viewer->account_id,
            now()->subDays($days)->startOfDay(),
        ))->forAgent($user->id);

        return Inertia::render('Supervision/Agent', [
            ...$data,
            'days' => $days,
            'ranges' => self::RANGES,
            'currency' => $viewer->account->default_currency,
            'sla_minutes' => ResponseMetrics::SLA_MINUTES,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Supervision\BacklogCharts;
use App\Services\Supervision\ResponseMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $days = $this->windowDays($request);

        $since = now()->subDays($days)->startOfDay();

        $metrics = (new ResponseMetrics(
            $user->account_id,
            $since,
        ))->build();

        // Los gráficos nuevos viven en su propia clase (no en el «gemelo» del
        // ResponseMetrics que comparten con el Komo).
        $charts = (new BacklogCharts($user->account_id, $since))->build();

        return Inertia::render('Supervision/Index', [
            ...$metrics,
            ...$charts,
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

        $days = $this->windowDays($request);

        $since = now()->subDays($days)->startOfDay();

        $metrics = new ResponseMetrics($viewer->account_id, $since);

        $data = $metrics->forAgent($user->id);

        return Inertia::render('Supervision/Agent', [
            ...$data,
            // Línea de contexto en la ficha: promedios diarios de TODO el equipo
            // para superponerlos a los del agente.
            'teamDaily' => $metrics->build()['daily'],
            'days' => $days,
            'ranges' => self::RANGES,
            'currency' => $viewer->account->default_currency,
            'sla_minutes' => ResponseMetrics::SLA_MINUTES,
        ]);
    }

    /**
     * El panel entero en CSV: resumen por agente + el desglose contacto por
     * contacto que el admin expele para un archivo. Admin-only como el index,
     * misma ventana `?days=` (7/15/30/90) y mismo aislamiento por cuenta;
     * stream con BOM UTF-8 y `;` (patrón de `ContactController@exportCsv`).
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->hasRoleAtLeast(User::ROLE_ADMIN), 403,
            'Solo un administrador puede exportar el seguimiento del equipo.');

        $days = $this->windowDays($request);
        $since = now()->subDays($days)->startOfDay();

        $metrics = (new ResponseMetrics($user->account_id, $since))->build();

        $filename = 'seguimiento-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($metrics) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Por agente'], ';');
            fputcsv($out, [
                'Agente', 'Rol', 'Contactos asignados', 'Conversaciones', 'Atendidas', 'Sin respuesta',
                'Esperando ahora', 'SLA vencidas', '1ª respuesta (s)', 'Respuesta media (s)',
            ], ';');
            foreach ($metrics['agents'] as $a) {
                fputcsv($out, [
                    $a['name'],
                    $a['role'] ?? '',
                    $a['assigned_contacts'],
                    $a['conversations'],
                    $a['answered'],
                    $a['never_answered'],
                    $a['waiting_now'],
                    $a['breached_sla'],
                    $a['avg_first_response_seconds'] ?? '',
                    $a['avg_response_seconds'] ?? '',
                ], ';');
            }

            fputcsv($out, [], ';');
            fputcsv($out, ['Contacto por contacto'], ';');
            fputcsv($out, [
                'Contacto', 'Teléfono', 'Estado', 'Agente', 'Contestó 1º',
                '1ª respuesta (s)', 'Respuesta media (s)', 'Entrantes', 'Resp. humanas', 'Resp. IA',
                'Espera (min)', 'SLA vencida', 'Última actividad',
            ], ';');
            foreach ($metrics['conversations'] as $c) {
                fputcsv($out, [
                    $c['contact'],
                    $c['phone'] ?? '',
                    $c['status'],
                    $c['agent']['name'] ?? '',
                    $c['first_responder'],
                    $c['first_response_seconds'] ?? '',
                    $c['avg_response_seconds'] ?? '',
                    $c['inbound'],
                    $c['human_replies'],
                    $c['bot_replies'],
                    $c['awaiting_minutes'] ?? '',
                    $c['breached_sla'] ? 'Sí' : 'No',
                    $c['last_activity_at'] ?? '',
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function windowDays(Request $request): int
    {
        $days = (int) $request->query('days', 30);

        return in_array($days, self::RANGES, true) ? $days : 30;
    }
}

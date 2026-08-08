import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { WindowPicker, ChartCard, CompareBars, TrendArea, TONE } from '@/Components/Charts';
import { fmtDuration, fmtInteger } from '@/Components/Charts/format';

const SLA_SECONDS = 300; // 5 min: objetivo de este panel

/** Un círculo más rápido es mejor, más lento peor. */
function DurationDelta({ pct }) {
    if (pct === null || pct === undefined) return null;
    const pctAbs = Math.abs(Math.round(pct));
    const better = pct <= 0;
    const arrow = pct < 0 ? '↓' : '↑';
    return (
        <span
            title={`Vs. ventana anterior (${pct < 0 ? 'más rápido' : 'más lento'})`}
            className={`inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold ${better ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
        >
            {arrow} {pctAbs}%
        </span>
    );
}

function CountDelta({ pct }) {
    if (pct === null || pct === undefined) return null;
    const pctAbs = Math.abs(Math.round(pct));
    const more = pct > 0;
    return (
        <span
            title={`Vs. ventana anterior (${more ? 'más actividad' : 'menos actividad'})`}
            className={`inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold ${more ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
        >
            {pct > 0 ? '↑' : '↓'} {pctAbs}%
        </span>
    );
}

function KpiCard({ label, value, delta, sub }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div className="flex items-start justify-between gap-2">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
                {delta}
            </div>
            <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums leading-none">{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-2">{sub}</p>}
        </div>
    );
}

export default function ResponseTime({ byAgent, histogram, daily, kpis, deltas, days, ranges }) {
    const agentChart = byAgent.map((a) => ({
        id: a.name,
        name: a.name,
        value: a.median_seconds,
        color: a.median_seconds < 60 ? TONE.positive : a.median_seconds < SLA_SECONDS ? TONE.warning : TONE.danger,
    }));

    const exportUrl = route('settings.response-time.export', { days });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Tiempo de respuesta</h2>}>
            <Head title="Tiempo de respuesta" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Tiempo de respuesta</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Últimos {days} días. Aquí la IA cuenta como una respuesta (a diferencia de /supervision, que mide la espera humana).
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <a href={exportUrl} title="Descargar CSV de este lapso" className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 hover:bg-gray-50 px-3 py-2 rounded-lg whitespace-nowrap">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            CSV
                        </a>
                        <WindowPicker days={days} ranges={ranges} routeName="settings.response-time" />
                    </div>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <KpiCard label="Mediana" value={kpis.median_label} delta={<DurationDelta pct={deltas.median_pct} />} sub="La mitad de las respuestas tardó menos" />
                    <KpiCard label="Promedio" value={kpis.avg_label} delta={<DurationDelta pct={deltas.avg_pct} />} sub="lo distorsionan las demoras largas" />
                    <KpiCard label="Respuestas medidas" value={fmtInteger(kpis.total_replies)} delta={<CountDelta pct={deltas.total_pct} />} sub="mensajes de cliente respondidos" />
                    <KpiCard label="Agentes activos" value={fmtInteger(byAgent.length)} sub="incluye la IA" />
                </div>

                <ChartCard
                    title="Cómo se reparte la espera"
                    subtitle="Distribución de los tiempos de respuesta. El SLA objetivo es de 5 minutos."
                    empty={!histogram || histogram.every((b) => b.count === 0)}
                    emptyMessage="Todavía no hay respuestas medidas en este periodo."
                >
                    <CompareBars
                        data={histogram.map((b) => ({
                            id: b.label,
                            name: b.label,
                            value: b.count,
                            color: b.label.includes('5 m') ? TONE.warning : b.label.includes('15 a 30') || b.label.startsWith('Más') ? TONE.danger : TONE.positive,
                        }))}
                        valueFormatter={fmtInteger}
                        emptyMessage="Sin respuestas medidas todavía."
                    />
                </ChartCard>

                <ChartCard
                    title="Mediana por día"
                    subtitle="Línea diaria; los días sin respuestas no se dibujan (no son ceros)."
                    empty={!daily || daily.every((d) => d.median_seconds === null)}
                    emptyMessage="Sin respuestas medidas todavía."
                >
                    <TrendArea
                        data={daily}
                        xKey="label"
                        series={[{ key: 'median_seconds', name: 'Mediana', color: TONE.brand }]}
                        valueFormatter={fmtDuration}
                        reference={{ value: SLA_SECONDS, label: `SLA 5 m`, color: TONE.warning }}
                    />
                </ChartCard>

                <ChartCard
                    title="Comparativa por agente"
                    subtitle="Mediana (el promedio lo distorsionan las demoras largas). La línea marca los 5 minutos del SLA."
                    empty={byAgent.length === 0}
                    emptyMessage="Nadie tiene respuestas medidas todavía."
                >
                    <CompareBars
                        data={agentChart}
                        height={Math.max(220, byAgent.length * 42)}
                        valueFormatter={fmtDuration}
                        target={SLA_SECONDS}
                        targetLabel="SLA 5 m"
                    />
                </ChartCard>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Ranking por agente</h3>
                            <p className="text-xs text-gray-400 mt-0.5">Ordenado por mediana (el número que no distorsionan las demoras)</p>
                        </div>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50/80">
                                <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Agente</th>
                                <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Respuestas</th>
                                <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Promedio</th>
                                <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Mediana</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {byAgent.map((a, i) => {
                                const badge = a.median_seconds < 60 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                    : a.median_seconds < SLA_SECONDS ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                        : 'bg-red-50 text-red-700 ring-red-200';
                                const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : '';
                                return (
                                    <tr key={a.name} className="hover:bg-gray-50">
                                        <td className="px-5 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="text-lg">{medal}</span>
                                                <span className={`font-semibold ${a.is_bot ? 'text-violet-700' : 'text-gray-900'}`}>{a.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-5 py-3 text-right tabular-nums text-gray-600">{a.count}</td>
                                        <td className="px-5 py-3 text-right tabular-nums text-gray-500">{a.avg_label}</td>
                                        <td className="px-5 py-3 text-right">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 ${badge}`}>{a.median_label}</span>
                                        </td>
                                    </tr>
                                );
                            })}
                            {byAgent.length === 0 && <tr><td colSpan={4} className="p-8 text-center text-sm text-gray-400">Sin respuestas medidas todavía</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import { Head, Link, router } from '@inertiajs/react';
import { ChartCard, DailyVolumeChart, ResponseTimeChart, TONE } from './Charts';

/** Segundos → "45s" / "12m" / "3h 20m" / "2d 4h". Null se muestra como guion. */
function duration(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    if (seconds < 86400) {
        const h = Math.floor(seconds / 3600);
        const m = Math.round((seconds % 3600) / 60);
        return m ? `${h}h ${m}m` : `${h}h`;
    }
    const d = Math.floor(seconds / 86400);
    const h = Math.round((seconds % 86400) / 3600);
    return h ? `${d}d ${h}h` : `${d}d`;
}

function timeAgo(iso) {
    if (!iso) return '—';
    const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (mins < 60) return `hace ${mins}m`;
    if (mins < 1440) return `hace ${Math.round(mins / 60)}h`;
    return `hace ${Math.round(mins / 1440)}d`;
}

function money(value, currency) {
    return new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'BOB', maximumFractionDigits: 0 }).format(value || 0);
}

function responseTone(seconds, slaMinutes) {
    if (seconds === null || seconds === undefined) return 'text-gray-400';
    const sla = slaMinutes * 60;
    if (seconds <= sla) return 'text-emerald-600';
    if (seconds <= sla * 2) return 'text-amber-600';
    return 'text-red-600';
}

const ROLE_LABEL = { owner: 'Owner', admin: 'Admin', agent: 'Agente', viewer: 'Viewer' };

const FIRST_RESPONDER = {
    ia: { label: 'IA', className: 'bg-violet-50 text-violet-700 ring-violet-200' },
    asignado: { label: 'Asignado', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    otro_agente: { label: 'Otro agente', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    sin_identificar: { label: 'Humano', className: 'bg-gray-100 text-gray-600 ring-gray-200' },
    sin_respuesta: { label: 'Sin respuesta', className: 'bg-red-50 text-red-700 ring-red-200' },
};

const CONV_STATUS = {
    open: { label: 'Abierta', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    pending: { label: 'Pendiente', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    closed: { label: 'Cerrada', className: 'bg-gray-100 text-gray-600 ring-gray-200' },
};

function KpiCard({ label, value, sub, gradient, iconPath, tone }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 relative overflow-hidden hover:shadow-md transition-all">
            <div className={`absolute -top-4 -right-4 w-24 h-24 rounded-full opacity-10 bg-gradient-to-br ${gradient}`} />
            <div className={`relative w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-md mb-3`}>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d={iconPath} /></svg>
            </div>
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
            <p className={`text-3xl font-extrabold mt-1 tabular-nums leading-none ${tone ?? 'text-gray-900'}`}>{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-2">{sub}</p>}
        </div>
    );
}

/** Histograma de tiempos de primera respuesta en barras verticales. */
function HistogramChart({ histogram }) {
    const max = Math.max(1, ...histogram.map((h) => h.count));
    const total = histogram.reduce((a, h) => a + h.count, 0);

    if (total === 0) return <div className="h-48 flex items-center justify-center text-sm text-gray-400">Sin respuestas medidas en este periodo.</div>;

    return (
        <div>
            <div className="flex items-end gap-2 h-48">
                {histogram.map((h) => {
                    const tone = h.count > 0 && (h.label === '30 m a 1 h' || h.label === 'Más de 1 h' || h.label === '15 a 30 m') ? TONE.vencido : (h.count > 0 ? TONE.responsable : '#e2e8f0');
                    return (
                        <div key={h.label} className="flex-1 flex flex-col items-center justify-end gap-1 group relative">
                            <div className="w-full rounded-t-md transition-opacity group-hover:opacity-80"
                                style={{ height: `${(h.count / max) * 100}%`, background: tone, minHeight: h.count ? 3 : 0 }} />
                            <span className="text-[9px] text-gray-400 whitespace-nowrap flex flex-col items-center leading-tight">{h.label}</span>
                            <div className="pointer-events-none absolute bottom-full mb-1 hidden group-hover:block z-20 whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-[11px] text-white shadow-lg">
                                {h.label}: {h.count} conversaciones
                            </div>
                        </div>
                    );
                })}
            </div>
            <p className="text-[11px] text-gray-400 mt-2">Tiempo entre el primer mensaje del contacto y la primera respuesta humana.</p>
        </div>
    );
}

/** Embudo: etapas de los negocios abiertos del agente, acumuladas. */
function FunnelChart({ byStage }) {
    if (!byStage || byStage.length === 0) return <div className="h-48 flex items-center justify-center text-sm text-gray-400">Sin negocios abiertos en este periodo.</div>;

    const max = Math.max(...byStage.map((s) => s.count));
    const cumulative = byStage.map((s, i) => byStage.slice(0, i + 1).reduce((a, x) => a + x.count, 0));
    const total = cumulative[cumulative.length - 1];

    return (
        <div className="space-y-3">
            {byStage.map((s, i) => (
                <div key={s.name}>
                    <div className="flex items-baseline justify-between text-xs mb-1">
                        <span className="inline-flex items-center gap-1.5 font-semibold text-gray-700 truncate">
                            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: s.color || TONE.desconocido }} />
                            {s.name}
                            {i === 0 && <span className="text-[10px] font-bold text-gray-400">({total})</span>}
                        </span>
                        <span className="tabular-nums text-gray-500">{s.count}</span>
                    </div>
                    <div className="h-6 bg-white border border-gray-100 rounded-md overflow-hidden flex">
                        <div className="h-full transition-all"
                            style={{ width: `${(cumulative[i] / total) * 100}%`, background: s.color || TONE.responsable, opacity: 1 - (i * 0.14) }} />
                        <div className="h-full flex-1 bg-gray-50" />
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function SupervisionAgent({ agent, kpis, histogram, daily, teamDaily, conversations, days, ranges, currency, slaMinutes }) {
    const attentionRate = kpis.conversations > 0 ? Math.round((kpis.answered / kpis.conversations) * 100) : 0;
    const operative = conversations.filter((c) => c.awaiting_minutes !== null || (c.service_window && !c.service_window.is_open && c.service_window.source !== 'none'));

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Ficha del agente</h2>}>
            <Head title={`${agent.name} · Seguimiento`} />

            <div className="w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white font-bold shadow-md">
                                {agent.name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
                            </div>
                            <div>
                                <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">{agent.name}</h1>
                                <p className="text-sm text-gray-500 mt-0.5">
                                    {ROLE_LABEL[agent.role] ?? agent.role}
                                    {agent.email && ` · ${agent.email}`}
                                    <span className="ml-1 text-gray-400">· actividad en los últimos {days} días</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('supervision.index')} className="text-sm font-semibold text-emerald-700 hover:underline">← Todos los agentes</Link>
                        <div className="flex gap-1 bg-white rounded-xl border border-gray-200 p-1 shrink-0">
                            {ranges.map((r) => (
                                <button
                                    key={r}
                                    onClick={() => router.get(route('supervision.agent', agent.id), { days: r }, { preserveScroll: true, preserveState: false })}
                                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${r === days ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'}`}
                                >
                                    {r}d
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                    <KpiCard
                        label="Contactos asignados"
                        value={kpis.assigned_contacts}
                        sub={`${kpis.assigned_conversations} conversaciones`}
                        gradient="from-[#045474] to-[#1c486c]"
                        iconPath="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z"
                    />
                    <KpiCard
                        label="Atendidas"
                        value={`${attentionRate}%`}
                        sub={`${kpis.answered}/${kpis.conversations} conversaciones`}
                        tone={attentionRate >= 80 ? 'text-emerald-600' : attentionRate >= 50 ? 'text-amber-600' : 'text-red-600'}
                        gradient="from-emerald-500 to-teal-600"
                        iconPath="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                    <KpiCard
                        label="Esperando ahora"
                        value={kpis.waiting_now}
                        sub={`${kpis.breached_sla} pasaron el SLA`}
                        tone={kpis.breached_sla > 0 ? 'text-red-600' : undefined}
                        gradient="from-amber-500 to-orange-600"
                        iconPath="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                    <KpiCard
                        label="1ª respuesta"
                        value={duration(kpis.avg_first_response_seconds)}
                        sub="promedio del periodo"
                        tone={responseTone(kpis.avg_first_response_seconds, slaMinutes)}
                        gradient="from-emerald-500 to-teal-600"
                        iconPath="M13 10V3L4 14h7v7l9-11h-7z"
                    />
                    <KpiCard
                        label="Respuesta más lenta"
                        value={duration(kpis.slowest_response_seconds)}
                        sub="lo peor que tardó"
                        gradient="from-violet-500 to-purple-600"
                        iconPath="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                    />
                    <KpiCard
                        label="Ventana cerrada"
                        value={kpis.window_closed}
                        sub="escribirles ya tiene costo"
                        tone={kpis.window_closed > 0 ? 'text-red-600' : undefined}
                        gradient="from-red-500 to-rose-600"
                        iconPath="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
                    />
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <ChartCard title="Histograma de respuesta" subtitle="¿Responde al instante o deja correr el reloj?">
                        <HistogramChart histogram={histogram} />
                    </ChartCard>

                    <ChartCard title="Embudo de sus negocios" subtitle={`${kpis.deals_open} abiertos · ${money(kpis.deals_value, currency)}`}>
                        <FunnelChart byStage={kpis.by_stage || []} />
                    </ChartCard>

                    <ChartCard title="Volumen diario" subtitle="Ritmo del periodo">
                        <DailyVolumeChart daily={daily} />
                    </ChartCard>

                    <ChartCard title="Tiempo de respuesta por día" subtitle={`Promedio contra el SLA de ${slaMinutes} minutos. La línea gris es el promedio de todo el equipo.`}>
                        <ResponseTimeChart daily={daily} slaMinutes={slaMinutes} formatDuration={duration} team={teamDaily} />
                    </ChartCard>
                </div>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Pendientes operativos</h3>
                        <p className="text-xs text-gray-500 mt-0.5">
                            {operative.length} contacto{operative.length === 1 ? '' : 's'} esperando respuesta o con la ventana cerrada — el día a día que hay que atender.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[820px]">
                            <thead>
                                <tr className="bg-gray-50/95 backdrop-blur">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Contacto</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Contestó 1º</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">1ª resp.</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Mensajes</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Situación</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {operative.length === 0 ? (
                                    <tr><td colSpan={6} className="px-5 py-10 text-center text-sm text-gray-400">Al día: nada esperando y nada con la ventana cerrada.</td></tr>
                                ) : operative.map((c) => {
                                    const badge = FIRST_RESPONDER[c.first_responder];
                                    const st = CONV_STATUS[c.status];
                                    const closed = c.service_window && !c.service_window.is_open && c.service_window.source !== 'none';
                                    return (
                                        <tr key={c.id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-5 py-3">
                                                <Link href={route('inbox')} className="font-semibold text-gray-900 hover:text-emerald-700">{c.contact}</Link>
                                                {c.phone && <span className="block text-[11px] text-gray-400 tabular-nums">{c.phone}</span>}
                                                <ServiceWindowBadge window={c.service_window} showOrigin />
                                            </td>
                                            <td className="px-5 py-3">
                                                {st && <span className={`inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${st.className}`}>{st.label}</span>}
                                            </td>
                                            <td className="px-5 py-3">
                                                <span className={`inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${badge.className}`}>{badge.label}</span>
                                            </td>
                                            <td className={`px-5 py-3 text-right tabular-nums font-semibold ${responseTone(c.first_response_seconds, slaMinutes)}`}>{duration(c.first_response_seconds)}</td>
                                            <td className="px-5 py-3 text-right text-xs text-gray-500 tabular-nums whitespace-nowrap">
                                                {c.inbound} ↓ {c.human_replies} ↑
                                                {c.bot_replies > 0 && <span className="text-violet-500"> · {c.bot_replies} IA</span>}
                                            </td>
                                            <td className="px-5 py-3 text-right whitespace-nowrap">
                                                {closed ? (
                                                    <span className="inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 bg-red-50 text-red-700 ring-red-200" title="Ya no se le puede escribir sin costo">
                                                        Ventana cerrada
                                                    </span>
                                                ) : c.awaiting_minutes !== null ? (
                                                    <span className={`inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${c.breached_sla ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-700 ring-amber-200'}`}>
                                                        Esperando {duration(c.awaiting_minutes * 60)}
                                                    </span>
                                                ) : <span className="text-xs text-gray-400">{timeAgo(c.last_activity_at)}</span>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
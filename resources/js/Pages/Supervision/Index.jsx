import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ChartCard,
    DailyVolumeChart,
    FirstResponderDonut,
    ResponseByAgentChart,
    ResponseTimeChart,
    StageChart,
    TONE,
} from './Charts';

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

/** Verde bajo el SLA, ámbar hasta el doble, rojo por encima. */
function responseTone(seconds, slaMinutes) {
    if (seconds === null || seconds === undefined) return 'text-gray-400';
    const sla = slaMinutes * 60;
    if (seconds <= sla) return 'text-emerald-600';
    if (seconds <= sla * 2) return 'text-amber-600';
    return 'text-red-600';
}

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

const ROLE_LABEL = { owner: 'Owner', admin: 'Admin', agent: 'Agente', viewer: 'Viewer' };

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

/** Barra apilada: quién dio la primera respuesta en las conversaciones del agente. */
function FirstResponderBar({ ia, asignado, otro, desconocido }) {
    const total = ia + asignado + otro + desconocido;
    if (!total) return <span className="text-xs text-gray-400">—</span>;

    const segments = [
        { n: asignado, color: TONE.asignado, label: 'Asignado' },
        { n: otro, color: TONE.otro, label: 'Otro agente' },
        { n: desconocido, color: TONE.desconocido, label: 'Humano sin identificar' },
        { n: ia, color: TONE.ia, label: 'IA' },
    ].filter((s) => s.n > 0);

    return (
        <div className="min-w-[130px]">
            <div className="flex h-2 rounded-full overflow-hidden bg-gray-100">
                {segments.map((s) => (
                    <div key={s.label} style={{ width: `${(s.n / total) * 100}%`, background: s.color }} title={`${s.label}: ${s.n}`} />
                ))}
            </div>
            <p className="text-[11px] text-gray-500 mt-1 tabular-nums">
                {segments.map((s) => `${s.n} ${s.label.split(' ')[0].toLowerCase()}`).join(' · ')}
            </p>
        </div>
    );
}

/** Carga asignada: contactos y el reparto abierta/pendiente/cerrada. */
function LoadCell({ agent }) {
    const total = agent.assigned_conversations;

    return (
        <div className="min-w-[150px]">
            <p className="text-sm font-bold text-gray-900 tabular-nums">
                {agent.assigned_contacts}
                <span className="ml-1 text-[11px] font-normal text-gray-400">contactos</span>
            </p>
            {total > 0 && (
                <>
                    <div className="flex h-1.5 rounded-full overflow-hidden bg-gray-100 mt-1.5">
                        {agent.open_conversations > 0 && <div className="bg-sky-500" style={{ width: `${(agent.open_conversations / total) * 100}%` }} title={`${agent.open_conversations} abiertas`} />}
                        {agent.pending_conversations > 0 && <div className="bg-amber-500" style={{ width: `${(agent.pending_conversations / total) * 100}%` }} title={`${agent.pending_conversations} pendientes`} />}
                        {agent.closed_conversations > 0 && <div className="bg-gray-300" style={{ width: `${(agent.closed_conversations / total) * 100}%` }} title={`${agent.closed_conversations} cerradas`} />}
                    </div>
                    <p className="text-[11px] text-gray-500 mt-1 tabular-nums">
                        {agent.open_conversations} abiertas · {agent.pending_conversations} pend.
                    </p>
                </>
            )}
        </div>
    );
}

function AgentRow({ agent, slaMinutes, currency, expanded, onToggle }) {
    const attentionRate = agent.conversations > 0 ? Math.round((agent.answered / agent.conversations) * 100) : 0;

    return (
        <>
            <tr className="hover:bg-gray-50 transition-colors">
                <td className="px-5 py-3">
                    <button onClick={onToggle} className="flex items-center gap-2 text-left group">
                        <svg className={`w-4 h-4 text-gray-400 transition-transform ${expanded ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                        <span>
                            <button
                                onClick={(e) => { e.stopPropagation(); router.get(route('supervision.agent', agent.id)); }}
                                className="text-left font-semibold text-gray-900 group-hover:text-emerald-700 hover:text-emerald-600"
                                title="Ver ficha del agente"
                            >
                                {agent.name}
                            </button>
                            {agent.role && <span className="block text-[11px] text-gray-400">{ROLE_LABEL[agent.role] ?? agent.role}</span>}
                        </span>
                    </button>
                </td>
                <td className="px-5 py-3"><LoadCell agent={agent} /></td>
                <td className="px-5 py-3 text-right tabular-nums text-gray-900 font-semibold">{agent.conversations}</td>
                <td className="px-5 py-3 text-right">
                    <span className={`tabular-nums font-semibold ${attentionRate >= 80 ? 'text-emerald-600' : attentionRate >= 50 ? 'text-amber-600' : 'text-red-600'}`}>{attentionRate}%</span>
                    <span className="block text-[11px] text-gray-400">{agent.answered}/{agent.conversations}</span>
                </td>
                <td className="px-5 py-3 text-right">
                    {agent.waiting_now > 0 ? (
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-bold ring-1 ${agent.breached_sla > 0 ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-700 ring-amber-200'}`}>
                            {agent.waiting_now}
                            {agent.breached_sla > 0 && <span className="font-normal">({agent.breached_sla} vencidas)</span>}
                        </span>
                    ) : <span className="text-xs text-emerald-600 font-semibold">Al día</span>}
                </td>
                <td className={`px-5 py-3 text-right tabular-nums font-bold ${responseTone(agent.avg_first_response_seconds, slaMinutes)}`}>{duration(agent.avg_first_response_seconds)}</td>
                <td className={`px-5 py-3 text-right tabular-nums ${responseTone(agent.avg_response_seconds, slaMinutes)}`}>{duration(agent.avg_response_seconds)}</td>
                <td className="px-5 py-3"><FirstResponderBar ia={agent.ia_first} asignado={agent.assigned_first} otro={agent.other_agent_first} desconocido={agent.unknown_first} /></td>
                <td className="px-5 py-3 text-right">
                    {agent.window_closed > 0 ? (
                        <span className="inline-flex px-2 py-0.5 rounded-lg text-xs font-bold ring-1 bg-red-50 text-red-700 ring-red-200" title="Conversaciones a las que ya no se puede escribir sin costo">
                            {agent.window_closed}
                        </span>
                    ) : <span className="text-xs text-gray-300">—</span>}
                </td>
                <td className="px-5 py-3 text-right text-xs text-gray-500 whitespace-nowrap">{timeAgo(agent.last_activity_at)}</td>
            </tr>
            {expanded && (
                <tr className="bg-gray-50/60">
                    <td colSpan={10} className="px-5 py-4">
                        <div className="flex flex-wrap items-start gap-8">
                            <div>
                                <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-2">Etapas de sus negocios abiertos</p>
                                {agent.by_stage.length === 0 ? (
                                    <p className="text-xs text-gray-400">Sin negocios abiertos.</p>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        {agent.by_stage.map((s) => (
                                            <span key={s.name} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white border border-gray-200">
                                                <span className="w-2 h-2 rounded-full" style={{ background: s.color || '#94a3b8' }} />
                                                {s.name}
                                                <span className="tabular-nums text-gray-500">{s.count}</span>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-8 text-xs">
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Mensajes</p>
                                    <p className="text-gray-700 tabular-nums">{agent.messages_received} recibidos · {agent.messages_sent} enviados</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Nunca respondidas</p>
                                    <p className={`tabular-nums font-semibold ${agent.never_answered > 0 ? 'text-red-600' : 'text-emerald-600'}`}>{agent.never_answered}</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Ventana por cerrar</p>
                                    <p className={`tabular-nums font-semibold ${agent.window_closing > 0 ? 'text-amber-600' : 'text-gray-400'}`}>{agent.window_closing}</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Pipeline abierto</p>
                                    <p className="text-gray-700 tabular-nums">{agent.deals_open} · {money(agent.deals_value, currency)}</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Respuesta más lenta</p>
                                    <p className="text-gray-700 tabular-nums">{duration(agent.slowest_response_seconds)}</p>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function SupervisionIndex({ agents, conversations, totals, daily, stages, days, ranges, members, currency }) {
    const [agentFilter, setAgentFilter] = useState('all');
    const [onlyWaiting, setOnlyWaiting] = useState(false);
    const [expanded, setExpanded] = useState(null);

    const visible = useMemo(() => conversations.filter((c) => {
        if (onlyWaiting && c.awaiting_minutes === null) return false;
        if (agentFilter === 'all') return true;
        if (agentFilter === 'none') return !c.agent;
        return c.agent?.id === agentFilter;
    }), [conversations, agentFilter, onlyWaiting]);

    const iaFirstPct = totals.conversations > 0 ? Math.round((totals.ia_first / totals.conversations) * 100) : 0;
    const totalAssigned = agents.reduce((a, x) => a + (x.id ? x.assigned_contacts : 0), 0);

    const responderSlices = useMemo(() => [
        { key: 'asignado', label: 'El agente asignado', value: agents.reduce((a, x) => a + x.assigned_first, 0), color: TONE.asignado },
        { key: 'otro', label: 'Otro agente', value: agents.reduce((a, x) => a + x.other_agent_first, 0), color: TONE.otro },
        { key: 'desconocido', label: 'Humano sin identificar', value: agents.reduce((a, x) => a + x.unknown_first, 0), color: TONE.desconocido },
        { key: 'ia', label: 'La IA', value: totals.ia_first, color: TONE.ia },
        { key: 'nadie', label: 'Nadie respondió', value: totals.never_answered, color: TONE.vencido },
    ], [agents, totals]);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Seguimiento</h2>}>
            <Head title="Seguimiento" />

            <div className="w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Cómo está atendiendo el equipo</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Conversaciones con actividad en los últimos {days} días. El SLA de respuesta es de {totals.sla_minutes} minutos.
                        </p>
                    </div>
                    <div className="flex gap-1 bg-white rounded-xl border border-gray-200 p-1 shrink-0">
                        {ranges.map((r) => (
                            <button
                                key={r}
                                onClick={() => router.get(route('supervision.index'), { days: r }, { preserveScroll: true, preserveState: false })}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${r === days ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'}`}
                            >
                                {r}d
                            </button>
                        ))}
                    </div>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                    <KpiCard
                        label="Contactos asignados"
                        value={totalAssigned}
                        sub="repartidos entre el equipo"
                        gradient="from-[#045474] to-[#1c486c]"
                        iconPath="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z"
                    />
                    <KpiCard
                        label="Conversaciones"
                        value={totals.conversations}
                        sub={`con actividad en ${days} días`}
                        gradient="from-slate-500 to-slate-700"
                        iconPath="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.9A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                    />
                    <KpiCard
                        label="Esperando ahora"
                        value={totals.waiting_now}
                        sub={`${totals.breached_sla} pasaron el SLA`}
                        tone={totals.breached_sla > 0 ? 'text-red-600' : undefined}
                        gradient="from-amber-500 to-orange-600"
                        iconPath="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                    <KpiCard
                        label="1ª respuesta"
                        value={duration(totals.avg_first_response_seconds)}
                        sub="promedio del equipo"
                        tone={responseTone(totals.avg_first_response_seconds, totals.sla_minutes)}
                        gradient="from-emerald-500 to-teal-600"
                        iconPath="M13 10V3L4 14h7v7l9-11h-7z"
                    />
                    <KpiCard
                        label="Sin respuesta humana"
                        value={totals.never_answered}
                        sub="nadie del equipo contestó"
                        tone={totals.never_answered > 0 ? 'text-red-600' : undefined}
                        gradient="from-red-500 to-rose-600"
                        iconPath="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
                    />
                    <KpiCard
                        label="Ventana cerrada"
                        value={totals.window_closed}
                        sub="escribirles ya tiene costo"
                        tone={totals.window_closed > 0 ? 'text-red-600' : undefined}
                        gradient="from-violet-500 to-purple-600"
                        iconPath="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                    />
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <ChartCard
                        title="Volumen diario"
                        subtitle="¿El equipo sigue el ritmo de lo que entra?"
                        legend={[
                            { label: 'Recibidos', color: TONE.entrante },
                            { label: 'Respuestas', color: TONE.asignado },
                            { label: 'IA', color: TONE.ia },
                        ]}
                    >
                        <DailyVolumeChart daily={daily} />
                    </ChartCard>

                    <ChartCard title="Tiempo de respuesta por día" subtitle="Promedio del equipo contra el SLA">
                        <ResponseTimeChart daily={daily} slaMinutes={totals.sla_minutes} formatDuration={duration} />
                    </ChartCard>

                    <ChartCard title="Quién contestó primero" subtitle={`Sobre ${totals.conversations} conversaciones del periodo`}>
                        <FirstResponderDonut slices={responderSlices} total={totals.conversations} />
                    </ChartCard>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <ChartCard title="1ª respuesta por agente" className="h-full">
                            <ResponseByAgentChart agents={agents} slaMinutes={totals.sla_minutes} formatDuration={duration} />
                        </ChartCard>
                        <ChartCard title="Negocios por etapa" subtitle="Pipeline abierto" className="h-full">
                            <StageChart stages={stages} />
                        </ChartCard>
                    </div>
                </div>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Por agente</h3>
                        <p className="text-xs text-gray-500 mt-0.5">Toca una fila para ver sus etapas, mensajes y pipeline.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[1100px]">
                            <thead>
                                <tr className="bg-gray-50/95 backdrop-blur">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Agente</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Carga asignada</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Activas</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Atendidas</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Esperando</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">1ª resp.</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Resp. media</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Quién contestó 1º</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Vent. cerrada</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Últ. actividad</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {agents.length === 0 ? (
                                    <tr><td colSpan={10} className="px-5 py-10 text-center text-sm text-gray-400">Sin conversaciones en este periodo.</td></tr>
                                ) : agents.map((a) => (
                                    <AgentRow
                                        key={a.id ?? 'none'}
                                        agent={a}
                                        slaMinutes={totals.sla_minutes}
                                        currency={currency}
                                        expanded={expanded === (a.id ?? 'none')}
                                        onToggle={() => setExpanded(expanded === (a.id ?? 'none') ? null : (a.id ?? 'none'))}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Contacto por contacto</h3>
                            <p className="text-xs text-gray-500 mt-0.5">{visible.length} de {conversations.length} conversaciones.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <label className="inline-flex items-center gap-2 text-xs font-semibold text-gray-600 cursor-pointer">
                                <input type="checkbox" checked={onlyWaiting} onChange={(e) => setOnlyWaiting(e.target.checked)} className="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                                Solo esperando respuesta
                            </label>
                            <select
                                value={agentFilter}
                                onChange={(e) => setAgentFilter(e.target.value)}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold border-gray-200 bg-gray-50 focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                <option value="all">Todos los agentes</option>
                                <option value="none">Sin asignar</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[900px]">
                            <thead>
                                <tr className="bg-gray-50/95 backdrop-blur">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Contacto</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Agente</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Contestó 1º</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">1ª resp.</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Resp. media</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Mensajes</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Estado actual</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {visible.length === 0 ? (
                                    <tr><td colSpan={8} className="px-5 py-10 text-center text-sm text-gray-400">Nada que mostrar con este filtro.</td></tr>
                                ) : visible.map((c) => {
                                    const badge = FIRST_RESPONDER[c.first_responder];
                                    const st = CONV_STATUS[c.status];
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
                                            <td className="px-5 py-3 text-xs text-gray-600">
                                                {c.agent?.name ?? <span className="text-amber-600 font-semibold">Sin asignar</span>}
                                            </td>
                                            <td className="px-5 py-3">
                                                <span className={`inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${badge.className}`}>{badge.label}</span>
                                            </td>
                                            <td className={`px-5 py-3 text-right tabular-nums font-semibold ${responseTone(c.first_response_seconds, totals.sla_minutes)}`}>{duration(c.first_response_seconds)}</td>
                                            <td className={`px-5 py-3 text-right tabular-nums ${responseTone(c.avg_response_seconds, totals.sla_minutes)}`}>{duration(c.avg_response_seconds)}</td>
                                            <td className="px-5 py-3 text-right text-xs text-gray-500 tabular-nums whitespace-nowrap">
                                                {c.inbound} ↓ {c.human_replies} ↑
                                                {c.bot_replies > 0 && <span className="text-violet-500"> · {c.bot_replies} IA</span>}
                                            </td>
                                            <td className="px-5 py-3 text-right whitespace-nowrap">
                                                {c.awaiting_minutes === null ? (
                                                    <span className="text-xs text-gray-400">{timeAgo(c.last_activity_at)}</span>
                                                ) : (
                                                    <span className={`inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${c.breached_sla ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-700 ring-amber-200'}`}>
                                                        Esperando {duration(c.awaiting_minutes * 60)}
                                                    </span>
                                                )}
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

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ComposedChart, Bar, Line, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartCard, TONE, axisStyle, tooltipStyle } from '@/Components/Charts';

function timeAgo(iso) {
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return `${Math.floor(diff / 86400)}d`;
}

function DeltaChip({ pct, suffix = '%', lowerIsBetter = false }) {
    if (pct === null || pct === undefined) return null;
    const v = Math.abs(Math.round(pct));
    const better = lowerIsBetter ? pct <= 0 : pct >= 0;
    const arrow = pct >= 0 ? '↑' : '↓';
    return (
        <span
            className={`inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold ${better ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
        >
            {arrow} {v}{suffix}
        </span>
    );
}

function StatCard({ label, value, gradient, icon, delta }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div className="flex items-start justify-between gap-2 mb-3">
                <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-lg`}>
                    {icon}
                </div>
                {delta}
            </div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">{label}</p>
            <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 tabular-nums">{value}</p>
        </div>
    );
}

function ChartTip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) return null;
    const ai = payload.find((p) => p.dataKey === 'ai_replies');
    const fb = payload.find((p) => p.dataKey === 'fallbacks');
    const ok = payload.find((p) => p.dataKey === 'success_rate');

    return (
        <div style={tooltipStyle} className="px-3 py-2 min-w-[150px]">
            {label && <p className="text-xs font-semibold text-gray-500 mb-1">{label}</p>}
            <ul className="space-y-0.5 text-xs">
                <li className="flex items-center gap-2">
                    <span className="w-2 h-2 rounded-sm shrink-0" style={{ background: TONE.ai }} />
                    <span className="text-gray-600">Respuestas IA</span>
                    <span className="ml-auto pl-2 font-bold tabular-nums text-gray-900">{ai?.value ?? 0}</span>
                </li>
                <li className="flex items-center gap-2">
                    <span className="w-2 h-2 rounded-sm shrink-0" style={{ background: TONE.warning }} />
                    <span className="text-gray-600">Fallbacks</span>
                    <span className="ml-auto pl-2 font-bold tabular-nums text-gray-900">{fb?.value ?? 0}</span>
                </li>
            </ul>
            {ok?.value !== undefined && ok?.value !== null && (
                <p className="mt-1.5 pt-1.5 border-t border-gray-100 flex justify-between text-xs text-gray-600">
                    <span>Éxito</span>
                    <span className="font-bold tabular-nums" style={{ color: TONE.brand }}>{ok.value}%</span>
                </p>
            )}
        </div>
    );
}

function AiChart({ data }) {
    const total = data.reduce((acc, d) => acc + d.ai_replies + d.fallbacks, 0);

    return (
        <ChartCard title="Respuestas IA vs fallbacks — últimos 14 días" subtitle="Barras moradas de respuestas; la línea ámbar son los fallbacks; la línea verde es la tasa de éxito (eje derecho).">
            {total === 0 ? (
                <div className="h-56 flex items-center justify-center text-sm text-gray-400">Sin actividad de la IA en los últimos 14 días.</div>
            ) : (
                <ResponsiveContainer width="100%" height={270}>
                    <ComposedChart data={data} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                        <defs>
                            <linearGradient id="aiBar" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor={TONE.ai} stopOpacity={0.95} />
                                <stop offset="100%" stopColor={TONE.ai} stopOpacity={0.55} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                        <XAxis dataKey="label" {...axisStyle} />
                        <YAxis yAxisId="left" {...axisStyle} width={40} allowDecimals={false} />
                        <YAxis yAxisId="right" orientation="right" {...axisStyle} domain={[0, 100]} tickFormatter={(v) => `${v}%`} />
                        <Tooltip content={<ChartTip />} />
                        <Bar yAxisId="left" dataKey="ai_replies" name="Respuestas IA" fill="url(#aiBar)" radius={[4, 4, 0, 0]} barSize={18} />
                        <Line yAxisId="left" dataKey="fallbacks" name="Fallbacks" stroke={TONE.warning} strokeWidth={2} dot={{ r: 2, fill: TONE.warning }} activeDot={{ r: 4 }} />
                        <Line yAxisId="right" dataKey="success_rate" name="Tasa de éxito %" stroke={TONE.brand} strokeWidth={2} strokeDasharray="4 3" dot={{ r: 2, fill: TONE.brand }} activeDot={{ r: 4 }} connectNulls />
                    </ComposedChart>
                </ResponsiveContainer>
            )}
        </ChartCard>
    );
}

export default function AiStats({ stats, chart, deltas, recentQuestions }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Estadísticas IA</h2>}>
            <Head title="Estadísticas IA" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Estadísticas de la IA</h1>
                        <p className="text-sm text-gray-400 mt-1">
                            Cómo está rindiendo el bot y qué está preguntando la gente. Útil para ver qué agregar al{' '}
                            <Link href={route('settings.ai')} className="text-emerald-600 font-semibold underline">knowledge base</Link>.
                        </p>
                    </div>
                    <Link
                        href={route('settings.ai')}
                        className="hidden sm:inline-flex items-center gap-1.5 text-xs font-semibold text-[#045474] bg-[#045474]/5 hover:bg-[#045474]/10 px-3 py-2 rounded-lg"
                    >
                        ← Configuración IA
                    </Link>
                </div>

                {/* Cards de KPIs */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    <StatCard
                        label="Respuestas (7d)"
                        value={stats.replies_7d}
                        delta={<DeltaChip pct={deltas.replies_7d_pct} />}
                        gradient="from-violet-500 to-purple-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>}
                    />
                    <StatCard
                        label="Respuestas (30d)"
                        value={stats.replies_30d}
                        delta={<DeltaChip pct={deltas.replies_30d_pct} />}
                        gradient="from-blue-500 to-indigo-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" /></svg>}
                    />
                    <StatCard
                        label="Respuestas total"
                        value={stats.replies_total}
                        gradient="from-emerald-500 to-teal-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>}
                    />
                    <StatCard
                        label="Fallbacks (30d)"
                        value={stats.fallbacks_30d}
                        gradient="from-amber-500 to-orange-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>}
                    />
                    <StatCard
                        label="Tasa de éxito"
                        value={`${stats.success_rate}%`}
                        delta={<DeltaChip pct={deltas.success_pp} suffix=" pp" lowerIsBetter={false} />}
                        gradient={stats.success_rate >= 90 ? 'from-emerald-500 to-green-600' : stats.success_rate >= 70 ? 'from-amber-500 to-orange-600' : 'from-red-500 to-rose-600'}
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>}
                    />
                </div>

                {/* Chart */}
                <AiChart data={chart} />

                {/* Últimas preguntas */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 sm:px-6 py-4 border-b border-gray-100">
                        <h4 className="text-sm font-bold text-gray-900">Últimas 30 preguntas de clientes</h4>
                        <p className="text-xs text-gray-400 mt-0.5">
                            Solo conversaciones con IA activa. Usá esto para detectar temas que se repiten y agregarlos al knowledge base.
                        </p>
                    </div>
                    <ul className="divide-y divide-gray-50 max-h-[500px] overflow-y-auto">
                        {recentQuestions.map((q) => (
                            <li key={q.id} className="px-5 sm:px-6 py-3 hover:bg-gray-50 transition-colors">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-gray-800 truncate">{q.content_text}</p>
                                        <p className="text-[10px] text-gray-400 mt-1">
                                            {q.conversation?.contact?.name || q.conversation?.contact?.phone || 'Desconocido'}
                                        </p>
                                    </div>
                                    <span className="text-[10px] text-gray-400 shrink-0">{timeAgo(q.created_at)}</span>
                                </div>
                            </li>
                        ))}
                        {recentQuestions.length === 0 && (
                            <li className="p-8 text-center text-sm text-gray-400">Sin preguntas todavía</li>
                        )}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
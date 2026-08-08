import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { WindowPicker, ChartCard, TrendArea, FunnelSteps, TONE } from '@/Components/Charts';
import { fmtInteger } from '@/Components/Charts/format';

function StatCard({ label, value, subtitle, gradient, icon }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div className="flex items-center justify-between mb-3">
                <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-lg`}>{icon}</div>
            </div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">{label}</p>
            <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 tabular-nums">{value}</p>
            {subtitle && <p className="text-xs text-gray-400 mt-1">{subtitle}</p>}
        </div>
    );
}

function RateBar({ label, rate, color }) {
    return (
        <div>
            <div className="flex justify-between text-xs mb-1">
                <span className="font-semibold text-gray-700">{label}</span>
                <span className="font-bold text-gray-900">{rate}%</span>
            </div>
            <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div className={`h-full ${color} rounded-full transition-all`} style={{ width: `${Math.min(100, rate)}%` }} />
            </div>
        </div>
    );
}

const pct = (v) => (v === null || v === undefined ? '—' : `${String(Math.round(v)).replace('.', ',')}%`);

export default function Metrics({ totals, rates, topByReply, funnels, chart, days, ranges }) {
    const volSeries = [
        { key: 'sent', name: 'Enviados', color: '#3b82f6' },
        { key: 'delivered', name: 'Entregados', color: TONE.positive },
        { key: 'read', name: 'Leídos', color: TONE.ai },
    ];

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Métricas de Broadcasts</h2>}>
            <Head title="Métricas Broadcasts" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Métricas de Broadcasts</h1>
                        <p className="text-sm text-gray-400 mt-1">Rendimiento agregado de todas las campañas enviadas</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <WindowPicker days={days} ranges={ranges} routeName="broadcasts.metrics" />
                        <a href={route('broadcasts.metrics.export', { days })} title="Descargar CSV de este lapso" className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 hover:bg-gray-50 px-3 py-2 rounded-lg whitespace-nowrap">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            CSV
                        </a>
                        <Link href={route('broadcasts.index')} className="text-xs font-semibold text-[#045474] bg-[#045474]/5 hover:bg-[#045474]/10 px-3 py-2 rounded-lg whitespace-nowrap">
                            ← Lista de broadcasts
                        </Link>
                    </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <StatCard
                        label="Campañas"
                        value={fmtInteger(totals.broadcasts)}
                        gradient="from-[#045474] to-[#1c486c]"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18a24.301 24.301 0 004.5 1.207M10.34 6.66a24.301 24.301 0 014.5-1.207m0 0v13.788m0-13.788c1.35.207 2.685.582 3.968 1.108" /></svg>}
                    />
                    <StatCard
                        label="Enviados"
                        value={fmtInteger(totals.sent)}
                        gradient="from-blue-500 to-indigo-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>}
                    />
                    <StatCard
                        label="Entregados"
                        value={fmtInteger(totals.delivered)}
                        subtitle={`${rates.delivery}%`}
                        gradient="from-emerald-500 to-teal-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>}
                    />
                    <StatCard
                        label="Leídos"
                        value={fmtInteger(totals.read)}
                        subtitle={`${rates.read}%`}
                        gradient="from-violet-500 to-purple-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                    />
                    <StatCard
                        label="Respuestas"
                        value={fmtInteger(totals.replied)}
                        subtitle={`${rates.reply}%`}
                        gradient="from-pink-500 to-rose-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M8 10.5h8m-8 4h5.5m5.879.879l1.5 1.5m-6.379-2.379a5.25 5.25 0 100-10.5 5.25 5.25 0 000 10.5z" /></svg>}
                    />
                    <StatCard
                        label="Fallos"
                        value={fmtInteger(totals.failed)}
                        subtitle={`${rates.failure}%`}
                        gradient="from-red-500 to-rose-600"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>}
                    />
                </div>

                <div className="grid lg:grid-cols-5 gap-6">
                    <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                        <h4 className="text-sm font-bold text-gray-900">Tasas globales</h4>
                        <RateBar label="Entrega" rate={rates.delivery} color="bg-gradient-to-r from-emerald-400 to-emerald-600" />
                        <RateBar label="Lectura" rate={rates.read} color="bg-gradient-to-r from-violet-400 to-violet-600" />
                        <RateBar label="Respuesta" rate={rates.reply} color="bg-gradient-to-r from-pink-400 to-pink-600" />
                        <RateBar label="Fallos" rate={rates.failure} color="bg-gradient-to-r from-red-400 to-red-600" />
                    </div>

                    <ChartCard
                        className="lg:col-span-3"
                        title="Volumen por día"
                        subtitle="Enviados / entregados / leídos apilados"
                        empty={!chart || chart.every((d) => d.sent === 0 && d.delivered === 0 && d.read === 0)}
                        emptyMessage="Sin envíos en este periodo."
                    >
                        <TrendArea
                            data={chart}
                            xKey="label"
                            series={volSeries}
                            stackId="volumen"
                            emptyMessage="Sin envíos en este periodo."
                        />
                    </ChartCard>
                </div>

                <ChartCard
                    title="Tasa de respuesta por día"
                    subtitle="Porcentaje de los enviados del día que llegaron a responder (el embudo se completa después de un rato)."
                    empty={!chart || chart.every((d) => d.reply_rate === null)}
                    emptyMessage="Sin respuestas medidas en este periodo."
                >
                    <TrendArea
                        data={chart.filter((d) => d.reply_rate !== null)}
                        xKey="label"
                        series={[{ key: 'reply_rate', name: 'Tasa de respuesta', color: TONE.danger }]}
                        valueFormatter={(v) => `${Math.round(v)}%`}
                        emptyMessage="Sin respuestas medidas en este periodo."
                    />
                </ChartCard>

                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <h4 className="text-base font-bold text-gray-900">Embudo por campaña</h4>
                        <p className="text-xs text-gray-400">Enviados → entregados → leídos → respondidos</p>
                    </div>

                    {funnels.length > 0 ? (
                        <div className="grid md:grid-cols-2 gap-6">
                            {funnels.map((f) => (
                                <div key={f.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                                    <div className="mb-2">
                                        <Link href={route('broadcasts.show', f.id)} className="font-bold text-gray-900 hover:text-[#045474]">{f.name}</Link>
                                    </div>
                                    <FunnelSteps steps={f.steps} />
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center text-sm text-gray-400">
                            Sin broadcasts enviados en los últimos {days} días.
                        </div>
                    )}
                </div>

                {/* Top 10 por respuesta */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 sm:px-6 py-4 border-b border-gray-100">
                        <h4 className="text-sm font-bold text-gray-900">Top 10 campañas por tasa de respuesta</h4>
                        <p className="text-xs text-gray-400 mt-0.5">Las que más engagement generaron</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase">Campaña</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase w-24">Enviados</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase w-24">Entreg.</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase w-24">Leídos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase w-24">Respuestas</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase w-24">% Resp.</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {topByReply.map((b) => (
                                    <tr key={b.id} className="hover:bg-gray-50">
                                        <td className="px-5 py-3">
                                            <Link href={route('broadcasts.show', b.id)} className="font-semibold text-gray-900 hover:text-[#045474]">{b.name}</Link>
                                            <p className="text-[10px] text-gray-400 font-mono">{b.template_name}</p>
                                        </td>
                                        <td className="px-5 py-3 text-right tabular-nums">{b.sent_count}</td>
                                        <td className="px-5 py-3 text-right tabular-nums text-emerald-600">{b.delivered_count}</td>
                                        <td className="px-5 py-3 text-right tabular-nums text-violet-600">{b.read_count}</td>
                                        <td className="px-5 py-3 text-right tabular-nums text-pink-600">{b.replied_count}</td>
                                        <td className="px-5 py-3 text-right">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-pink-50 text-pink-700 ring-1 ring-pink-200">
                                                {b.reply_rate ?? 0}%
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {topByReply.length === 0 && (
                                    <tr><td colSpan={6} className="px-5 py-10 text-center text-sm text-gray-400">Sin broadcasts enviados todavía</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
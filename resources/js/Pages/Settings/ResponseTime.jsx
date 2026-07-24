import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function ResponseTime({ byAgent, overallLabel, totalReplies }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Tiempo de respuesta</h2>}>
            <Head title="Tiempo de respuesta" />

            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Tiempo de respuesta</h1>
                    <p className="text-sm text-gray-400 mt-1">Cuánto tarda cada agente (o la IA) en contestar al cliente. Últimos 30 días. Considera solo la primera respuesta después de cada mensaje del cliente.</p>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Promedio global</p>
                        <p className="text-3xl font-extrabold text-gray-900">{overallLabel}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Respuestas medidas</p>
                        <p className="text-3xl font-extrabold text-gray-900">{totalReplies.toLocaleString()}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Agentes activos</p>
                        <p className="text-3xl font-extrabold text-gray-900">{byAgent.length}</p>
                    </div>
                </div>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Ranking por agente</h3>
                        <p className="text-xs text-gray-400 mt-0.5">Ordenado por menor promedio (más rápido primero)</p>
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
                                const badge = a.avg_seconds < 60 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : a.avg_seconds < 300 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-red-50 text-red-700 ring-red-200';
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
                                        <td className="px-5 py-3 text-right">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 ${badge}`}>{a.avg_label}</span>
                                        </td>
                                        <td className="px-5 py-3 text-right tabular-nums text-gray-500">{a.median_label}</td>
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

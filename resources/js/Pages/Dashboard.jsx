import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

function money(value, currency) {
    return new Intl.NumberFormat('es', {
        style: 'currency',
        currency: currency || 'BOB',
        maximumFractionDigits: 0,
    }).format(value || 0);
}

const statItems = [
    {
        key: 'contacts',
        label: 'Contactos',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
        ),
        gradient: 'from-emerald-500 to-teal-600',
        lightBg: 'bg-emerald-50',
        ring: 'ring-emerald-500/20',
        href: route('contacts.index'),
    },
    {
        key: 'openConversations',
        label: 'Conv. abiertas',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
            </svg>
        ),
        gradient: 'from-blue-500 to-indigo-600',
        lightBg: 'bg-blue-50',
        ring: 'ring-blue-500/20',
        href: route('inbox'),
    },
    {
        key: 'unreadTotal',
        label: 'Sin leer',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
            </svg>
        ),
        gradient: 'from-amber-400 to-orange-500',
        lightBg: 'bg-amber-50',
        ring: 'ring-amber-400/20',
        href: route('inbox'),
    },
    {
        key: 'pipelineValue',
        label: 'Pipeline abierto',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
        ),
        gradient: 'from-purple-500 to-violet-600',
        lightBg: 'bg-purple-50',
        ring: 'ring-purple-500/20',
        href: route('pipelines.index'),
    },
    {
        key: 'dealsWon',
        label: 'Deals ganados',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
            </svg>
        ),
        gradient: 'from-rose-500 to-pink-600',
        lightBg: 'bg-rose-50',
        ring: 'ring-rose-500/20',
        href: route('pipelines.index'),
    },
];

const activities = [
    {
        key: 'broadcasts',
        label: 'Broadcasts enviados',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
        ),
        gradient: 'from-emerald-500 to-teal-600',
    },
    {
        key: 'automations',
        label: 'Automatizaciones activas',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
            </svg>
        ),
        gradient: 'from-blue-500 to-indigo-600',
    },
    {
        key: 'flows',
        label: 'Flows activos',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
        gradient: 'from-purple-500 to-violet-600',
    },
    {
        key: 'pending',
        label: 'Conversaciones pendientes',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
        ),
        gradient: 'from-amber-400 to-orange-500',
    },
    {
        key: 'aiReplies',
        label: 'Respuestas IA (7 días)',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
            </svg>
        ),
        gradient: 'from-violet-500 to-purple-600',
    },
];

function Chart({ data }) {
    const max = Math.max(1, ...data.map((d) => d.inbound + d.outbound));
    const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h4 className="text-sm font-bold text-gray-900">Mensajes — últimos 7 días</h4>
                    <p className="text-xs text-gray-400 mt-0.5">Actividad de mensajes entrantes y salientes</p>
                </div>
                <div className="flex gap-4 text-xs font-medium text-gray-500">
                    <span className="flex items-center gap-1.5">
                        <span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-emerald-500 to-teal-600" />
                        Entrantes
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-amber-400 to-orange-500" />
                        Salientes
                    </span>
                </div>
            </div>
            <div className="relative h-56">
                <div className="absolute inset-0 flex items-end gap-2">
                    {data.map((d, i) => {
                        const total = d.inbound + d.outbound;
                        const inboundPct = (d.inbound / max) * 100;
                        const outboundPct = (d.outbound / max) * 100;
                        return (
                            <div key={d.day} className="flex-1 flex flex-col items-center justify-end h-full group">
                                <div className="w-full flex items-end justify-center gap-1 relative" style={{ height: `${Math.max(inboundPct || 4, outboundPct || 4)}%` }}>
                                    {d.inbound > 0 && (
                                        <div
                                            className="w-3 rounded-t-md transition-all duration-500 group-hover:brightness-110 relative"
                                            style={{
                                                height: `${inboundPct}%`,
                                                minHeight: d.inbound > 0 ? '4px' : '0',
                                                background: 'linear-gradient(180deg, #10b981 0%, #059669 100%)',
                                            }}
                                        >
                                            <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-emerald-600 opacity-0 group-hover:opacity-100 transition-opacity">
                                                {d.inbound}
                                            </span>
                                        </div>
                                    )}
                                    {d.outbound > 0 && (
                                        <div
                                            className="w-3 rounded-t-md transition-all duration-500 group-hover:brightness-110 relative"
                                            style={{
                                                height: `${outboundPct}%`,
                                                minHeight: d.outbound > 0 ? '4px' : '0',
                                                background: 'linear-gradient(180deg, #fbbf24 0%, #f97316 100%)',
                                            }}
                                        >
                                            <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">
                                                {d.outbound}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <span className="text-[10px] font-semibold text-gray-400 mt-3 pt-0.5 border-t border-gray-100 w-full text-center">
                                    {days[new Date(d.day + 'T00:00:00').getDay()]}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function ConversationRow({ conv }) {
    return (
        <tr className="group hover:bg-gray-50 transition-colors">
            <td className="px-5 py-4">
                <Link href={route('inbox')} className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-bold shadow-sm shadow-[#045474]/20">
                        {(conv.contact?.name || conv.contact?.phone || '?').charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <span className="font-semibold text-gray-900 text-sm">
                            {conv.contact?.name || conv.contact?.phone || 'Desconocido'}
                        </span>
                        {conv.contact?.name && conv.contact?.phone && (
                            <p className="text-[11px] text-gray-400 mt-0.5">{conv.contact.phone}</p>
                        )}
                    </div>
                </Link>
            </td>
            <td className="px-5 py-4 text-sm text-gray-500 max-w-[220px] truncate">
                {conv.last_message_text || <span className="italic text-gray-300">Sin mensajes</span>}
            </td>
            <td className="px-5 py-4 text-right">
                {conv.unread_count > 0 ? (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                        {conv.unread_count} sin leer
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500 ring-1 ring-gray-200">
                        <span className="w-1.5 h-1.5 rounded-full bg-gray-400" />
                        Leído
                    </span>
                )}
            </td>
            <td className="px-5 py-4 text-right text-xs text-gray-400 whitespace-nowrap font-medium">
                {conv.last_message_at
                    ? new Date(conv.last_message_at).toLocaleDateString('es', {
                          day: 'numeric',
                          month: 'short',
                          hour: '2-digit',
                          minute: '2-digit',
                      })
                    : '—'}
            </td>
        </tr>
    );
}

export default function Dashboard({ stats, chart, recentConversations, currency }) {
    const resolveStatValue = (item) => {
        if (item.key === 'pipelineValue') return money(stats.pipelineValue, currency);
        if (item.key === 'unreadTotal') return stats.unreadTotal;
        return stats[item.key];
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
                {/* Header */}
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard</h1>
                    <p className="text-sm text-gray-400 mt-1">Resumen general de tu CRM</p>
                </div>

                {/* Stat Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 sm:gap-5">
                    {statItems.map((item) => {
                        const val = resolveStatValue(item);
                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="group relative bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 overflow-hidden"
                            >
                                <div
                                    className={`absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 ${item.lightBg}`}
                                />
                                <div className="relative">
                                    <div className="flex items-center justify-between mb-4">
                                        <div
                                            className={`w-10 h-10 rounded-xl bg-gradient-to-br ${item.gradient} flex items-center justify-center text-white shadow-lg ${item.ring}`}
                                        >
                                            {item.icon}
                                        </div>
                                        <svg className="w-4 h-4 text-gray-300 group-hover:text-gray-400 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </div>
                                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">
                                        {item.label}
                                    </p>
                                    <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 tabular-nums">
                                        {val}
                                    </p>
                                </div>
                            </Link>
                        );
                    })}
                </div>

                {/* Chart + Activity */}
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <Chart data={chart} />
                    </div>

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div className="mb-5">
                            <h4 className="text-sm font-bold text-gray-900">Actividad</h4>
                            <p className="text-xs text-gray-400 mt-0.5">Resumen del sistema</p>
                        </div>
                        <div className="space-y-2">
                            {activities.map((a) => {
                                const val = stats[a.key];
                                return (
                                    <div
                                        key={a.key}
                                        className="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 transition-colors group cursor-default"
                                    >
                                        <div
                                            className={`flex-shrink-0 w-10 h-10 rounded-xl bg-gradient-to-br ${a.gradient} flex items-center justify-center text-white shadow-sm group-hover:shadow-md transition-shadow`}
                                        >
                                            {a.icon}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs font-medium text-gray-400">{a.label}</p>
                                            <p className="text-lg font-bold text-gray-900 tabular-nums">{val ?? 0}</p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Recent Conversations */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 sm:px-6 py-4 sm:py-5 border-b border-gray-100">
                        <div className="flex items-center justify-between">
                            <div>
                                <h4 className="text-sm font-bold text-gray-900">Conversaciones recientes</h4>
                                <p className="text-xs text-gray-400 mt-0.5">Últimas interacciones con contactos</p>
                            </div>
                            <Link
                                href={route('inbox')}
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#045474] hover:text-[#1c486c] transition-colors bg-[#045474]/5 hover:bg-[#045474]/10 px-3 py-1.5 rounded-lg"
                            >
                                Ver todas
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Contacto</th>
                                    <th className="text-left px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Último mensaje</th>
                                    <th className="text-right px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider w-32">Estado</th>
                                    <th className="text-right px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider w-28">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {recentConversations.map((conv) => (
                                    <ConversationRow key={conv.id} conv={conv} />
                                ))}
                                {recentConversations.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-16 text-center">
                                            <div className="flex flex-col items-center gap-2">
                                                <svg className="w-10 h-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                                                </svg>
                                                <p className="text-sm font-medium text-gray-300">Aún no hay conversaciones</p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

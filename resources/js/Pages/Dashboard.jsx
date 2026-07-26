import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { usePage } from '@inertiajs/react';

function money(value, currency) {
    return new Intl.NumberFormat('es', {
        style: 'currency',
        currency: currency || 'BOB',
        maximumFractionDigits: 0,
    }).format(value || 0);
}

function greeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Buenos días';
    if (h < 18) return 'Buenas tardes';
    return 'Buenas noches';
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
        label: 'Ganados',
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
        label: 'Automatizaciones',
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
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
        label: 'Respuestas IA (7d)',
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
    const months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const totalInbound = data.reduce((s, d) => s + d.inbound, 0);
    const totalOutbound = data.reduce((s, d) => s + d.outbound, 0);
    const weekLabel = data.length > 0
        ? `${new Date(data[0].day + 'T00:00:00').getDate()} ${months[new Date(data[0].day + 'T00:00:00').getMonth()]} – ${new Date(data[data.length - 1].day + 'T00:00:00').getDate()} ${months[new Date(data[data.length - 1].day + 'T00:00:00').getMonth()]}`
        : '';
    const gridLines = [25, 50, 75];

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-sm shrink-0">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <div>
                        <h4 className="text-sm font-bold text-gray-900">Mensajes — últimos 7 días</h4>
                        <p className="text-xs text-gray-400 mt-0.5">{weekLabel || 'Entrantes vs salientes'}</p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-3 text-xs font-medium text-gray-500">
                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                        <span className="w-2 h-2 rounded-full bg-emerald-500 ring-2 ring-emerald-200" />
                        {totalInbound} Entrantes
                    </span>
                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-200/50">
                        <span className="w-2 h-2 rounded-full bg-amber-500 ring-2 ring-amber-200" />
                        {totalOutbound} Salientes
                    </span>
                </div>
            </div>
            {data.length === 0 ? (
                <div className="h-56 flex flex-col items-center justify-center gap-3 text-sm text-gray-400">
                    <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                        <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <div className="text-center">
                        <p className="text-sm font-semibold text-gray-900">Sin datos esta semana</p>
                        <p className="text-xs text-gray-500 mt-0.5">Los mensajes aparecerán aquí cuando tengas actividad</p>
                    </div>
                </div>
            ) : (
                <div className="relative h-56">
                    <svg className="absolute inset-0 w-full h-full pointer-events-none" preserveAspectRatio="none">
                        {gridLines.map((pct) => (
                            <line key={pct} x1="0" y1={`${(1 - pct / 100) * 100}%`} x2="100%" y2={`${(1 - pct / 100) * 100}%`} stroke="#f1f5f9" strokeWidth="1" />
                        ))}
                    </svg>
                    <div className="absolute inset-0 flex items-end gap-2">
                        {data.map((d, i) => {
                            const inboundPct = (d.inbound / max) * 100;
                            const outboundPct = (d.outbound / max) * 100;
                            const hasData = d.inbound > 0 || d.outbound > 0;
                            const barH = Math.max(inboundPct || 0, outboundPct || 0);
                            const isToday = new Date(d.day + 'T00:00:00').toDateString() === new Date().toDateString();
                            return (
                                <div key={d.day} className="flex-1 flex flex-col items-center justify-end h-full group relative">
                                    <div className="w-full flex items-end justify-center gap-1 relative" style={{ height: barH > 0 ? `${barH}%` : '10%' }}>
                                        {d.inbound > 0 && (
                                            <div className="relative w-full max-w-[14px] group/bar">
                                                <div
                                                    className="w-full rounded-t-sm transition-all duration-500 ease-out group-hover/bar:brightness-110 group-hover/bar:scale-y-105 origin-bottom"
                                                    style={{
                                                        height: `${inboundPct}%`,
                                                        minHeight: d.inbound > 0 ? '4px' : '0',
                                                        background: 'linear-gradient(180deg, #34d399 0%, #059669 100%)',
                                                    }}
                                                />
                                            </div>
                                        )}
                                        {d.outbound > 0 && (
                                            <div className="relative w-full max-w-[14px] group/bar">
                                                <div
                                                    className="w-full rounded-t-sm transition-all duration-500 ease-out group-hover/bar:brightness-110 group-hover/bar:scale-y-105 origin-bottom"
                                                    style={{
                                                        height: `${outboundPct}%`,
                                                        minHeight: d.outbound > 0 ? '4px' : '0',
                                                        background: 'linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%)',
                                                    }}
                                                />
                                            </div>
                                        )}
                                        {!hasData && <div className="w-full max-w-[14px] h-full rounded-t-sm bg-gray-100" />}
                                    </div>
                                    <span className={`text-[10px] font-semibold mt-2 w-full text-center ${isToday ? 'text-emerald-600' : 'text-gray-400'}`}>
                                        {isToday ? 'Hoy' : days[new Date(d.day + 'T00:00:00').getDay()]}
                                    </span>
                                    {hasData && (
                                        <div className="absolute -top-1 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-all duration-200 pointer-events-none z-10">
                                            <div className="bg-gray-900 text-white text-[10px] font-bold rounded-lg px-2.5 py-1.5 shadow-xl whitespace-nowrap flex gap-3">
                                                <span className="flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />{d.inbound}</span>
                                                <span className="flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-amber-400" />{d.outbound}</span>
                                            </div>
                                            <div className="w-2 h-2 bg-gray-900 rotate-45 mx-auto -mt-1" />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

const AVATAR_COLORS = ['from-emerald-500 to-teal-600', 'from-blue-500 to-indigo-600', 'from-purple-500 to-pink-600', 'from-amber-500 to-orange-600', 'from-rose-500 to-red-600', 'from-cyan-500 to-sky-600'];
function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    return { initials, gradient: AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length] };
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)}h`;
    const d = new Date(dateStr);
    const days = Math.floor(diff / 86400);
    if (days === 1) return 'ayer';
    if (days < 7) return `hace ${days}d`;
    return d.toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

function ConversationRow({ conv }) {
    const contactName = conv.contact?.name || conv.contact?.phone || 'Desconocido';
    const av = avatarFor(contactName);

    return (
        <tr className="group transition-all duration-150">
            <td className="px-4 sm:px-6 py-3.5">
                <Link href={route('inbox')} className="flex items-center gap-3">
                    <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${av.gradient} flex items-center justify-center text-white text-xs font-bold shadow-sm shrink-0`}>
                        {av.initials}
                    </div>
                    <div>
                        <span className="font-semibold text-gray-900 text-sm">
                            {contactName}
                        </span>
                        {conv.contact?.name && conv.contact?.phone && (
                            <p className="text-[11px] text-gray-400 mt-0.5">{conv.contact.phone}</p>
                        )}
                    </div>
                </Link>
            </td>
            <td className="px-4 sm:px-6 py-3.5 max-w-[200px]">
                <p className="text-sm text-gray-500 truncate group-hover:text-gray-700 transition-colors">
                    {conv.last_message_text || <span className="italic text-gray-300">Sin mensajes</span>}
                </p>
            </td>
            <td className="px-4 sm:px-6 py-3.5 text-right">
                {conv.unread_count > 0 ? (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-sm">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 ring-2 ring-emerald-200 animate-pulse" />
                        {conv.unread_count} sin leer
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-medium bg-gray-50 text-gray-500 border border-gray-200">
                        <span className="w-1.5 h-1.5 rounded-full bg-gray-300" />
                        Leído
                    </span>
                )}
            </td>
            <td className="px-4 sm:px-6 py-3.5 text-right">
                <span className="text-xs text-gray-400 whitespace-nowrap font-medium tabular-nums">
                    {timeAgo(conv.last_message_at)}
                </span>
            </td>
        </tr>
    );
}

export default function Dashboard({ stats, chart, recentConversations, currency }) {
    const { auth } = usePage().props;
    const userName = auth?.user?.name?.split(' ')[0] || '';
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
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20 shrink-0">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605" />
                        </svg>
                    </div>
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">
                            {greeting()}{userName ? `, ${userName}` : ''}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Resumen general de tu CRM</p>
                    </div>
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

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-center gap-3 mb-5">
                            <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-sm shrink-0">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                                </svg>
                            </div>
                            <div>
                                <h4 className="text-sm font-bold text-gray-900">Actividad</h4>
                                <p className="text-xs text-gray-400 mt-0.5">Resumen del sistema</p>
                            </div>
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
                            <div className="flex items-center gap-2.5">
                                <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-sm">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                                    </svg>
                                </div>
                                <div>
                                    <h4 className="text-sm font-bold text-gray-900">Conversaciones recientes</h4>
                                    <p className="text-xs text-gray-400 mt-0.5">Últimas interacciones con contactos</p>
                                </div>
                            </div>
                            <Link
                                href={route('inbox')}
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-600 hover:text-emerald-700 transition-colors bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 rounded-lg"
                            >
                                Ver todas
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                    <div className="divide-y divide-gray-50">
                        {recentConversations.length === 0 ? (
                            <div className="px-6 py-16 text-center">
                                <div className="flex flex-col items-center gap-3">
                                    <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                        <svg className="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p className="text-sm font-semibold text-gray-900">Sin conversaciones</p>
                                        <p className="text-xs text-gray-500 mt-0.5">Las conversaciones aparecerán aquí cuando tengas actividad</p>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100">
                                        <th className="text-left px-4 sm:px-6 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Contacto</th>
                                        <th className="text-left px-4 sm:px-6 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Último mensaje</th>
                                        <th className="text-right px-4 sm:px-6 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Estado</th>
                                        <th className="text-right px-4 sm:px-6 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {recentConversations.map((conv) => (
                                        <ConversationRow key={conv.id} conv={conv} />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

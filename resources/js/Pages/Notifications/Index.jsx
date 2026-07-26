import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import { Head, Link, router } from '@inertiajs/react';

const TYPE_META = {
    conversation_assigned: { icon: '💬', gradient: 'from-emerald-500 to-teal-600' },
    ai_fallback: { icon: '🤖', gradient: 'from-amber-500 to-orange-600' },
};

/** Estado de la conversación: se lee antes que el texto del aviso. */
const CONVERSATION_STATUS = {
    open: { label: 'Abierta', icon: '⏳', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    pending: { label: 'Pendiente', icon: '⏸', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    closed: { label: 'Cerrada', icon: '✓', className: 'bg-gray-100 text-gray-600 ring-gray-200' },
};

/**
 * Avatar del contacto con color derivado del nombre. El mismo contacto
 * siempre sale del mismo color, así se lo reconoce recorriendo la lista sin
 * tener que leer.
 */
const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
];

function ContactAvatar({ name }) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const gradient = AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];

    return (
        <span className={`w-8 h-8 shrink-0 rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center text-white text-[11px] font-bold shadow-sm`}>
            {initials}
        </span>
    );
}

/**
 * Una fila en tres columnas: qué pasó / de quién es / cuándo. Reemplaza a la
 * grilla de tarjetas, que obligaba a leer en zigzag y descuadraba las alturas.
 */
function NotificationRow({ n }) {
    const meta = TYPE_META[n.type] ?? { icon: '🔔', gradient: 'from-[#045474] to-[#1c486c]' };
    const status = n.conversation ? CONVERSATION_STATUS[n.conversation.status] : null;
    const unread = !n.read_at;
    const contactName = n.contact?.name || n.contact?.phone;

    return (
        <li className={`group relative grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_18rem_9rem] gap-x-6 gap-y-3 px-5 py-4 transition-colors hover:bg-gray-50/80 ${unread ? 'bg-emerald-50/30' : ''}`}>
            {unread && <span className="absolute left-0 inset-y-0 w-1 bg-emerald-500" />}

            <div className="flex items-start gap-4 min-w-0">
                <div className={`w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white text-lg shadow-sm`}>
                    {meta.icon}
                </div>
                <div className="min-w-0">
                    <p className={`text-sm ${unread ? 'font-bold text-gray-900' : 'font-semibold text-gray-600'}`}>{n.title}</p>
                    {n.actor && (
                        <span className="inline-flex items-center gap-1 mt-1 text-[11px] text-gray-400">
                            <span className="w-4 h-4 rounded-full bg-gradient-to-br from-[#045474] to-[#1c486c] text-white text-[8px] font-bold flex items-center justify-center">
                                {n.actor.name.charAt(0).toUpperCase()}
                            </span>
                            {n.actor.name}
                        </span>
                    )}
                    {n.body && <p className="mt-2 text-sm text-gray-600 leading-relaxed whitespace-pre-line">{n.body}</p>}
                </div>
            </div>

            <div className="min-w-0 lg:pl-0 pl-14">
                {contactName ? (
                    <Link href={route('inbox')} className="flex items-start gap-2.5 group/c">
                        <ContactAvatar name={contactName} />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-gray-900 truncate group-hover/c:text-emerald-700 transition-colors">
                                {contactName}
                            </p>
                            {status && (
                                <span className={`inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${status.className}`}>
                                    {status.icon} {status.label}
                                </span>
                            )}
                            {n.service_window && (
                                <div className="mt-1">
                                    <ServiceWindowBadge window={n.service_window} showOrigin />
                                </div>
                            )}
                        </div>
                    </Link>
                ) : (
                    <span className="text-xs text-gray-300 italic">Sin contacto asociado</span>
                )}
            </div>

            <div className="flex lg:flex-col lg:items-end items-center gap-2 lg:pl-0 pl-14">
                <span className="text-xs text-gray-400 tabular-nums whitespace-nowrap">{timeAgo(n.created_at)}</span>
                {n.conversation_id && (
                    <Link href={route('inbox')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 whitespace-nowrap">
                        Ir a la conversación →
                    </Link>
                )}
            </div>
        </li>
    );
}

function timeAgo(iso) {
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'hace un momento';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    return new Date(iso).toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

function groupByDate(items) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    const groups = { today: [], yesterday: [], earlier: [] };

    items.forEach((n) => {
        const d = new Date(n.created_at);
        d.setHours(0, 0, 0, 0);
        if (d.getTime() === today.getTime()) groups.today.push(n);
        else if (d.getTime() === yesterday.getTime()) groups.yesterday.push(n);
        else groups.earlier.push(n);
    });

    return groups;
}

const GROUP_LABELS = {
    today: 'Hoy',
    yesterday: 'Ayer',
    earlier: 'Anteriores',
};

export default function Index({ notifications }) {
    const unread = notifications.data.filter((n) => !n.read_at).length;
    const groups = groupByDate(notifications.data);

    const hasMore = notifications.prev_page_url || notifications.next_page_url;

    return (
        <AuthenticatedLayout>
            <Head title="Notificaciones" />

            {/* Ancho completo: cada fila reparte el espacio en columnas
                (qué pasó / de quién es / cuándo), así que aprovecha el ancho
                en vez de estirar una sola línea de texto. */}
            <div className="w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20 shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                        </div>
                        <div>
                            <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Notificaciones</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                {unread > 0
                                    ? `Tenés ${unread} notificación${unread !== 1 ? 'es' : ''} sin leer`
                                    : 'Todo al día'}
                            </p>
                        </div>
                    </div>
                    {unread > 0 && (
                        <button
                            onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}
                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20"
                        >
                            ✓ Marcar todas como leídas
                        </button>
                    )}
                </div>

                {/* Grid de notificaciones agrupadas */}
                <div className="space-y-6">
                    {Object.entries(groups).map(
                        ([key, items]) =>
                            items.length > 0 && (
                                <div key={key}>
                                    <h3 className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">
                                        {GROUP_LABELS[key]}
                                    </h3>
                                    <ul className="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-50 overflow-hidden">
                                        {items.map((n) => <NotificationRow key={n.id} n={n} />)}
                                    </ul>
                                </div>
                            ),
                    )}

                    {/* Empty state */}
                    {notifications.data.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-12 sm:p-16 text-center">
                                <div className="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-4">
                                    <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                    </svg>
                                </div>
                                <p className="text-base font-semibold text-gray-900">Sin notificaciones</p>
                                <p className="text-sm text-gray-500 mt-1">Te avisaremos cuando te asignen conversaciones o la IA necesite atención.</p>
                            </div>
                        </div>
                    )}
                </div>

                {/* Paginación */}
                {hasMore && (
                    <div className="flex items-center justify-between pt-2">
                        {notifications.total && (
                            <p className="text-sm text-gray-400">
                                Mostrando {notifications.from}–{notifications.to} de {notifications.total}
                            </p>
                        )}
                        <div className="flex gap-2 ml-auto">
                            {notifications.prev_page_url && (
                                <Link
                                    href={notifications.prev_page_url}
                                    className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 12H5m7-7l-7 7 7 7" />
                                    </svg>
                                    Anteriores
                                </Link>
                            )}
                            {notifications.next_page_url && (
                                <Link
                                    href={notifications.next_page_url}
                                    className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20"
                                >
                                    Siguientes
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-7-7l7 7-7 7" />
                                    </svg>
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

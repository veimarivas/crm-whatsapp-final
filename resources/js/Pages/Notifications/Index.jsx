import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const TYPE_META = {
    conversation_assigned: { icon: '💬', gradient: 'from-emerald-500 to-teal-600' },
    ai_fallback: { icon: '🤖', gradient: 'from-amber-500 to-orange-600' },
};

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

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
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
                                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                                        {items.map((n) => {
                                            const meta = TYPE_META[n.type] ?? { icon: '🔔', gradient: 'from-[#045474] to-[#1c486c]' };
                                            return (
                                                <div
                                                    key={n.id}
                                                    className={`group rounded-2xl border transition-all hover:shadow-md ${
                                                        n.read_at
                                                            ? 'bg-white border-gray-100 hover:border-gray-200'
                                                            : 'bg-gradient-to-r from-emerald-50/80 to-teal-50/80 border-emerald-200 hover:border-emerald-300'
                                                    }`}
                                                >
                                                    <div className="flex items-start gap-4 p-4">
                                                        <div className={`w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white text-lg shadow-md`}>
                                                            {meta.icon}
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-start justify-between gap-2">
                                                                <p className="font-semibold text-gray-900 text-sm">{n.title}</p>
                                                                <span className="text-xs text-gray-400 tabular-nums whitespace-nowrap shrink-0">{timeAgo(n.created_at)}</span>
                                                            </div>
                                                            {n.body && (
                                                                <p className="mt-1 text-sm text-gray-600 leading-relaxed">{n.body}</p>
                                                            )}
                                                            <div className="flex items-center justify-between mt-2">
                                                                <div className="flex items-center gap-2 text-xs text-gray-400">
                                                                    {n.actor && (
                                                                        <span className="flex items-center gap-1">
                                                                            <span className="w-4 h-4 rounded-full bg-gradient-to-br from-[#045474] to-[#1c486c] text-white text-[8px] font-bold flex items-center justify-center">
                                                                                {n.actor.name.charAt(0).toUpperCase()}
                                                                            </span>
                                                                            {n.actor.name}
                                                                        </span>
                                                                    )}
                                                                    {n.conversation_id && (
                                                                        <Link href={route('inbox')} className="text-emerald-600 font-medium hover:text-emerald-700">
                                                                            Ir a la conversación →
                                                                        </Link>
                                                                    )}
                                                                </div>
                                                                {!n.read_at && (
                                                                    <span className="w-2 h-2 rounded-full bg-emerald-500 shadow-sm shadow-emerald-400/50 shrink-0" />
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
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

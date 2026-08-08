import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/** Un color por grupo del pack sugerido, para leerlo de un vistazo. */
const GROUP_DOT = {
    'Información': 'bg-sky-500',
    'Promoción': 'bg-violet-500',
    'Cierre de inscripciones': 'bg-amber-500',
    'Seguimiento': 'bg-emerald-500',
};

export default function QuickReplies({ replies, suggested = [] }) {
    const { flash, auth } = usePage().props;
    const isAdmin = auth?.user?.account_role === 'owner' || auth?.user?.account_role === 'admin';
    const [editingId, setEditingId] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [deletingBusy, setDeletingBusy] = useState(false);

    const form = useForm({ shortcut: '', content: '', shared: false });
    const editForm = useForm({ shortcut: '', content: '', shared: false });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('quick-replies.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const startEdit = (r) => {
        editForm.setData({ shortcut: r.shortcut, content: r.content, shared: r.user_id === null });
        setEditingId(r.id);
    };

    const saveEdit = (e, id) => {
        e.preventDefault();
        editForm.patch(route('quick-replies.update', id), {
            preserveScroll: true,
            onSuccess: () => { setEditingId(null); editForm.reset(); },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Plantillas rápidas</h2>}>
            <Head title="Plantillas rápidas" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Plantillas rápidas</h1>
                    <p className="text-sm text-gray-400 mt-1">
                        Respuestas frecuentes para el Inbox. Escribe <code className="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/atajo</code> en el composer o toca el botón 📋.
                        Variables disponibles: <code className="bg-gray-100 px-1 rounded text-xs">{'{name}'}</code> <code className="bg-gray-100 px-1 rounded text-xs">{'{phone}'}</code> <code className="bg-gray-100 px-1 rounded text-xs">{'{email}'}</code> <code className="bg-gray-100 px-1 rounded text-xs">{'{company}'}</code>
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">
                        {flash.success}
                    </div>
                )}

                {/* El dato que decide si esto cuesta plata o no. */}
                <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-4 sm:p-5">
                    <div className="flex items-start gap-3">
                        <span className="text-xl leading-none">💡</span>
                        <div className="text-sm text-amber-900 space-y-2">
                            <p className="font-bold">Estas plantillas son gratis, pero solo dentro de la ventana.</p>
                            <p className="leading-relaxed">
                                Son mensajes de <strong>texto libre</strong>, así que WhatsApp solo las entrega mientras la
                                ventana de servicio esté abierta: <strong>24 h</strong> desde el último mensaje del contacto,
                                o <strong>72 h</strong> si llegó por un anuncio de Facebook. Dentro de ese plazo no tienen costo.
                            </p>
                            <p className="leading-relaxed">
                                Con la ventana <strong className="text-red-700">cerrada, Meta las rechaza</strong> — no es que
                                cuesten más, es que no salen. Para escribir fuera de plazo hace falta una{' '}
                                <a href={route('templates.index')} className="underline font-semibold">plantilla aprobada de Meta</a>,
                                y esa sí se factura.
                            </p>
                            <p className="text-xs text-amber-700">
                                En el Inbox tenés el contador de la ventana al lado de cada contacto: si está en verde, cualquiera
                                de estas sale sin costo.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Pack sugerido */}
                {isAdmin && suggested.length > 0 && (
                    <div className="rounded-2xl border border-[#045474]/20 bg-[#045474]/5 p-5 sm:p-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="min-w-0">
                                <h3 className="text-sm font-bold text-gray-900">Plantillas sugeridas para inscripciones</h3>
                                <p className="text-xs text-gray-500 mt-1 max-w-xl leading-relaxed">
                                    {suggested.length} plantillas listas para informar, promocionar, avisar del cierre de
                                    inscripciones y cerrar la venta. Se cargan compartidas para todo el equipo.
                                    <strong className="text-gray-700"> Revisá los campos entre [CORCHETES]</strong> (fechas,
                                    montos, datos bancarios) antes de usarlas.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.post(route('quick-replies.load-suggested'), {}, { preserveScroll: true })}
                                className="px-4 py-2.5 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] shadow-lg shadow-[#045474]/20 hover:opacity-90 transition-all shrink-0"
                            >
                                Cargar {suggested.length} plantillas
                            </button>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-1.5">
                            {suggested.map((s) => (
                                <span
                                    key={s.shortcut}
                                    title={s.content}
                                    className="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-white border border-gray-200 text-[11px]"
                                >
                                    <span className={`w-1.5 h-1.5 rounded-full ${GROUP_DOT[s.group] ?? 'bg-gray-400'}`} />
                                    <code className="font-mono font-bold text-[#045474]">/{s.shortcut}</code>
                                </span>
                            ))}
                        </div>
                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                            {Object.entries(GROUP_DOT).map(([group, dot]) => (
                                <span key={group} className="inline-flex items-center gap-1.5 text-[11px] text-gray-500">
                                    <span className={`w-1.5 h-1.5 rounded-full ${dot}`} />
                                    {group}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {/* Formulario crear */}
                <form onSubmit={submit} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                    <h3 className="text-sm font-bold text-gray-900">Nueva plantilla</h3>
                    <div className="grid sm:grid-cols-4 gap-3">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Atajo</label>
                            <input
                                value={form.data.shortcut}
                                onChange={(e) => form.setData('shortcut', e.target.value)}
                                placeholder="precios"
                                required
                                className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-mono bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white"
                            />
                        </div>
                        <div className="sm:col-span-3">
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Contenido</label>
                            <textarea
                                rows={2}
                                value={form.data.content}
                                onChange={(e) => form.setData('content', e.target.value)}
                                placeholder="Hola {name}! Nuestros precios son…"
                                required
                                className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white"
                            />
                        </div>
                    </div>
                    <div className="flex items-center justify-between">
                        {isAdmin ? (
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.shared}
                                    onChange={(e) => form.setData('shared', e.target.checked)}
                                    className="w-4 h-4 rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                                />
                                Compartir con todo el equipo
                            </label>
                        ) : <span />}
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-[#045474]/20"
                        >
                            {form.processing ? 'Guardando…' : 'Añadir plantilla'}
                        </button>
                    </div>
                </form>

                {/* Lista */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 sm:px-6 py-4 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Mis plantillas + compartidas</h3>
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {replies.map((r) => (
                            <li key={r.id} className={`p-5 ${editingId === r.id ? 'bg-amber-50/50' : 'hover:bg-gray-50'} transition-colors`}>
                                {editingId === r.id ? (
                                    <form onSubmit={(e) => saveEdit(e, r.id)} className="space-y-3">
                                        <div className="grid sm:grid-cols-4 gap-3 items-start">
                                            <div>
                                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Atajo</label>
                                                <input
                                                    value={editForm.data.shortcut}
                                                    onChange={(e) => editForm.setData('shortcut', e.target.value)}
                                                    required
                                                    className="w-full px-3.5 py-2.5 border border-amber-300 rounded-xl text-sm font-mono bg-white focus:ring-2 focus:ring-amber-500/30 focus:outline-none"
                                                />
                                            </div>
                                            <div className="sm:col-span-3">
                                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Contenido</label>
                                                <textarea
                                                    autoFocus
                                                    rows={Math.max(4, Math.ceil((editForm.data.content || '').length / 90))}
                                                    value={editForm.data.content}
                                                    onChange={(e) => editForm.setData('content', e.target.value)}
                                                    required
                                                    className="w-full px-3.5 py-2.5 border border-amber-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-amber-500/30 focus:outline-none whitespace-pre-wrap"
                                                />
                                            </div>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            {isAdmin && (
                                                <label className="flex items-center gap-2 text-xs text-gray-600">
                                                    <input
                                                        type="checkbox"
                                                        checked={editForm.data.shared}
                                                        onChange={(e) => editForm.setData('shared', e.target.checked)}
                                                        className="w-4 h-4 rounded border-gray-300 text-amber-600"
                                                    />
                                                    Compartida
                                                </label>
                                            )}
                                            <div className="flex gap-2 ml-auto">
                                                <button type="button" onClick={() => setEditingId(null)} className="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50">Cancelar</button>
                                                <button type="submit" disabled={editForm.processing} className="px-4 py-1.5 text-xs font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg shadow-sm">Guardar</button>
                                            </div>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex items-start gap-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 mb-1.5">
                                                <code className="px-2 py-0.5 rounded-lg bg-[#045474]/10 text-[#045474] font-mono text-xs font-bold">/{r.shortcut}</code>
                                                {r.user_id === null ? (
                                                    <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded ring-1 ring-emerald-200">Compartida</span>
                                                ) : (
                                                    <span className="text-[10px] text-gray-400">Mía</span>
                                                )}
                                            </div>
                                            <p className="text-sm text-gray-700 whitespace-pre-wrap">{r.content}</p>
                                        </div>
                                        <div className="flex gap-2 shrink-0">
                                            <button onClick={() => startEdit(r)} className="p-2 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                            </button>
                                            <button onClick={() => setDeleting(r)} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                        {replies.length === 0 && (
                            <li className="p-8 text-center text-sm text-gray-400">Sin plantillas todavía. Añadí la primera arriba.</li>
                        )}
                    </ul>
                </div>

                <Modal show={!!deleting} onClose={() => setDeleting(null)} maxWidth="md">
                    <div className="p-6">
                        <div className="flex items-start gap-4">
                            <div className="w-11 h-11 rounded-full bg-red-50 flex items-center justify-center text-red-600 shrink-0">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </div>
                            <div className="min-w-0 flex-1">
                                <h3 className="text-base font-bold text-gray-900">Eliminar plantilla</h3>
                                <p className="text-sm text-gray-500 mt-1">
                                    ¿Eliminar la plantilla{' '}
                                    <code className="px-1.5 py-0.5 rounded bg-[#045474]/10 text-[#045474] font-mono text-xs font-bold">/{deleting?.shortcut}</code>?
                                </p>
                                <p className="text-xs text-red-600 mt-2">Esta acción no se puede deshacer.</p>
                            </div>
                        </div>
                        <div className="mt-6 flex justify-end gap-3">
                            <button onClick={() => setDeleting(null)} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                                Cancelar
                            </button>
                            <button
                                onClick={() => {
                                    setDeletingBusy(true);
                                    router.delete(route('quick-replies.destroy', deleting.id), {
                                        preserveScroll: true,
                                        onSuccess: () => { setDeleting(null); setDeletingBusy(false); },
                                        onError: () => setDeletingBusy(false),
                                    });
                                }}
                                disabled={deletingBusy}
                                className="px-5 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-500 rounded-xl transition-all shadow-lg shadow-red-500/20 disabled:opacity-50"
                            >
                                {deletingBusy ? 'Eliminando…' : 'Sí, eliminar'}
                            </button>
                        </div>
                    </div>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

export default function AutoTags({ rules, tags }) {
    const { flash } = usePage().props;

    const form = useForm({
        keyword: '',
        tag_id: tags[0]?.id ?? '',
        first_message_only: false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('auto-tags.store'), { preserveScroll: true, onSuccess: () => form.reset('keyword') });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Reglas de auto-etiquetado</h2>}>
            <Head title="Auto-tags" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Auto-etiquetado</h1>
                    <p className="text-sm text-gray-400 mt-1">
                        Cuando un cliente escriba palabras que coincidan con estas reglas, el contacto recibirá automáticamente el tag asociado.
                        Útil para clasificar leads por interés sin tener que revisar manualmente cada uno.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                {tags.length === 0 && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 shadow-sm">
                        ⚠️ Primero creá algunos tags en <a href={route('contacts.index')} className="underline font-semibold">Contactos</a> para poder asociarlos aquí.
                    </div>
                )}

                {tags.length > 0 && (
                    <div className="grid gap-6 lg:grid-cols-3 items-start">
                    <form onSubmit={submit} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                        <h3 className="text-sm font-bold text-gray-900">Nueva regla</h3>
                        <div className="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Palabra clave</label>
                                <input
                                    value={form.data.keyword}
                                    onChange={(e) => form.setData('keyword', e.target.value)}
                                    placeholder="maestría"
                                    required
                                    className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white"
                                />
                                <p className="text-[10px] text-gray-400 mt-1">Búsqueda case-insensitive. Ej. "precio" matchea también "precios".</p>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Tag a aplicar</label>
                                <select
                                    value={form.data.tag_id}
                                    onChange={(e) => form.setData('tag_id', e.target.value)}
                                    className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white"
                                >
                                    {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                </select>
                            </div>
                        </div>
                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.first_message_only}
                                    onChange={(e) => form.setData('first_message_only', e.target.checked)}
                                    className="w-4 h-4 rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                                />
                                Solo en el primer mensaje del contacto (evita re-taggear)
                            </label>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-[#045474]/20"
                            >
                                Añadir regla
                            </button>
                        </div>
                    </form>
                    </div>
                )}

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden lg:col-span-2">
                    <div className="px-5 sm:px-6 py-4 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Reglas activas ({rules.filter((r) => r.is_active).length}/{rules.length})</h3>
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {rules.map((r) => (
                            <li key={r.id} className={`p-4 sm:p-5 flex items-center gap-4 ${r.is_active ? '' : 'opacity-50'}`}>
                                <div className="flex-1 min-w-0 flex items-center gap-3 flex-wrap">
                                    <code className="px-2.5 py-1 rounded-lg bg-gray-100 text-gray-800 font-mono text-xs font-semibold">{r.keyword}</code>
                                    <span className="text-gray-400">→</span>
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold text-white shadow-sm" style={{ backgroundColor: r.tag?.color ?? '#6b7280' }}>
                                        {r.tag?.name ?? '—'}
                                    </span>
                                    {r.first_message_only && (
                                        <span className="text-[10px] text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">1er msg</span>
                                    )}
                                </div>
                                <div className="flex gap-2 shrink-0">
                                    <button
                                        onClick={() => router.post(route('auto-tags.toggle', r.id), {}, { preserveScroll: true })}
                                        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 transition-all ${
                                            r.is_active
                                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                                : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                                        }`}
                                    >
                                        <span className={`w-1.5 h-1.5 rounded-full ${r.is_active ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                        {r.is_active ? 'Activa' : 'Pausada'}
                                    </button>
                                    <button
                                        onClick={() => { if (confirm('¿Eliminar esta regla?')) router.delete(route('auto-tags.destroy', r.id), { preserveScroll: true }); }}
                                        className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg"
                                    >
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </div>
                            </li>
                        ))}
                        {rules.length === 0 && (
                            <li className="p-8 text-center text-sm text-gray-400">Sin reglas configuradas. Creá la primera arriba.</li>
                        )}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

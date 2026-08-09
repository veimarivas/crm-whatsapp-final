import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Cola de revisión de las correcciones a la IA.
 *
 * El paso humano es obligatorio: enchufar lo que escribe un agente apurado
 * directo a la base de conocimiento hace que la IA repita ese error con todos
 * los clientes. Acá se lee, se edita y recién entonces se aprueba.
 */

function ApplyForm({ item, onDone }) {
    const form = useForm({
        title: item.question ? `Corrección: ${item.question.slice(0, 80)}` : 'Corrección del equipo',
        // El revisor edita el texto antes de aprobarlo: revisar sin poder
        // corregir la corrección sería aprobar a ciegas.
        content: item.correction ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('settings.ai.feedback.apply', item.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-2 border-t border-gray-100 pt-3">
            <input
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
                className="w-full text-sm border-gray-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                placeholder="Título del documento"
            />
            <textarea
                value={form.data.content}
                onChange={(e) => form.setData('content', e.target.value)}
                rows={4}
                className="w-full text-sm border-gray-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                placeholder="Qué debería haber contestado la IA"
            />
            {form.errors.content && <p className="text-xs text-rose-600">{form.errors.content}</p>}
            <p className="text-[11px] text-gray-400">
                Entra como documento <strong>fijo</strong>: va completo en cada consulta, sin depender de la búsqueda.
            </p>
            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={form.processing || !form.data.content.trim()}
                    className="px-3 py-1.5 rounded-xl text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-40"
                >
                    {form.processing ? 'Aplicando…' : 'Aprobar y enseñar'}
                </button>
                <button type="button" onClick={onDone} className="px-3 py-1.5 rounded-xl text-xs font-semibold text-gray-500 hover:bg-gray-100">
                    Cancelar
                </button>
            </div>
        </form>
    );
}

export default function AiFeedbackPage({ pending = [], resolved = [], stats = {} }) {
    const [applying, setApplying] = useState(null);

    return (
        <AuthenticatedLayout>
            <Head title="Correcciones de la IA" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Correcciones de la IA</h1>
                    <p className="text-sm text-gray-400 mt-1">
                        Lo que el equipo marcó como mal contestado desde el CRM. Nada llega al conocimiento sin pasar por acá.
                    </p>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    {[
                        ['Por revisar', stats.pending ?? 0, 'text-amber-600'],
                        ['Marcadas mal', stats.down ?? 0, 'text-rose-600'],
                        ['Marcadas bien', stats.up ?? 0, 'text-emerald-600'],
                        ['Tasa de rechazo', stats.downRate === null || stats.downRate === undefined ? '—' : `${stats.downRate}%`, 'text-gray-900'],
                    ].map(([label, value, tone]) => (
                        <div key={label} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
                            <p className={`text-3xl font-extrabold mt-1 tabular-nums leading-none ${tone}`}>{value}</p>
                        </div>
                    ))}
                </div>

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 py-3.5 border-b border-gray-100">
                        <h2 className="text-base font-bold text-gray-900">Por revisar</h2>
                        <p className="text-xs text-gray-400 mt-0.5">Respuestas que el equipo marcó como incorrectas.</p>
                    </div>

                    {pending.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-gray-400">Nada pendiente de revisión.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {pending.map((item) => (
                                <li key={item.id} className="px-5 py-4">
                                    {item.question && (
                                        <p className="text-xs text-gray-500">
                                            <span className="font-bold text-gray-400">Cliente:</span> {item.question}
                                        </p>
                                    )}
                                    {item.ai_text && (
                                        <p className="text-sm text-gray-800 mt-1.5 bg-rose-50/60 rounded-xl px-3 py-2">
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-rose-600 block">Contestó la IA</span>
                                            {item.ai_text}
                                        </p>
                                    )}
                                    {item.correction && (
                                        <p className="text-sm text-gray-800 mt-1.5 bg-emerald-50/60 rounded-xl px-3 py-2">
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-700 block">
                                                Corrección de {item.reporter ?? 'el equipo'}
                                            </span>
                                            {item.correction}
                                        </p>
                                    )}

                                    {applying === item.id ? (
                                        <ApplyForm item={item} onDone={() => setApplying(null)} />
                                    ) : (
                                        <div className="flex gap-2 mt-3">
                                            <button
                                                onClick={() => setApplying(item.id)}
                                                disabled={!item.correction}
                                                title={item.correction ? undefined : 'Sin texto de corrección no hay nada que enseñar'}
                                                className="px-3 py-1.5 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100 disabled:opacity-40"
                                            >
                                                Revisar y aprobar
                                            </button>
                                            <button
                                                onClick={() => router.post(route('settings.ai.feedback.dismiss', item.id), {}, { preserveScroll: true })}
                                                className="px-3 py-1.5 rounded-xl text-xs font-semibold text-gray-500 hover:bg-gray-100"
                                            >
                                                Descartar
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {resolved.length > 0 && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="px-5 py-3.5 border-b border-gray-100">
                            <h2 className="text-base font-bold text-gray-900">Ya resueltas</h2>
                        </div>
                        <ul className="divide-y divide-gray-100">
                            {resolved.slice(0, 30).map((item) => (
                                <li key={item.id} className="px-5 py-3 flex items-center gap-3">
                                    <span className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 ${
                                        item.status === 'applied'
                                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                            : 'bg-gray-100 text-gray-500 ring-gray-200'
                                    }`}>
                                        {item.status === 'applied' ? 'Aplicada' : 'Descartada'}
                                    </span>
                                    <span className="text-xs text-gray-600 min-w-0 truncate">
                                        {item.correction || item.ai_text || item.question}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

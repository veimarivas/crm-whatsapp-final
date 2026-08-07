import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const TRIGGER_META = {
    keyword: { label: 'Escriben una palabra clave', short: 'Palabra clave', gradient: 'from-amber-500 to-orange-600', chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
    first_inbound_message: { label: 'Es su primer mensaje', short: 'Primer mensaje', gradient: 'from-emerald-500 to-teal-600', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    manual: { label: 'Se lanza a mano', short: 'Manual', gradient: 'from-purple-500 to-violet-600', chip: 'bg-purple-50 text-purple-700 ring-purple-200' },
};

const RECIPE_ICONS = {
    menu: 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
    qualify: 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
    form: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z',
    faq: 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z',
};

/** Agrupa las plantillas: primero las generadas con la oferta real. */
function RecipeGroup({ label, hint, recipes, selected, onPick, tone }) {
    if (recipes.length === 0) return null;

    return (
        <div>
            <div className="flex items-baseline gap-2 mb-2">
                <p className={`text-[11px] font-bold uppercase tracking-wider ${tone}`}>{label}</p>
                {hint && <p className="text-[11px] text-gray-400">{hint}</p>}
            </div>
            <div className="grid gap-2.5 sm:grid-cols-2">
                {recipes.map((r) => (
                    <button
                        key={r.slug}
                        type="button"
                        onClick={() => onPick(r)}
                        className={`text-left rounded-xl border p-3.5 transition-all ${
                            selected === r.slug ? 'border-emerald-500 bg-emerald-50/60 ring-2 ring-emerald-500/20' : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <div className="flex items-start gap-2.5">
                            <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${
                                selected === r.slug ? 'bg-gradient-to-br from-emerald-500 to-teal-600 text-white' : 'bg-gray-100 text-gray-500'
                            }`}>
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d={RECIPE_ICONS[r.icon] ?? RECIPE_ICONS.menu} />
                                </svg>
                            </div>
                            <div className="min-w-0">
                                <p className="text-xs font-bold text-gray-900">{r.title}</p>
                                <p className="text-[11px] text-gray-500 leading-snug mt-0.5">{r.summary}</p>
                                <p className="text-[10px] text-gray-400 italic mt-1">{r.why}</p>
                            </div>
                        </div>
                    </button>
                ))}
            </div>
        </div>
    );
}

function relativeDate(iso) {
    if (!iso) return 'nunca';
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'hace instantes';
    if (mins < 60) return `hace ${mins} min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `hace ${hours} h`;
    const days = Math.floor(hours / 24);
    return days < 30 ? `hace ${days} d` : new Date(iso).toLocaleDateString();
}

function FlowCard({ flow }) {
    const trigger = TRIGGER_META[flow.trigger_type] ?? TRIGGER_META.keyword;
    const keywords = flow.trigger_config?.keywords ?? [];
    const isActive = flow.status === 'active';

    return (
        <div className={`bg-white rounded-2xl border shadow-sm overflow-hidden transition-all hover:shadow-md ${isActive ? 'border-emerald-100' : 'border-gray-100'}`}>
            <div className="p-5 sm:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Link href={route('flows.edit', flow.id)} className="flex items-start gap-3 min-w-0 group">
                        <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${trigger.gradient} flex items-center justify-center text-white shadow-lg flex-shrink-0`}>
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-base font-bold text-gray-900 group-hover:text-emerald-700 transition-colors truncate">{flow.name}</h3>
                            <p className="text-xs text-gray-400 mt-0.5">
                                {trigger.label}
                                {flow.trigger_type === 'keyword' && keywords.length > 0 && `: ${keywords.slice(0, 3).join(', ')}`}
                            </p>
                        </div>
                    </Link>

                    <button
                        onClick={() => router.post(route('flows.toggle', flow.id), {}, { preserveScroll: true })}
                        title={isActive ? 'Pausar: deja de responder' : 'Activar: empieza a responder'}
                        className={`flex-shrink-0 inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold ring-1 transition-all ${
                            isActive
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                        }`}
                    >
                        <span className={`w-1.5 h-1.5 rounded-full ${isActive ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'}`} />
                        {isActive ? 'Activo' : 'Borrador'}
                    </button>
                </div>

                {/* Lo primero que vería el cliente, como burbuja de WhatsApp. */}
                <div className="mt-4 rounded-xl bg-gray-50/70 border border-gray-100 p-3.5">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Primer mensaje del bot</p>
                    {flow.entry_missing ? (
                        <p className="text-xs text-red-500 font-medium">
                            El nodo de entrada «{flow.entry_node_id}» no existe. No se puede activar hasta arreglarlo.
                        </p>
                    ) : flow.entry_text ? (
                        <div className="inline-block max-w-full rounded-2xl rounded-tl-sm bg-white border border-gray-200 px-3 py-2 shadow-sm">
                            <p className="text-xs text-gray-700 whitespace-pre-wrap break-words">{flow.entry_text}</p>
                        </div>
                    ) : (
                        <p className="text-xs text-gray-400">El nodo de entrada no envía texto.</p>
                    )}
                </div>
            </div>

            <div className="px-5 sm:px-6 py-3 bg-gray-50/60 border-t border-gray-100 flex items-center justify-between gap-3">
                <div className="flex items-center gap-4 text-[11px] text-gray-500">
                    <span><strong className="text-gray-700 tabular-nums">{flow.runs_count}</strong> conversaciones</span>
                    <span className="hidden sm:inline"><strong className="text-gray-700 tabular-nums">{flow.nodes_count}</strong> pasos</span>
                    <span className="hidden md:inline">Último: {relativeDate(flow.last_executed_at)}</span>
                </div>
                <div className="flex items-center gap-1">
                    <Link href={route('flows.edit', flow.id)} className="px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors">
                        Editar y probar
                    </Link>
                    <Link href={route('flows.runs', flow.id)} className="px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:text-sky-700 hover:bg-sky-50 rounded-lg transition-colors">
                        Historial
                    </Link>
                    <button
                        onClick={() => {
                            if (confirm(`¿Eliminar «${flow.name}»? También se borra su historial.`)) {
                                router.delete(route('flows.destroy', flow.id));
                            }
                        }}
                        className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        title="Eliminar"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function Index({ flows, recipes, oferta }) {
    const { flash, errors } = usePage().props;
    const [showNew, setShowNew] = useState(false);
    const form = useForm({ name: '', recipe: recipes[0]?.slug ?? '' });

    const genericas = recipes.filter((r) => r.source !== 'oferta');
    const deOferta = recipes.filter((r) => r.source === 'oferta');

    const create = (e) => {
        e.preventDefault();
        form.post(route('flows.store'));
    };

    const pick = (recipe) => {
        form.setData((prev) => ({
            ...prev,
            recipe: recipe.slug,
            // El nombre sigue a la plantilla mientras el usuario no escriba el suyo.
            name: prev.name && prev.name !== recipes.find((r) => r.slug === prev.recipe)?.title ? prev.name : recipe.title,
        }));
    };

    const activeCount = flows.filter((f) => f.status === 'active').length;
    const totalRuns = flows.reduce((sum, f) => sum + f.runs_count, 0);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Flows</h2>}>
            <Head title="Flows" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Chatbots</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Un chatbot <strong className="text-gray-700">conversa</strong>: pregunta, espera la respuesta del cliente y sigue por un camino u otro.
                            Para acciones sueltas sin diálogo están las <Link href={route('automations.index')} className="text-emerald-600 hover:text-emerald-700 font-semibold">automatizaciones</Link>.
                        </p>
                    </div>
                    <button
                        onClick={() => {
                            // Si hay plantillas con la oferta real, esas van preseleccionadas:
                            // llegan con los datos puestos y son el mejor punto de partida.
                            const inicial = deOferta[0] ?? recipes[0];
                            form.setData({ name: inicial?.title ?? '', recipe: inicial?.slug ?? '' });
                            setShowNew(true);
                        }}
                        className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20 flex items-center gap-1.5 w-fit"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nuevo chatbot
                    </button>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-center gap-3 shadow-sm">
                        <div className="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center flex-shrink-0">
                            <svg className="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        {flash.success}
                    </div>
                )}
                {errors?.flow && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm">{errors.flow}</div>
                )}

                {flows.length > 0 && (
                    <div className="grid grid-cols-3 gap-4">
                        {[
                            { label: 'Activos', value: activeCount, hint: 'respondiendo ahora' },
                            { label: 'Borradores', value: flows.length - activeCount, hint: 'no responden' },
                            { label: 'Conversaciones', value: totalRuns, hint: 'desde siempre' },
                        ].map((s) => (
                            <div key={s.label} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                                <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">{s.label}</p>
                                <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{s.value}</p>
                                <p className="text-[11px] text-gray-400 mt-0.5">{s.hint}</p>
                            </div>
                        ))}
                    </div>
                )}

                <div className="space-y-4">
                    {flows.map((flow) => <FlowCard key={flow.id} flow={flow} />)}

                    {flows.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 py-16 text-center">
                            <div className="mx-auto w-14 h-14 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-4">
                                <svg className="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                            </div>
                            <p className="text-sm font-medium text-gray-600">Todavía no tienes chatbots</p>
                            <p className="text-xs text-gray-400 mt-1">Creá uno desde una plantilla — podés conversar con él antes de activarlo</p>
                        </div>
                    )}
                </div>
            </div>

            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="2xl">
                <form onSubmit={create}>
                    <div className="px-6 pt-6 pb-4 border-b border-gray-100">
                        <h2 className="text-base font-bold text-gray-900">Nuevo chatbot</h2>
                        <p className="text-xs text-gray-500 mt-0.5">Elegí de dónde partir. Se crea como borrador: no responde a nadie hasta que lo actives.</p>
                    </div>

                    <div className="px-6 py-5 space-y-4 max-h-[60vh] overflow-y-auto">
                        <RecipeGroup
                            label="Con tu oferta académica"
                            hint={oferta?.disponible ? `${oferta.programas} programas · ${oferta.areas} áreas` : null}
                            tone="text-sky-700"
                            recipes={deOferta}
                            selected={form.data.recipe}
                            onPick={pick}
                        />

                        {deOferta.length > 0 && (
                            <p className="text-[11px] text-gray-500 leading-relaxed bg-sky-50/60 rounded-xl p-3">
                                Se arman con los programas, precios, módulos, docentes y horarios que hay ahora en la base ESAM
                                — la misma que alimenta la base de conocimiento de la IA.
                                <strong className="text-gray-700"> Los textos quedan fijos al crear el chatbot:</strong> si la
                                oferta cambia, creá el chatbot de nuevo o editá los mensajes.
                            </p>
                        )}

                        {oferta && !oferta.disponible && (
                            <p className="text-[11px] text-amber-800 bg-amber-50 rounded-xl p-3">
                                No se pudo leer la oferta académica: o la base ESAM no responde, o no hay programas con
                                inscripciones abiertas. Las plantillas genéricas funcionan igual.
                            </p>
                        )}

                        <RecipeGroup
                            label="Genéricas"
                            tone="text-gray-500"
                            recipes={genericas}
                            selected={form.data.recipe}
                            onPick={pick}
                        />

                        <div>
                            <label htmlFor="flow-name" className="block text-sm font-semibold text-gray-700 mb-1.5">Nombre</label>
                            <input
                                id="flow-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                                placeholder="ej. Bienvenida y menú principal"
                                className={`w-full px-3.5 py-2.5 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
                                    form.errors.name ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white focus:bg-white'
                                }`}
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.name}</p>}
                        </div>
                    </div>

                    <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                        <button type="button" onClick={() => setShowNew(false)} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                        >
                            Crear chatbot
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

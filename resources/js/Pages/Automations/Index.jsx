import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const TRIGGER_META = {
    inbound_message: { label: 'Cualquier mensaje entrante', short: 'Mensaje entrante', gradient: 'from-blue-500 to-indigo-600', chip: 'bg-blue-50 text-blue-700 ring-blue-200' },
    new_contact: { label: 'Un contacto escribe por primera vez', short: 'Contacto nuevo', gradient: 'from-emerald-500 to-teal-600', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    keyword: { label: 'Un mensaje trae una palabra clave', short: 'Palabra clave', gradient: 'from-amber-500 to-orange-600', chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
};

const STEP_LABEL = {
    send_message: 'Enviar mensaje',
    add_tag: 'Añadir etiqueta',
    remove_tag: 'Quitar etiqueta',
    condition: 'Condición',
    wait: 'Esperar',
    webhook: 'Webhook',
};

const RECIPE_ICONS = {
    welcome: 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75',
    price: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z',
    follow: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    tag: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
    agent: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    branch: 'M6 3v12m0 0a3 3 0 103 3M6 15a3 3 0 113-3m0 0h6a3 3 0 003-3V3',
    academic: 'M4.26 10.147a60.438 60.438 0 00-.491 6.347A48.62 48.62 0 0112 20.904a48.62 48.62 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.636 50.636 0 00-2.658-.813A59.906 59.906 0 0112 3.493a59.903 59.903 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
};

function humanMinutes(minutes) {
    const m = Math.max(1, Number(minutes) || 60);
    if (m < 60) return `${m} min`;
    if (m % 1440 === 0) return `${m / 1440} ${m / 1440 === 1 ? 'día' : 'días'}`;
    if (m % 60 === 0) return `${m / 60} ${m / 60 === 1 ? 'hora' : 'horas'}`;
    return `${Math.floor(m / 60)} h ${m % 60} min`;
}

/** Resumen legible de un paso, para entender la automatización sin abrirla. */
function stepSummary(step) {
    const c = step.config ?? {};
    switch (step.type) {
        case 'send_message': {
            const text = (c.text ?? '').trim();
            return text ? `“${text.length > 42 ? `${text.slice(0, 42)}…` : text}”` : 'Enviar mensaje (vacío)';
        }
        case 'wait':
            return `Esperar ${humanMinutes(c.minutes)}`;
        case 'condition':
            return 'Según una condición';
        case 'add_tag':
            return 'Añadir etiqueta';
        case 'remove_tag':
            return 'Quitar etiqueta';
        case 'webhook':
            return 'Llamar webhook';
        default:
            return STEP_LABEL[step.type] ?? step.type;
    }
}

function relativeDate(iso) {
    if (!iso) return 'nunca';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'hace instantes';
    if (mins < 60) return `hace ${mins} min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `hace ${hours} h`;
    const days = Math.floor(hours / 24);
    return days < 30 ? `hace ${days} d` : new Date(iso).toLocaleDateString();
}

function RecipeCard({ recipe }) {
    return (
        <Link
            href={route('automations.create', { recipe: recipe.slug })}
            className="group text-left bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-emerald-200 transition-all flex flex-col"
        >
            <div className="flex items-start gap-3">
                <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20 flex-shrink-0">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d={RECIPE_ICONS[recipe.icon] ?? RECIPE_ICONS.welcome} />
                    </svg>
                </div>
                <div className="min-w-0">
                    <h4 className="text-sm font-bold text-gray-900 group-hover:text-emerald-700 transition-colors">{recipe.title}</h4>
                    <p className="text-xs text-gray-500 mt-1 leading-relaxed">{recipe.summary}</p>
                </div>
            </div>
            <p className="text-[11px] text-gray-400 mt-3 italic">{recipe.why}</p>
            <div className="mt-4 pt-3 border-t border-gray-50 flex items-center justify-between">
                <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold ring-1 ${TRIGGER_META[recipe.trigger_type].chip}`}>
                    {TRIGGER_META[recipe.trigger_type].short}
                </span>
                <span className="text-[11px] font-semibold text-emerald-600 group-hover:translate-x-0.5 transition-transform">
                    Usar plantilla →
                </span>
            </div>
        </Link>
    );
}

function AutomationCard({ automation }) {
    const trigger = TRIGGER_META[automation.trigger_type] ?? TRIGGER_META.inbound_message;
    const keywords = automation.trigger_config?.keywords ?? [];
    const steps = automation.root_steps ?? [];
    const visible = steps.slice(0, 3);

    return (
        <div className={`bg-white rounded-2xl border shadow-sm overflow-hidden transition-all hover:shadow-md ${
            automation.is_active ? 'border-emerald-100' : 'border-gray-100'
        }`}>
            <div className="p-5 sm:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Link href={route('automations.edit', automation.id)} className="flex items-start gap-3 min-w-0 group">
                        <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${trigger.gradient} flex items-center justify-center text-white shadow-lg flex-shrink-0`}>
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-base font-bold text-gray-900 group-hover:text-emerald-700 transition-colors truncate">{automation.name}</h3>
                            {automation.description && <p className="text-xs text-gray-400 mt-0.5 truncate">{automation.description}</p>}
                        </div>
                    </Link>

                    <button
                        onClick={() => router.post(route('automations.toggle', automation.id), {}, { preserveScroll: true })}
                        title={automation.is_active ? 'Pausar: deja de ejecutarse' : 'Activar: empieza a ejecutarse'}
                        className={`flex-shrink-0 inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold ring-1 transition-all ${
                            automation.is_active
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                        }`}
                    >
                        <span className={`w-1.5 h-1.5 rounded-full ${automation.is_active ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'}`} />
                        {automation.is_active ? 'Activa' : 'Pausada'}
                    </button>
                </div>

                {/* Resumen en lenguaje natural: CUANDO … ENTONCES … */}
                <div className="mt-4 rounded-xl bg-gray-50/70 border border-gray-100 p-3.5 space-y-2">
                    <div className="flex items-start gap-2 flex-wrap">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mt-1">Cuando</span>
                        <span className="text-xs font-medium text-gray-700">{trigger.label}</span>
                        {automation.trigger_type === 'keyword' && keywords.length > 0 && (
                            <span className="flex flex-wrap gap-1">
                                {keywords.slice(0, 4).map((k) => (
                                    <span key={k} className="px-1.5 py-0.5 rounded-md bg-amber-100 text-amber-800 text-[10px] font-semibold">{k}</span>
                                ))}
                                {keywords.length > 4 && <span className="text-[10px] text-gray-400 self-center">+{keywords.length - 4}</span>}
                            </span>
                        )}
                    </div>
                    <div className="flex items-start gap-2 flex-wrap">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mt-1">Entonces</span>
                        {steps.length === 0 ? (
                            <span className="text-xs text-red-500 font-medium">Sin pasos todavía — no hará nada</span>
                        ) : (
                            <span className="flex items-center gap-1.5 flex-wrap">
                                {visible.map((s, i) => (
                                    <span key={i} className="flex items-center gap-1.5">
                                        {i > 0 && <span className="text-gray-300">→</span>}
                                        <span className="text-xs text-gray-700">{stepSummary(s)}</span>
                                    </span>
                                ))}
                                {steps.length > visible.length && (
                                    <span className="text-xs text-gray-400">→ +{steps.length - visible.length} más</span>
                                )}
                            </span>
                        )}
                    </div>
                </div>
            </div>

            <div className="px-5 sm:px-6 py-3 bg-gray-50/60 border-t border-gray-100 flex items-center justify-between gap-3">
                <div className="flex items-center gap-4 text-[11px] text-gray-500">
                    <span><strong className="text-gray-700 tabular-nums">{automation.execution_count}</strong> ejecuciones</span>
                    <span className="hidden sm:inline">Última: {relativeDate(automation.last_executed_at)}</span>
                    <span className="hidden md:inline"><strong className="text-gray-700 tabular-nums">{automation.steps_count}</strong> pasos</span>
                </div>
                <div className="flex items-center gap-1">
                    <Link href={route('automations.edit', automation.id)} className="px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors">
                        Editar
                    </Link>
                    <Link href={route('automations.logs', automation.id)} className="px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:text-sky-700 hover:bg-sky-50 rounded-lg transition-colors">
                        Historial
                    </Link>
                    <button
                        onClick={() => {
                            if (confirm(`¿Eliminar «${automation.name}»? También se borra su historial.`)) {
                                router.delete(route('automations.destroy', automation.id));
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

export default function Index({ automations, recipes, oferta }) {
    const { flash, errors } = usePage().props;
    const [showRecipes, setShowRecipes] = useState(automations.length === 0);

    const genericas = recipes.filter((r) => r.source !== 'oferta');
    const deOferta = recipes.filter((r) => r.source === 'oferta');

    const activeCount = automations.filter((a) => a.is_active).length;
    const totalRuns = automations.reduce((sum, a) => sum + a.execution_count, 0);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Automatizaciones</h2>}>
            <Head title="Automatizaciones" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Automatizaciones</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Una automatización es una regla: <strong className="text-gray-700">cuando</strong> pasa algo en WhatsApp,
                            <strong className="text-gray-700"> entonces</strong> el CRM hace estos pasos solo.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setShowRecipes((v) => !v)}
                            className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all w-fit"
                        >
                            {showRecipes ? 'Ocultar plantillas' : 'Ver plantillas'}
                        </button>
                        <Link
                            href={route('automations.create')}
                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20 flex items-center gap-1.5 w-fit"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Crear desde cero
                        </Link>
                    </div>
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
                {errors?.steps && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm">{errors.steps}</div>
                )}

                {showRecipes && (
                    <div className="space-y-5">
                        {/* Generadas con la oferta académica real: van primero porque
                            son las que llegan con los datos ya puestos. */}
                        {deOferta.length > 0 && (
                            <div className="rounded-2xl border border-sky-200 bg-sky-50/40 p-5 sm:p-6">
                                <div className="mb-4">
                                    <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                        Con tu oferta académica
                                        <span className="px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 text-[10px] font-bold ring-1 ring-sky-200">
                                            {oferta?.programas} programas · {oferta?.areas} áreas
                                        </span>
                                    </h3>
                                    <p className="text-xs text-gray-500 mt-1 leading-relaxed">
                                        Generadas con los programas, precios, fechas, módulos y docentes que hay ahora mismo en la base ESAM
                                        — la misma que alimenta la base de conocimiento de la IA.
                                        <strong className="text-gray-700"> Al aplicarlas, los textos quedan fijos:</strong> si la oferta cambia,
                                        vuelve a aplicar la plantilla o edita el mensaje a mano.
                                    </p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {deOferta.map((r) => <RecipeCard key={r.slug} recipe={r} />)}
                                </div>
                            </div>
                        )}

                        {oferta && !oferta.disponible && (
                            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                <strong>No hay plantillas con tu oferta académica.</strong>{' '}
                                {oferta.error
                                    ? 'La base ESAM devolvió un error al consultarla.'
                                    : 'O la base ESAM no responde, o no hay ningún programa con inscripciones abiertas.'}
                                {' '}Las plantillas genéricas de abajo funcionan igual.
                                {oferta.error && (
                                    <code className="block mt-2 text-[11px] font-mono bg-amber-100 rounded-lg px-2.5 py-1.5 break-words">
                                        {oferta.error}
                                    </code>
                                )}
                            </div>
                        )}

                        <div className="rounded-2xl border border-emerald-100 bg-emerald-50/40 p-5 sm:p-6">
                            <div className="mb-4">
                                <h3 className="text-base font-bold text-gray-900">Plantillas genéricas</h3>
                                <p className="text-xs text-gray-500 mt-0.5">
                                    Se abre el editor con todo armado. Cambias los textos, la pruebas y recién ahí la activas — nada se envía hasta que la actives.
                                </p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {genericas.map((r) => <RecipeCard key={r.slug} recipe={r} />)}
                            </div>
                        </div>
                    </div>
                )}

                {automations.length > 0 && (
                    <div className="grid grid-cols-3 gap-4">
                        {[
                            { label: 'Activas', value: activeCount, hint: 'corriendo ahora' },
                            { label: 'Pausadas', value: automations.length - activeCount, hint: 'no se ejecutan' },
                            { label: 'Ejecuciones', value: totalRuns, hint: 'desde siempre' },
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
                    {automations.map((a) => <AutomationCard key={a.id} automation={a} />)}

                    {automations.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 py-16 text-center">
                            <div className="mx-auto w-14 h-14 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-4">
                                <svg className="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                </svg>
                            </div>
                            <p className="text-sm font-medium text-gray-600">Todavía no tienes automatizaciones</p>
                            <p className="text-xs text-gray-400 mt-1">Elige una plantilla de arriba — la de bienvenida es la más fácil para empezar</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

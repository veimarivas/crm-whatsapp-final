import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FreeCanvas, NodeCard } from '@/Components/WorkflowCanvas';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * Editor de automatizaciones como lienzo de workflow, al estilo HubSpot:
 * el disparador arriba, los pasos encadenados hacia abajo y las condiciones
 * **abriendo el árbol** en dos ramas etiquetadas (No / Sí), cada una hasta su
 * propia bandera de fin.
 *
 * El cambio respecto del lienzo anterior no es cosmético: antes las dos ramas
 * iban en dos columnas *dentro* de la tarjeta de condición, así que en el
 * segundo nivel el ancho se partía otra vez y el recorrido se volvía ilegible.
 */

const STEP_META = {
    send_message: {
        label: 'Enviar mensaje',
        help: 'Manda un WhatsApp al contacto. Admite {name}, {phone}, {email} y {company}.',
        gradient: 'from-emerald-500 to-teal-600',
        chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
    },
    add_tag: {
        label: 'Añadir etiqueta',
        help: 'Etiqueta al contacto para poder filtrarlo o segmentarlo después.',
        gradient: 'from-blue-500 to-indigo-600',
        chip: 'bg-blue-50 text-blue-700 ring-blue-200',
        icon: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
    },
    remove_tag: {
        label: 'Quitar etiqueta',
        help: 'Le saca una etiqueta al contacto.',
        gradient: 'from-gray-400 to-gray-500',
        chip: 'bg-gray-100 text-gray-600 ring-gray-200',
        icon: 'M6 18L18 6M6 6l12 12',
    },
    condition: {
        label: 'Condición (Sí / No)',
        help: 'Parte el camino en dos: se ejecuta una rama u otra, nunca las dos.',
        gradient: 'from-purple-500 to-violet-600',
        chip: 'bg-purple-50 text-purple-700 ring-purple-200',
        icon: 'M6 3v12m0 0a3 3 0 103 3M6 15a3 3 0 113-3m0 0h6a3 3 0 003-3V3',
    },
    wait: {
        label: 'Esperar',
        help: 'Pausa la automatización y la retoma más tarde. Todo lo que sigue ocurre después.',
        gradient: 'from-amber-400 to-orange-500',
        chip: 'bg-amber-50 text-amber-700 ring-amber-200',
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    webhook: {
        label: 'Webhook',
        help: 'Avisa a otro sistema con un POST con los datos del contacto.',
        gradient: 'from-sky-500 to-blue-600',
        chip: 'bg-sky-50 text-sky-700 ring-sky-200',
        icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
    },
};

const TRIGGERS = {
    inbound_message: {
        label: 'Cualquier mensaje entrante',
        help: 'Se dispara con cada mensaje que envía un contacto. Úsalo con una condición adentro, si no se ejecuta demasiado.',
        gradient: 'from-blue-500 to-indigo-600',
    },
    new_contact: {
        label: 'Contacto nuevo',
        help: 'Solo la primera vez que un número escribe. Ideal para la bienvenida.',
        gradient: 'from-emerald-500 to-teal-600',
    },
    keyword: {
        label: 'Mensaje con palabra clave',
        help: 'Cuando el mensaje contiene alguna de las palabras que definas. No distingue mayúsculas y busca dentro de la frase.',
        gradient: 'from-amber-500 to-orange-600',
    },
};

const CONDITION_FIELDS = {
    message_text: 'el texto del mensaje',
    contact_name: 'el nombre del contacto',
    contact_email: 'el email del contacto',
    contact_company: 'la empresa del contacto',
    has_tag: 'las etiquetas del contacto',
};

const OPERATORS = {
    contains: 'contiene',
    equals: 'es igual a',
    not_equals: 'es distinto de',
    empty: 'está vacío',
    not_empty: 'no está vacío',
};

const WAIT_PRESETS = [
    { minutes: 5, label: '5 min' },
    { minutes: 60, label: '1 hora' },
    { minutes: 240, label: '4 horas' },
    { minutes: 1440, label: '1 día' },
    { minutes: 4320, label: '3 días' },
];

const inputBase =
    'w-full px-3.5 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';

function newStep(type) {
    const base = { type, config: {}, children_yes: [], children_no: [] };
    if (type === 'wait') base.config = { minutes: 60 };
    if (type === 'condition') base.config = { field: 'message_text', operator: 'contains', value: '' };
    return base;
}

function humanMinutes(minutes) {
    const m = Math.max(1, Number(minutes) || 60);
    if (m < 60) return `${m} min`;
    if (m % 1440 === 0) return `${m / 1440} ${m / 1440 === 1 ? 'día' : 'días'}`;
    if (m % 60 === 0) return `${m / 60} ${m / 60 === 1 ? 'hora' : 'horas'}`;
    return `${Math.floor(m / 60)} h ${m % 60} min`;
}

/** Qué le falta a un paso para poder ejecutarse. Se muestra en el propio nodo. */
function stepProblem(step) {
    const c = step.config ?? {};
    switch (step.type) {
        case 'send_message':
            return (c.text ?? '').trim() === '' ? 'Escribe el mensaje que se va a enviar' : null;
        case 'add_tag':
        case 'remove_tag':
            return c.tag_id ? null : 'Elige la etiqueta';
        case 'webhook':
            return (c.url ?? '').startsWith('http') ? null : 'Falta una URL válida (https://…)';
        case 'condition':
            if (c.field === 'has_tag') return c.tag_id ? null : 'Elige la etiqueta a comprobar';
            return ['empty', 'not_empty'].includes(c.operator) || (c.value ?? '').trim() !== ''
                ? null
                : 'Escribe con qué comparar';
        default:
            return null;
    }
}

function countProblems(steps) {
    return steps.reduce(
        (n, s) =>
            n +
            (stepProblem(s) ? 1 : 0) +
            countProblems(s.children_yes ?? []) +
            countProblems(s.children_no ?? []),
        0,
    );
}

function countSteps(steps) {
    return steps.reduce((n, s) => n + 1 + countSteps(s.children_yes ?? []) + countSteps(s.children_no ?? []), 0);
}

/* ---------------------------------------------------------------- config de cada tipo */

function StepConfig({ step, onChange, tags }) {
    const config = step.config ?? {};
    const set = (patch) => onChange({ ...step, config: { ...config, ...patch } });

    switch (step.type) {
        case 'send_message':
            return (
                <div className="space-y-2">
                    <textarea
                        rows={3}
                        placeholder="Hola {name}, gracias por escribirnos…"
                        value={config.text ?? ''}
                        onChange={(e) => set({ text: e.target.value })}
                        className={inputBase}
                    />
                    <div className="flex flex-wrap gap-1.5">
                        {['{name}', '{phone}', '{email}', '{company}'].map((v) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => set({ text: `${config.text ?? ''}${v}` })}
                                className="px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 text-[11px] font-mono hover:bg-emerald-100 hover:text-emerald-700 transition-colors"
                                title={`Insertar ${v}`}
                            >
                                {v}
                            </button>
                        ))}
                    </div>
                </div>
            );

        case 'add_tag':
        case 'remove_tag':
            return (
                <select value={config.tag_id ?? ''} onChange={(e) => set({ tag_id: e.target.value })} className={inputBase}>
                    <option value="">Selecciona etiqueta…</option>
                    {tags.map((t) => (
                        <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                </select>
            );

        case 'wait':
            return (
                <div className="space-y-2">
                    <div className="flex flex-wrap gap-1.5">
                        {WAIT_PRESETS.map((p) => (
                            <button
                                key={p.minutes}
                                type="button"
                                onClick={() => set({ minutes: p.minutes })}
                                className={`px-2.5 py-1 rounded-lg text-xs font-semibold transition-colors ${
                                    Number(config.minutes) === p.minutes
                                        ? 'bg-amber-500 text-white'
                                        : 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                                }`}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-2 text-xs text-gray-600">
                        <span>o exactamente</span>
                        <input
                            type="number"
                            min="1"
                            value={config.minutes ?? 60}
                            onChange={(e) => set({ minutes: Number(e.target.value) })}
                            className="w-24 px-3 py-1.5 border border-gray-200 rounded-xl text-sm bg-white text-center tabular-nums focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500"
                        />
                        <span>minutos ({humanMinutes(config.minutes)})</span>
                    </div>
                </div>
            );

        case 'webhook':
            return (
                <input
                    type="url"
                    placeholder="https://tu-servidor.com/hook"
                    value={config.url ?? ''}
                    onChange={(e) => set({ url: e.target.value })}
                    className={inputBase}
                />
            );

        case 'condition':
            return (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-xs font-semibold text-gray-500">Si</span>
                    <select
                        value={config.field ?? 'message_text'}
                        onChange={(e) => set({ field: e.target.value })}
                        className="px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-500"
                    >
                        {Object.entries(CONDITION_FIELDS).map(([v, l]) => (
                            <option key={v} value={v}>{l}</option>
                        ))}
                    </select>
                    {config.field === 'has_tag' ? (
                        <>
                            <span className="text-xs font-semibold text-gray-500">incluyen</span>
                            <select
                                value={config.tag_id ?? ''}
                                onChange={(e) => set({ tag_id: e.target.value })}
                                className="px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-500"
                            >
                                <option value="">Selecciona etiqueta…</option>
                                {tags.map((t) => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                        </>
                    ) : (
                        <>
                            <select
                                value={config.operator ?? 'contains'}
                                onChange={(e) => set({ operator: e.target.value })}
                                className="px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-500"
                            >
                                {Object.entries(OPERATORS).map(([v, l]) => (
                                    <option key={v} value={v}>{l}</option>
                                ))}
                            </select>
                            {!['empty', 'not_empty'].includes(config.operator) && (
                                <input
                                    placeholder="valor a buscar"
                                    value={config.value ?? ''}
                                    onChange={(e) => set({ value: e.target.value })}
                                    className="flex-1 min-w-[140px] px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-500"
                                />
                            )}
                        </>
                    )}
                </div>
            );

        default:
            return null;
    }
}

/* ---------------------------------------------------------------- lienzo */

function AddStepMenu({ onPick, onClose }) {
    return (
        <div className="absolute z-20 left-1/2 -translate-x-1/2 top-8 w-[19rem] rounded-2xl border border-gray-200 bg-white shadow-xl p-2">
            <div className="flex items-center justify-between px-2 py-1.5">
                <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400">Añadir paso</span>
                <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 text-xs">✕</button>
            </div>
            {Object.entries(STEP_META).map(([type, meta]) => (
                <button
                    key={type}
                    type="button"
                    onClick={() => { onPick(newStep(type)); onClose(); }}
                    className="w-full flex items-start gap-2.5 px-2 py-2 rounded-xl hover:bg-gray-50 text-left transition-colors"
                >
                    <div className={`w-7 h-7 rounded-lg bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white flex-shrink-0`}>
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d={meta.icon} />
                        </svg>
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs font-bold text-gray-900">{meta.label}</p>
                        <p className="text-[11px] text-gray-500 leading-snug">{meta.help}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

/** Conector vertical con el «+» que inserta un paso en esa posición exacta. */
/** Quita las posiciones manuales de todo el árbol: vuelve al layout automático. */
function clearPositions(steps) {
    return steps.map(({ x, y, ...step }) => ({
        ...step,
        children_yes: clearPositions(step.children_yes ?? []),
        children_no: clearPositions(step.children_no ?? []),
    }));
}

function StepNode({ step, index, total, onChange, onRemove, onMove, tags, dragHandleProps, dragging, onAddAfter, onAddBranch }) {
    const meta = STEP_META[step.type] ?? STEP_META.send_message;
    const problem = stepProblem(step);

    return (
        <NodeCard
            icon={meta.icon}
            gradient={meta.gradient}
            tone={problem ? 'warning' : undefined}
            className={dragging ? 'shadow-xl ring-2 ring-emerald-400/50' : ''}
        >
            {/* La manija de arrastre es la cabecera, no la tarjeta entera:
                adentro hay inputs y textareas, y arrastrar desde cualquier
                parte haría imposible seleccionar texto. */}
            <div className="flex items-center justify-between gap-2 px-4">
                <div className="min-w-0 flex items-center gap-2" {...(dragHandleProps ?? {})}>
                    <svg className="w-3.5 h-3.5 text-gray-300 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M7 4a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm5-12a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0z" />
                    </svg>
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-gray-900 truncate">{meta.label}</p>
                        <p className="text-[11px] text-gray-400">Paso {index + 1} de {total}</p>
                    </div>
                </div>
                <div className="flex items-center gap-0.5 flex-shrink-0">
                    <button type="button" onClick={() => onMove(-1)} disabled={index === 0} className="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg disabled:opacity-25 disabled:hover:bg-transparent transition-colors" title="Subir">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                        </svg>
                    </button>
                    <button type="button" onClick={() => onMove(1)} disabled={index === total - 1} className="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg disabled:opacity-25 disabled:hover:bg-transparent transition-colors" title="Bajar">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <button type="button" onClick={onRemove} className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar paso">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div className="px-4 py-3">
                <StepConfig step={step} onChange={onChange} tags={tags} />
                {problem && (
                    <p className="mt-2 text-[11px] font-semibold text-amber-600 flex items-center gap-1">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                        {problem}
                    </p>
                )}
            </div>

            {step.type === 'wait' && (
                <div className="px-4 pb-3 -mt-1">
                    <p className="text-[11px] text-amber-700 bg-amber-50 rounded-lg px-2.5 py-1.5">
                        Todo lo que sigue se ejecutará {humanMinutes(step.config?.minutes)} después.
                    </p>
                </div>
            )}

            {/* En el lienzo libre el «+» vive en la tarjeta y no en la línea:
                con las tarjetas movidas a mano no hay un punto medio fijo donde
                ponerlo. */}
            <div className="px-4 pb-3 flex flex-wrap gap-1.5">
                {step.type === 'condition' ? (
                    <>
                        <StepAddButton label="+ rama No" tone="no" onAdd={(s) => onAddBranch?.('children_no', s)} />
                        <StepAddButton label="+ rama Sí" tone="yes" onAdd={(s) => onAddBranch?.('children_yes', s)} />
                    </>
                ) : (
                    <StepAddButton label="+ paso siguiente" onAdd={(s) => onAddAfter?.(s)} />
                )}
            </div>
        </NodeCard>
    );
}

/** Botón que despliega el menú de pasos y lo inserta donde corresponda. */
function StepAddButton({ label, tone, onAdd }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className={`px-2.5 py-1 rounded-lg text-[11px] font-bold border transition-colors ${
                    tone === 'yes'
                        ? 'border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100'
                        : tone === 'no'
                            ? 'border-rose-200 text-rose-700 bg-rose-50 hover:bg-rose-100'
                            : 'border-gray-200 text-gray-600 bg-white hover:border-emerald-300 hover:text-emerald-700'
                }`}
            >
                {label}
            </button>
            {open && (
                <AddStepMenu
                    onPick={(step) => { onAdd(step); setOpen(false); }}
                    onClose={() => setOpen(false)}
                />
            )}
        </div>
    );
}

/* ------------------------------------------------- árbol ⇄ lienzo libre */

const NODE_W = 320;

const NODE_H = 250;

const GAP_X = 40;

const GAP_Y = 70;

/** Lee/escribe un paso dentro del árbol por su ruta (`[0,'children_no',1]`). */
function readPath(steps, path) {
    return path.reduce((acc, key) => (typeof key === 'number' ? acc[key] : acc[key] ?? []), steps);
}

function writePath(steps, path, updater) {
    if (path.length === 0) return updater(steps);
    const [head, ...rest] = path;

    if (typeof head === 'number') {
        return steps.map((s, i) => (i === head ? writePath(s, rest, updater) : s));
    }

    return { ...steps, [head]: writePath(steps[head] ?? [], rest, updater) };
}

/**
 * Aplana el árbol a nodos posicionables y calcula el **layout automático**.
 *
 * La posición guardada gana; la calculada es el respaldo. Así las
 * automatizaciones que ya existían —que no tienen coordenadas— se dibujan
 * ordenadas en vez de amontonarse en el origen, y en cuanto alguien mueve una
 * tarjeta esa posición manda.
 */
function flatten(steps, { path = [], x = 0, y = 0, nodes = [], edges = [], parentId = null, edgeLabel = null, edgeTone = null } = {}) {
    let cursorY = y;
    let right = x + NODE_W;
    let previousId = parentId;
    let previousLabel = edgeLabel;
    let previousTone = edgeTone;

    steps.forEach((step, i) => {
        const nodePath = [...path, i];
        const id = nodePath.join('/');

        nodes.push({
            id,
            path: nodePath,
            step,
            width: NODE_W,
            height: NODE_H,
            x: step.x ?? x,
            y: step.y ?? cursorY,
        });

        if (previousId !== null) {
            edges.push({ from: previousId, to: id, label: previousLabel, tone: previousTone });
        }

        previousLabel = null;
        previousTone = null;

        if (step.type === 'condition') {
            const childY = cursorY + NODE_H + GAP_Y;

            const no = flatten(step.children_no ?? [], {
                path: [...nodePath, 'children_no'], x, y: childY,
                nodes, edges, parentId: id, edgeLabel: 'No', edgeTone: 'no',
            });

            const yes = flatten(step.children_yes ?? [], {
                path: [...nodePath, 'children_yes'], x: no.right + GAP_X, y: childY,
                nodes, edges, parentId: id, edgeLabel: 'Sí', edgeTone: 'yes',
            });

            right = Math.max(right, yes.right);
            cursorY = Math.max(no.bottom, yes.bottom);
            // Tras una condición el tronco no sigue: cada rama es su camino.
            previousId = null;
        } else {
            previousId = id;
            cursorY += NODE_H + GAP_Y;
        }
    });

    return { nodes, edges, right, bottom: cursorY };
}

/* ---------------------------------------------------------------- panel de prueba */

const SIM_STATUS = {
    ok: { label: 'Se ejecuta', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    yes: { label: 'Rama Sí', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    no: { label: 'Rama No', cls: 'bg-red-50 text-red-700 ring-red-200' },
    wait: { label: 'Pausa', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    later: { label: 'Después', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    skipped: { label: 'No se ejecuta', cls: 'bg-gray-100 text-gray-500 ring-gray-200' },
    error: { label: 'Falla', cls: 'bg-red-50 text-red-700 ring-red-200' },
};

function TestPanel({ data, sampleContacts }) {
    const [messageText, setMessageText] = useState('Hola, quisiera información sobre precios');
    const [contactId, setContactId] = useState('');
    const [result, setResult] = useState(null);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState(null);

    const run = async () => {
        setRunning(true);
        setError(null);
        try {
            const { data: res } = await window.axios.post(route('automations.simulate'), {
                trigger_type: data.trigger_type,
                trigger_config: data.trigger_config,
                steps: data.steps,
                message_text: messageText,
                contact_id: contactId || null,
            });
            setResult(res);
        } catch (e) {
            setError(e.response?.data?.message ?? 'No se pudo simular. Revisa los pasos.');
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-4 border-b border-gray-100 flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-500 to-blue-600 flex items-center justify-center text-white shadow-sm">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c.251.023.501.05.75.082m-.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l1.57.393A9.065 9.065 0 0112 21a9.065 9.065 0 01-9.37-5.307L5 14.5" />
                    </svg>
                </div>
                <div>
                    <h3 className="text-sm font-bold text-gray-900">Probar sin enviar</h3>
                    <p className="text-[11px] text-gray-400">Simula el recorrido. No manda WhatsApp ni etiqueta a nadie.</p>
                </div>
            </div>

            <div className="p-4 space-y-3">
                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Mensaje de prueba</label>
                    <textarea
                        rows={2}
                        value={messageText}
                        onChange={(e) => setMessageText(e.target.value)}
                        placeholder="Lo que escribiría el cliente…"
                        className={inputBase}
                    />
                </div>

                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Contacto (opcional)</label>
                    <select value={contactId} onChange={(e) => setContactId(e.target.value)} className={inputBase}>
                        <option value="">Sin contacto — no reemplaza {'{name}'} ni comprueba etiquetas</option>
                        {sampleContacts.map((c) => (
                            <option key={c.id} value={c.id}>{c.name || c.phone} · {c.phone}</option>
                        ))}
                    </select>
                </div>

                <button
                    type="button"
                    onClick={run}
                    disabled={running}
                    className="w-full px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-sky-600 to-blue-600 rounded-xl hover:from-sky-500 hover:to-blue-500 disabled:opacity-50 transition-all shadow-lg shadow-sky-500/20"
                >
                    {running ? 'Simulando…' : 'Ejecutar simulación'}
                </button>

                {error && <p className="text-xs text-red-600 font-medium">{error}</p>}

                {result && (
                    <div className="space-y-2 pt-1">
                        <div className={`rounded-xl p-3 text-xs ring-1 ${
                            result.trigger.matched ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-gray-50 text-gray-600 ring-gray-200'
                        }`}>
                            <p className="font-bold">{result.trigger.matched ? 'El disparador se activa' : 'El disparador NO se activa'}</p>
                            <p className="mt-0.5 leading-snug">{result.trigger.reason}</p>
                        </div>

                        {result.steps.map((s, i) => {
                            const meta = STEP_META[s.type] ?? STEP_META.send_message;
                            const status = SIM_STATUS[s.status] ?? SIM_STATUS.ok;
                            return (
                                <div
                                    key={i}
                                    className={`rounded-xl border border-gray-100 bg-white p-2.5 ${s.status === 'skipped' ? 'opacity-55' : ''}`}
                                    style={{ marginLeft: `${s.depth * 12}px` }}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-[11px] font-bold text-gray-800">{meta.label}</span>
                                        <span className={`px-1.5 py-0.5 rounded-full text-[9px] font-bold ring-1 ${status.cls}`}>{status.label}</span>
                                    </div>
                                    {s.detail && <p className="text-[11px] text-gray-700 mt-1 whitespace-pre-wrap break-words">{s.detail}</p>}
                                    {s.note && <p className="text-[10px] text-gray-400 mt-1 leading-snug">{s.note}</p>}
                                </div>
                            );
                        })}

                        {result.trigger.matched && result.steps.length === 0 && (
                            <p className="text-xs text-gray-400">No hay pasos que ejecutar.</p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- página */

export default function Edit({ automation, steps, tags, sampleContacts = [], isDraft = false, recipeTitle = null }) {
    const { flash } = usePage().props;
    const isEdit = !!automation?.id;

    const { data, setData, post, patch, processing, errors } = useForm({
        name: automation?.name ?? '',
        description: automation?.description ?? '',
        trigger_type: automation?.trigger_type ?? 'inbound_message',
        trigger_config: automation?.trigger_config ?? {},
        steps: steps ?? [],
    });

    const problems = useMemo(() => countProblems(data.steps), [data.steps]);
    const totalSteps = useMemo(() => countSteps(data.steps), [data.steps]);
    const trigger = TRIGGERS[data.trigger_type];

    // Nodos y conexiones del lienzo. Se recalcula con cada cambio del árbol
    // porque insertar o borrar un paso cambia el layout de los que no se
    // movieron a mano.
    const canvas = useMemo(() => flatten(data.steps), [data.steps]);

    const submit = (e) => {
        e.preventDefault();
        isEdit
            ? patch(route('automations.update', automation.id), { preserveScroll: true })
            : post(route('automations.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">{isEdit ? 'Editar' : 'Nueva'} automatización</h2>}>
            <Head title="Automatización" />

            {/* Ancho completo y no `max-w-7xl`: el lienzo de un workflow con
                ramas necesita todo el espacio disponible, y recortarlo a 80rem
                obligaba a hacer scroll horizontal desde el segundo nivel. */}
            <div className="w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <Link href={route('automations.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Volver a automatizaciones
                    </Link>
                    <div className="flex flex-wrap items-center gap-2 mt-1">
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">{isEdit ? automation.name : data.name || 'Nueva automatización'}</h1>
                        {isEdit && (
                            <span className={`px-2.5 py-1 rounded-full text-xs font-bold ring-1 ${
                                automation.is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-600 ring-gray-200'
                            }`}>
                                {automation.is_active ? 'Activa' : 'Pausada'}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-gray-500 mt-1">
                        Arma el recorrido de arriba abajo. Los pasos se ejecutan en ese orden, uno tras otro.
                    </p>
                </div>

                {isDraft && (
                    <div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
                        <strong>Plantilla «{recipeTitle}» cargada.</strong> Todavía no está guardada: revisa los textos, pruébala en el panel de la derecha y dale a «Crear automatización».
                    </div>
                )}

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

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] items-start">
                    {/* ------------------------------------------------ lienzo */}
                    <form onSubmit={submit} id="automation-form" className="space-y-5">
                        <div className="rounded-2xl border border-gray-100 bg-gray-50/60 p-4 sm:p-6">
                            {/* Nodo disparador */}
                            <div className="flex flex-col items-center">
                                <span className="px-2.5 py-0.5 rounded-full bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider">Cuando</span>
                            </div>

                            <div className={`mt-2 rounded-2xl border-2 bg-white shadow-sm overflow-hidden ${data.trigger_type === 'keyword' && (data.trigger_config.keywords ?? []).length === 0 ? 'border-amber-300' : 'border-gray-200'}`}>
                                <div className="flex items-start gap-3 p-4">
                                    <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${trigger.gradient} flex items-center justify-center text-white shadow-lg flex-shrink-0`}>
                                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    </div>
                                    <div className="flex-1 min-w-0 space-y-2.5">
                                        <div>
                                            <p className="text-sm font-bold text-gray-900">Disparador</p>
                                            <p className="text-[11px] text-gray-500 leading-snug">{trigger.help}</p>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-3">
                                            {Object.entries(TRIGGERS).map(([value, t]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    onClick={() => setData('trigger_type', value)}
                                                    className={`px-3 py-2 rounded-xl text-xs font-semibold border transition-all text-left ${
                                                        data.trigger_type === value
                                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-2 ring-emerald-500/20'
                                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                                                    }`}
                                                >
                                                    {t.label}
                                                </button>
                                            ))}
                                        </div>

                                        {data.trigger_type === 'keyword' && (
                                            <div>
                                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                                                    Palabras clave <span className="font-normal normal-case tracking-normal">(separadas por coma)</span>
                                                </label>
                                                <input
                                                    placeholder="precio, catálogo, info"
                                                    value={(data.trigger_config.keywords ?? []).join(', ')}
                                                    onChange={(e) =>
                                                        setData('trigger_config', {
                                                            keywords: e.target.value.split(',').map((k) => k.trim()).filter(Boolean),
                                                        })
                                                    }
                                                    className={inputBase}
                                                />
                                                {(data.trigger_config.keywords ?? []).length === 0 && (
                                                    <p className="mt-1 text-[11px] font-semibold text-amber-600">Sin palabras clave no se disparará nunca.</p>
                                                )}
                                                {errors['trigger_config.keywords'] && (
                                                    <p className="mt-1 text-xs text-red-500 font-medium">{errors['trigger_config.keywords']}</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {errors.steps && (
                                <div className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700 font-medium">{errors.steps}</div>
                            )}

                            {/* Lienzo libre: las tarjetas se arrastran desde su
                                cabecera y la posición queda guardada con el
                                paso. Lo que nunca se movió usa el layout
                                automático del árbol. */}
                            <div className="mt-3 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StepAddButton
                                        label="+ Añadir paso"
                                        onAdd={(s) => setData('steps', [...data.steps, s])}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setData('steps', clearPositions(data.steps))}
                                        className="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-gray-500 border border-gray-200 bg-white hover:text-gray-800"
                                        title="Vuelve a acomodar todo con el layout automático"
                                    >
                                        Reordenar automáticamente
                                    </button>
                                    <span className="text-[11px] text-gray-400">
                                        Arrastrá una tarjeta desde su cabecera para moverla.
                                    </span>
                                </div>

                                <FreeCanvas
                                    nodes={canvas.nodes.map((node) => ({
                                        ...node,
                                        render: ({ dragHandleProps, dragging }) => (
                                            <StepNode
                                                step={node.step}
                                                index={node.path.at(-1)}
                                                total={readPath(data.steps, node.path.slice(0, -1)).length}
                                                tags={tags}
                                                dragHandleProps={dragHandleProps}
                                                dragging={dragging}
                                                onChange={(s) => setData('steps', writePath(data.steps, node.path, () => s))}
                                                onRemove={() => setData('steps', writePath(
                                                    data.steps,
                                                    node.path.slice(0, -1),
                                                    (lane) => lane.filter((_, i) => i !== node.path.at(-1)),
                                                ))}
                                                onMove={(dir) => setData('steps', writePath(
                                                    data.steps,
                                                    node.path.slice(0, -1),
                                                    (lane) => {
                                                        const i = node.path.at(-1);
                                                        const j = i + dir;
                                                        if (j < 0 || j >= lane.length) return lane;
                                                        const next = [...lane];
                                                        [next[i], next[j]] = [next[j], next[i]];

                                                        return next;
                                                    },
                                                ))}
                                                onAddAfter={(s) => setData('steps', writePath(
                                                    data.steps,
                                                    node.path.slice(0, -1),
                                                    (lane) => [
                                                        ...lane.slice(0, node.path.at(-1) + 1),
                                                        s,
                                                        ...lane.slice(node.path.at(-1) + 1),
                                                    ],
                                                ))}
                                                onAddBranch={(key, s) => setData('steps', writePath(
                                                    data.steps,
                                                    [...node.path, key],
                                                    (lane) => [...lane, s],
                                                ))}
                                            />
                                        ),
                                    }))}
                                    edges={canvas.edges}
                                    onMove={(id, x, y) => {
                                        const node = canvas.nodes.find((n) => n.id === id);
                                        if (!node) return;
                                        setData('steps', writePath(data.steps, node.path, (s) => ({ ...s, x, y })));
                                    }}
                                />

                                {data.steps.length === 0 && (
                                    <p className="text-xs text-gray-400 text-center py-2">
                                        Todavía no hay pasos. Usá «Añadir paso» para el primero.
                                    </p>
                                )}
                            </div>
                        </div>
                    </form>

                    {/* ------------------------------------------------ panel lateral */}
                    <div className="space-y-4 lg:sticky lg:top-6">
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3">
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Nombre</label>
                                <input
                                    form="automation-form"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                    placeholder="Ej.: Bienvenida a contacto nuevo"
                                    className={inputBase}
                                />
                                {errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{errors.name}</p>}
                            </div>
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                                    Descripción <span className="font-normal normal-case tracking-normal">(opcional)</span>
                                </label>
                                <input
                                    form="automation-form"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Para qué sirve"
                                    className={inputBase}
                                />
                            </div>

                            <div className="rounded-xl bg-gray-50 p-3 text-[11px] text-gray-600 flex items-center justify-between">
                                <span><strong className="text-gray-900 tabular-nums">{totalSteps}</strong> pasos</span>
                                {problems > 0 ? (
                                    <span className="font-semibold text-amber-600">{problems} sin completar</span>
                                ) : (
                                    <span className="font-semibold text-emerald-600">Todo listo</span>
                                )}
                            </div>

                            <button
                                type="submit"
                                form="automation-form"
                                disabled={processing}
                                className="w-full px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                            >
                                {isEdit ? 'Guardar cambios' : 'Crear automatización'}
                            </button>

                            {isEdit ? (
                                <p className="text-[11px] text-gray-400 text-center leading-snug">
                                    {automation.is_active
                                        ? 'Está activa: los cambios empiezan a aplicarse en cuanto guardes.'
                                        : 'Está pausada. Actívala desde el listado cuando la hayas probado.'}
                                </p>
                            ) : (
                                <p className="text-[11px] text-gray-400 text-center leading-snug">
                                    Se crea pausada. No enviará nada hasta que la actives desde el listado.
                                </p>
                            )}
                        </div>

                        <TestPanel data={data} sampleContacts={sampleContacts} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

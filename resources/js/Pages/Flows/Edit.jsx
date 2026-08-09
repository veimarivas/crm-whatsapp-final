import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FreeCanvas, TriggerCard } from '@/Components/WorkflowCanvas';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Editor de chatbots, con el mismo lenguaje visual que el de automatizaciones
 * (lienzo claro, nodo de arranque arriba, bandera de fin).
 *
 * **Pero no es un árbol y no se dibuja como uno.** Un flow es un GRAFO: dos
 * ramas pueden caer en el mismo paso y un paso puede volver atrás. Rendearlo
 * como el árbol de HubSpot obligaría a duplicar nodos —o a cortar los ciclos—
 * y mostraría una estructura que no es la real. Por eso los pasos van en una
 * columna ordenada por recorrido desde la entrada, y las salidas se muestran
 * como destinos con nombre. Los nodos a los que no llega nadie se agrupan
 * aparte: son el error clásico de un flow.
 */

const NODE_META = {
    send_message: { label: 'Mensaje', help: 'Manda un texto y sigue de largo, sin esperar respuesta.', gradient: 'from-emerald-500 to-teal-600', icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
    send_buttons: { label: 'Pregunta con botones', help: 'Hasta 3 botones. Espera a que el cliente toque uno.', gradient: 'from-blue-500 to-indigo-600', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' },
    send_list: { label: 'Pregunta con lista', help: 'Hasta 10 opciones en un desplegable. Espera a que elija una.', gradient: 'from-purple-500 to-violet-600', icon: 'M4 6h16M4 10h16M4 14h16M4 18h16' },
    collect_input: { label: 'Capturar dato', help: 'Pregunta algo abierto y guarda la respuesta en una variable.', gradient: 'from-amber-500 to-orange-600', icon: 'M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z' },
    condition: { label: 'Condición', help: 'Bifurca según lo que valga una variable capturada antes.', gradient: 'from-pink-500 to-rose-600', icon: 'M6 3v12m0 0a3 3 0 103 3M6 15a3 3 0 113-3m0 0h6a3 3 0 003-3V3' },
    set_tag: { label: 'Etiquetar', help: 'Le pone una etiqueta al contacto y sigue.', gradient: 'from-sky-500 to-blue-600', icon: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z' },
    http_fetch: { label: 'Consultar API', help: 'Llama a una URL y guarda la respuesta en una variable.', gradient: 'from-cyan-500 to-teal-600', icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244' },
    handoff: { label: 'Pasar a un agente', help: 'Termina el bot y deja la conversación en pendiente para el equipo.', gradient: 'from-indigo-500 to-purple-600', icon: 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z' },
    end: { label: 'Fin', help: 'Cierra la conversación, con un mensaje de despedida opcional.', gradient: 'from-gray-500 to-gray-700', icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
};

const TRIGGERS = {
    keyword: { label: 'Palabra clave', help: 'Arranca cuando el mensaje del cliente contiene alguna de tus palabras.' },
    first_inbound_message: { label: 'Primer mensaje', help: 'Arranca solo la primera vez que ese número escribe.' },
    manual: { label: 'Manual', help: 'No arranca solo: se lanza desde la API o el código.' },
};

const TEXT_NODES = ['send_message', 'send_buttons', 'send_list', 'collect_input'];
const WAITING_NODES = ['send_buttons', 'send_list', 'collect_input'];
const TERMINAL_NODES = ['handoff', 'end'];

const inputBase = 'w-full px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';
const smallInput = 'px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';

const slug = (s) => (s ?? '').toLowerCase().replace(/[^a-z0-9_]/g, '_');

/* ---------------------------------------------------------------- grafo */

/** Salidas de un nodo, con el setter que reescribe esa conexión. */
function edgesOf(node) {
    const c = node.config ?? {};
    const setConfig = (patch) => ({ ...node, config: { ...c, ...patch } });
    const setOption = (listKey, i, next) => ({
        ...node,
        config: { ...c, [listKey]: (c[listKey] ?? []).map((o, idx) => (idx === i ? { ...o, next } : o)) },
    });

    switch (node.node_type) {
        case 'send_message':
        case 'set_tag':
        case 'http_fetch':
        case 'collect_input':
            return [{ id: 'next', label: 'Después', target: c.next, set: (next) => setConfig({ next }) }];
        case 'condition':
            return [
                { id: 'yes', label: 'Si se cumple', target: c.next_yes, tone: 'yes', set: (next) => setConfig({ next_yes: next }) },
                { id: 'no', label: 'Si no', target: c.next_no, tone: 'no', set: (next) => setConfig({ next_no: next }) },
            ];
        case 'send_buttons':
            return (c.buttons ?? []).map((b, i) => ({
                id: `b${i}`, label: b.title || b.id || `Botón ${i + 1}`, target: b.next,
                set: (next) => setOption('buttons', i, next),
            }));
        case 'send_list':
            return (c.rows ?? []).map((r, i) => ({
                id: `r${i}`, label: r.title || r.id || `Opción ${i + 1}`, target: r.next,
                set: (next) => setOption('rows', i, next),
            }));
        default:
            return [];
    }
}

/** Orden de lectura: recorrido en anchura desde la entrada; el resto queda huérfano. */
function orderGraph(nodes, entryKey) {
    const byKey = new Map(nodes.map((n) => [n.node_key, n]));
    const reachable = [];
    const seen = new Set();
    const queue = byKey.has(entryKey) ? [entryKey] : [];

    while (queue.length) {
        const key = queue.shift();
        if (seen.has(key) || !byKey.has(key)) continue;
        seen.add(key);
        const node = byKey.get(key);
        reachable.push(node);
        for (const edge of edgesOf(node)) {
            if (edge.target && !seen.has(edge.target)) queue.push(edge.target);
        }
    }

    return { reachable, orphans: nodes.filter((n) => !seen.has(n.node_key)) };
}

/**
 * Reapunta a `to` todas las conexiones de `node` que iban a `from`.
 * Se recalculan los edges en cada vuelta porque cada `set` devuelve un
 * nodo nuevo: usar la lista original pisaría los cambios anteriores.
 */
function rewireTo(node, from, to) {
    let current = node;

    for (let guard = 0; guard < 20; guard++) {
        const edge = edgesOf(current).find((e) => e.target === from);
        if (!edge) break;
        current = edge.set(to);
    }

    return current;
}

/** Quién apunta a cada nodo — sirve para no romper conexiones sin darse cuenta. */
function incomingMap(nodes) {
    const map = {};
    for (const node of nodes) {
        for (const edge of edgesOf(node)) {
            if (!edge.target) continue;
            (map[edge.target] ??= []).push(node.node_key);
        }
    }
    return map;
}

/** Qué le falta a un nodo para funcionar. */
function nodeProblems(node, keys) {
    const c = node.config ?? {};
    const out = [];

    if (TEXT_NODES.includes(node.node_type) && !(c.text ?? '').trim()) {
        out.push('Falta el texto que se envía');
    }
    if (node.node_type === 'set_tag' && !c.tag_id) {
        out.push('Elige la etiqueta');
    }
    if (node.node_type === 'http_fetch' && !(c.url ?? '').startsWith('http')) {
        out.push('Falta una URL válida');
    }
    if (node.node_type === 'collect_input' && !(c.var ?? '').trim()) {
        out.push('Falta el nombre de la variable donde guardar la respuesta');
    }
    if (node.node_type === 'condition' && !(c.var ?? '').trim()) {
        out.push('Falta la variable a comprobar');
    }
    if (WAITING_NODES.includes(node.node_type) && node.node_type !== 'collect_input') {
        const options = node.node_type === 'send_buttons' ? c.buttons ?? [] : c.rows ?? [];
        if (options.length === 0) out.push('Sin opciones: el cliente no tendría qué elegir');
        if (options.some((o) => !(o.title ?? '').trim())) out.push('Hay una opción sin texto');
    }
    for (const edge of edgesOf(node)) {
        if (edge.target && !keys.includes(edge.target)) {
            out.push(`«${edge.label}» apunta a «${edge.target}», que ya no existe`);
        }
    }

    return out;
}

/* ---------------------------------------------------------------- piezas de UI */

/** Selector de destino que además puede CREAR el nodo siguiente y conectarlo. */
function TargetPicker({ value, onChange, onCreate, nodeKeys, label, tone }) {
    const [open, setOpen] = useState(false);
    const toneCls = tone === 'yes' ? 'text-emerald-700' : tone === 'no' ? 'text-red-700' : 'text-gray-500';

    return (
        <div className="relative flex flex-wrap items-center gap-1.5">
            <span className={`text-[11px] font-bold uppercase tracking-wider ${toneCls}`}>{label}</span>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value || null)} className={`${smallInput} max-w-[11rem]`}>
                <option value="">— termina acá —</option>
                {nodeKeys.map((k) => <option key={k} value={k}>{k}</option>)}
            </select>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="px-2 py-1 rounded-lg border border-dashed border-gray-300 text-[11px] font-semibold text-gray-500 hover:border-emerald-400 hover:text-emerald-700 hover:bg-emerald-50 transition-all"
                title="Crear el siguiente paso y conectarlo acá"
            >
                + nuevo
            </button>
            {open && (
                <div className="absolute z-20 top-8 left-0 w-[17rem] rounded-2xl border border-gray-200 bg-white shadow-xl p-2">
                    <div className="flex items-center justify-between px-2 py-1">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400">Crear y conectar</span>
                        <button type="button" onClick={() => setOpen(false)} className="text-gray-400 hover:text-gray-700 text-xs">✕</button>
                    </div>
                    {Object.entries(NODE_META).map(([type, meta]) => (
                        <button
                            key={type}
                            type="button"
                            onClick={() => { onCreate(type); setOpen(false); }}
                            className="w-full flex items-start gap-2.5 px-2 py-1.5 rounded-xl hover:bg-gray-50 text-left transition-colors"
                        >
                            <div className={`w-6 h-6 rounded-lg bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white flex-shrink-0`}>
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d={meta.icon} />
                                </svg>
                            </div>
                            <div className="min-w-0">
                                <p className="text-[11px] font-bold text-gray-900">{meta.label}</p>
                                <p className="text-[10px] text-gray-500 leading-snug">{meta.help}</p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function OptionsEditor({ options, onChange, label, max, hint, withDescription = false }) {
    const update = (i, patch) => onChange(options.map((o, idx) => (idx === i ? { ...o, ...patch } : o)));

    return (
        <div className="mt-3 rounded-xl bg-gray-50 border border-gray-200 p-3 space-y-2">
            <div className="flex items-baseline justify-between">
                <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">{label}</p>
                <p className="text-[10px] text-gray-400">{hint}</p>
            </div>
            {options.map((opt, i) => (
                <div key={i} className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[11px] text-gray-400 w-4 tabular-nums">{i + 1}.</span>
                    <input
                        placeholder="Lo que ve el cliente"
                        value={opt.title ?? ''}
                        onChange={(e) => update(i, {
                            title: e.target.value,
                            // El id es interno: se deriva del título mientras nadie lo toque a mano.
                            id: opt.id && opt.id !== slug(opt.title ?? '') ? opt.id : slug(e.target.value).slice(0, 24),
                        })}
                        className={`flex-1 min-w-[10rem] ${smallInput}`}
                    />
                    <button type="button" onClick={() => onChange(options.filter((_, idx) => idx !== i))} className="p-1 text-gray-400 hover:text-red-500 rounded" title="Quitar opción">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    {withDescription && (
                        <input
                            placeholder="Subtítulo (opcional) — se ve debajo del título en WhatsApp"
                            value={opt.description ?? ''}
                            onChange={(e) => update(i, { description: e.target.value })}
                            className={`w-full ml-6 ${smallInput} text-gray-500`}
                        />
                    )}
                </div>
            ))}
            {options.length < max && (
                <button type="button" onClick={() => onChange([...options, { id: '', title: '', next: null }])} className="text-xs text-emerald-600 hover:text-emerald-700 font-semibold flex items-center gap-1">
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Añadir opción
                </button>
            )}
        </div>
    );
}

/**
 * Tarjeta de un paso del chatbot.
 *
 * Se llama `FlowNodeCard` y no `NodeCard` para no chocar con la primitiva del
 * lienzo compartido (`Components/WorkflowCanvas`), que este archivo también
 * importa.
 */
function FlowNodeCard({ node, index, onChange, onRemove, onCreateNext, nodeKeys, tags, isEntry, onMakeEntry, incoming, orphan, dragHandleProps, dragging }) {
    const config = node.config ?? {};
    const setConfig = (patch) => onChange({ ...node, config: { ...config, ...patch } });
    const meta = NODE_META[node.node_type] ?? NODE_META.send_message;
    const problems = nodeProblems(node, nodeKeys);
    const edges = edgesOf(node);

    return (
        <div
            id={`node-${node.node_key}`}
            className={`rounded-2xl border bg-white shadow-sm hover:shadow-md transition-all scroll-mt-24 ${
                dragging ? 'shadow-xl ring-2 ring-emerald-400/50' : ''
            } ${
                orphan ? 'border-amber-300' : isEntry ? 'border-emerald-300 ring-2 ring-emerald-500/20' : problems.length ? 'border-amber-200' : 'border-gray-200'
            }`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2 px-4 pt-3.5">
                {/* Manija de arrastre: la cabecera, no la tarjeta entera —
                    adentro hay inputs y textareas. */}
                <div className="flex items-center gap-2.5 min-w-0" {...(dragHandleProps ?? {})}>
                    <div className={`w-8 h-8 rounded-lg bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white shadow-sm flex-shrink-0`}>
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d={meta.icon} />
                        </svg>
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-gray-900">{meta.label}</p>
                        <p className="text-[11px] text-gray-400">
                            {isEntry ? 'Empieza acá' : index != null ? `Paso ${index}` : 'Suelto'}
                            {incoming.length > 0 && ` · llega desde ${incoming.join(', ')}`}
                        </p>
                    </div>
                    {isEntry && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5 text-[10px] font-bold ring-1 ring-emerald-200">
                            <span className="w-1 h-1 rounded-full bg-emerald-500" />
                            ENTRADA
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-1.5">
                    {!isEntry && (
                        <button type="button" onClick={onMakeEntry} className="px-2 py-1 rounded-lg text-[11px] font-semibold text-gray-500 hover:text-emerald-700 hover:bg-emerald-50 transition-colors" title="Hacer que la conversación empiece por este nodo">
                            Empezar acá
                        </button>
                    )}
                    <input
                        value={node.node_key}
                        onChange={(e) => onChange({ ...node, node_key: slug(e.target.value) })}
                        className={`w-32 ${smallInput} font-mono`}
                        title="Nombre interno del paso — es lo que ven las conexiones"
                    />
                    <button type="button" onClick={onRemove} className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar paso">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div className="px-4 py-3 space-y-2">
                {TEXT_NODES.includes(node.node_type) && (
                    <textarea
                        rows={2}
                        placeholder="Lo que le llega al cliente — admite {name} y las variables que capturaste"
                        value={config.text ?? ''}
                        onChange={(e) => setConfig({ text: e.target.value })}
                        className={inputBase}
                    />
                )}

                {TERMINAL_NODES.includes(node.node_type) && (
                    <textarea
                        rows={2}
                        placeholder="Mensaje de despedida (opcional)"
                        value={config.message ?? ''}
                        onChange={(e) => setConfig({ message: e.target.value })}
                        className={inputBase}
                    />
                )}

                {node.node_type === 'send_buttons' && (
                    <OptionsEditor options={config.buttons ?? []} onChange={(buttons) => setConfig({ buttons })} max={3} label="Botones" hint="máx. 3 · WhatsApp corta a 20 caracteres" />
                )}

                {node.node_type === 'send_list' && (
                    <>
                        <input placeholder="Texto del botón que abre la lista (ej. Ver opciones)" value={config.button_label ?? ''} onChange={(e) => setConfig({ button_label: e.target.value })} className={`w-full ${smallInput}`} />
                        <OptionsEditor options={config.rows ?? []} onChange={(rows) => setConfig({ rows })} max={10} label="Opciones de la lista" hint="máx. 10 · título 24 car." withDescription />
                    </>
                )}

                {node.node_type === 'collect_input' && (
                    <label className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                        <span className="font-medium">Guardar la respuesta en</span>
                        <span className="text-gray-400 font-mono">{'{'}</span>
                        <input value={config.var ?? ''} onChange={(e) => setConfig({ var: slug(e.target.value) })} placeholder="correo" className={`w-28 ${smallInput} font-mono`} />
                        <span className="text-gray-400 font-mono">{'}'}</span>
                    </label>
                )}

                {node.node_type === 'condition' && (
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <span className="text-gray-600 font-medium">Si la variable</span>
                        <input value={config.var ?? ''} onChange={(e) => setConfig({ var: slug(e.target.value) })} placeholder="correo" className={`w-28 ${smallInput} font-mono`} />
                        <select value={config.operator ?? 'equals'} onChange={(e) => setConfig({ operator: e.target.value })} className={smallInput}>
                            <option value="equals">es igual a</option>
                            <option value="contains">contiene</option>
                            <option value="not_empty">no está vacía</option>
                        </select>
                        {config.operator !== 'not_empty' && (
                            <input value={config.value ?? ''} onChange={(e) => setConfig({ value: e.target.value })} placeholder="valor" className={`w-32 ${smallInput}`} />
                        )}
                    </div>
                )}

                {node.node_type === 'set_tag' && (
                    <select value={config.tag_id ?? ''} onChange={(e) => setConfig({ tag_id: e.target.value })} className={`${smallInput} w-56`}>
                        <option value="">Selecciona etiqueta…</option>
                        {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                )}

                {node.node_type === 'http_fetch' && (
                    <div className="space-y-2">
                        <input type="url" placeholder="https://api.ejemplo.com/dato" value={config.url ?? ''} onChange={(e) => setConfig({ url: e.target.value })} className={`w-full ${smallInput}`} />
                        <label className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                            <span className="font-medium">Guardar la respuesta en</span>
                            <input value={config.var ?? ''} onChange={(e) => setConfig({ var: slug(e.target.value) })} placeholder="respuesta" className={`w-28 ${smallInput} font-mono`} />
                        </label>
                    </div>
                )}

                {WAITING_NODES.includes(node.node_type) && (
                    <p className="text-[11px] text-blue-700 bg-blue-50 rounded-lg px-2.5 py-1.5">
                        Acá el bot se detiene y espera la respuesta del cliente.
                    </p>
                )}

                {problems.length > 0 && (
                    <ul className="space-y-0.5">
                        {problems.map((p) => (
                            <li key={p} className="text-[11px] font-semibold text-amber-600 flex items-start gap-1">
                                <svg className="w-3.5 h-3.5 flex-shrink-0 mt-px" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                </svg>
                                {p}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {edges.length > 0 && (
                <div className="px-4 pb-3.5 pt-2 border-t border-gray-100 space-y-2">
                    {edges.map((edge) => (
                        <TargetPicker
                            key={edge.id}
                            label={edge.label}
                            tone={edge.tone}
                            value={edge.target}
                            nodeKeys={nodeKeys}
                            onChange={(next) => onChange(edge.set(next))}
                            onCreate={(type) => onCreateNext(edge, type)}
                        />
                    ))}
                </div>
            )}

            {TERMINAL_NODES.includes(node.node_type) && (
                <div className="px-4 pb-3.5 pt-2 border-t border-gray-100">
                    <p className="text-[11px] text-gray-400">
                        {node.node_type === 'handoff' ? 'La conversación queda en «pendiente» para el equipo.' : 'La conversación termina acá.'}
                    </p>
                </div>
            )}
        </div>
    );
}

/* ---------------------------------------------------------------- chat de prueba */

const NOTE_TONES = {
    info: 'bg-gray-100 text-gray-600',
    warn: 'bg-amber-50 text-amber-700',
    error: 'bg-red-50 text-red-700',
    success: 'bg-emerald-50 text-emerald-700',
};

const STATUS_LABEL = {
    awaiting: { text: 'Esperando tu respuesta', cls: 'bg-blue-50 text-blue-700 ring-blue-200' },
    ended: { text: 'Conversación terminada', cls: 'bg-gray-100 text-gray-600 ring-gray-200' },
    handoff: { text: 'Pasó a un agente', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    failed: { text: 'El flow se rompió', cls: 'bg-red-50 text-red-700 ring-red-200' },
};

function ChatTester({ data, sampleContacts }) {
    const [replies, setReplies] = useState([]);
    const [contactId, setContactId] = useState('');
    const [draft, setDraft] = useState('');
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [running, setRunning] = useState(false);
    const scroller = useRef(null);

    // Se reproduce la conversación entera en cada cambio: el simulador no
    // guarda estado, así el chat siempre refleja el grafo que está en pantalla.
    // Con debounce, porque `data.nodes` cambia de identidad en cada tecla.
    useEffect(() => {
        let cancelled = false;

        const timer = setTimeout(async () => {
            setRunning(true);
            setError(null);
            try {
                const { data: res } = await window.axios.post(route('flows.simulate'), {
                    entry_node_id: data.entry_node_id,
                    fallback_policy: data.fallback_policy,
                    nodes: data.nodes,
                    replies,
                    contact_id: contactId || null,
                });
                if (!cancelled) setResult(res);
            } catch (e) {
                if (!cancelled) {
                    setResult(null);
                    setError(e.response?.data?.message ?? 'No se pudo simular. Revisa los pasos del chatbot.');
                }
            } finally {
                if (!cancelled) setRunning(false);
            }
        }, 400);

        return () => { cancelled = true; clearTimeout(timer); };
    }, [data.nodes, data.entry_node_id, data.fallback_policy, replies, contactId]);

    useEffect(() => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
    }, [result]);

    const send = (text) => {
        if (!text.trim()) return;
        setReplies((prev) => [...prev, text]);
        setDraft('');
    };

    const awaiting = result?.status === 'awaiting' ? result.awaiting : null;
    const status = result ? STATUS_LABEL[result.status] : null;

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
            <div className="p-4 border-b border-gray-100 flex items-start justify-between gap-2">
                <div className="flex items-center gap-2.5 min-w-0">
                    <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-sm flex-shrink-0">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div className="min-w-0">
                        <h3 className="text-sm font-bold text-gray-900">Probar el chatbot</h3>
                        <p className="text-[11px] text-gray-400">Conversa con él. No se envía nada por WhatsApp.</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => { setReplies([]); setDraft(''); }}
                    className="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-gray-500 hover:text-emerald-700 hover:bg-emerald-50 transition-colors flex-shrink-0"
                >
                    Reiniciar
                </button>
            </div>

            <div className="px-4 py-2.5 border-b border-gray-100">
                <select value={contactId} onChange={(e) => { setContactId(e.target.value); setReplies([]); }} className={`${smallInput} w-full`}>
                    <option value="">Sin contacto — no reemplaza {'{name}'}</option>
                    {sampleContacts.map((c) => (
                        <option key={c.id} value={c.id}>{c.name || c.phone} · {c.phone}</option>
                    ))}
                </select>
            </div>

            <div ref={scroller} className="flex-1 max-h-[26rem] overflow-y-auto bg-gray-50/70 p-3 space-y-2">
                {error && <p className="text-xs text-red-600 font-medium">{error}</p>}

                {result?.transcript.map((entry, i) => {
                    if (entry.from === 'system') {
                        return (
                            <p key={i} className={`text-[11px] rounded-lg px-2.5 py-1.5 leading-snug ${NOTE_TONES[entry.tone] ?? NOTE_TONES.info}`}>
                                {entry.text}
                            </p>
                        );
                    }

                    const isUser = entry.from === 'user';
                    return (
                        <div key={i} className={`flex ${isUser ? 'justify-end' : 'justify-start'}`}>
                            <div className={`max-w-[85%] rounded-2xl px-3 py-2 shadow-sm ${
                                isUser ? 'bg-emerald-500 text-white rounded-br-sm' : 'bg-white border border-gray-200 text-gray-800 rounded-bl-sm'
                            }`}>
                                <p className="text-xs whitespace-pre-wrap break-words">{entry.text || <span className="italic opacity-60">(sin texto)</span>}</p>
                                {entry.options?.length > 0 && (
                                    <div className="mt-2 pt-2 border-t border-gray-100 space-y-1">
                                        {entry.options.map((o, j) => (
                                            <p key={j} className={`text-[11px] font-semibold text-center rounded-lg py-1 ${o.dangling ? 'bg-amber-50 text-amber-700' : 'bg-gray-50 text-sky-700'}`}>
                                                {o.title || '(sin texto)'}{o.dangling && ' · sin destino'}
                                            </p>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}

                {result?.error && (
                    <p className="text-[11px] rounded-lg px-2.5 py-1.5 bg-red-50 text-red-700 font-medium">{result.error}</p>
                )}
                {running && !result && <p className="text-xs text-gray-400">Simulando…</p>}
            </div>

            <div className="border-t border-gray-100 p-3 space-y-2">
                {status && (
                    <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-bold ring-1 ${status.cls}`}>{status.text}</span>
                )}

                {awaiting?.options?.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {awaiting.options.map((o, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => send(o.id || o.title)}
                                className="px-2.5 py-1 rounded-lg bg-sky-50 text-sky-700 text-[11px] font-semibold hover:bg-sky-100 transition-colors"
                            >
                                {o.title || o.id}
                            </button>
                        ))}
                    </div>
                )}

                <div className="flex items-center gap-2">
                    <input
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); send(draft); } }}
                        disabled={result?.status !== 'awaiting'}
                        placeholder={result?.status === 'awaiting' ? 'Responde como el cliente…' : 'La conversación terminó'}
                        className={`flex-1 ${smallInput} disabled:bg-gray-50 disabled:text-gray-400`}
                    />
                    <button
                        type="button"
                        onClick={() => send(draft)}
                        disabled={result?.status !== 'awaiting' || !draft.trim()}
                        className="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-500 disabled:opacity-40 transition-all"
                    >
                        Enviar
                    </button>
                </div>

                {result && Object.keys(result.vars ?? {}).length > 0 && (
                    <div className="rounded-lg bg-gray-50 p-2">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Variables capturadas</p>
                        {Object.entries(result.vars).map(([k, v]) => (
                            <p key={k} className="text-[11px] text-gray-600 font-mono truncate">{'{'}{k}{'}'} = {v}</p>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- página */

export default function Edit({ flow, nodes, tags, sampleContacts = [] }) {
    const { flash } = usePage().props;

    const { data, setData, patch, processing, errors } = useForm({
        name: flow.name,
        trigger_type: flow.trigger_type,
        trigger_config: flow.trigger_config ?? {},
        entry_node_id: flow.entry_node_id ?? '',
        fallback_policy: flow.fallback_policy ?? {},
        nodes: nodes ?? [],
    });

    const nodeKeys = useMemo(() => data.nodes.map((n) => n.node_key).filter(Boolean), [data.nodes]);
    const { reachable, orphans } = useMemo(() => orderGraph(data.nodes, data.entry_node_id), [data.nodes, data.entry_node_id]);
    const incoming = useMemo(() => incomingMap(data.nodes), [data.nodes]);
    const problemCount = useMemo(
        () => data.nodes.reduce((n, node) => n + (nodeProblems(node, nodeKeys).length > 0 ? 1 : 0), 0),
        [data.nodes, nodeKeys],
    );

    const replaceNode = (key, next) => setData('nodes', data.nodes.map((n) => (n.node_key === key ? next : n)));
    const removeNode = (key) => setData('nodes', data.nodes.filter((n) => n.node_key !== key));

    const freeKey = (type) => {
        let candidate = slug(type);
        let i = 1;
        while (nodeKeys.includes(candidate)) candidate = `${slug(type)}_${++i}`;
        return candidate;
    };

    /** Crea el nodo destino y lo engancha a la conexión de golpe. */
    const createNext = (fromKey, edge, type) => {
        const key = freeKey(type);
        const wired = edge.set(key);

        setData('nodes', [
            ...data.nodes.map((n) => (n.node_key === fromKey ? wired : n)),
            { node_key: key, node_type: type, config: {} },
        ]);

        // Al conectarlo, el nodo nuevo cae al final del recorrido: llevar al usuario hasta él.
        setTimeout(() => document.getElementById(`node-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 60);
    };

    const addLooseNode = (type) => {
        const key = freeKey(type);
        setData('nodes', [...data.nodes, { node_key: key, node_type: type, config: {} }]);
    };

    const submit = (e) => {
        e.preventDefault();
        patch(route('flows.update', flow.id), { preserveScroll: true });
    };

    /**
     * Nodos del lienzo.
     *
     * La posición guardada gana; `0,0` significa «nunca se movió» y entonces
     * se ubica en columna por orden de recorrido. Los huérfanos van a una
     * columna aparte a la derecha, para que se vea de un vistazo que están
     * desconectados.
     */
    const canvasNodes = useMemo(() => {
        const order = [...reachable, ...orphans];

        return order.map((node, i) => {
            const orphan = orphans.includes(node);
            const auto = orphan
                ? { x: 460, y: orphans.indexOf(node) * 320 }
                : { x: 40, y: reachable.indexOf(node) * 320 };

            return {
                id: node.node_key,
                width: 380,
                height: 300,
                x: node.position_x || auto.x,
                y: node.position_y || auto.y,
                render: ({ dragHandleProps, dragging }) =>
                    renderNode(node, orphan ? null : reachable.indexOf(node) + 1, orphan, { dragHandleProps, dragging }),
            };
        });
    }, [data.nodes, reachable, orphans]);

    /** Una flecha por cada salida configurada. */
    const canvasEdges = useMemo(
        () => data.nodes.flatMap((node) => edgesOf(node)
            .filter((edge) => edge.target)
            .map((edge) => ({
                from: node.node_key,
                to: edge.target,
                label: edge.label,
                tone: edge.label === 'Sí' ? 'yes' : edge.label === 'No' ? 'no' : undefined,
            }))),
        [data.nodes],
    );

    const renderNode = (node, index, orphan, canvasProps = {}) => (
        <FlowNodeCard
            {...canvasProps}
            // Se keyea por identidad y no por node_key: renombrar no debe
            // remontar la tarjeta (perdería el foco del input a media palabra).
            key={data.nodes.indexOf(node)}
            node={node}
            index={index}
            orphan={orphan}
            nodeKeys={nodeKeys}
            tags={tags}
            incoming={incoming[node.node_key] ?? []}
            isEntry={node.node_key === data.entry_node_id}
            onMakeEntry={() => setData('entry_node_id', node.node_key)}
            onChange={(next) => {
                // Renombrar un nodo reapunta solo las conexiones que lo señalaban:
                // si no, cambiar el nombre rompería el flow en silencio.
                if (next.node_key !== node.node_key) {
                    const rewired = data.nodes.map((n) =>
                        rewireTo(n === node ? next : n, node.node_key, next.node_key));

                    setData((prev) => ({
                        ...prev,
                        nodes: rewired,
                        entry_node_id: prev.entry_node_id === node.node_key ? next.node_key : prev.entry_node_id,
                    }));
                    return;
                }
                replaceNode(node.node_key, next);
            }}
            onRemove={() => removeNode(node.node_key)}
            onCreateNext={(edge, type) => createNext(node.node_key, edge, type)}
        />
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Editar chatbot</h2>}>
            <Head title={`Chatbot — ${flow.name}`} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <Link href={route('flows.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Volver a chatbots
                    </Link>
                    <div className="flex flex-wrap items-center gap-2 mt-1">
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">{flow.name}</h1>
                        <span className={`px-2.5 py-1 rounded-full text-xs font-bold ring-1 ${
                            flow.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-600 ring-gray-200'
                        }`}>
                            {flow.status === 'active' ? 'Activo' : 'Borrador'}
                        </span>
                    </div>
                    <p className="text-sm text-gray-500 mt-1">
                        Los pasos están ordenados según el recorrido de la conversación, no según cuándo los creaste.
                    </p>
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

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_23rem] items-start">
                    <form onSubmit={submit} id="flow-form" className="space-y-5">
                        {/* Disparador */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                            <div>
                                <p className="text-sm font-bold text-gray-900">¿Cuándo arranca el chatbot?</p>
                                <p className="text-[11px] text-gray-500">{TRIGGERS[data.trigger_type].help}</p>
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
                                        value={(data.trigger_config.keywords ?? []).join(', ')}
                                        onChange={(e) => setData('trigger_config', { keywords: e.target.value.split(',').map((k) => k.trim()).filter(Boolean) })}
                                        className={inputBase}
                                    />
                                    {(data.trigger_config.keywords ?? []).length === 0 && (
                                        <p className="mt-1 text-[11px] font-semibold text-amber-600">Sin palabras clave no arrancará nunca.</p>
                                    )}
                                    {errors['trigger_config.keywords'] && <p className="mt-1 text-xs text-red-500 font-medium">{errors['trigger_config.keywords']}</p>}
                                </div>
                            )}
                        </div>

                        {/* Grafo */}
                        <div className="rounded-2xl border border-slate-200 bg-[#f4f8fa] p-4 sm:p-5 space-y-3">
                            {errors.nodes && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700 font-medium">{errors.nodes}</div>}
                            {errors.entry_node_id && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700 font-medium">{errors.entry_node_id}</div>}

                            <div className="max-w-md mx-auto">
                                <TriggerCard
                                    title="La conversación arranca"
                                    description={(TRIGGERS[data.trigger_type]?.help) ?? 'Cuando se cumple el disparador configurado arriba.'}
                                />
                            </div>

                            <p className="text-[11px] text-gray-400 text-center">
                                Arrastrá una tarjeta desde su cabecera para moverla. Las flechas siguen las conexiones,
                                no la posición.
                            </p>

                            {/* Un flow es un GRAFO: dos ramas pueden caer en el
                                mismo paso y un paso puede volver atrás. Por eso
                                las conexiones se dibujan entre las tarjetas
                                donde estén, en vez de imponer un árbol. */}
                            <FreeCanvas
                                nodes={canvasNodes}
                                edges={canvasEdges}
                                onMove={(key, x, y) => setData('nodes', data.nodes.map((n) => (
                                    n.node_key === key ? { ...n, position_x: x, position_y: y } : n
                                )))}
                            />

                            {reachable.length === 0 && (
                                <p className="text-xs text-red-500 font-medium text-center py-4">
                                    El nodo de entrada «{data.entry_node_id || '—'}» no existe. Marca uno con «Empezar acá».
                                </p>
                            )}

                            {orphans.length > 0 && (
                                <div className="pt-3 border-t border-gray-200 space-y-3">
                                    <div className="rounded-xl bg-amber-50 border border-amber-200 p-3">
                                        <p className="text-xs font-bold text-amber-800">
                                            {orphans.length} {orphans.length === 1 ? 'paso suelto' : 'pasos sueltos'}
                                        </p>
                                        <p className="text-[11px] text-amber-700 mt-0.5">
                                            Ningún camino llega hasta acá, así que el cliente nunca los va a ver. Conéctalos desde otro paso o elimínalos.
                                        </p>
                                    </div>
                                    {orphans.map((node) => renderNode(node, null, true))}
                                </div>
                            )}

                            <div className="rounded-xl border-2 border-dashed border-gray-300 bg-white/60 p-3">
                                <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">
                                    Añadir un paso suelto <span className="font-normal normal-case tracking-normal">— normalmente conviene usar «+ nuevo» dentro de un paso, que además lo conecta</span>
                                </p>
                                <div className="flex flex-wrap gap-1.5">
                                    {Object.entries(NODE_META).map(([type, meta]) => (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => addLooseNode(type)}
                                            title={meta.help}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:border-emerald-400 hover:text-emerald-700 hover:bg-emerald-50/50 transition-all"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                            {meta.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </form>

                    {/* Panel lateral */}
                    <div className="space-y-4 lg:sticky lg:top-6">
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3">
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">Nombre</label>
                                <input form="flow-form" value={data.name} onChange={(e) => setData('name', e.target.value)} required className={inputBase} />
                                {errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{errors.name}</p>}
                            </div>

                            <div className="rounded-xl bg-gray-50 p-3 text-[11px] text-gray-600 flex items-center justify-between">
                                <span><strong className="text-gray-900 tabular-nums">{data.nodes.length}</strong> pasos</span>
                                {problemCount > 0 || orphans.length > 0 ? (
                                    <span className="font-semibold text-amber-600">
                                        {problemCount > 0 && `${problemCount} con avisos`}
                                        {problemCount > 0 && orphans.length > 0 && ' · '}
                                        {orphans.length > 0 && `${orphans.length} sueltos`}
                                    </span>
                                ) : (
                                    <span className="font-semibold text-emerald-600">Todo conectado</span>
                                )}
                            </div>

                            <button
                                type="submit"
                                form="flow-form"
                                disabled={processing}
                                className="w-full px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                            >
                                Guardar cambios
                            </button>
                            <p className="text-[11px] text-gray-400 text-center leading-snug">
                                {flow.status === 'active'
                                    ? 'Está activo: los cambios se aplican en cuanto guardes.'
                                    : 'Es un borrador. Actívalo desde el listado cuando lo hayas probado.'}
                            </p>
                        </div>

                        <ChatTester data={data} sampleContacts={sampleContacts} />

                        {/* Fallbacks: importan poco al principio, por eso van al final. */}
                        <details className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                            <summary className="text-sm font-bold text-gray-900 cursor-pointer">Si el cliente responde cualquier cosa</summary>
                            <div className="mt-3 space-y-3 text-xs">
                                <label className="flex items-center justify-between gap-2">
                                    <span className="text-gray-600">Reintentos antes de rendirse</span>
                                    <input type="number" min="0" max="5" form="flow-form" value={data.fallback_policy.max_reprompts ?? 2} onChange={(e) => setData('fallback_policy', { ...data.fallback_policy, max_reprompts: Number(e.target.value) })} className={`w-16 text-center ${smallInput}`} />
                                </label>
                                <label className="flex items-center justify-between gap-2">
                                    <span className="text-gray-600">Abandonar si no contesta en (horas)</span>
                                    <input type="number" min="1" max="168" form="flow-form" value={data.fallback_policy.on_timeout_hours ?? 24} onChange={(e) => setData('fallback_policy', { ...data.fallback_policy, on_timeout_hours: Number(e.target.value) })} className={`w-16 text-center ${smallInput}`} />
                                </label>
                                <label className="flex items-center justify-between gap-2">
                                    <span className="text-gray-600">Al agotar los reintentos</span>
                                    <select form="flow-form" value={data.fallback_policy.on_exhaust ?? 'handoff'} onChange={(e) => setData('fallback_policy', { ...data.fallback_policy, on_exhaust: e.target.value })} className={smallInput}>
                                        <option value="handoff">Pasar a un agente</option>
                                        <option value="end">Terminar</option>
                                    </select>
                                </label>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

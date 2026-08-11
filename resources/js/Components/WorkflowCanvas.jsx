/**
 * Primitivas visuales del lienzo estilo HubSpot.
 *
 * La diferencia con el lienzo anterior no es estética: es **dónde se dibuja la
 * bifurcación**. Antes las dos ramas iban en dos columnas *dentro* de la
 * tarjeta de condición, así que a partir del segundo nivel el ancho se partía
 * a la mitad otra vez y el árbol se volvía ilegible. Acá la condición se cierra
 * y el árbol se abre **debajo**, centrado, con los conectores etiquetados
 * (NO / SÍ) — que es lo que deja seguir el recorrido de un vistazo.
 *
 * Cada rama termina en una **bandera a cuadros**: sin ella no se distingue
 * «la rama se acabó» de «me olvidé de configurar el resto».
 */

import { useEffect, useRef, useState } from 'react';

const LINE = 'bg-slate-300';

/** Tramo vertical simple. */
export function Stem({ h = 'h-5' }) {
    return <div className={`w-px ${h} ${LINE}`} />;
}

/**
 * Conector con «+» para insertar un paso en ese punto exacto.
 *
 * El «+» va entre cada par y no solo al final: la mayoría de las correcciones
 * de un workflow son «falta algo en el medio».
 */
export function Connector({ onAdd, children }) {
    return (
        <div className="relative flex flex-col items-center">
            <Stem h="h-4" />
            {onAdd ? (
                <button
                    type="button"
                    onClick={onAdd}
                    title="Insertar un paso aquí"
                    className="w-6 h-6 rounded-full border border-slate-300 bg-white text-slate-400 grid place-items-center hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 transition-colors shadow-sm"
                >
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </button>
            ) : (
                <span className="w-1.5 h-1.5 rounded-full bg-slate-300" />
            )}
            <Stem h="h-4" />
            {children}
        </div>
    );
}

/**
 * Tarjeta de paso con el icono en un círculo montado sobre el borde superior,
 * como las de HubSpot.
 */
export function NodeCard({ icon, gradient = 'from-slate-600 to-slate-700', tone, children, className = '' }) {
    return (
        <div className="relative flex flex-col items-center w-full">
            <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${gradient} grid place-items-center text-white shadow-md ring-4 ring-[#f4f8fa] z-10`}>
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                </svg>
            </div>
            <div
                className={`-mt-4 w-full rounded-xl border bg-white shadow-sm hover:shadow-md transition-shadow ${
                    tone === 'warning' ? 'border-amber-300' : 'border-slate-200'
                } ${className}`}
            >
                <div className="pt-6">{children}</div>
            </div>
        </div>
    );
}

/** Nodo de inicio: quién entra al workflow. */
export function TriggerCard({ title, description, onEdit }) {
    return (
        <div className="relative flex flex-col items-center w-full">
            <div className="w-9 h-9 rounded-full bg-gradient-to-br from-[#0f4c5c] to-[#123f5a] grid place-items-center text-white shadow-md ring-4 ring-[#f4f8fa] z-10">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
            <div className="-mt-4 w-full rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="pt-6 px-4 pb-4 text-center">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{title}</p>
                    <p className="text-sm text-slate-700 mt-1">{description}</p>
                    {onEdit && (
                        <button type="button" onClick={onEdit} className="mt-2 text-xs font-bold text-emerald-600 hover:text-emerald-700">
                            Cambiar
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

/** Fin de una rama. */
export function EndFlag({ label = 'Fin' }) {
    return (
        <div className="flex flex-col items-center">
            <Stem h="h-4" />
            <div
                className="w-6 h-6 rounded-sm border border-slate-300"
                title={label}
                style={{
                    backgroundImage:
                        'linear-gradient(45deg, #334155 25%, transparent 25%), linear-gradient(-45deg, #334155 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #334155 75%), linear-gradient(-45deg, transparent 75%, #334155 75%)',
                    backgroundSize: '12px 12px',
                    backgroundPosition: '0 0, 0 6px, 6px -6px, -6px 0',
                    backgroundColor: '#fff',
                }}
            />
            <span className="mt-1 text-[10px] font-semibold text-slate-400">{label}</span>
        </div>
    );
}

/**
 * Bifurcación: el árbol se abre debajo del nodo, con una rama por salida.
 *
 * El tramo horizontal se dibuja con medias líneas en cada columna (mitad
 * derecha en la primera, mitad izquierda en la última, completa en las del
 * medio). Es lo que permite que funcione con 2 ramas o con 5 sin recalcular
 * anchos ni posicionar nada en absoluto respecto del padre.
 *
 * @param branches [{ key, label, tone, content }]
 */
export function BranchSplit({ branches }) {
    return (
        <div className="w-full">
            <div className="flex flex-col items-center">
                <Stem h="h-5" />
            </div>

            <div className="flex items-stretch">
                {branches.map((branch, i) => {
                    const first = i === 0;
                    const last = i === branches.length - 1;

                    return (
                        // `min-w` y no `min-w-0`: con anidado el contenedor tiene
                        // que **crecer y hacer scroll**, no comprimir las columnas
                        // hasta que las tarjetas queden ilegibles.
                        <div key={branch.key} className="flex-1 min-w-[19rem] flex flex-col items-center relative px-3">
                            {/* Media línea horizontal según la posición de la rama. */}
                            <div
                                className={`absolute top-0 h-px ${LINE} ${
                                    first ? 'left-1/2 right-0' : last ? 'left-0 right-1/2' : 'left-0 right-0'
                                }`}
                            />
                            <Stem h="h-5" />

                            <span
                                className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                                    branch.tone === 'yes'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : branch.tone === 'no'
                                            ? 'bg-rose-100 text-rose-700'
                                            : 'bg-slate-100 text-slate-600'
                                }`}
                            >
                                {branch.label}
                            </span>

                            <div className="w-full flex flex-col items-center">{branch.content}</div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/** Fondo del lienzo: el azul muy claro de HubSpot, que separa el árbol del resto. */
export function CanvasSurface({ children }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-[#f4f8fa] p-6 overflow-x-auto">
            <div className="min-w-[720px] flex flex-col items-center">{children}</div>
        </div>
    );
}

/* ------------------------------------------------------------ lienzo libre */

/** Tamaño de la grilla a la que se pegan las tarjetas al soltarlas. */
export const GRID = 20;

const snap = (v) => Math.round(v / GRID) * GRID;

/**
 * Lienzo con nodos que se pueden mover libremente.
 *
 * Los nodos se posicionan en absoluto y se arrastran **desde su manija**, no
 * desde toda la tarjeta: adentro hay inputs y textareas, y arrastrar desde
 * cualquier parte haría imposible seleccionar texto.
 *
 * Se pega a una grilla al soltar. Sin eso, dos tarjetas puestas «a ojo» quedan
 * desalineadas por tres píxeles y el lienzo se ve sucio enseguida.
 *
 * El alto del contenedor se calcula del nodo más bajo: sin eso, mover una
 * tarjeta hacia abajo la deja fuera del área visible y no hay forma de
 * alcanzarla.
 *
 * @param nodes [{ id, x, y, width, render({ dragHandleProps, dragging }) }]
 * @param edges [{ from, to, label, tone }]
 */
/**
 * @param toolbar Controles propios del editor (añadir paso, reordenar…). Van
 *   **dentro** de la barra del lienzo y no en la página: en pantalla completa
 *   el lienzo tapa todo, así que cualquier control que quede afuera se vuelve
 *   inalcanzable justo cuando más se está trabajando.
 * @param context Texto corto de contexto (el disparador, por ejemplo) que
 *   también hay que poder leer en pantalla completa.
 */
export function FreeCanvas({ nodes = [], edges = [], onMove, toolbar, context, children }) {
    const [drag, setDrag] = useState(null);
    const [front, setFront] = useState(null);
    const [zoom, setZoom] = useState(1);
    const [fullscreen, setFullscreen] = useState(false);
    const surfaceRef = useRef(null);
    const viewportRef = useRef(null);

    /**
     * Puntero → coordenadas del lienzo.
     *
     * **La división por `zoom` no es opcional.** `getBoundingClientRect()`
     * devuelve el rectángulo YA escalado, así que sin dividir, al 50% la
     * tarjeta se movería el doble de lo que se arrastra el mouse y al 150% la
     * mitad. Es el error clásico de agregar zoom a un lienzo que ya arrastraba.
     */
    const toCanvas = (event) => {
        const rect = surfaceRef.current.getBoundingClientRect();

        return {
            x: (event.clientX - rect.left) / zoom,
            y: (event.clientY - rect.top) / zoom,
        };
    };

    const startDrag = (node) => (event) => {
        if (event.button !== 0) return;
        event.preventDefault();

        const point = toCanvas(event);
        setDrag({
            id: node.id,
            offsetX: point.x - node.x,
            offsetY: point.y - node.y,
            x: node.x,
            y: node.y,
        });
    };

    useEffect(() => {
        if (!drag) return undefined;

        const move = (event) => {
            const point = toCanvas(event);
            setDrag((d) => d && {
                ...d,
                // Nunca a coordenadas negativas: una tarjeta arrastrada fuera
                // por arriba o por la izquierda queda inalcanzable.
                x: Math.max(0, point.x - d.offsetX),
                y: Math.max(0, point.y - d.offsetY),
            });
        };

        const end = () => {
            setDrag((d) => {
                if (d) onMove?.(d.id, snap(d.x), snap(d.y));

                return null;
            });
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);

        return () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
        };
    }, [drag, onMove, zoom]);

    const positioned = nodes.map((n) => (drag?.id === n.id ? { ...n, x: drag.x, y: drag.y } : n));
    const contentH = Math.max(360, ...positioned.map((n) => n.y + (n.height ?? 220))) + 80;
    const contentW = Math.max(760, ...positioned.map((n) => n.x + (n.width ?? 320))) + 80;

    const setZoomClamped = (value) => setZoom(Math.min(1.5, Math.max(0.3, Math.round(value * 100) / 100)));

    /**
     * Ajusta el zoom para que entre todo el contenido en la ventana visible.
     *
     * Es lo que contesta «quiero ver todo»: con un workflow largo, bajar el
     * zoom a mano hasta encontrar el punto justo es tedioso.
     */
    const fitToScreen = () => {
        const viewport = viewportRef.current;
        if (!viewport) return;

        setZoomClamped(Math.min(
            viewport.clientWidth / contentW,
            viewport.clientHeight / contentH,
            1,
        ));
    };

    const toolbarButton = 'px-2 py-1 rounded-lg text-xs font-bold text-slate-600 bg-white border border-slate-200 hover:border-emerald-400 hover:text-emerald-700 transition-colors';

    return (
        <div
            className={
                fullscreen
                    // Pantalla completa: es la forma real de «ver todo el
                    // contenido» en un workflow grande, más que cualquier ancho
                    // que se le pueda dar dentro de la página.
                    ? 'fixed inset-0 z-50 bg-white flex flex-col'
                    : 'rounded-2xl border border-slate-200 bg-[#f4f8fa] flex flex-col'
            }
        >
            {/* `relative z-40` y **sin** `backdrop-blur`: el blur crea un
                contexto de apilado que encierra a los menús de la barra, así
                que quedaban por debajo del área del lienzo y no se podían
                clickear. */}
            <div className="relative z-40 flex flex-wrap items-center gap-1.5 px-3 py-2 border-b border-slate-200 bg-white">
                {/* Controles del editor primero: son los que se usan todo el
                    tiempo. El zoom queda a la derecha, que se toca de a ratos. */}
                {toolbar}
                {toolbar && <span className="w-px h-5 bg-slate-200 mx-1" />}

                <button type="button" onClick={() => setZoomClamped(zoom - 0.1)} className={toolbarButton} title="Alejar">−</button>
                <button
                    type="button"
                    onClick={() => setZoom(1)}
                    className={`${toolbarButton} tabular-nums w-14`}
                    title="Volver al 100%"
                >
                    {Math.round(zoom * 100)}%
                </button>
                <button type="button" onClick={() => setZoomClamped(zoom + 0.1)} className={toolbarButton} title="Acercar">+</button>
                <button type="button" onClick={fitToScreen} className={toolbarButton} title="Ajustar todo a la pantalla">Ajustar</button>

                <button
                    type="button"
                    onClick={() => setFullscreen((v) => !v)}
                    className={`${toolbarButton} ml-auto`}
                    title={fullscreen ? 'Salir de pantalla completa' : 'Pantalla completa'}
                >
                    {fullscreen ? 'Salir' : 'Pantalla completa'}
                </button>
            </div>

            {/* Contexto (el disparador): en pantalla completa es lo único que
                recuerda para quién se está armando todo esto. */}
            {context && (
                <div className="px-3 py-1.5 border-b border-slate-100 bg-white/60 text-[11px] text-slate-500">
                    {context}
                </div>
            )}

            <div
                ref={viewportRef}
                className={`overflow-auto bg-[#f4f8fa] ${fullscreen ? 'flex-1' : ''}`}
                style={fullscreen ? undefined : { height: 'min(70vh, 720px)' }}
            >
                {/* Envoltorio del tamaño YA escalado: sin él las barras de
                    scroll miden el contenido sin escalar y sobra o falta
                    espacio según el zoom. */}
                <div style={{ width: contentW * zoom, height: contentH * zoom }}>
                    <div
                        ref={surfaceRef}
                        className="relative origin-top-left"
                        style={{
                            height: contentH,
                            width: contentW,
                            transform: `scale(${zoom})`,
                            // Grilla de puntos: da referencia para alinear sin
                            // agregar una sola línea de lógica.
                            backgroundImage: 'radial-gradient(#cbd5e1 1px, transparent 1px)',
                            backgroundSize: `${GRID}px ${GRID}px`,
                        }}
                    >
                <svg className="absolute inset-0 w-full h-full pointer-events-none" style={{ overflow: 'visible' }}>
                    {edges.map((edge) => {
                        const from = positioned.find((n) => n.id === edge.from);
                        const to = positioned.find((n) => n.id === edge.to);
                        if (!from || !to) return null;

                        const x1 = from.x + (from.width ?? 320) / 2;
                        const y1 = from.y + (from.height ?? 220);
                        const x2 = to.x + (to.width ?? 320) / 2;
                        const y2 = to.y;
                        const mid = (y1 + y2) / 2;

                        return (
                            <g key={`${edge.from}->${edge.to}-${edge.label ?? ''}`}>
                                {/* Codo en tres tramos: baja, cruza y vuelve a
                                    bajar. Una recta diagonal entre tarjetas
                                    lejanas cruza por encima de las del medio. */}
                                <path
                                    d={`M ${x1} ${y1} L ${x1} ${mid} L ${x2} ${mid} L ${x2} ${y2}`}
                                    fill="none"
                                    stroke="#cbd5e1"
                                    strokeWidth="2"
                                />
                                {edge.label && (
                                    <foreignObject x={(x1 + x2) / 2 - 24} y={mid - 11} width="48" height="22">
                                        <div
                                            className={`text-center text-[10px] font-bold uppercase rounded px-1 py-0.5 ${
                                                edge.tone === 'yes'
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : edge.tone === 'no'
                                                        ? 'bg-rose-100 text-rose-700'
                                                        : 'bg-slate-100 text-slate-600'
                                            }`}
                                        >
                                            {edge.label}
                                        </div>
                                    </foreignObject>
                                )}
                            </g>
                        );
                    })}
                </svg>

                {positioned.map((node) => (
                    <div
                        key={node.id}
                        // La tarjeta con la que se está interactuando pasa al
                        // frente. No es solo estética: cada tarjeta es
                        // `absolute` con z-index, o sea **un contexto de
                        // apilado propio**, así que un menú desplegable adentro
                        // no puede pintarse por encima de las tarjetas que
                        // vienen después en el DOM — se veía, pero los clics
                        // los recibía la tarjeta de arriba.
                        onPointerDownCapture={() => setFront(node.id)}
                        className={`absolute ${
                            drag?.id === node.id ? 'z-30' : front === node.id ? 'z-20' : 'z-10'
                        }`}
                        style={{ left: node.x, top: node.y, width: node.width ?? 320 }}
                    >
                        {node.render({
                            dragging: drag?.id === node.id,
                            dragHandleProps: {
                                onPointerDown: startDrag(node),
                                style: { cursor: drag?.id === node.id ? 'grabbing' : 'grab', touchAction: 'none' },
                                title: 'Arrastrar para mover',
                            },
                        })}
                    </div>
                ))}

                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}

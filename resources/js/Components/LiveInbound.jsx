import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Aviso en vivo de mensajes entrantes, visible desde CUALQUIER pantalla.
 *
 * Va por polling y no por websocket a propósito: Reverb no corre en el
 * servidor (`BROADCAST_CONNECTION=log`), así que un canal de Echo no
 * llegaría nunca. Con un intervalo de 10s el aviso se siente inmediato y el
 * costo es una query indexada.
 *
 * Tres capas, de menos a más intrusiva:
 *   1. Toast en la esquina — siempre.
 *   2. Sonido corto — solo si el usuario ya interactuó con la página (los
 *      navegadores bloquean el audio automático hasta el primer clic).
 *   3. Notificación del sistema — solo si la pestaña está en segundo plano y
 *      el usuario dio permiso. No se pide permiso al entrar: se ofrece desde
 *      el propio toast, que es cuando tiene sentido.
 */

const POLL_MS = 10000;
const TOAST_MS = 12000;

/** Beep corto sintetizado: evita depender de un archivo de audio. */
function playChime() {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        const ctx = new Ctx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.setValueAtTime(1174, ctx.currentTime + 0.09);
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
        osc.start();
        osc.stop(ctx.currentTime + 0.36);
        setTimeout(() => ctx.close(), 600);
    } catch {
        // El audio es un extra: si el navegador lo bloquea, el toast alcanza.
    }
}

function initials(name) {
    return (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

export default function LiveInbound({ enabled = true, inboxUrl }) {
    const [toasts, setToasts] = useState([]);
    const [canAskPermission, setCanAskPermission] = useState(false);
    const since = useRef(null);
    const interacted = useRef(false);
    const seen = useRef(new Set());

    // El audio necesita un gesto previo del usuario para poder sonar.
    useEffect(() => {
        const mark = () => { interacted.current = true; };
        window.addEventListener('pointerdown', mark, { once: true });
        window.addEventListener('keydown', mark, { once: true });

        return () => {
            window.removeEventListener('pointerdown', mark);
            window.removeEventListener('keydown', mark);
        };
    }, []);

    useEffect(() => {
        if (!enabled) return;

        setCanAskPermission('Notification' in window && Notification.permission === 'default');

        let cancelled = false;
        let avisado = false;

        // Un catch mudo hace imposible diagnosticar por qué no llegan avisos:
        // se reporta una vez por sesión y se sigue reintentando.
        const reportar = (motivo, detalle) => {
            if (avisado) return;
            avisado = true;
            console.warn(`[avisos en vivo] ${motivo}`, detalle ?? '');
        };

        const poll = async () => {
            try {
                const url = new URL(route('notifications.recent-inbound'), window.location.origin);
                if (since.current) url.searchParams.set('since', since.current);

                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (cancelled) return;

                if (!res.ok) {
                    reportar(`el servidor respondió ${res.status} en ${url.pathname}`,
                        res.status === 419 || res.status === 401 ? 'sesión vencida: recargá la página' : '');

                    return;
                }

                const data = await res.json();
                // El reloj lo manda el servidor: si el del navegador está
                // corrido, un `since` local se saltearía mensajes.
                const first = since.current === null;
                since.current = data.now;

                // En la primera vuelta solo se fija el punto de partida: si no,
                // al abrir la app aparecerían avisos de mensajes ya vistos.
                if (first || !data.messages?.length) return;

                const nuevos = data.messages.filter((m) => !seen.current.has(m.id));
                if (!nuevos.length) return;
                nuevos.forEach((m) => seen.current.add(m.id));

                setToasts((prev) => [...nuevos, ...prev].slice(0, 4));

                if (interacted.current) playChime();

                if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                    nuevos.slice(0, 3).forEach((m) => {
                        new Notification(`${m.contact} te escribió`, { body: m.preview, tag: m.conversation_id });
                    });
                }
            } catch (error) {
                // Un fallo no puede romper la app, pero tampoco desaparecer:
                // el caso típico es que la ruta no exista todavía en el JS
                // (falta rebuild) o que se haya caído la red.
                reportar('no se pudo consultar', error?.message ?? error);
            }
        };

        poll();
        const id = setInterval(poll, POLL_MS);

        return () => { cancelled = true; clearInterval(id); };
    }, [enabled]);

    useEffect(() => {
        if (!toasts.length) return;
        const id = setTimeout(() => setToasts((prev) => prev.slice(0, -1)), TOAST_MS);

        return () => clearTimeout(id);
    }, [toasts]);

    const dismiss = (id) => setToasts((prev) => prev.filter((t) => t.id !== id));

    const open = (toast) => {
        dismiss(toast.id);
        
        router.visit(inboxUrl ?? route('inbox'));
    };

    if (!toasts.length) return null;

    return (
        <div className="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-[min(22rem,calc(100vw-2rem))]">
            {canAskPermission && (
                <button
                    type="button"
                    onClick={() => Notification.requestPermission().then(() => setCanAskPermission(false))}
                    className="self-end text-[11px] font-semibold text-gray-500 hover:text-emerald-700 bg-white/90 backdrop-blur rounded-lg px-2 py-1 shadow-sm border border-gray-200"
                >
                    Avisarme aunque esté en otra pestaña
                </button>
            )}

            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    onClick={() => open(toast)}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => { if (e.key === 'Enter') open(toast); }}
                    className="group cursor-pointer rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 border-l-4 border-emerald-500 p-3.5 flex items-start gap-3 hover:shadow-emerald-500/10 transition-all animate-[slideIn_0.2s_ease-out]"
                >
                    <span className="w-9 h-9 shrink-0 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white text-xs font-bold">
                        {initials(toast.contact)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-bold text-gray-900 truncate">{toast.contact}</p>
                        <p className="text-xs text-gray-600 mt-0.5 line-clamp-2 break-words">{toast.preview}</p>
                        <p className="text-[11px] font-semibold text-emerald-600 mt-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                            Abrir conversación →
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); dismiss(toast.id); }}
                        className="text-gray-300 hover:text-gray-600 p-1 shrink-0"
                        aria-label="Descartar"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            ))}
        </div>
    );
}

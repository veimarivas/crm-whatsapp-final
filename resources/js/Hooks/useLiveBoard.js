import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Refresco en vivo de un tablero (kanban/lista) sin recargar la pagina.
 *
 * Va por polling y no por websocket a proposito: Reverb no corre en el VPS
 * (BROADCAST_CONNECTION=log), asi que este es el mismo mecanismo que ya usan
 * el inbox y la ficha del lead.
 *
 * Lo que evita, que es donde estaba el problema:
 * - No pide nada con la pestaña en segundo plano (`document.hidden`), y al
 *   volver refresca de inmediato en vez de esperar al siguiente tick: el
 *   usuario que vuelve al tablero ve el estado real al instante.
 * - No encima peticiones: si una sigue en vuelo, se saltea el tick.
 * - `paused` corta el ciclo mientras el usuario esta arrastrando una tarjeta
 *   o tiene una seleccion abierta — reordenar debajo del cursor pierde el drag.
 *
 * @param {object}   opts
 * @param {string[]} opts.only     props de Inertia a re-traer (partial reload).
 * @param {boolean}  opts.enabled  falso apaga el ciclo por completo.
 * @param {number}   opts.interval ms entre refrescos.
 * @param {boolean}  opts.paused   true suspende sin desmontar el ciclo.
 */
export default function useLiveBoard({ only, enabled = true, interval = 5000, paused = false }) {
    const [refreshing, setRefreshing] = useState(false);
    const [lastSync, setLastSync] = useState(() => Date.now());

    const onlyKey = only.join(',');
    const pausedRef = useRef(paused);
    const inFlight = useRef(false);
    pausedRef.current = paused;

    const refresh = useCallback((force = false) => {
        if (!enabled || inFlight.current) return;
        if (!force && (pausedRef.current || document.hidden)) return;

        inFlight.current = true;
        setRefreshing(true);
        router.reload({
            only: onlyKey.split(','),
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                inFlight.current = false;
                setRefreshing(false);
                setLastSync(Date.now());
            },
        });
    }, [enabled, onlyKey]);

    useEffect(() => {
        if (!enabled) return undefined;

        const tick = setInterval(() => refresh(), interval);
        const onVisible = () => { if (!document.hidden) refresh(); };

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            clearInterval(tick);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, [enabled, interval, refresh]);

    return { refreshing, lastSync, refresh: () => refresh(true) };
}

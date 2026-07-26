/**
 * Ventana de servicio de WhatsApp: cuánto queda para escribirle al contacto
 * sin que Meta cobre.
 *
 *  - 24 h desde su último mensaje (conversación de servicio).
 *  - 72 h si llegó tocando un anuncio Click-to-WhatsApp (free entry point).
 *
 * Cerrada = escribirle exige una plantilla aprobada y **eso se factura**, por
 * eso el badge se pone rojo: ahí es donde se decide si vale el gasto.
 */

export function windowCountdown(seconds) {
    if (seconds <= 0) return 'Cerrada';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h >= 24) return `${Math.floor(h / 24)}d ${h % 24}h`;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export function windowTone(w) {
    if (!w?.is_open) return 'bg-red-50 border-red-200 text-red-700';
    if (w.is_expiring) return 'bg-amber-50 border-amber-200 text-amber-700';
    return 'bg-emerald-50 border-emerald-200 text-emerald-700';
}

export function windowTitle(w) {
    if (!w) return '';
    if (!w.is_open) {
        return 'Ventana cerrada: escribirle ahora requiere una plantilla aprobada y tiene costo.';
    }
    const origen = w.source === 'meta_ad' ? ', abierta por un anuncio de Facebook' : '';

    return `Quedan ${windowCountdown(w.remaining_seconds)} para escribirle sin costo (ventana de ${w.window_hours} h${origen}).`;
}

/**
 * `size`: 'sm' para listados densos (tablas, kanban), 'md' para cabeceras.
 */
export default function ServiceWindowBadge({ window: w, size = 'sm', showOrigin = false }) {
    if (!w || w.source === 'none') return null;

    const fromAd = w.source === 'meta_ad';
    const tone = windowTone(w);

    if (size === 'md') {
        return (
            <span title={windowTitle(w)} className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold border ${tone}`}>
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                {w.is_open ? windowCountdown(w.remaining_seconds) : 'Ventana cerrada'}
                <span className="font-normal opacity-70">{fromAd ? '· anuncio 72h' : '· 24h'}</span>
            </span>
        );
    }

    return (
        <span title={windowTitle(w)} className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold border ${tone}`}>
            {fromAd ? '📣' : '💬'} {w.is_open ? windowCountdown(w.remaining_seconds) : 'Cerrada'}
            {showOrigin && <span className="font-normal opacity-70">{fromAd ? '72h' : '24h'}</span>}
        </span>
    );
}

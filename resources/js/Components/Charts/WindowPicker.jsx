/**
 * Selector de ventana (7/15/30/90 d) que navega con `?days=` y preserva el
 * scroll, igual que hace /supervision. Se usa en cada página de analítica para
 * que el periodo elegido quede en la URL y sea compartible.
 *
 * Puedes pasar `routeName` (lo más común) y el componente navegara con Inertia,
 * o `onSelect(days)` si tú mismo controlas la navegación.
 */

import { router } from '@inertiajs/react';

export const DEFAULT_RANGES = [7, 15, 30, 90];

export default function WindowPicker({
    days,
    ranges = DEFAULT_RANGES,
    routeName,
    onSelect,
    className = '',
}) {
    const go = (r) => {
        if (r === days) return;
        if (onSelect) return onSelect(r);
        if (routeName) router.get(route(routeName), { days: r }, { preserveScroll: true, preserveState: false });
    };

    return (
        <div className={`flex gap-1 bg-white rounded-xl border border-gray-200 p-1 shrink-0 ${className}`}>
            {ranges.map((r) => (
                <button
                    key={r}
                    onClick={() => go(r)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${r === days ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'}`}
                >
                    {r}d
                </button>
            ))}
        </div>
    );
}
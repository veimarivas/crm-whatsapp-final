/**
 * Tooltip compartido para la capa de gráficos (consumido por TrendArea y
 * CompareBars). Muestra siempre el valor absoluto y, junto a él, la
 * participación porcentual dentro del punto que se está mirando, para que la
 * comparación entre series (o barras) se lea al pasar el cursor.
 */

import { fmtNumber } from './format';
import { tooltipStyle } from './chartTheme';

export default function ChartTip({ active, payload, label, valueFormatter = fmtNumber, showPct = true, totalLabel }) {
    if (!active || !payload || payload.length === 0) return null;

    const items = payload.filter((p) => p.value !== undefined && p.value !== null);
    if (items.length === 0) return null;

    const total = items.reduce((acc, p) => acc + (Number(p.value) || 0), 0);

    return (
        <div style={tooltipStyle} className="px-3 py-2 min-w-[160px]">
            {label !== undefined && label !== null && (
                <p className="text-xs font-semibold text-gray-500 mb-1">{label}</p>
            )}
            <ul className="space-y-0.5">
                {items.map((p) => (
                    <li key={`${p.dataKey}-${p.name}`} className="flex items-center gap-2 text-xs">
                        <span className="w-2 h-2 rounded-sm shrink-0" style={{ background: p.color || p.fill }} />
                        <span className="text-gray-600 truncate">{p.name}</span>
                        <span className="ml-auto pl-2 font-bold text-gray-900 tabular-nums">{valueFormatter(p.value)}</span>
                        {showPct && total > 0 && (
                            <span className="text-[10px] text-gray-400 tabular-nums w-10 text-right">
                                {Math.round(((Number(p.value) || 0) / total) * 100)}%
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {totalLabel && total > 0 && (
                <p className="mt-1.5 pt-1.5 border-t border-gray-100 text-[10px] text-gray-500 flex justify-between">
                    <span>{totalLabel}</span>
                    <span className="font-bold tabular-nums text-gray-800">{valueFormatter(total)}</span>
                </p>
            )}
        </div>
    );
}
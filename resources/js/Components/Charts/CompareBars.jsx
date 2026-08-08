/**
 * Barras comparativas con una línea de objetivo (SLA, meta, media…).
 *
 * layout:
 *   'horizontal' — una fila por categoría (agentes, campañas); la línea de
 *                  objetivo es vertical. Pensado para listas de pocas filas.
 *   'vertical'    — barras verticales por período/bucket; línea horizontal.
 *
 * data: [{ name, value }]; cada fila puede traer `color` propio (p. ej. pintar
 * verde/ámbar/rojo según el SLA) o se colorea contra `target` automáticamente.
 */

import { Cell, Bar, BarChart, CartesianGrid, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { axisStyle, TONE } from './chartTheme';
import { fmtNumber } from './format';
import { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';

const autoTone = (value, target) => {
    if (target === undefined || target === null) return TONE.brand;
    if (value <= target) return TONE.positive;
    if (value <= target * 1.2) return TONE.warning;
    return TONE.danger;
};

export default function CompareBars({
    data = [],
    xKey = 'name',
    height = 240,
    layout = 'horizontal',
    target,
    targetLabel,
    targetColor = TONE.warning,
    valueFormatter = fmtNumber,
    emptyMessage = 'Sin datos en este periodo.',
    className = '',
}) {
    if (data.length === 0) return <EmptyChart message={emptyMessage} />;

    const isHorizontal = layout === 'horizontal';

    const chart = (
        <BarChart
            data={data}
            layout={layout}
            margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
            barSize={isHorizontal ? 16 : 14}
        >
            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={isHorizontal} horizontal={!isHorizontal} />
            {isHorizontal ? (
                <>
                    <XAxis type="number" {...axisStyle} tickFormatter={valueFormatter} />
                    <YAxis type="category" dataKey={xKey} {...axisStyle} width={120} />
                </>
            ) : (
                <>
                    <XAxis dataKey={xKey} {...axisStyle} />
                    <YAxis {...axisStyle} width={40} tickFormatter={valueFormatter} allowDecimals={false} />
                </>
            )}
            <Tooltip content={<ChartTip valueFormatter={valueFormatter} showPct={false} />} cursor={{ fill: 'rgba(15,118,110,0.06)' }} />
            {target !== undefined && (
                <ReferenceLine
                    x={isHorizontal ? target : undefined}
                    y={isHorizontal ? undefined : target}
                    stroke={targetColor}
                    strokeDasharray="4 4"
                    label={{
                        value: targetLabel || 'Objetivo',
                        position: isHorizontal ? 'top' : 'insideTopRight',
                        fill: targetColor,
                        fontSize: 10,
                        fontWeight: 700,
                    }}
                />
            )}
            <Bar dataKey="value" radius={isHorizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]}>
                {data.map((d, i) => (
                    <Cell
                        key={`${d.id ?? d[xKey] ?? i}`}
                        fill={d.color || autoTone(d.value, target)}
                        className="transition-opacity hover:opacity-80"
                    />
                ))}
            </Bar>
        </BarChart>
    );

    return <div className={className}><ResponsiveContainer width="100%" height={height}>{chart}</ResponsiveContainer></div>;
}
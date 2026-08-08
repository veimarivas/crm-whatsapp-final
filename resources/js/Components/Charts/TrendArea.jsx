/**
 * Área con tendencia de una o varias series, con gradiente de marca y leyenda
 * clicable para ver/esconder cada serie.
 *
 * Uso típico: la serie principal (entrante, humano, IA) como área con
 * gradiente del color de marca; las secundarias apiladas o superpuestas.
 *
 * series: [{ key, name, color }] — el color completo (brush) se toma de
 * chartTheme/TONE o se pasa explícito.
 */

import { useState } from 'react';
import { Area, AreaChart, CartesianGrid, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { axisStyle, TONE } from './chartTheme';
import { fmtNumber } from './format';
import { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';

export default function TrendArea({
    data = [],
    series = [],
    xKey = 'label',
    height = 260,
    stackId = '',
    valueFormatter = fmtNumber,
    yFormatter,
    showLegend = true,
    reference, // { value, label, color }
    emptyMessage = 'Sin datos en este periodo.',
    className = '',
}) {
    const [hidden, setHidden] = useState(() => new Set());
    const visible = series.filter((s) => !hidden.has(s.key));
    const total = data.reduce((acc, d) => acc + visible.reduce((s, sr) => s + (Number(d[sr.key]) || 0), 0), 0);

    if (total === 0) return <EmptyChart message={emptyMessage} />;

    const toggle = (key) => {
        setHidden((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    return (
        <div className={className}>
            {showLegend && series.length > 1 && (
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mb-3">
                    {series.map((s) => {
                        const isHidden = hidden.has(s.key);
                        const isVisible = !isHidden && visible.some((v) => v.key === s.key);
                        return (
                            <button
                                key={s.key}
                                onClick={() => toggle(s.key)}
                                title={isHidden ? 'Mostrar serie' : 'Ocultar serie'}
                                className={`inline-flex items-center gap-1.5 text-[11px] font-semibold transition-opacity ${isHidden ? 'opacity-40 line-through' : 'opacity-100'}`}
                                style={{ color: isHidden ? '#64748b' : s.color }}
                            >
                                <span className="w-2.5 h-2.5 rounded-sm shrink-0" style={{ background: isHidden ? '#cbd5e1' : s.color }} />
                                {s.name}
                            </button>
                        );
                    })}
                </div>
            )}

            <ResponsiveContainer width="100%" height={height}>
                <AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <defs>
                        {series.map((s) => (
                            <linearGradient key={`g-${s.key}`} id={`trend-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor={s.color} stopOpacity={0.32} />
                                <stop offset="100%" stopColor={s.color} stopOpacity={0.02} />
                            </linearGradient>
                        ))}
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                    <XAxis dataKey={xKey} {...axisStyle} tickMargin={6} />
                    <YAxis
                        {...axisStyle}
                        width={40}
                        tickFormatter={yFormatter || valueFormatter}
                        allowDecimals={false}
                    />
                    <Tooltip content={<ChartTip valueFormatter={valueFormatter} totalLabel="Total" />} />
                    {reference && (
                        <ReferenceLine
                            y={reference.value}
                            stroke={reference.color || TONE.warning}
                            strokeDasharray="4 4"
                            label={{
                                value: reference.label || '',
                                position: 'insideTopRight',
                                fill: reference.color || TONE.warning,
                                fontSize: 10,
                                fontWeight: 700,
                            }}
                        />
                    )}
                    {visible.map((s) => (
                        <Area
                            key={s.key}
                            type="monotone"
                            dataKey={s.key}
                            name={s.name}
                            stroke={s.color}
                            strokeWidth={2}
                            fill={`url(#trend-${s.key})`}
                            stackId={stackId || undefined}
                            connectNulls
                            activeDot={{ r: 3, strokeWidth: 2, stroke: '#fff' }}
                        />
                    ))}
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}
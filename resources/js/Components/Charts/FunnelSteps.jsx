/**
 * Embudo de pasos consecutivos (p. ej. enviados → entregados → leídos →
 * respondidos). El ancho de cada paso es proporcional al anterior, de forma
 * que la pérdida de un paso a otro se lee al instante.
 *
 * steps: [{ name, value, color }] — el orden de la lista es el orden del embudo.
 * Debajo del funnel se lista cada paso con su conteo, el % que conserva frente
 * al paso anterior y la caída en rojo cuando pierde.
 */

import { Funnel, FunnelChart, LabelList, ResponsiveContainer, Tooltip } from 'recharts';
import { tooltipStyle, TONE } from './chartTheme';
import { fmtNumber, fmtPct } from './format';
import { EmptyChart } from './ChartCard';

function FunnelTip({ active, payload, valueFormatter }) {
    if (!active || !payload || payload.length === 0) return null;
    const s = payload[0];
    const pass = s.payload.passPct;

    return (
        <div style={tooltipStyle} className="px-3 py-2 min-w-[140px]">
            <p className="text-xs font-bold text-gray-900 mb-0.5">{s.payload.name}</p>
            <p className="text-sm font-extrabold tabular-nums text-gray-800">{valueFormatter(s.payload.value)}</p>
            {s.payload.prevName && (
                <p className={`text-[11px] mt-0.5 ${pass >= 100 ? 'text-emerald-600' : 'text-rose-600'}`}>
                    {pass >= 100 ? 'Conserva el' : 'Cae a'} {pass.toFixed(0).replace('.', ',')}% del paso anterior
                </p>
            )}
        </div>
    );
}

export default function FunnelSteps({
    steps = [],
    valueFormatter = fmtNumber,
    emptyMessage = 'Sin datos en este periodo.',
    className = '',
}) {
    const withPct = steps.map((s, i) => {
        const prev = i > 0 ? steps[i - 1] : null;
        return {
            ...s,
            passPct: prev && prev.value > 0 ? (s.value / prev.value) * 100 : null,
            dropPct: prev && s.value < prev.value && prev.value > 0 ? 100 - (s.value / prev.value) * 100 : 0,
            prevName: prev?.name || null,
        };
    });

    const first = withPct[0]?.value;
    if (!first) return <EmptyChart message={emptyMessage} />;

    return (
        <div className={className}>
            <ResponsiveContainer width="100%" height={230}>
                <FunnelChart>
                    <Tooltip content={<FunnelTip valueFormatter={valueFormatter} />} />
                    <Funnel dataKey="value" data={withPct} isAnimationActive={false}>
                        <LabelList dataKey="value" position="right" style={{ fill: '#475569', fontSize: 12, fontWeight: 700 }} formatter={(v) => valueFormatter(v)} />
                    </Funnel>
                </FunnelChart>
            </ResponsiveContainer>

            <div className="mt-4 grid gap-3" style={{ gridTemplateColumns: `repeat(${withPct.length}, minmax(0, 1fr))` }}>
                {withPct.map((s, i) => (
                    <div key={`${s.name}-${i}`} className="rounded-xl border border-gray-100 bg-gray-50/60 px-3 py-2">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 truncate">{s.name}</p>
                        <p className="text-lg font-extrabold tabular-nums text-gray-900 leading-tight">{valueFormatter(s.value)}</p>
                        <p className="text-[10px] text-gray-500 tabular-nums">
                            {fmtPct(s.value, first)} del total
                        </p>
                        {s.prevName && (
                            <p className={`text-[10px] font-bold tabular-nums mt-0.5 ${s.dropPct > 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
                                {s.dropPct > 0 ? `▼ ${s.dropPct.toFixed(0).replace('.', ',')}% vs ${s.prevName}` : 'Sin pérdida'}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
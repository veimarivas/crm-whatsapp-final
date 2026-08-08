/**
 * Mapa de calor hora × día de la semana (para ver cuándo entra el volumen y
 * cuadrar horarios/cobertura). Escala continua de ámbar a rosa: lo más tenue
 * es poco volumen, lo más intenso es el pico.
 *
 * data: [{ label: 'Lun', hours: [v0 … v23] }] — 24 celdas por fila en orden de
 * hora de 00 a 23; un `null`/undefined significa sin actividad ese hueco.
 */

import { fmtInteger } from './format';
import { EmptyChart } from './ChartCard';

const AMBER = [245, 158, 11];
const ROSE = [244, 63, 94];

function mix(c1, c2, t) {
    return `rgb(${Math.round(c1[0] + (c2[0] - c1[0]) * t)}, ${Math.round(c1[1] + (c2[1] - c1[1]) * t)}, ${Math.round(c1[2] + (c2[2] - c1[2]) * t)})`;
}

function hPa(value, max) {
    if (!value) return '#ffffff';
    return mix(AMBER, ROSE, value / max);
}

function htc(value, max) {
    if (!value) return 'Dato sin movimientos';
    const pct = ((value / max) * 100).toFixed(0).replace('.', ',');
    return `${fmtInteger(value)} mensajes (${pct}% del pico)`;
}

export default function HeatmapGrid({
    data = [],
    hourStep = 1,
    hourFormat = (h) => `${h}h`,
    emptyMessage = 'Sin actividad en este periodo.',
    className = '',
}) {
    const max = Math.max(0, ...data.flatMap((r) => r.hours).filter((v) => v));

    if (data.length === 0 || max === 0) return <EmptyChart message={emptyMessage} />;

    const cols = 24 / hourStep;

    return (
        <div className={className}>
            <div className="overflow-x-auto">
                <div className="min-w-[640px]">
                    {/* Cabecera de horas */}
                    <div className="flex mb-1" style={{ paddingLeft: `${40 / (28 * (cols + 1)) * 100}%` }}>
                        {Array.from({ length: cols }, (_, i) => i * hourStep).map((h) => (
                            <span key={h} className="flex-1 text-center text-[10px] text-gray-400 font-semibold">
                                {hourFormat(h)}
                            </span>
                        ))}
                    </div>

                    <div className="space-y-1">
                        {data.map((row, ri) => (
                            <div key={`${row.label}-${ri}`} className="flex items-center gap-2">
                                <span className="w-10 shrink-0 text-[11px] font-semibold text-gray-500 text-right">{row.label}</span>
                                <div className="flex flex-1 gap-1">
                                    {Array.from({ length: cols }, (_, i) => i * hourStep).map((h) => {
                                        const value = row.hours[h];
                                        return (
                                            <div
                                                key={h}
                                                title={value ? htc(value, max) : 'Sin movimientos en este hueco'}
                                                className="flex-1 h-5 rounded-[3px] transition-transform hover:scale-[1.08] border border-gray-50"
                                                style={{ background: hPa(value, max) }}
                                            />
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <div className="flex items-center justify-between mt-2 text-[10px] text-gray-400">
                <span>Menos {'>'}</span>
                <span className="flex items-center gap-1.5">
                    <span className="w-3 h-3 rounded-sm" style={{ background: AMBER }} />
                    <span className="w-3 h-3 rounded-sm" style={{ background: mix(AMBER, ROSE, 0.5) }} />
                    <span className="w-3 h-3 rounded-sm" style={{ background: ROSE }} />
                    Máximo
                </span>
            </div>
        </div>
    );
}
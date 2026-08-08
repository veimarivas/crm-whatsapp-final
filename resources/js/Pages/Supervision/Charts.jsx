/**
 * Gráficas del panel de Seguimiento, en SVG a mano.
 *
 * Sin librería de charts a propósito: el resto del proyecto también dibuja
 * sus barras con divs/SVG y meter recharts por cuatro gráficas cuesta ~90kB
 * de bundle. Todas comparten la misma paleta que las tablas para que el
 * color signifique lo mismo en toda la página:
 *   verde  = responsable / atendido      ámbar = esperando
 *   violeta = IA                          rojo  = fuera de SLA / sin respuesta
 *   celeste = otro agente                 gris  = humano sin identificar
 */

export const TONE = {
    asignado: '#10b981',
    responsable: '#10b981',
    otro: '#0ea5e9',
    ia: '#8b5cf6',
    desconocido: '#94a3b8',
    espera: '#f59e0b',
    vencido: '#ef4444',
    entrante: '#64748b',
};

export function ChartCard({ title, subtitle, legend, children, className = '' }) {
    return (
        <div className={`bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 ${className}`}>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h3 className="text-base font-bold text-gray-900">{title}</h3>
                    {subtitle && <p className="text-xs text-gray-500 mt-0.5">{subtitle}</p>}
                </div>
                {legend && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        {legend.map((l) => (
                            <span key={l.label} className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-500">
                                <span className="w-2.5 h-2.5 rounded-sm" style={{ background: l.color }} />
                                {l.label}
                            </span>
                        ))}
                    </div>
                )}
            </div>
            {children}
        </div>
    );
}

function EmptyChart({ message = 'Sin datos en este periodo.' }) {
    return <div className="h-48 flex items-center justify-center text-sm text-gray-400">{message}</div>;
}

/**
 * Volumen diario: barras agrupadas de mensajes recibidos vs respuestas
 * humanas, con las respuestas de IA apiladas encima de estas últimas.
 * Deja ver de un vistazo si el equipo sigue el ritmo de lo que entra.
 */
export function DailyVolumeChart({ daily }) {
    const max = Math.max(1, ...daily.map((d) => Math.max(d.inbound, d.human_replies + d.bot_replies)));
    const total = daily.reduce((acc, d) => acc + d.inbound + d.human_replies + d.bot_replies, 0);

    if (total === 0) return <EmptyChart />;

    // Con muchos días las etiquetas se pisan: se muestra una de cada N.
    const labelEvery = Math.ceil(daily.length / 12);

    return (
        <div className="overflow-x-auto">
            <div className="flex items-end gap-1 h-48 min-w-full" style={{ minWidth: daily.length * 22 }}>
                {daily.map((d, i) => (
                    <div key={d.date} className="flex-1 flex flex-col justify-end items-center gap-1 group relative min-w-[14px]">
                        {/* flex-1 y no h-full: la etiqueta del eje ocupa su propio
                            alto, con h-full las barras se salían del contenedor. */}
                        <div className="w-full flex-1 min-h-0 flex items-end justify-center gap-[2px]">
                            <div
                                className="w-1/2 rounded-t-sm transition-opacity group-hover:opacity-80"
                                style={{ height: `${(d.inbound / max) * 100}%`, background: TONE.entrante, minHeight: d.inbound ? 2 : 0 }}
                            />
                            <div className="w-1/2 flex flex-col justify-end" style={{ height: '100%' }}>
                                <div
                                    className="w-full rounded-t-sm transition-opacity group-hover:opacity-80"
                                    style={{ height: `${(d.bot_replies / max) * 100}%`, background: TONE.ia, minHeight: d.bot_replies ? 2 : 0 }}
                                />
                                <div
                                    className="w-full transition-opacity group-hover:opacity-80"
                                    style={{ height: `${(d.human_replies / max) * 100}%`, background: TONE.responsable, minHeight: d.human_replies ? 2 : 0 }}
                                />
                            </div>
                        </div>
                        <span className="text-[9px] text-gray-400 whitespace-nowrap h-3">
                            {i % labelEvery === 0 ? d.label : ''}
                        </span>
                        <div className="pointer-events-none absolute bottom-full mb-1 hidden group-hover:block z-20 whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1.5 text-[11px] text-white shadow-lg">
                            <p className="font-bold">{d.label}</p>
                            <p>{d.inbound} recibidos</p>
                            <p>{d.human_replies} respuestas</p>
                            {d.bot_replies > 0 && <p>{d.bot_replies} de IA</p>}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Tiempo medio de respuesta por día, en área, con la banda del SLA de fondo.
 * Lo que importa no es el valor exacto sino cuándo se sale de la banda verde.
 *
 * `team` (opcional) es la serie diaria del equipo completo en el MISMO periodo:
 * se superpone en gris para que el agente compare su curva contra la del grupo.
 */
export function ResponseTimeChart({ daily, slaMinutes, formatDuration, team }) {
    const points = daily.map((d, i) => ({ ...d, i }));
    const withData = points.filter((p) => p.avg_response_seconds !== null);
    const teamPoints = (team || []).map((d, i) => ({ ...d, i })).filter((p) => p.avg_response_seconds !== null);

    if (withData.length === 0) return <EmptyChart message="Todavía no hay respuestas medidas en este periodo." />;

    const sla = slaMinutes * 60;
    const max = Math.max(sla * 1.5, ...withData.map((p) => p.avg_response_seconds), ...teamPoints.map((p) => p.avg_response_seconds));
    const w = 100;
    const h = 100;
    const x = (i) => (points.length === 1 ? w / 2 : (i / (points.length - 1)) * w);
    const y = (v) => h - (v / max) * h;

    // Una sola polilínea uniendo solo los días con datos: interpolar sobre los
    // días sin respuestas inventaría un dato que no existe.
    const line = withData.map((p, n) => `${n === 0 ? 'M' : 'L'} ${x(p.i).toFixed(2)} ${y(p.avg_response_seconds).toFixed(2)}`).join(' ');
    const area = `${line} L ${x(withData[withData.length - 1].i).toFixed(2)} ${h} L ${x(withData[0].i).toFixed(2)} ${h} Z`;
    const teamLine = teamPoints.length > 0
        ? teamPoints.map((p, n) => `${n === 0 ? 'M' : 'L'} ${x(p.i).toFixed(2)} ${y(p.avg_response_seconds).toFixed(2)}`).join(' ')
        : '';

    return (
        <div>
            <div className="relative h-48">
                <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" className="w-full h-full overflow-visible">
                    <defs>
                        <linearGradient id="respFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={TONE.responsable} stopOpacity="0.28" />
                            <stop offset="100%" stopColor={TONE.responsable} stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    {/* Banda verde: por debajo del SLA. */}
                    <rect x="0" y={y(sla)} width={w} height={h - y(sla)} fill={TONE.responsable} opacity="0.07" />
                    <line x1="0" y1={y(sla)} x2={w} y2={y(sla)} stroke={TONE.vencido} strokeWidth="0.4" strokeDasharray="2 2" vectorEffect="non-scaling-stroke" />
                    {teamLine && (
                        <path d={teamLine} fill="none" stroke={TONE.desconocido} strokeWidth="1.4" strokeDasharray="3 2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
                    )}
                    <path d={area} fill="url(#respFill)" />
                    <path d={line} fill="none" stroke={TONE.responsable} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
                    {withData.map((p) => (
                        <circle key={p.date} cx={x(p.i)} cy={y(p.avg_response_seconds)} r="2.5" fill="#fff" stroke={TONE.responsable} strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
                    ))}
                    {teamPoints.map((p) => (
                        <circle key={`team-${p.date}`} cx={x(p.i)} cy={y(p.avg_response_seconds)} r="1.8" fill="#fff" stroke={TONE.desconocido} strokeWidth="1.2" vectorEffect="non-scaling-stroke" />
                    ))}
                </svg>
                <span className="absolute right-0 text-[10px] font-semibold text-red-500 bg-white/80 px-1 rounded" style={{ top: `${(y(sla) / h) * 100}%`, transform: 'translateY(-50%)' }}>
                    SLA {slaMinutes}m
                </span>
            </div>
            <div className="flex items-center justify-between text-[10px] text-gray-400 mt-2">
                <span>{points[0]?.label}</span>
                <span className="flex items-center gap-3">
                    {teamLine && (
                        <span className="inline-flex items-center gap-1 font-semibold text-gray-500">
                            <span className="w-3 h-0 border-t-2 border-dashed" style={{ borderColor: TONE.desconocido }} />
                            Equipo
                        </span>
                    )}
                    <span className="font-semibold text-gray-500">máx. {formatDuration(Math.max(...withData.map((p) => p.avg_response_seconds)))}</span>
                </span>
                <span>{points[points.length - 1]?.label}</span>
            </div>
        </div>
    );
}

/** Dona: quién dio la primera respuesta en todo el equipo. */
export function FirstResponderDonut({ slices, total }) {
    const shown = slices.filter((s) => s.value > 0);

    if (total === 0) return <EmptyChart />;

    const r = 42;
    const c = 2 * Math.PI * r;
    let offset = 0;

    return (
        <div className="flex items-center gap-6">
            <div className="relative shrink-0">
                <svg viewBox="0 0 110 110" className="w-36 h-36 -rotate-90">
                    <circle cx="55" cy="55" r={r} fill="none" stroke="#f1f5f9" strokeWidth="16" />
                    {shown.map((s) => {
                        const len = (s.value / total) * c;
                        const dash = <circle key={s.key} cx="55" cy="55" r={r} fill="none" stroke={s.color} strokeWidth="16" strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-offset} />;
                        offset += len;
                        return dash;
                    })}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-2xl font-extrabold text-gray-900 tabular-nums leading-none">{total}</span>
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mt-0.5">convers.</span>
                </div>
            </div>
            <ul className="space-y-2 min-w-0 flex-1">
                {slices.map((s) => (
                    <li key={s.key} className="flex items-center gap-2 text-sm">
                        <span className="w-2.5 h-2.5 rounded-sm shrink-0" style={{ background: s.color }} />
                        <span className="text-gray-600 truncate">{s.label}</span>
                        <span className="ml-auto font-bold text-gray-900 tabular-nums">{s.value}</span>
                        <span className="text-xs text-gray-400 tabular-nums w-10 text-right">
                            {total ? Math.round((s.value / total) * 100) : 0}%
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Barras horizontales: en qué etapa están los contactos del periodo. */
export function StageChart({ stages }) {
    if (stages.length === 0) return <EmptyChart />;

    const max = Math.max(...stages.map((s) => s.count));

    return (
        <div className="space-y-3">
            {stages.map((s) => (
                <div key={s.name}>
                    <div className="flex items-baseline justify-between text-xs mb-1">
                        <span className="inline-flex items-center gap-1.5 font-semibold text-gray-700 truncate">
                            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: s.color || TONE.desconocido }} />
                            {s.name}
                        </span>
                        <span className="tabular-nums text-gray-500">
                            {s.count}
                            {s.waiting > 0 && <span className="text-amber-600 font-semibold"> · {s.waiting} esperando</span>}
                        </span>
                    </div>
                    <div className="h-3 bg-gray-100 rounded-full overflow-hidden flex">
                        <div className="h-full" style={{ width: `${((s.count - s.waiting) / max) * 100}%`, background: s.color || TONE.responsable }} />
                        <div className="h-full" style={{ width: `${(s.waiting / max) * 100}%`, background: TONE.espera }} />
                    </div>
                </div>
            ))}
        </div>
    );
}

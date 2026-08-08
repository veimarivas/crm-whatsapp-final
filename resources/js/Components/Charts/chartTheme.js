/**
 * Paleta semántica de gráficos (no inventar otra):
 *  emerald  = positivo / dentro de SLA / entregado
 *  amber    = advertencia (<5 min, <4 h de ventana)
 *  rose     = peligro (>=5 min, ventana cerrada)
 *  purple   = IA
 *  marque    = serie principal (gradiente de marca)
 *  slate    = neutral / serie secundaria
 */
export const TONE = {
    positive: '#10b981',
    warning: '#f59e0b',
    danger: '#f43f5e',
    ai: '#8b5cf6',
    brand: '#0d9488',
    brandDark: '#134e4a',
    slate: '#64748b',
    blue: '#3b82f6',
    violet: '#8b5cf6',
    pink: '#ec4899',
};

export const SERIES = {
    entrante: TONE.slate,
    humano: TONE.brand,
    ia: TONE.ai,
    dentroSla: TONE.brand,
    sobreSla: TONE.warning,
    vencido: TONE.danger,
    objetivo: TONE.warning,
};

/** Estilo compartido de tooltip para todos los charts. */
export const tooltipStyle = {
    backgroundColor: '#fff',
    border: '1px solid #e2e8f0',
    borderRadius: '0.75rem',
    boxShadow: '0 10px 25px -5px rgb(0 0 0 / 0.1)',
    fontSize: 12,
};

export const axisStyle = {
    tick: { fill: '#94a3b8', fontSize: 11 },
    axisLine: false,
    tickLine: false,
};
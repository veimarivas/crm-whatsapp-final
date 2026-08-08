/**
 * Formateadores compartidos por toda la capa de gráficos y las páginas que
 * la usan, para que un número signifique lo mismo en todas partes.
 */

/** N° compacto estilo es-BO: 1.234 → "1.234", 15.600 → "15,6 mil". */
export function fmtNumber(value) {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    return new Intl.NumberFormat('es-BO', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

/** Entero con separador de miles: 1234567 → "1.234.567". */
export function fmtInteger(value) {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    return new Intl.NumberFormat('es-BO', { maximumFractionDigits: 0 }).format(value);
}

/** Dinero en bolivianos: 1234 → "Bs 1.234". */
export function fmtMoney(value, currency = 'BOB') {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    return new Intl.NumberFormat('es-BO', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(value);
}

/**
 * Segundos → duración legible: "45s", "1 m 23 s", "3 h 05 m", "2 d 4 h".
 * Null/undefined se muestra como guion.
 */
export function fmtDuration(seconds) {
    if (seconds === null || seconds === undefined || Number.isNaN(seconds)) return '—';
    const s = Math.round(seconds);
    if (s < 60) return `${s}s`;
    if (s < 3600) {
        const m = Math.floor(s / 60);
        const r = s % 60;
        return r ? `${m} m ${r} s` : `${m} m`;
    }
    if (s < 86400) {
        const h = Math.floor(s / 3600);
        const m = Math.round((s % 3600) / 60);
        return m ? `${h} h ${String(m).padStart(2, '0')} m` : `${h} h`;
    }
    const d = Math.floor(s / 86400);
    const h = Math.round((s % 86400) / 3600);
    return h ? `${d} d ${h} h` : `${d} d`;
}

/** Porcentaje con un decimal: (12, 200) → "6,0%". */
export function fmtPct(part, total) {
    if (!total) return '—';
    return `${((part / total) * 100).toFixed(1).replace('.', ',')}%`;
}
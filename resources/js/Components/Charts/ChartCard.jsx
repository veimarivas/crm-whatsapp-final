/**
 * Tarjeta base de todo gráfico: mismo estilo Velzon que el resto del panel
 * (blanca, esquinas 2xl, sombra suave) con título, subtítulo y una ranura
 * de acciones (switch, leyenda, enlace…) en la esquina superior derecha.
 *
 * `children` se reemplaza por <EmptyChart /> cuando `empty` es true, para que
 * ningún gráfico muestre una página rota o NaN cuando el periodo no tiene datos.
 */

export function EmptyChart({ message = 'Sin datos en este periodo.' }) {
    return (
        <div className="h-56 flex items-center justify-center text-sm text-gray-400">
            <div className="text-center space-y-1">
                <svg className="w-8 h-8 mx-auto text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <p className="text-sm">{message}</p>
            </div>
        </div>
    );
}

export default function ChartCard({ title, subtitle, actions, empty = false, emptyMessage, children, className = '' }) {
    return (
        <div className={`bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 ${className}`}>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h3 className="text-base font-bold text-gray-900">{title}</h3>
                    {subtitle && <p className="text-xs text-gray-500 mt-0.5">{subtitle}</p>}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-3">{actions}</div>
                )}
            </div>
            {empty ? <EmptyChart message={emptyMessage} /> : children}
        </div>
    );
}
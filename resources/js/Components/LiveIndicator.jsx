import { useEffect, useState } from 'react';

/**
 * Chip "En vivo" con el tiempo desde el ultimo refresco.
 *
 * Existe porque un tablero que se actualiza solo, sin decirlo, se lee como un
 * tablero congelado: si no se ve nada nuevo no hay forma de distinguir "no
 * llego ningun mensaje" de "esto se quedo pegado". El contador es la prueba de
 * que sigue vivo, y el click fuerza el refresco.
 */
export default function LiveIndicator({ refreshing, lastSync, onRefresh, paused = false }) {
    const [, force] = useState(0);

    // Repinta el contador cada segundo (lastSync solo cambia al refrescar).
    useEffect(() => {
        const t = setInterval(() => force((n) => n + 1), 1000);
        return () => clearInterval(t);
    }, []);

    const seconds = Math.max(0, Math.round((Date.now() - lastSync) / 1000));
    const ago = seconds < 60 ? `hace ${seconds}s` : `hace ${Math.floor(seconds / 60)}m`;

    return (
        <button
            type="button"
            onClick={onRefresh}
            title={paused ? 'Pausado mientras movés tarjetas' : 'Actualizar ahora'}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold ring-1 transition-colors ${
                paused
                    ? 'bg-gray-50 text-gray-500 ring-gray-200'
                    : 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
            }`}
        >
            <span
                className={`w-1.5 h-1.5 rounded-full ${
                    paused ? 'bg-gray-400' : refreshing ? 'bg-emerald-500 animate-ping' : 'bg-emerald-500 animate-pulse'
                }`}
            />
            {paused ? 'Pausado' : `En vivo · ${ago}`}
        </button>
    );
}

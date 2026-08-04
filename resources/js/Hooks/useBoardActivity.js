import { useEffect, useState } from 'react';

/**
 * Marca las tarjetas que cambiaron desde el ultimo vistazo, para que el
 * tablero diga QUE se movio y no solo que se movio algo.
 *
 * Dos marcas distintas, porque no significan lo mismo:
 * - `nuevo`: la tarjeta no estaba antes — contacto que escribe por primera vez.
 * - `mensaje`: la tarjeta ya estaba y le entro un mensaje (cambio su sello de
 *   ultima actividad), asi que ademas subio a la cima de su columna.
 *
 * La marca dura `ttl` y despues se apaga sola. El vencimiento se maneja con un
 * ticker y no con el cleanup del efecto a proposito: el efecto vuelve a correr
 * en cada refresco (llega un array nuevo aunque no haya cambios) y su cleanup
 * borraria el temporizador pendiente, dejando la marca encendida para siempre.
 *
 * @param {Array<{id: string}>} items
 * @param {(item: object) => string|null} stamp  sello de ultima actividad.
 * @param {number} ttl  ms que dura la marca.
 */
export default function useBoardActivity(items, { stamp = (i) => i.last_message_at ?? null, ttl = 20000 } = {}) {
    const [marks, setMarks] = useState(() => new Map()); // id -> { kind, expires }
    const [seen, setSeen] = useState(null); // id -> sello, del vistazo anterior

    useEffect(() => {
        const snapshot = new Map(items.map((i) => [i.id, stamp(i) ?? null]));

        // Primer render: se toma como linea base. Sin esto todo el tablero
        // aparecería "nuevo" al entrar, que es justo lo contrario de avisar.
        if (seen === null) {
            setSeen(snapshot);

            return;
        }

        const cambios = [];
        snapshot.forEach((sello, id) => {
            if (! seen.has(id)) {
                cambios.push([id, 'nuevo']);
            } else if (sello && sello !== seen.get(id)) {
                cambios.push([id, 'mensaje']);
            }
        });

        setSeen(snapshot);

        if (cambios.length === 0) {
            return;
        }

        const expires = Date.now() + ttl;
        setMarks((prev) => {
            const next = new Map(prev);
            cambios.forEach(([id, kind]) => next.set(id, { kind, expires }));

            return next;
        });
    }, [items]);

    // Apaga las marcas vencidas. Solo corre mientras haya alguna encendida.
    useEffect(() => {
        if (marks.size === 0) return undefined;

        const t = setInterval(() => {
            setMarks((prev) => {
                const now = Date.now();
                const next = new Map();
                prev.forEach((v, k) => { if (v.expires > now) next.set(k, v); });

                return next.size === prev.size ? prev : next;
            });
        }, 1000);

        return () => clearInterval(t);
    }, [marks]);

    return {
        /** @returns {'nuevo'|'mensaje'|null} */
        markOf: (id) => marks.get(id)?.kind ?? null,
        activeCount: marks.size,
    };
}

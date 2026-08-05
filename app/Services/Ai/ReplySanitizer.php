<?php

namespace App\Services\Ai;

/**
 * Deja la respuesta como se ve bien en WhatsApp.
 *
 * El prompt pide "sin markdown" desde siempre, y los modelos chicos lo
 * incumplen igual: contestan con `**negritas**` y `### títulos` que en WhatsApp
 * se leen literalmente, con los asteriscos a la vista. Pedirlo más veces no
 * alcanza — un 3B sigue las instrucciones a medias por definición.
 *
 * Así que no se pide: se corrige. Esto es determinista y no depende del humor
 * del modelo.
 */
class ReplySanitizer
{
    public function clean(string $reply): string
    {
        $texto = trim($reply);

        if ($texto === '') {
            return '';
        }

        // Encabezados markdown: "## Programas" → "Programas"
        $texto = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $texto);

        // Negritas/cursivas de markdown. WhatsApp usa *un* asterisco para
        // negrita, así que `**texto**` se ve con los asteriscos puestos.
        $texto = preg_replace('/\*\*(.+?)\*\*/su', '$1', $texto);
        $texto = preg_replace('/__(.+?)__/su', '$1', $texto);

        // Viñetas markdown al principio de línea → guion simple, que en
        // WhatsApp se lee natural.
        $texto = preg_replace('/^\s*[\*\-]\s+/m', '- ', $texto);

        // Bloques de código: no tienen sentido en una conversación de ventas.
        $texto = str_replace('```', '', $texto);

        // Tres o más saltos seguidos: el modelo tiende a espaciar de más.
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }

    /**
     * ¿Se cortó a mitad de frase?
     *
     * Pasa cuando la respuesta llega al tope de tokens: termina en "Dipl" y el
     * cliente recibe media palabra. Es mejor recortar hasta la última idea
     * completa que mandar un pedazo.
     */
    public function looksTruncated(string $reply): bool
    {
        $texto = rtrim($reply);

        if ($texto === '') {
            return false;
        }

        // Termina en signo de puntuación o emoji → se cerró bien.
        return (bool) ! preg_match('/[.!?…:)\]"\p{So}]$/u', $texto);
    }

    /**
     * Corta hasta la última línea completa, para que no se vea partido.
     */
    public function trimToLastComplete(string $reply): string
    {
        $lineas = preg_split('/\n/', rtrim($reply));

        // Se descarta la última línea (la que quedó a medias) siempre que
        // quede algo que valga la pena mandar.
        if (count($lineas) > 1) {
            array_pop($lineas);

            return rtrim(implode("\n", $lineas));
        }

        // Una sola línea cortada: hasta la última oración terminada.
        if (preg_match('/^(.*[.!?…])[^.!?…]*$/su', $reply, $m)) {
            return trim($m[1]);
        }

        return trim($reply);
    }
}

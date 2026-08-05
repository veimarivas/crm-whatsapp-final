<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * El proveedor dijo "esperá": cuota agotada por ahora (HTTP 429).
 *
 * Se distingue de cualquier otro fallo porque el arreglo es distinto: no hay
 * nada roto ni que configurar, solo hay que reintentar más tarde. Tratarlo
 * como una falla normal era doblemente malo — apagaba la IA de esa
 * conversación (dos fallos seguidos) por algo que no tiene que ver con ella,
 * y descartaba una respuesta que iba a salir bien en un minuto.
 */
class RateLimitedException extends RuntimeException
{
}

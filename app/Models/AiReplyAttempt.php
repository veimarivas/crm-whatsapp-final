<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de lo que decidió el bot ante un mensaje.
 *
 * Existe porque «no responde» tiene diez causas distintas y todas se ven igual
 * desde afuera. Acá queda cuál fue, con hora.
 */
#[Fillable(['account_id', 'conversation_id', 'decision', 'detail', 'duration_ms'])]
class AiReplyAttempt extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    /** Decisiones posibles, con su explicación para el diagnóstico. */
    public const LABELS = [
        'encolada' => 'Mensaje recibido: se encoló la respuesta',
        'enviada' => 'Respondió',
        'sin_config' => 'La cuenta no tiene IA activa',
        'ia_apagada' => 'La IA está apagada en esta conversación',
        'pausa' => 'En pausa por haber agotado el tope',
        'tope' => 'Alcanzó el tope de respuestas: pasa a pausa',
        'flow_activo' => 'Hay un chatbot (flow) activo, la IA se abstiene',
        'fuera_horario' => 'Fuera de horario: se envió el mensaje de ausencia',
        'ocupado' => 'El modelo estaba ocupado: se reencoló',
        'abandonada' => 'El modelo siguió ocupado tras varios intentos',
        'descartada' => 'El cliente escribió de nuevo mientras se generaba',
        'vacia' => 'El modelo devolvió una respuesta vacía',
        'limite_proveedor' => 'Cuota del proveedor agotada: se reintenta',
        'fallo' => 'Falló la generación',
    ];

    /** Nunca puede romper el flujo: es un registro, no una función. */
    public static function registrar(Conversation $conversation, string $decision, ?string $detail = null, ?float $inicio = null): void
    {
        rescue(fn () => static::create([
            'account_id' => $conversation->account_id,
            'conversation_id' => $conversation->id,
            'decision' => $decision,
            'detail' => $detail ? mb_substr($detail, 0, 500) : null,
            'duration_ms' => $inicio ? (int) round((microtime(true) - $inicio) * 1000) : null,
        ]), report: false);
    }
}

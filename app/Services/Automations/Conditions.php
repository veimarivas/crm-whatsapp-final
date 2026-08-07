<?php

namespace App\Services\Automations;

use App\Models\Contact;

/**
 * Evaluación de un paso `condition`. Vive fuera del Engine porque el
 * simulador (`Simulator`) tiene que dar exactamente el mismo resultado
 * que la ejecución real — si esto se duplicara, la prueba mentiría.
 */
class Conditions
{
    public const FIELDS = ['message_text', 'contact_name', 'contact_email', 'contact_company', 'has_tag'];

    public const OPERATORS = ['contains', 'equals', 'not_equals', 'empty', 'not_empty'];

    public static function evaluate(array $config, ?Contact $contact, ?string $messageText): bool
    {
        $field = $config['field'] ?? 'message_text';
        $operator = $config['operator'] ?? 'contains';
        $expected = mb_strtolower((string) ($config['value'] ?? ''));

        if ($field === 'has_tag') {
            return $contact?->tags()->where('tags.id', $config['tag_id'] ?? '')->exists() ?? false;
        }

        $actual = mb_strtolower((string) match ($field) {
            'message_text' => $messageText ?? '',
            'contact_name' => $contact?->name ?? '',
            'contact_email' => $contact?->email ?? '',
            'contact_company' => $contact?->company ?? '',
            default => '',
        });

        return match ($operator) {
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'empty' => $actual === '',
            'not_empty' => $actual !== '',
            default => false,
        };
    }
}

<?php

namespace App\Services\Automations;

use App\Models\Contact;
use App\Models\Tag;
use App\Services\WhatsApp\Messenger;

/**
 * Recorre una automatización SIN ejecutar nada: no envía WhatsApp, no
 * toca etiquetas, no llama webhooks, no escribe logs.
 *
 * Trabaja sobre el árbol que tiene el editor en pantalla (no sobre lo
 * guardado), así se puede probar antes de guardar y antes de activar.
 *
 * Las condiciones y el match del disparador se resuelven con el MISMO
 * código que usa el Engine (`Conditions`, `Engine::matchesTrigger`) —
 * si divergieran, la prueba diría una cosa y producción haría otra.
 */
class Simulator
{
    /** Un `wait` corta el recorrido: lo que sigue ocurre más tarde. */
    private bool $paused = false;

    public function run(
        string $triggerType,
        array $triggerConfig,
        array $steps,
        ?string $messageText,
        ?Contact $contact,
    ): array {
        $matched = Engine::matchesTrigger($triggerType, $triggerConfig, $messageText);

        return [
            'trigger' => [
                'matched' => $matched,
                'reason' => $this->triggerReason($triggerType, $triggerConfig, $messageText, $matched),
            ],
            'steps' => $matched ? $this->walk($steps, $messageText, $contact, 0) : [],
        ];
    }

    private function triggerReason(string $type, array $config, ?string $text, bool $matched): string
    {
        return match (true) {
            $type === 'new_contact' => 'Se dispararía solo la primera vez que este número escribe. En la prueba se asume que es la primera.',
            $type === 'inbound_message' => 'Se dispara con cualquier mensaje entrante, así que este también cuenta.',
            $matched => 'El mensaje contiene una de las palabras clave.',
            default => 'El mensaje no contiene ninguna de las palabras clave: no pasaría nada.',
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function walk(array $steps, ?string $messageText, ?Contact $contact, int $depth, ?string $branch = null): array
    {
        $out = [];

        foreach ($steps as $step) {
            if ($this->paused) {
                $out[] = $this->entry($step, $depth, $branch, 'later', 'Ocurriría después de la espera.', $this->describe($step, $contact));

                if (($step['type'] ?? '') === 'condition') {
                    $out = array_merge(
                        $out,
                        $this->walk($step['children_yes'] ?? [], $messageText, $contact, $depth + 1, 'yes'),
                        $this->walk($step['children_no'] ?? [], $messageText, $contact, $depth + 1, 'no'),
                    );
                }

                continue;
            }

            $type = $step['type'] ?? '';

            if ($type === 'condition') {
                $result = Conditions::evaluate($step['config'] ?? [], $contact, $messageText);

                $out[] = $this->entry(
                    $step,
                    $depth,
                    $branch,
                    $result ? 'yes' : 'no',
                    $result ? 'Se cumple: sigue por la rama Sí.' : 'No se cumple: sigue por la rama No.',
                    $this->describe($step, $contact),
                );

                $taken = $result ? ($step['children_yes'] ?? []) : ($step['children_no'] ?? []);
                $skipped = $result ? ($step['children_no'] ?? []) : ($step['children_yes'] ?? []);

                $out = array_merge($out, $this->walk($taken, $messageText, $contact, $depth + 1, $result ? 'yes' : 'no'));

                foreach ($skipped as $s) {
                    $out[] = $this->entry($s, $depth + 1, $result ? 'no' : 'yes', 'skipped', 'No se ejecuta: la condición fue por la otra rama.', $this->describe($s, $contact));
                }

                continue;
            }

            if ($type === 'wait') {
                $minutes = max(1, (int) ($step['config']['minutes'] ?? 60));
                $this->paused = true;

                $out[] = $this->entry($step, $depth, $branch, 'wait', 'Acá se corta y se retoma solo cuando venza la espera (lo hace el cron `automations:process-pending`).', 'Esperar '.self::humanMinutes($minutes));

                continue;
            }

            [$status, $note] = $this->validate($step, $contact);
            $out[] = $this->entry($step, $depth, $branch, $status, $note, $this->describe($step, $contact));
        }

        return $out;
    }

    /** Detecta pasos que reventarían en producción (config incompleta). */
    private function validate(array $step, ?Contact $contact): array
    {
        $config = $step['config'] ?? [];

        return match ($step['type'] ?? '') {
            'send_message' => trim((string) ($config['text'] ?? '')) === ''
                ? ['error', 'El mensaje está vacío: no se enviaría nada.']
                : ['ok', 'Se enviaría por WhatsApp a este contacto.'],
            'add_tag', 'remove_tag' => empty($config['tag_id'])
                ? ['error', 'Falta elegir la etiqueta: el paso se saltaría.']
                : ['ok', 'Se aplicaría sobre el contacto.'],
            'webhook' => str_starts_with((string) ($config['url'] ?? ''), 'http')
                ? ['ok', 'Se haría un POST con los datos del contacto.']
                : ['error', 'La URL no es válida: el paso fallaría.'],
            default => ['ok', ''],
        };
    }

    /** Texto que ve el usuario: el mensaje ya interpolado, el nombre real de la etiqueta, etc. */
    private function describe(array $step, ?Contact $contact): string
    {
        $config = $step['config'] ?? [];

        return match ($step['type'] ?? '') {
            'send_message' => $contact
                ? Messenger::interpolate((string) ($config['text'] ?? ''), $contact)
                : (string) ($config['text'] ?? ''),
            'add_tag', 'remove_tag' => $this->tagName($config['tag_id'] ?? null) ?? '(sin etiqueta elegida)',
            'webhook' => (string) ($config['url'] ?? ''),
            'wait' => 'Esperar '.self::humanMinutes((int) ($config['minutes'] ?? 60)),
            'condition' => $this->conditionText($config),
            default => '',
        };
    }

    private function conditionText(array $config): string
    {
        $fields = [
            'message_text' => 'el texto del mensaje',
            'contact_name' => 'el nombre del contacto',
            'contact_email' => 'el email del contacto',
            'contact_company' => 'la empresa del contacto',
            'has_tag' => 'las etiquetas del contacto',
        ];

        if (($config['field'] ?? '') === 'has_tag') {
            return 'Si el contacto tiene la etiqueta «'.($this->tagName($config['tag_id'] ?? null) ?? '?').'»';
        }

        $operators = [
            'contains' => 'contiene',
            'equals' => 'es igual a',
            'not_equals' => 'es distinto de',
            'empty' => 'está vacío',
            'not_empty' => 'no está vacío',
        ];

        $field = $fields[$config['field'] ?? 'message_text'] ?? 'el texto del mensaje';
        $operator = $operators[$config['operator'] ?? 'contains'] ?? 'contiene';
        $value = (string) ($config['value'] ?? '');

        return in_array($config['operator'] ?? '', ['empty', 'not_empty'], true)
            ? "Si {$field} {$operator}"
            : "Si {$field} {$operator} «{$value}»";
    }

    private function tagName(?string $tagId): ?string
    {
        return $tagId ? Tag::find($tagId)?->name : null;
    }

    private function entry(array $step, int $depth, ?string $branch, string $status, string $note, string $detail): array
    {
        return [
            'type' => $step['type'] ?? '',
            'depth' => $depth,
            'branch' => $branch,
            'status' => $status,
            'note' => $note,
            'detail' => $detail,
        ];
    }

    public static function humanMinutes(int $minutes): string
    {
        $minutes = max(1, $minutes);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.($days === 1 ? ' día' : ' días');
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.($hours === 1 ? ' hora' : ' horas');
        }

        return intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}

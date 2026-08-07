<?php

namespace App\Services\Flows;

use App\Models\Contact;
use App\Models\Flow;
use App\Models\Tag;
use App\Services\WhatsApp\Messenger;

/**
 * Conversa con el flow SIN mandar nada por WhatsApp.
 *
 * Es sin estado: el cliente guarda la lista de respuestas del "cliente"
 * y en cada llamada se reproduce la conversación entera desde el nodo
 * de entrada. Así se prueba el grafo **que está en pantalla**, sin
 * guardarlo ni activarlo, y sin ensuciar `flow_runs`.
 *
 * Replica las reglas del Runner: interpolación de variables, match de
 * botón por id o por texto exacto, condiciones sobre variables,
 * reprompts y `on_exhaust`. Lo único que no hace de verdad es tocar el
 * mundo exterior: `set_tag` y `http_fetch` se anotan como simulados.
 */
class Simulator
{
    /** Igual que Runner::MAX_TRANSITIONS: corta ciclos de grafos mal armados. */
    private const MAX_TRANSITIONS = 20;

    /** Tope global de la reproducción, para que un ciclo entre turnos no cuelgue la request. */
    private const MAX_TRANSCRIPT = 120;

    private array $transcript = [];

    private array $vars = [];

    private array $nodes = [];

    private array $policy = [];

    private ?Contact $contact = null;

    private int $reprompts = 0;

    /**
     * @param  array  $nodes    grafo tal cual lo tiene el editor
     * @param  array  $replies  lo que fue respondiendo el "cliente", en orden
     */
    public function run(array $flow, array $nodes, array $replies, ?Contact $contact): array
    {
        $this->transcript = [];
        $this->vars = [];
        $this->contact = $contact;
        $this->reprompts = 0;
        $this->policy = array_merge(Flow::DEFAULT_FALLBACK_POLICY, $flow['fallback_policy'] ?? []);
        $this->nodes = [];

        foreach ($nodes as $node) {
            if (! empty($node['node_key'])) {
                $this->nodes[$node['node_key']] = $node;
            }
        }

        $entry = $flow['entry_node_id'] ?? null;

        if (! $entry || ! isset($this->nodes[$entry])) {
            return $this->result('failed', null, "El nodo de entrada «{$entry}» no existe en el grafo.");
        }

        $state = $this->execute($entry);

        foreach ($replies as $reply) {
            if ($state['status'] !== 'awaiting') {
                break;
            }

            $this->say('user', (string) $reply);
            $state = $this->reply($state['node_key'], (string) $reply);
        }

        return $this->result($state['status'], $state['node_key'] ?? null, $state['error'] ?? null);
    }

    /**
     * Ejecuta la cadena desde $nodeKey hasta un nodo que espera respuesta
     * o hasta un terminal. Espeja `Runner::executeFrom`.
     */
    private function execute(?string $nodeKey): array
    {
        for ($i = 0; $i < self::MAX_TRANSITIONS; $i++) {
            if (count($this->transcript) >= self::MAX_TRANSCRIPT) {
                return ['status' => 'failed', 'node_key' => $nodeKey, 'error' => 'La simulación se cortó: demasiados pasos (¿un ciclo?).'];
            }

            if ($nodeKey === null || $nodeKey === '') {
                $this->note('Rama sin continuación: la conversación termina acá.', 'info');

                return ['status' => 'ended', 'node_key' => null];
            }

            $node = $this->nodes[$nodeKey] ?? null;

            if (! $node) {
                return ['status' => 'failed', 'node_key' => $nodeKey, 'error' => "El nodo «{$nodeKey}» no existe. Revisa a dónde apunta la conexión anterior."];
            }

            $config = $node['config'] ?? [];

            switch ($node['node_type'] ?? '') {
                case 'send_message':
                    $this->say('bot', $this->interpolate($config['text'] ?? ''), $nodeKey);
                    $nodeKey = $config['next'] ?? null;
                    break;

                case 'set_tag':
                    $name = ! empty($config['tag_id']) ? Tag::find($config['tag_id'])?->name : null;
                    $name
                        ? $this->note("Se etiquetaría al contacto con «{$name}».", 'success', $nodeKey)
                        : $this->note('Este nodo no tiene etiqueta elegida: en producción no haría nada.', 'warn', $nodeKey);
                    $nodeKey = $config['next'] ?? null;
                    break;

                case 'condition':
                    $result = $this->evaluateCondition($config);
                    $var = $config['var'] ?? '';
                    $this->note(
                        sprintf('Condición sobre {%s} (vale «%s»): %s', $var, $this->vars[$var] ?? '', $result ? 'sí' : 'no'),
                        'info',
                        $nodeKey,
                    );
                    $nodeKey = $result ? ($config['next_yes'] ?? null) : ($config['next_no'] ?? null);
                    break;

                case 'http_fetch':
                    // En simulación NO se llama al servicio externo.
                    $var = $config['var'] ?? 'response';
                    $this->vars[$var] = '(respuesta simulada)';
                    $this->note('Llamaría a '.($config['url'] ?: 'una URL sin definir')." y guardaría el resultado en {{$var}}. En la prueba no se llama de verdad.", 'info', $nodeKey);
                    $nodeKey = $config['next'] ?? null;
                    break;

                case 'send_buttons':
                    $this->say('bot', $this->interpolate($config['text'] ?? ''), $nodeKey, $this->options($config['buttons'] ?? []), 'buttons');

                    return ['status' => 'awaiting', 'node_key' => $nodeKey];

                case 'send_list':
                    $this->say('bot', $this->interpolate($config['text'] ?? ''), $nodeKey, $this->options($config['rows'] ?? []), 'list');

                    return ['status' => 'awaiting', 'node_key' => $nodeKey];

                case 'collect_input':
                    $this->say('bot', $this->interpolate($config['text'] ?? ''), $nodeKey, [], 'input');

                    return ['status' => 'awaiting', 'node_key' => $nodeKey];

                case 'handoff':
                    if (! empty($config['message'])) {
                        $this->say('bot', $this->interpolate($config['message']), $nodeKey);
                    }
                    $this->note('La conversación pasa a un agente humano y queda en «pendiente».', 'success', $nodeKey);

                    return ['status' => 'handoff', 'node_key' => $nodeKey];

                case 'end':
                    if (! empty($config['message'])) {
                        $this->say('bot', $this->interpolate($config['message']), $nodeKey);
                    }
                    $this->note('Fin del chatbot.', 'success', $nodeKey);

                    return ['status' => 'ended', 'node_key' => $nodeKey];

                default:
                    return ['status' => 'failed', 'node_key' => $nodeKey, 'error' => 'Tipo de nodo desconocido: '.($node['node_type'] ?? '?')];
            }
        }

        return ['status' => 'failed', 'node_key' => $nodeKey, 'error' => 'Se superó el máximo de saltos seguidos: el grafo tiene un ciclo.'];
    }

    /** Consume una respuesta del cliente estando parados en $nodeKey. */
    private function reply(string $nodeKey, string $text): array
    {
        $node = $this->nodes[$nodeKey] ?? null;

        if (! $node) {
            return ['status' => 'failed', 'node_key' => $nodeKey, 'error' => "El nodo «{$nodeKey}» desapareció del grafo."];
        }

        $config = $node['config'] ?? [];

        $next = match ($node['node_type']) {
            'send_buttons' => $this->matchOption($config['buttons'] ?? [], $text),
            'send_list' => $this->matchOption($config['rows'] ?? [], $text),
            'collect_input' => $this->captureInput($config, $text),
            default => null,
        };

        if ($next === null) {
            return $this->fallback($nodeKey);
        }

        $this->reprompts = 0;

        return $this->execute($next);
    }

    /** Espeja `Runner::fallback`: reprompt hasta el máximo, después on_exhaust. */
    private function fallback(string $nodeKey): array
    {
        $max = (int) ($this->policy['max_reprompts'] ?? 2);

        if ($this->reprompts < $max) {
            $this->reprompts++;
            $this->note("No se reconoce esa respuesta. Reintento {$this->reprompts} de {$max}: se vuelve a enviar la pregunta.", 'warn', $nodeKey);

            return $this->execute($nodeKey);
        }

        if (($this->policy['on_exhaust'] ?? 'handoff') === 'handoff') {
            $this->say('bot', 'Un agente te atenderá en breve.', $nodeKey);
            $this->note('Se agotaron los reintentos: pasa a un agente humano.', 'warn', $nodeKey);

            return ['status' => 'handoff', 'node_key' => $nodeKey];
        }

        $this->note('Se agotaron los reintentos: la conversación termina.', 'warn', $nodeKey);

        return ['status' => 'ended', 'node_key' => $nodeKey];
    }

    /** Igual que el Runner: coincide por id de botón o por el título exacto. */
    private function matchOption(array $options, string $text): ?string
    {
        foreach ($options as $option) {
            $byId = ($option['id'] ?? null) !== null && $option['id'] === $text;
            $byTitle = mb_strtolower(trim($text)) === mb_strtolower($option['title'] ?? '');

            if ($byId || ($byTitle && ($option['title'] ?? '') !== '')) {
                return $option['next'] ?? null;
            }
        }

        return null;
    }

    private function captureInput(array $config, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $var = $config['var'] ?? 'input';
        $this->vars[$var] = mb_substr(trim($text), 0, 500);
        $this->note("Se guarda «{$this->vars[$var]}» en la variable {{$var}}.", 'info');

        return $config['next'] ?? null;
    }

    private function evaluateCondition(array $config): bool
    {
        $actual = mb_strtolower((string) ($this->vars[$config['var'] ?? ''] ?? ''));
        $expected = mb_strtolower((string) ($config['value'] ?? ''));

        return match ($config['operator'] ?? 'equals') {
            'equals' => $actual === $expected,
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'not_empty' => $actual !== '',
            default => false,
        };
    }

    /** Variables capturadas + datos del contacto, igual que `Runner::interpolate`. */
    private function interpolate(string $text): string
    {
        foreach ($this->vars as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $this->contact ? Messenger::interpolate($text, $this->contact) : $text;
    }

    private function options(array $raw): array
    {
        return array_values(array_map(fn ($o) => [
            'id' => $o['id'] ?? '',
            'title' => $o['title'] ?? '',
            'dangling' => empty($o['next']) || ! isset($this->nodes[$o['next']]),
        ], $raw));
    }

    private function say(string $from, string $text, ?string $nodeKey = null, array $options = [], string $kind = 'text'): void
    {
        $this->transcript[] = [
            'from' => $from,
            'kind' => $from === 'user' ? 'text' : $kind,
            'text' => $text,
            'options' => $options,
            'node_key' => $nodeKey,
        ];
    }

    private function note(string $text, string $tone, ?string $nodeKey = null): void
    {
        $this->transcript[] = [
            'from' => 'system',
            'kind' => 'note',
            'tone' => $tone,
            'text' => $text,
            'options' => [],
            'node_key' => $nodeKey,
        ];
    }

    private function result(string $status, ?string $nodeKey, ?string $error): array
    {
        $last = null;

        foreach (array_reverse($this->transcript) as $entry) {
            if ($entry['from'] === 'bot' && in_array($entry['kind'], ['buttons', 'list', 'input'], true)) {
                $last = $entry;
                break;
            }
        }

        return [
            'status' => $status,
            'current_node' => $nodeKey,
            'error' => $error,
            'vars' => $this->vars,
            'transcript' => $this->transcript,
            // Qué puede contestar ahora el cliente: alimenta el composer del chat de prueba.
            'awaiting' => $status === 'awaiting' ? [
                'kind' => $last['kind'] ?? 'input',
                'options' => $last['options'] ?? [],
            ] : null,
        ];
    }
}

<?php

namespace App\Services\Flows;

use App\Services\Academico\Plantillas;

/**
 * Chatbots ya armados que se eligen al crear un flow, en vez de
 * empezar siempre con el mismo menú.
 *
 * Los nodos `set_tag` van sin `tag_id`: el id es por cuenta y quedaría
 * colgado. El editor marca esos nodos como incompletos.
 */
class Recipes
{
    public const DEFAULT = 'menu-principal';

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'slug' => 'menu-principal',
                'title' => 'Menú principal',
                'summary' => 'Saluda y ofrece dos botones: información o hablar con un asesor.',
                'why' => 'El punto de partida clásico. Si no sabes cuál elegir, empieza por este.',
                'icon' => 'menu',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['hola', 'buenas', 'información']],
                'entry_node_id' => 'menu',
                'nodes' => [
                    ['node_key' => 'menu', 'node_type' => 'send_buttons', 'config' => [
                        'text' => '¡Hola {name}! 👋 ¿En qué podemos ayudarte?',
                        'buttons' => [
                            ['id' => 'info', 'title' => 'Información', 'next' => 'info'],
                            ['id' => 'agente', 'title' => 'Hablar con asesor', 'next' => 'pasar_agente'],
                        ],
                    ]],
                    ['node_key' => 'info', 'node_type' => 'send_message', 'config' => [
                        'text' => 'Somos ESAM. Atendemos de lunes a viernes de 9:00 a 18:00.',
                        'next' => 'despedida',
                    ]],
                    ['node_key' => 'pasar_agente', 'node_type' => 'handoff', 'config' => [
                        'message' => 'Perfecto, un asesor te atenderá en breve. 🙌',
                    ]],
                    ['node_key' => 'despedida', 'node_type' => 'end', 'config' => [
                        'message' => '¡Gracias por escribirnos!',
                    ]],
                ],
            ],
            [
                'slug' => 'calificar-lead',
                'title' => 'Calificar al interesado',
                'summary' => 'Pregunta qué tipo de programa busca, lo etiqueta según la respuesta y lo pasa a un asesor.',
                'why' => 'Llega al asesor ya segmentado: no hay que preguntar lo mismo dos veces.',
                'icon' => 'qualify',
                'needs_tag' => true,
                'trigger_type' => 'first_inbound_message',
                'trigger_config' => [],
                'entry_node_id' => 'que_busca',
                'nodes' => [
                    ['node_key' => 'que_busca', 'node_type' => 'send_buttons', 'config' => [
                        'text' => '¡Hola {name}! Para orientarte mejor: ¿qué tipo de programa buscas?',
                        'buttons' => [
                            ['id' => 'maestria', 'title' => 'Maestría', 'next' => 'tag_maestria'],
                            ['id' => 'diplomado', 'title' => 'Diplomado', 'next' => 'tag_diplomado'],
                            ['id' => 'curso', 'title' => 'Curso corto', 'next' => 'tag_curso'],
                        ],
                    ]],
                    ['node_key' => 'tag_maestria', 'node_type' => 'set_tag', 'config' => ['next' => 'pasar_asesor']],
                    ['node_key' => 'tag_diplomado', 'node_type' => 'set_tag', 'config' => ['next' => 'pasar_asesor']],
                    ['node_key' => 'tag_curso', 'node_type' => 'set_tag', 'config' => ['next' => 'pasar_asesor']],
                    ['node_key' => 'pasar_asesor', 'node_type' => 'handoff', 'config' => [
                        'message' => '¡Gracias! Un asesor especializado te contacta en unos minutos.',
                    ]],
                ],
            ],
            [
                'slug' => 'capturar-datos',
                'title' => 'Capturar datos del interesado',
                'summary' => 'Pide correo y ciudad, los guarda en variables y se los muestra al despedirse.',
                'why' => 'El ejemplo más claro de cómo funcionan las variables `{correo}` y `{ciudad}`.',
                'icon' => 'form',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['inscribir', 'inscripción', 'quiero postular']],
                'entry_node_id' => 'pedir_correo',
                'nodes' => [
                    ['node_key' => 'pedir_correo', 'node_type' => 'collect_input', 'config' => [
                        'text' => '{name}, para enviarte la información: ¿cuál es tu correo electrónico?',
                        'var' => 'correo',
                        'next' => 'pedir_ciudad',
                    ]],
                    ['node_key' => 'pedir_ciudad', 'node_type' => 'collect_input', 'config' => [
                        'text' => '¡Gracias! ¿Desde qué ciudad nos escribes?',
                        'var' => 'ciudad',
                        'next' => 'confirmar',
                    ]],
                    ['node_key' => 'confirmar', 'node_type' => 'end', 'config' => [
                        'message' => 'Perfecto {name}: te escribimos a {correo} y te contamos las opciones en {ciudad}. 📩',
                    ]],
                ],
            ],
            [
                'slug' => 'preguntas-frecuentes',
                'title' => 'Preguntas frecuentes',
                'summary' => 'Muestra una lista desplegable con 4 dudas típicas y responde cada una.',
                'why' => 'Descarga al equipo de las preguntas que siempre son iguales.',
                'icon' => 'faq',
                'trigger_type' => 'keyword',
                'trigger_config' => ['keywords' => ['dudas', 'preguntas', 'ayuda']],
                'entry_node_id' => 'lista_faq',
                'nodes' => [
                    ['node_key' => 'lista_faq', 'node_type' => 'send_list', 'config' => [
                        'text' => '{name}, elige tu consulta y te respondo al instante:',
                        'button_label' => 'Ver opciones',
                        'rows' => [
                            ['id' => 'costos', 'title' => 'Costos y pagos', 'next' => 'r_costos'],
                            ['id' => 'duracion', 'title' => 'Duración', 'next' => 'r_duracion'],
                            ['id' => 'modalidad', 'title' => 'Modalidad', 'next' => 'r_modalidad'],
                            ['id' => 'otra', 'title' => 'Otra consulta', 'next' => 'pasar_agente'],
                        ],
                    ]],
                    ['node_key' => 'r_costos', 'node_type' => 'send_message', 'config' => [
                        'text' => 'Manejamos pago al contado con descuento y planes en cuotas sin interés.',
                        'next' => 'algo_mas',
                    ]],
                    ['node_key' => 'r_duracion', 'node_type' => 'send_message', 'config' => [
                        'text' => 'Los diplomados duran 6 meses y las maestrías 24 meses.',
                        'next' => 'algo_mas',
                    ]],
                    ['node_key' => 'r_modalidad', 'node_type' => 'send_message', 'config' => [
                        'text' => 'Clases virtuales en vivo, con grabación disponible por si no puedes conectarte.',
                        'next' => 'algo_mas',
                    ]],
                    ['node_key' => 'algo_mas', 'node_type' => 'send_buttons', 'config' => [
                        'text' => '¿Te ayudo con algo más?',
                        'buttons' => [
                            ['id' => 'si', 'title' => 'Sí, otra consulta', 'next' => 'lista_faq'],
                            ['id' => 'no', 'title' => 'No, gracias', 'next' => 'despedida'],
                        ],
                    ]],
                    ['node_key' => 'pasar_agente', 'node_type' => 'handoff', 'config' => [
                        'message' => 'Te comunico con un asesor para resolverlo. 🙌',
                    ]],
                    ['node_key' => 'despedida', 'node_type' => 'end', 'config' => [
                        'message' => '¡Que tengas un excelente día, {name}!',
                    ]],
                ],
            ],
        ];
    }

    /**
     * Las genéricas más las generadas con la oferta académica real de
     * `esam_datos` (la misma fuente que la base de conocimiento de la IA).
     * Si esa BD no responde, las dinámicas simplemente no aparecen.
     */
    public static function todas(): array
    {
        return [...self::all(), ...app(Plantillas::class)->flows()];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::todas() as $recipe) {
            if ($recipe['slug'] === $slug) {
                return $recipe;
            }
        }

        return null;
    }

    /** Catálogo para el modal de creación: sin el grafo completo. */
    public static function gallery(): array
    {
        return array_map(fn (array $r) => [
            'slug' => $r['slug'],
            'title' => $r['title'],
            'summary' => $r['summary'],
            'why' => $r['why'],
            'icon' => $r['icon'],
            'needs_tag' => $r['needs_tag'] ?? false,
            // 'oferta' = generada desde la BD académica; la UI las agrupa aparte.
            'source' => $r['source'] ?? 'base',
            'nodes_count' => count($r['nodes']),
        ], self::todas());
    }
}

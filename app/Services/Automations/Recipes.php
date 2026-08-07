<?php

namespace App\Services\Automations;

use App\Services\Academico\Plantillas;

/**
 * Recetas: automatizaciones ya armadas que el usuario elige de una
 * galería y ajusta, en vez de partir de un lienzo vacío.
 *
 * Ninguna receta referencia etiquetas por id — el id es por cuenta y
 * quedaría colgado. Los pasos de etiqueta se dejan sin elegir y la UI
 * marca el paso como incompleto.
 */
class Recipes
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'slug' => 'bienvenida',
                'title' => 'Bienvenida a contacto nuevo',
                'summary' => 'Cuando alguien escribe por primera vez, responde al instante presentándote.',
                'why' => 'El primer minuto es el que más convierte: nadie queda esperando.',
                'icon' => 'welcome',
                'automation' => [
                    'name' => 'Bienvenida a contacto nuevo',
                    'description' => 'Saludo automático la primera vez que escriben',
                    'trigger_type' => 'new_contact',
                    'trigger_config' => [],
                    'steps' => [
                        ['type' => 'send_message', 'config' => [
                            'text' => "¡Hola {name}! 👋 Gracias por escribirnos. Cuéntanos qué programa te interesa y te damos toda la información.",
                        ]],
                    ],
                ],
            ],
            [
                'slug' => 'precios',
                'title' => 'Responder por precios',
                'summary' => 'Detecta «precio», «costo» o «cuánto» y manda la información de inversión.',
                'why' => 'Es la pregunta más repetida; contestarla sola libera al equipo.',
                'icon' => 'price',
                'automation' => [
                    'name' => 'Consulta de precios',
                    'description' => 'Respuesta automática a preguntas de costo',
                    'trigger_type' => 'keyword',
                    'trigger_config' => ['keywords' => ['precio', 'precios', 'costo', 'cuánto cuesta', 'inversión']],
                    'steps' => [
                        ['type' => 'send_message', 'config' => [
                            'text' => "Con gusto, {name}. Te comparto la información de inversión y las formas de pago disponibles. ¿Sobre qué programa quieres el detalle?",
                        ]],
                    ],
                ],
            ],
            [
                'slug' => 'seguimiento-24h',
                'title' => 'Seguimiento si no responde',
                'summary' => 'Responde, espera 24 horas y vuelve a escribir para reactivar la conversación.',
                'why' => 'La mayoría de los leads se pierden por no insistir una segunda vez.',
                'icon' => 'follow',
                'automation' => [
                    'name' => 'Seguimiento a las 24 horas',
                    'description' => 'Reactiva al interesado que no siguió la conversación',
                    'trigger_type' => 'keyword',
                    'trigger_config' => ['keywords' => ['información', 'informacion', 'info', 'programas']],
                    'steps' => [
                        ['type' => 'send_message', 'config' => [
                            'text' => "¡Claro que sí, {name}! Te paso la información. Si quieres, te reservo un cupo para el próximo grupo.",
                        ]],
                        ['type' => 'wait', 'config' => ['minutes' => 1440]],
                        ['type' => 'send_message', 'config' => [
                            'text' => "Hola {name}, ¿pudiste revisar la información? Quedo atento a cualquier duda. 🙂",
                        ]],
                    ],
                ],
            ],
            [
                'slug' => 'etiquetar-interesado',
                'title' => 'Etiquetar a los interesados',
                'summary' => 'Cuando piden inscribirse, les pone una etiqueta para filtrarlos después.',
                'why' => 'Deja tu base segmentada sola, sin que nadie etiquete a mano.',
                'icon' => 'tag',
                'needs_tag' => true,
                'automation' => [
                    'name' => 'Etiquetar interesados en inscripción',
                    'description' => 'Marca a quienes preguntan por inscribirse',
                    'trigger_type' => 'keyword',
                    'trigger_config' => ['keywords' => ['inscribir', 'inscripción', 'inscripcion', 'matrícula', 'matricula']],
                    'steps' => [
                        ['type' => 'add_tag', 'config' => []],
                        ['type' => 'send_message', 'config' => [
                            'text' => "¡Excelente, {name}! Te acompaño con el proceso de inscripción paso a paso.",
                        ]],
                    ],
                ],
            ],
            [
                'slug' => 'derivar-asesor',
                'title' => 'Pedir hablar con un asesor',
                'summary' => 'Si escriben «asesor» o «humano», avisa que ya viene alguien del equipo.',
                'why' => 'Evita que el interesado sienta que solo lo atiende un bot.',
                'icon' => 'agent',
                'automation' => [
                    'name' => 'Derivar a un asesor',
                    'description' => 'Acuse de recibo cuando piden atención humana',
                    'trigger_type' => 'keyword',
                    'trigger_config' => ['keywords' => ['asesor', 'humano', 'persona', 'hablar con alguien']],
                    'steps' => [
                        ['type' => 'send_message', 'config' => [
                            'text' => "Por supuesto, {name}. Un asesor te contacta en unos minutos por este mismo chat.",
                        ]],
                    ],
                ],
            ],
            [
                'slug' => 'ruta-por-interes',
                'title' => 'Respuesta distinta según el interés',
                'summary' => 'Revisa si el mensaje menciona «maestría» y responde una cosa u otra.',
                'why' => 'Es el ejemplo más simple de una condición sí/no para copiar y adaptar.',
                'icon' => 'branch',
                'automation' => [
                    'name' => 'Ruta según el interés',
                    'description' => 'Condición sí/no sobre el texto del mensaje',
                    'trigger_type' => 'inbound_message',
                    'trigger_config' => [],
                    'steps' => [
                        [
                            'type' => 'condition',
                            'config' => ['field' => 'message_text', 'operator' => 'contains', 'value' => 'maestría'],
                            'children_yes' => [
                                ['type' => 'send_message', 'config' => [
                                    'text' => "{name}, te paso el detalle de nuestras maestrías: duración, modalidad y fechas de inicio.",
                                ]],
                            ],
                            'children_no' => [
                                ['type' => 'send_message', 'config' => [
                                    'text' => "Gracias por escribir, {name}. ¿Te interesa un diplomado, una maestría o un curso corto?",
                                ]],
                            ],
                        ],
                    ],
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
        return [...self::all(), ...app(Plantillas::class)->automatizaciones()];
    }

    /** Devuelve la receta (o null) lista para precargar el formulario. */
    public static function find(string $slug): ?array
    {
        foreach (self::todas() as $recipe) {
            if ($recipe['slug'] === $slug) {
                return $recipe;
            }
        }

        return null;
    }

    /** Catálogo para la galería: sin el árbol de pasos completo. */
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
            'trigger_type' => $r['automation']['trigger_type'],
            'steps_count' => count($r['automation']['steps']),
        ], self::todas());
    }
}

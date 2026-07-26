<?php

namespace App\Services\WhatsApp;

/**
 * Pack de plantillas rápidas sugeridas para un instituto que capta e inscribe
 * alumnos por WhatsApp.
 *
 * IMPORTANTE — esto son mensajes de TEXTO LIBRE, no plantillas aprobadas de
 * Meta. Eso significa que **solo salen dentro de la ventana de servicio**
 * (24 h desde el último mensaje del contacto, o 72 h si llegó por un anuncio
 * Click-to-WhatsApp). Dentro de la ventana no tienen costo; fuera de ella
 * Meta directamente las rechaza — ahí hace falta una plantilla aprobada
 * (Ajustes → Plantillas), que sí se factura.
 *
 * Por eso el Inbox muestra la ventana al lado del contacto: si está en verde,
 * cualquiera de estas sale gratis.
 *
 * Variables disponibles (las sustituye QuickReply::render): {name}, {phone},
 * {email}, {company}.
 */
class SuggestedQuickReplies
{
    /**
     * @return array<int, array{shortcut: string, content: string, group: string}>
     */
    public static function all(): array
    {
        return [
            // --- Información y captación -------------------------------
            [
                'group' => 'Información',
                'shortcut' => 'saludo',
                'content' => "¡Hola {name}! 👋 Gracias por escribirnos.\n\nSoy parte del equipo académico y con gusto te ayudo. ¿Sobre qué programa te gustaría recibir información?",
            ],
            [
                'group' => 'Información',
                'shortcut' => 'programas',
                'content' => "{name}, estos son nuestros programas con inscripciones abiertas 📚\n\n• Maestrías\n• Diplomados\n• Cursos de especialización\n\nDecime cuál te interesa y te paso plan de estudios, duración, horarios y costos. 😊",
            ],
            [
                'group' => 'Información',
                'shortcut' => 'costos',
                'content' => "Con gusto, {name} 💵\n\n• Inversión total: [MONTO]\n• Matrícula: [MONTO]\n• Cuotas: [N] cuotas de [MONTO]\n\nAceptamos transferencia, QR y tarjeta. ¿Querés que te reserve un cupo mientras lo pensás?",
            ],
            [
                'group' => 'Información',
                'shortcut' => 'requisitos',
                'content' => "Para inscribirte necesitás, {name} 📋\n\n1. Fotocopia de cédula de identidad\n2. Título académico (fotocopia legalizada)\n3. Fotografía tipo carnet\n4. Formulario de inscripción (te lo enviamos)\n\n¿Los tenés a mano? Puedo ayudarte a iniciar el trámite hoy mismo.",
            ],
            [
                'group' => 'Información',
                'shortcut' => 'horarios',
                'content' => "{name}, las clases son en este horario 🕐\n\n• Días: [DÍAS]\n• Horario: [HORA INICIO] a [HORA FIN]\n• Modalidad: [presencial / virtual / híbrida]\n\nAsí podés combinarlo con tu trabajo. ¿Te acomoda?",
            ],

            // --- Promoción ---------------------------------------------
            [
                'group' => 'Promoción',
                'shortcut' => 'promo',
                'content' => "🎓 {name}, tenemos una promoción vigente\n\n✅ [X]% de descuento en la matrícula\n✅ Plan de pagos sin interés\n✅ Material de estudio incluido\n\nAplica hasta el [FECHA]. ¿Querés que te reserve el cupo con este precio?",
            ],
            [
                'group' => 'Promoción',
                'shortcut' => 'promo_grupo',
                'content' => "{name}, si venís con alguien más te conviene 👥\n\nInscribiéndose de a dos o más, cada uno recibe [X]% de descuento adicional.\n\n¿Tenés algún colega o compañero que también esté interesado?",
            ],
            [
                'group' => 'Promoción',
                'shortcut' => 'beneficios',
                'content' => "Esto es lo que incluye tu inscripción, {name} ✨\n\n• Certificación avalada\n• Docentes con experiencia en el área\n• Material digital de por vida\n• Bolsa de trabajo y red de egresados\n\n¿Querés que avancemos con tu cupo?",
            ],

            // --- Cierre de inscripciones (urgencia) ---------------------
            [
                'group' => 'Cierre de inscripciones',
                'shortcut' => 'cierre_pronto',
                'content' => "⏳ {name}, te aviso que las inscripciones cierran el [FECHA].\n\nDespués de esa fecha el grupo arranca y ya no podemos sumar participantes hasta la próxima versión.\n\n¿Te reservo el cupo?",
            ],
            [
                'group' => 'Cierre de inscripciones',
                'shortcut' => 'ultimos_cupos',
                'content' => "🔔 {name}, quedan pocos cupos disponibles para este grupo.\n\nLos cupos se asignan por orden de inscripción. Si querés asegurarlo, con la matrícula queda reservado a tu nombre.\n\n¿Avanzamos?",
            ],
            [
                'group' => 'Cierre de inscripciones',
                'shortcut' => 'ultimo_dia',
                'content' => "🚨 {name}, hoy es el último día de inscripciones.\n\nSi todavía te interesa, puedo tomar tus datos ahora y dejarte el cupo reservado. Solo necesito tu nombre completo y CI.\n\n¿Lo hacemos?",
            ],
            [
                'group' => 'Cierre de inscripciones',
                'shortcut' => 'inicio_clases',
                'content' => "📅 {name}, las clases inician el [FECHA].\n\nTodavía estás a tiempo de sumarte. Los primeros módulos son introductorios, así que no te perderías contenido.\n\n¿Querés que te envíe el formulario?",
            ],

            // --- Seguimiento y cierre -----------------------------------
            [
                'group' => 'Seguimiento',
                'shortcut' => 'seguimiento',
                'content' => "Hola {name}, ¿cómo estás? 😊\n\nTe escribo para saber si pudiste revisar la información que te envié y si te quedó alguna duda.\n\nQuedo atento.",
            ],
            [
                'group' => 'Seguimiento',
                'shortcut' => 'sin_respuesta',
                'content' => "{name}, no quiero insistir de más 🙂\n\nSi por ahora no es el momento, decime sin problema y te escribo cuando abramos la próxima versión.\n\nY si seguís interesado, acá estoy para lo que necesites.",
            ],
            [
                'group' => 'Seguimiento',
                'shortcut' => 'pago',
                'content' => "Perfecto, {name} ✅ Estos son los datos para el pago:\n\n• Banco: [BANCO]\n• Cuenta: [NÚMERO]\n• A nombre de: [TITULAR]\n• QR: [adjuntar imagen]\n\nCuando lo hagas, mandame el comprobante por acá y te confirmo la inscripción.",
            ],
            [
                'group' => 'Seguimiento',
                'shortcut' => 'confirmado',
                'content' => "🎉 ¡Listo, {name}! Tu inscripción quedó confirmada.\n\nEn breve te llega por acá:\n• Datos de acceso a la plataforma\n• Cronograma de clases\n• Grupo del curso\n\n¡Bienvenido/a! 🎓",
            ],
            [
                'group' => 'Seguimiento',
                'shortcut' => 'asesor',
                'content' => "{name}, te paso con un asesor académico para que resuelva tu consulta en detalle 🙌\n\nEn unos minutos te escribe por acá.",
            ],
        ];
    }
}

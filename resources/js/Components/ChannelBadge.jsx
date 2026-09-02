/**
 * Por dónde llegó esta conversación.
 *
 * Se muestra **solo cuando NO es WhatsApp**: mientras casi todo entra por ahí,
 * un badge en cada fila sería ruido que nadie lee. El día que haya mezcla real,
 * lo que llama la atención es justamente lo que no es lo habitual.
 *
 * Un canal desconocido no se oculta ni rompe: se dibuja con su nombre crudo.
 * Los canales nacen en el servidor y el front puede ir por detrás.
 */

export const CHANNELS = {
    whatsapp: { label: 'WhatsApp', icon: '💬', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    telegram: { label: 'Telegram', icon: '✈️', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    messenger: { label: 'Messenger', icon: '📘', tone: 'bg-blue-50 text-blue-700 ring-blue-200' },
    instagram: { label: 'Instagram', icon: '📸', tone: 'bg-pink-50 text-pink-700 ring-pink-200' },
    email: { label: 'Correo', icon: '✉️', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
    sms: { label: 'SMS', icon: '📱', tone: 'bg-slate-50 text-slate-700 ring-slate-200' },
    webchat: { label: 'Chat web', icon: '🌐', tone: 'bg-violet-50 text-violet-700 ring-violet-200' },
};

export function channelMeta(channel) {
    return CHANNELS[channel] ?? {
        label: channel,
        icon: '💠',
        tone: 'bg-gray-50 text-gray-600 ring-gray-200',
    };
}

export default function ChannelBadge({ channel, always = false, size = 'sm' }) {
    if (!channel) return null;
    if (!always && channel === 'whatsapp') return null;

    const meta = channelMeta(channel);
    const dims = size === 'md' ? 'px-2 py-1 text-xs' : 'px-1.5 py-0.5 text-[10px]';

    return (
        <span
            title={`Llegó por ${meta.label}`}
            className={`inline-flex items-center gap-1 rounded-md font-bold ring-1 ${dims} ${meta.tone}`}
        >
            <span aria-hidden="true">{meta.icon}</span>
            {meta.label}
        </span>
    );
}

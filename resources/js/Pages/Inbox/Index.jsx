import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import ChannelBadge, { channelMeta } from '@/Components/ChannelBadge';
import ImageModal from '@/Components/ImageModal';
import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Recorder from 'opus-recorder';
import encoderPath from 'opus-recorder/dist/encoderWorker.min.js?url';

// Reverb -> respaldo con polling lento
const POLL_MS = 30000;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function api(url, options = {}) {
    const res = await fetch(url, {
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        ...options,
    });
    if (!res.ok) throw new Error((await res.json().catch(() => ({})))?.message ?? res.statusText);
    return res.json();
}

function timeAgo(iso) {
    if (!iso) return '';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d`;
    return new Date(iso).toLocaleDateString();
}

function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    const same = (a, b) => a.toDateString() === b.toDateString();
    if (same(d, today)) return 'Hoy';
    if (same(d, yesterday)) return 'Ayer';
    return d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
}

const STATUS_LABELS = { open: 'Abierta', pending: 'Pendiente', closed: 'Cerrada' };

const STATUS_META = {
    open: { dot: 'bg-emerald-500', ring: 'ring-emerald-200', text: 'text-emerald-700', bg: 'bg-emerald-50' },
    pending: { dot: 'bg-amber-500', ring: 'ring-amber-200', text: 'text-amber-700', bg: 'bg-amber-50' },
    closed: { dot: 'bg-gray-400', ring: 'ring-gray-200', text: 'text-gray-600', bg: 'bg-gray-100' },
};
const TICKS = { sending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠' };
const QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '🙏', '🔥', '😊', '👏'];

const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
    'from-lime-500 to-green-600',
    'from-fuchsia-500 to-purple-600',
];

function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const gradient = AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
    return { initials, gradient };
}

function Avatar({ name, size = 'md', online }) {
    const { initials, gradient } = avatarFor(name);
    const sizes = {
        sm: 'w-8 h-8 text-xs',
        md: 'w-10 h-10 text-sm',
        lg: 'w-12 h-12 text-base',
        xl: 'w-16 h-16 text-lg',
    };
    return (
        <div className="relative shrink-0">
            <div className={`${sizes[size]} rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center font-bold text-white shadow-sm`}>
                {initials}
            </div>
            {online && <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-2 ring-white" />}
        </div>
    );
}

function StatusBadge({ status }) {
    const meta = STATUS_META[status] ?? STATUS_META.open;
    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 ${meta.ring} ${meta.text} ${meta.bg}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${meta.dot}`} />
            {STATUS_LABELS[status] ?? status}
        </span>
    );
}

function senderLabel(msg) {
    if (msg.sender_type === 'bot') return { text: '✨ IA', color: 'text-violet-600' };
    if (msg.sender_type === 'agent') {
        const name = msg.sender?.name ?? 'Agente';
        const role = msg.sender?.account_role;
        const badge = role === 'owner' || role === 'admin' ? ' · Admin' : '';
        return { text: `${name}${badge}`, color: 'text-[#045474]' };
    }
    return null;
}

/** Web Speech API: lee texto en voz alta. Singleton para poder parar el actual. */
const ttsState = { current: null };
function speakText(text, onEnd) {
    if (! ('speechSynthesis' in window)) { onEnd?.(); return; }
    // Si ya hay uno hablando, parar (comportamiento toggle)
    if (ttsState.current) {
        window.speechSynthesis.cancel();
        const prev = ttsState.current;
        ttsState.current = null;
        prev.onEnd?.();
        if (prev.text === text) return; // segundo click al mismo = solo parar
    }
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-BO'; u.rate = 1.05; u.pitch = 1.0;
    u.onend = () => { ttsState.current = null; onEnd?.(); };
    u.onerror = () => { ttsState.current = null; onEnd?.(); };
    window.speechSynthesis.speak(u);
    ttsState.current = { text, onEnd };
}

function MessageBubble({ msg, onReply, onReact, onOpenImage }) {
    const isCustomer = msg.sender_type === 'customer';
    const [showEmojis, setShowEmojis] = useState(false);
    const [isSpeaking, setIsSpeaking] = useState(false);

    // Texto a leer: content_text para texto puro, transcript para audios
    const readableText = msg.content_type === 'text' ? msg.content_text : (msg.transcript ?? null);

    const toggleSpeak = () => {
        if (!readableText) return;
        if (isSpeaking) { window.speechSynthesis.cancel(); setIsSpeaking(false); return; }
        setIsSpeaking(true);
        speakText(readableText, () => setIsSpeaking(false));
    };
    const author = senderLabel(msg);
    return (
        <div className={`group flex items-end gap-2 ${isCustomer ? 'justify-start' : 'justify-end'}`}>
            {!isCustomer && msg.message_id && (
                <BubbleActions onReply={() => onReply(msg)} showEmojis={showEmojis} setShowEmojis={setShowEmojis} onReact={(e) => onReact(msg, e)} side="left"
                    onSpeak={readableText ? toggleSpeak : null} isSpeaking={isSpeaking} />
            )}
            <div className={`flex flex-col max-w-[75%] ${isCustomer ? 'items-start' : 'items-end'}`}>
            {!isCustomer && author && (
                <span className={`text-[10px] font-bold mb-0.5 mr-2 ${author.color}`}>{author.text}</span>
            )}
            <div
                className={`rounded-2xl px-3.5 py-2.5 text-sm shadow-sm ${
                    isCustomer
                        ? 'bg-white text-gray-900 rounded-bl-md border border-gray-100'
                        : 'bg-gradient-to-br from-[#045474] to-[#1c486c] text-white rounded-br-md shadow-md shadow-[#045474]/20'
                }`}
            >
                {msg.reply_to && (
                    <div
                        className={`mb-1.5 rounded-lg border-l-4 px-2 py-1.5 text-xs ${
                            isCustomer
                                ? 'border-[#045474] bg-gray-50 text-gray-600'
                                : 'border-white/80 bg-white/10 text-white/90'
                        }`}
                    >
                        <p className="opacity-60 text-[10px] font-semibold uppercase mb-0.5">Respondiendo</p>
                        <p className="truncate">{msg.reply_to.content_text || `[${msg.reply_to.content_type}]`}</p>
                    </div>
                )}
                {msg.content_type === 'image' && msg.media_url && (
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); onOpenImage?.({ src: `/whatsapp/media/${msg.media_url}`, alt: 'Imagen' }); }}
                        className="block mb-1 cursor-zoom-in"
                    >
                        <img src={`/whatsapp/media/${msg.media_url}`} alt="" className="max-h-64 rounded-lg" />
                    </button>
                )}
                {msg.content_type === 'sticker' && msg.media_url && (
                    <img src={`/whatsapp/media/${msg.media_url}`} alt="Sticker" className="mb-1 h-32 w-32 object-contain" />
                )}
                {msg.content_type === 'video' && msg.media_url && (
                    <video controls src={`/whatsapp/media/${msg.media_url}`} className="mb-1 max-h-64 rounded-lg" />
                )}
                {msg.content_type === 'audio' && msg.media_url && (
                    <>
                        <audio controls src={`/whatsapp/media/${msg.media_url}`} className="mb-1 w-64 max-w-full" />
                        {msg.transcript && (
                            <div className={`mt-1.5 text-xs italic border-l-2 pl-2 ${isCustomer ? 'border-emerald-400 text-gray-600' : 'border-white/50 text-white/80'}`}>
                                <span className="opacity-70">🎙</span> {msg.transcript}
                            </div>
                        )}
                    </>
                )}
                {msg.content_type === 'document' && msg.media_url && (
                    <a
                        href={`/whatsapp/media/${msg.media_url}`}
                        target="_blank"
                        rel="noreferrer"
                        className={`mb-1 flex items-center gap-2 rounded-lg px-2 py-1.5 underline ${isCustomer ? 'bg-gray-50' : 'bg-white/10'}`}
                    >
                        <span>📄</span>
                        <span className="truncate">{msg.content_text || 'Documento'}</span>
                    </a>
                )}
                {msg.content_text && msg.content_type !== 'document' && (
                    <p className="whitespace-pre-wrap break-words leading-relaxed">{msg.content_text}</p>
                )}
                <div className={`mt-1 flex items-center gap-1.5 text-[10px] ${isCustomer ? 'text-gray-400' : 'text-white/70'}`}>
                    <span>{new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                    {!isCustomer && <span className={msg.status === 'read' ? 'text-sky-300 font-bold' : ''}>{TICKS[msg.status] ?? ''}</span>}
                    {msg.reactions?.length > 0 && (
                        <span className="ml-1 rounded-full bg-white/20 px-1.5 py-0.5 text-sm">{msg.reactions.map((r) => r.emoji).join('')}</span>
                    )}
                </div>
            </div>
            </div>
            {isCustomer && msg.message_id && (
                <BubbleActions onReply={() => onReply(msg)} showEmojis={showEmojis} setShowEmojis={setShowEmojis} onReact={(e) => onReact(msg, e)} side="right"
                    onSpeak={readableText ? toggleSpeak : null} isSpeaking={isSpeaking} />
            )}
        </div>
    );
}

function BubbleActions({ onReply, showEmojis, setShowEmojis, onReact, side, onSpeak, isSpeaking }) {
    return (
        <div className="relative flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
            {onSpeak && (
                <button
                    type="button"
                    onClick={onSpeak}
                    title={isSpeaking ? 'Detener lectura' : 'Leer en voz alta'}
                    className={`rounded-full bg-white p-1.5 text-xs shadow-md hover:scale-110 transition-all ${isSpeaking ? 'text-emerald-600 animate-pulse' : 'text-gray-500 hover:text-emerald-600'}`}
                >
                    {isSpeaking ? (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <rect x="6" y="5" width="4" height="14" rx="1" fill="currentColor" />
                            <rect x="14" y="5" width="4" height="14" rx="1" fill="currentColor" />
                        </svg>
                    ) : (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z" />
                        </svg>
                    )}
                </button>
            )}
            <button type="button" onClick={onReply} title="Responder" className="rounded-full bg-white p-1.5 text-xs text-gray-500 shadow-md hover:text-[#045474] hover:scale-110 transition-all">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                </svg>
            </button>
            <button type="button" onClick={() => setShowEmojis(!showEmojis)} title="Reaccionar" className="rounded-full bg-white p-1.5 text-xs text-gray-500 shadow-md hover:text-amber-500 hover:scale-110 transition-all">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </button>
            {showEmojis && (
                <div className={`absolute bottom-8 ${side === 'left' ? 'left-0' : 'right-0'} z-10 flex gap-1 rounded-full bg-white px-2 py-1.5 shadow-lg border border-gray-100`}>
                    {QUICK_EMOJIS.map((emoji) => (
                        <button key={emoji} type="button" onClick={() => { onReact(emoji); setShowEmojis(false); }} className="text-base hover:scale-125 transition-transform">
                            {emoji}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Grabador de voz: opus-recorder produce ogg/opus (formato requerido por Meta). */
function VoiceRecorder({ onSend, onCancel, disabled }) {
    const [state, setState] = useState('idle'); // idle | recording | preview | sending
    const [seconds, setSeconds] = useState(0);
    const [blob, setBlob] = useState(null);
    const [error, setError] = useState(null);
    const recRef = useRef(null);
    const timerRef = useRef(null);

    const start = async () => {
        setError(null);
        try {
            const rec = new Recorder({
                encoderPath,
                encoderApplication: 2049, // VOIP
                encoderSampleRate: 48000,
                originalSampleRateOverride: 48000,
                numberOfChannels: 1,
                streamPages: false,
            });
            rec.ondataavailable = (data) => {
                const b = new Blob([data], { type: 'audio/ogg' });
                setBlob(b);
                setState('preview');
            };
            await rec.start();
            recRef.current = rec;
            setState('recording');
            setSeconds(0);
            timerRef.current = setInterval(() => setSeconds((s) => s + 1), 1000);
        } catch (e) {
            setError('No se pudo acceder al micrófono. Revisa los permisos.');
            setState('idle');
        }
    };

    const stop = async () => {
        clearInterval(timerRef.current);
        if (recRef.current) {
            await recRef.current.stop();
            recRef.current = null;
        }
    };

    const discard = () => {
        setBlob(null);
        setSeconds(0);
        setState('idle');
        onCancel?.();
    };

    const send = async () => {
        if (!blob) return;
        setState('sending');
        try {
            const file = new File([blob], `voz-${Date.now()}.ogg`, { type: 'audio/ogg' });
            await onSend(file);
            discard();
        } catch (e) {
            setError(e.message || 'Error al enviar');
            setState('preview');
        }
    };

    useEffect(() => () => {
        clearInterval(timerRef.current);
        recRef.current?.stop().catch(() => {});
    }, []);

    const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
    const ss = String(seconds % 60).padStart(2, '0');

    if (state === 'idle') {
        return (
            <button
                type="button"
                onClick={start}
                disabled={disabled}
                title="Grabar mensaje de voz"
                className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-600 hover:bg-rose-50 hover:border-rose-300 hover:text-rose-600 disabled:opacity-50 transition-all shadow-sm"
            >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 11a7 7 0 01-14 0m7 7v3m-3 0h6M9 5a3 3 0 016 0v6a3 3 0 01-6 0V5z" />
                </svg>
            </button>
        );
    }

    if (state === 'recording') {
        return (
            <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2">
                <span className="relative flex h-3 w-3">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-3 w-3 bg-rose-500"></span>
                </span>
                <span className="text-sm font-mono font-bold text-rose-700">{mm}:{ss}</span>
                <button type="button" onClick={discard} className="ml-1 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 rounded-lg">Cancelar</button>
                <button type="button" onClick={stop} className="px-3 py-1.5 text-xs font-semibold bg-rose-600 text-white rounded-lg hover:bg-rose-700 shadow-sm">Detener</button>
            </div>
        );
    }

    // preview / sending
    return (
        <div className="flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2">
            {blob && <audio controls src={URL.createObjectURL(blob)} className="h-9" />}
            <button type="button" onClick={discard} disabled={state === 'sending'} className="px-2 py-1 text-xs text-gray-600 hover:bg-white rounded-lg">Descartar</button>
            <button type="button" onClick={send} disabled={state === 'sending'} className="px-3 py-1.5 text-xs font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white rounded-lg shadow-sm disabled:opacity-50">
                {state === 'sending' ? 'Enviando…' : 'Enviar'}
            </button>
            {error && <span className="text-xs text-red-600">{error}</span>}
        </div>
    );
}

function DateSeparator({ label }) {
    return (
        <div className="flex items-center gap-3 py-2">
            <div className="flex-1 h-px bg-gray-200" />
            <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400 bg-gray-50 px-3 py-1 rounded-full">{label}</span>
            <div className="flex-1 h-px bg-gray-200" />
        </div>
    );
}

/**
 * Aviso dentro de la conversación: la IA agotó su tope de respuestas y queda
 * en pausa hasta cierta hora, cuando el contador se reinicia solo.
 *
 * Va en el hilo y no en el header a propósito: es información sobre lo que
 * pasó en esta conversación, y así queda a la vista del agente justo donde
 * está leyendo, sin tener que interpretar por qué el bot dejó de contestar.
 */
function AiPausedNotice({ pausedUntil, replyCount, onResume }) {
    if (!pausedUntil) return null;

    const hasta = new Date(pausedUntil);
    if (hasta <= new Date()) return null; // ya venció: la IA retoma sola

    const minutos = Math.max(1, Math.round((hasta - Date.now()) / 60000));
    const restante = minutos >= 60
        ? `${Math.floor(minutos / 60)}h ${minutos % 60}m`
        : `${minutos}m`;

    return (
        <div className="flex justify-center px-4">
            <div className="max-w-md w-full rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-center">
                <p className="text-xs font-bold text-amber-900">
                    ⏸ La IA llegó a su límite de {replyCount ?? ''} respuestas
                </p>
                <p className="text-[11px] text-amber-800 mt-1 leading-relaxed">
                    Sigue <strong>activa</strong>, pero en pausa para no seguir respondiendo sola.
                    Vuelve a contestar a las <strong>{hasta.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}</strong>
                    {' '}(en {restante}). Mientras tanto, contestá vos.
                </p>
                {onResume && (
                    <button
                        type="button"
                        onClick={onResume}
                        className="mt-2 text-[11px] font-bold text-amber-900 underline hover:text-amber-950"
                    >
                        Reactivar la IA ahora
                    </button>
                )}
            </div>
        </div>
    );
}

function TypingDots({ compact }) {
    return (
        <span className={`inline-flex items-center gap-0.5 ${compact ? '' : 'ml-1'}`}>
            <span className="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style={{ animationDelay: '0ms' }} />
            <span className="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style={{ animationDelay: '150ms' }} />
            <span className="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style={{ animationDelay: '300ms' }} />
        </span>
    );
}

export default function Index({ hasWhatsappConfig, hasAi, members }) {
    const [conversations, setConversations] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [channelFilter, setChannelFilter] = useState('all');
    const [showContactPanel, setShowContactPanel] = useState(true);
    const [showConversationList, setShowConversationList] = useState(true);
    const [replyTo, setReplyTo] = useState(null);
    const [notes, setNotes] = useState([]);
    const [noteDraft, setNoteDraft] = useState('');
    const [uploading, setUploading] = useState(false);
    const [drafting, setDrafting] = useState(false);
    const [showEmojiPicker, setShowEmojiPicker] = useState(false);
    const [quickReplies, setQuickReplies] = useState([]);
    const [showQuickReplies, setShowQuickReplies] = useState(false);
    const [slashQuery, setSlashQuery] = useState(null); // null = cerrado, string = filtrando
    const [searchResults, setSearchResults] = useState(null); // null = no búsqueda, array = resultados full-text
    const [selectedIds, setSelectedIds] = useState(new Set()); // bulk selection en la sidebar
    const [bulkBusy, setBulkBusy] = useState(false);
    const [typingByConv, setTypingByConv] = useState({});
    const [lightbox, setLightbox] = useState(null);
    const bottomRef = useRef(null);
    const selectedRef = useRef(null);
    const fileInputRef = useRef(null);
    const channelRef = useRef(null);
    const lastWhisperRef = useRef(0);
    const typingStopRef = useRef(null);
    const draftRef = useRef(null);
    const draftSentRef = useRef(false);
    selectedRef.current = selectedId;

    useEffect(() => {
        if (draftSentRef.current && !sending) {
            draftSentRef.current = false;
            draftRef.current?.focus();
        }
    }, [sending]);

    const selected = conversations.find((c) => c.id === selectedId);
    const me = usePage().props.auth.user;
    const accountId = me?.account_id;
    const isAdmin = me?.account_role === 'owner' || me?.account_role === 'admin';

    // El filtro de canal viaja al servidor, no se aplica sobre lo ya traído: el
    // listado se corta en 100, así que filtrar en pantalla mostraría «3 de
    // Telegram» cuando hay treinta más que nunca se pidieron.
    const loadConversations = useCallback(async () => {
        try {
            setConversations(await api(route('inbox.conversations', channelFilter === 'all' ? {} : { channel: channelFilter })));
        } catch { /* siguiente poll */ }
    }, [channelFilter]);

    const loadMessages = useCallback(async (id) => {
        try {
            const data = await api(route('inbox.messages', id));
            if (selectedRef.current === id) setMessages(data);
        } catch { /* siguiente poll */ }
    }, []);

    const loadNotes = async (id) => {
        try { setNotes(await api(route('inbox.notes', id))); } catch {}
    };

    useEffect(() => {
        loadConversations();
        const t = setInterval(() => {
            loadConversations();
            if (selectedRef.current) loadMessages(selectedRef.current);
        }, POLL_MS);
        return () => clearInterval(t);
    }, [loadConversations, loadMessages]);

    // Cargar plantillas rápidas del user (mías + compartidas) al montar
    useEffect(() => {
        api(route('inbox.quick-replies')).then(setQuickReplies).catch(() => {});
    }, []);

    // Búsqueda full-text debounced: cuando el user tipea >=3 chars y contiene
    // más de una palabra o parece "buscar" (no solo filtrar), consulta el endpoint.
    useEffect(() => {
        const q = search.trim();
        if (q.length < 3) { setSearchResults(null); return; }
        const t = setTimeout(() => {
            api(route('inbox.search', { q })).then(setSearchResults).catch(() => setSearchResults([]));
        }, 350);
        return () => clearTimeout(t);
    }, [search]);

    /** Interpola variables {name} {phone} {email} {company} con datos del contacto seleccionado. */
    const renderTemplate = (content) => {
        const c = selected?.contact ?? {};
        return content
            .replaceAll('{name}', c.name ?? '')
            .replaceAll('{phone}', c.phone ?? '')
            .replaceAll('{email}', c.email ?? '')
            .replaceAll('{company}', c.company ?? '');
    };

    const insertQuickReply = (reply) => {
        // Si el borrador tenía un /atajo tipeado, lo reemplaza; si no, agrega.
        const rendered = renderTemplate(reply.content);
        if (slashQuery !== null) {
            const cleaned = draft.replace(/\/\S*$/, rendered);
            setDraft(cleaned);
        } else {
            setDraft(draft ? `${draft} ${rendered}` : rendered);
        }
        setSlashQuery(null);
        setShowQuickReplies(false);
    };

    useEffect(() => {
        if (!window.Echo || !accountId) return;
        const channel = window.Echo.private(`account.${accountId}`);
        channelRef.current = channel;
        channel.listen('InboxUpdated', (e) => {
            loadConversations();
            if (selectedRef.current && (!e.conversation_id || e.conversation_id === selectedRef.current)) {
                loadMessages(selectedRef.current);
            }
        });
        channel.listenForWhisper('typing', (e) => {
            if (!e || e.user_id === me?.id) return; // ignoro mi propio whisper
            setTypingByConv((prev) => ({
                ...prev,
                [e.conversation_id]: { userId: e.user_id, name: e.name, expiresAt: Date.now() + 4000 },
            }));
        });
        channel.listenForWhisper('stopped', (e) => {
            if (!e) return;
            setTypingByConv((prev) => {
                const next = { ...prev };
                delete next[e.conversation_id];
                return next;
            });
        });
        // Limpia entradas expiradas cada segundo (por si el whisper de "stopped" se pierde).
        const gc = setInterval(() => {
            setTypingByConv((prev) => {
                const now = Date.now();
                const next = {};
                let changed = false;
                for (const [k, v] of Object.entries(prev)) {
                    if (v.expiresAt > now) next[k] = v; else changed = true;
                }
                return changed ? next : prev;
            });
        }, 1000);
        return () => {
            clearInterval(gc);
            window.Echo.leave(`account.${accountId}`);
            channelRef.current = null;
        };
    }, [accountId, loadConversations, loadMessages, me?.id]);

    /** Anuncia que este agente está escribiendo en la conversación abierta (throttle 2s). */
    const announceTyping = useCallback(() => {
        if (!channelRef.current || !selectedRef.current || !me) return;
        const now = Date.now();
        if (now - lastWhisperRef.current > 2000) {
            channelRef.current.whisper('typing', {
                conversation_id: selectedRef.current,
                user_id: me.id,
                name: me.name,
            });
            lastWhisperRef.current = now;
        }
        clearTimeout(typingStopRef.current);
        typingStopRef.current = setTimeout(() => {
            if (channelRef.current && selectedRef.current) {
                channelRef.current.whisper('stopped', { conversation_id: selectedRef.current, user_id: me.id });
            }
            lastWhisperRef.current = 0;
        }, 3000);
    }, [me]);

    useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [messages.length]);

    const filtered = useMemo(() => {
        const s = search.trim().toLowerCase();
        return conversations
            .filter((c) => statusFilter === 'all' || c.status === statusFilter)
            .filter((c) => {
                if (!s) return true;
                const name = (c.contact?.name || '').toLowerCase();
                const phone = (c.contact?.phone || '').toLowerCase();
                const last = (c.last_message_text || '').toLowerCase();
                return name.includes(s) || phone.includes(s) || last.includes(s);
            });
    }, [conversations, search, statusFilter]);

    // Los canales realmente presentes, para no ofrecer un filtro vacío. Se
    // calcula sobre lo traído a propósito: si el filtro de canal está activo la
    // lista viene recortada, así que se conserva el canal elegido en la lista
    // de opciones para poder volver.
    const canalesPresentes = useMemo(() => {
        const vistos = new Set(conversations.map((c) => c.channel).filter(Boolean));
        if (channelFilter !== 'all') vistos.add(channelFilter);

        return [...vistos].sort();
    }, [conversations, channelFilter]);

    const totals = useMemo(() => ({
        all: conversations.length,
        open: conversations.filter((c) => c.status === 'open').length,
        pending: conversations.filter((c) => c.status === 'pending').length,
        closed: conversations.filter((c) => c.status === 'closed').length,
        unread: conversations.reduce((a, c) => a + (c.unread_count || 0), 0),
    }), [conversations]);

    const openConversation = async (conv) => {
        setSelectedId(conv.id);
        setMessages([]);
        setReplyTo(null);
        await loadMessages(conv.id);
        loadNotes(conv.id);
        if (conv.unread_count > 0) {
            api(route('inbox.read', conv.id), { method: 'POST' }).then(loadConversations);
        }
    };

    const send = async (e) => {
        e?.preventDefault();
        if (!draft.trim() || sending || !selectedId) return;
        setSending(true);
        setError(null);
        try {
            await api(route('inbox.send', selectedId), {
                method: 'POST',
                body: JSON.stringify({ text: draft.trim(), reply_to_message_id: replyTo?.id ?? null }),
            });
            setDraft('');
            setReplyTo(null);
            await loadMessages(selectedId);
            await loadConversations();
        } catch (err) {
            setError(err.message);
        } finally {
            draftSentRef.current = true;
            setSending(false);
        }
    };

    const setStatus = async (status) => {
        if (!selectedId) return;
        await api(route('inbox.status', selectedId), { method: 'PATCH', body: JSON.stringify({ status }) });
        loadConversations();
    };

    const assign = async (agentId) => {
        if (!selectedId) return;
        await api(route('inbox.assign', selectedId), { method: 'PATCH', body: JSON.stringify({ agent_id: agentId || null }) });
        loadConversations();
    };

    const toggleSelected = (id) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const clearSelection = () => setSelectedIds(new Set());

    const bulkDo = async (action, agentId = null) => {
        if (selectedIds.size === 0 || bulkBusy) return;
        setBulkBusy(true);
        try {
            await api(route('inbox.bulk-action'), {
                method: 'POST',
                body: JSON.stringify({
                    conversation_ids: Array.from(selectedIds),
                    action,
                    agent_id: agentId,
                }),
            });
            clearSelection();
            await loadConversations();
        } catch (err) { setError(err.message); }
        finally { setBulkBusy(false); }
    };

    const setAiMode = async (enabled) => {
        if (!selectedId) return;
        try {
            await api(route('inbox.ai-mode', selectedId), { method: 'PATCH', body: JSON.stringify({ ai_enabled: enabled }) });
            loadConversations();
        } catch (err) { setError(err.message); }
    };

    const toggleAiMode = () => setAiMode(!!selected.ai_autoreply_disabled); // a IA si estaba en Humano

    // Levantar la pausa por tope NO es un toggle: la IA ya está activa, lo
    // que se reinicia es su contador. Togglear la apagaría.
    const resumeAiNow = () => setAiMode(true);

    const reactTo = async (msg, emoji) => {
        try {
            await api(route('inbox.react', msg.id), { method: 'POST', body: JSON.stringify({ emoji }) });
            await loadMessages(selectedId);
        } catch (err) { setError(err.message); }
    };

    const addNote = async (e) => {
        e.preventDefault();
        if (!noteDraft.trim() || !selectedId) return;
        try {
            await api(route('inbox.notes.add', selectedId), { method: 'POST', body: JSON.stringify({ text: noteDraft.trim() }) });
            setNoteDraft('');
            await loadNotes(selectedId);
        } catch (err) { setError(err.message); }
    };

    /** Enviar una nota de voz (audio blob) al endpoint como multipart. */
    const addVoiceNote = async (audioFile) => {
        if (!selectedId || !audioFile) return;
        try {
            const body = new FormData();
            body.append('audio', audioFile);
            const res = await fetch(route('inbox.notes.add', selectedId), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            if (!res.ok) throw new Error((await res.json().catch(() => ({})))?.message ?? 'Error');
            await loadNotes(selectedId);
        } catch (err) { setError(err.message); throw err; }
    };

    const sendFile = async (file) => {
        if (!file || !selectedId || uploading) return;
        setUploading(true);
        setError(null);
        try {
            const body = new FormData();
            body.append('file', file);
            const res = await fetch(route('inbox.send-media', selectedId), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            if (!res.ok) throw new Error((await res.json().catch(() => ({})))?.message ?? res.statusText);
            await loadMessages(selectedId);
            await loadConversations();
        } catch (err) {
            setError(err.message);
            throw err;
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const aiDraft = async () => {
        if (!selectedId || drafting) return;
        setDrafting(true);
        setError(null);
        try {
            const res = await api(route('inbox.ai-draft', selectedId), { method: 'POST' });
            setDraft(res.draft ?? '');
        } catch (err) { setError(err.message); }
        finally { setDrafting(false); }
    };

    // Agrupa mensajes por día para renderizar con separadores
    const messageGroups = useMemo(() => {
        const groups = [];
        let currentKey = null;
        for (const m of messages) {
            const key = new Date(m.created_at).toDateString();
            if (key !== currentKey) {
                groups.push({ label: dayLabel(m.created_at), items: [] });
                currentKey = key;
            }
            groups[groups.length - 1].items.push(m);
        }
        return groups;
    }, [messages]);

    return (
        <>
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Inbox</h2>}>
            <Head title="Inbox" />

            {!hasWhatsappConfig && (
                <div className="mx-4 mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-center gap-3 shadow-sm">
                    <svg className="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>WhatsApp no está conectado. <Link href={route('settings.whatsapp')} className="font-semibold underline">Configura tu número</Link> para enviar y recibir mensajes.</span>
                </div>
            )}

            <div className="h-[calc(100vh-8rem)] p-4">
                <div className="flex h-full overflow-hidden rounded-2xl bg-white shadow-sm border border-gray-100">
                    {/* COLUMNA 1: lista de conversaciones (colapsable) */}
                    {!showConversationList && (
                        <button
                            type="button"
                            onClick={() => setShowConversationList(true)}
                            title="Mostrar conversaciones"
                            className="shrink-0 w-10 flex items-center justify-center border-r border-gray-100 bg-white hover:bg-gray-50 transition-colors group"
                        >
                            <div className="flex flex-col items-center gap-2 text-gray-400 group-hover:text-[#045474]">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                                {totals.unread > 0 && (
                                    <span className="min-w-[20px] h-5 px-1.5 rounded-full bg-gradient-to-br from-rose-500 to-red-500 text-white text-[10px] font-bold flex items-center justify-center shadow-sm">
                                        {totals.unread}
                                    </span>
                                )}
                            </div>
                        </button>
                    )}
                    <aside className={`${showConversationList ? 'w-96' : 'hidden'} shrink-0 flex flex-col border-r border-gray-100 bg-gray-50/30`}>
                        <div className="p-4 border-b border-gray-100 bg-white space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                <h3 className="text-lg font-bold text-gray-900">Conversaciones</h3>
                                <div className="flex items-center gap-2">
                                    {totals.unread > 0 && (
                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gradient-to-r from-rose-500 to-red-500 text-white text-xs font-bold shadow-sm">
                                            {totals.unread} sin leer
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => setShowConversationList(false)}
                                        title="Ocultar conversaciones"
                                        className="p-1.5 text-gray-400 hover:text-[#045474] hover:bg-gray-100 rounded-lg transition-colors"
                                    >
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div className="relative">
                                <svg className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar por nombre, teléfono o mensaje…"
                                    className="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white transition-all"
                                />
                            </div>
                            <div className="grid grid-cols-4 gap-1 text-[11px]">
                                {[
                                    { k: 'all', l: 'Todas', n: totals.all },
                                    { k: 'open', l: 'Abiertas', n: totals.open },
                                    { k: 'pending', l: 'Pend.', n: totals.pending },
                                    { k: 'closed', l: 'Cerr.', n: totals.closed },
                                ].map((f) => (
                                    <button
                                        key={f.k}
                                        onClick={() => setStatusFilter(f.k)}
                                        className={`flex flex-col items-center justify-center px-1 py-1.5 rounded-lg font-semibold transition-all ${
                                            statusFilter === f.k
                                                ? 'bg-gradient-to-br from-[#045474] to-[#1c486c] text-white shadow-md shadow-[#045474]/20'
                                                : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                    >
                                        <span>{f.l}</span>
                                        <span className={`text-[10px] font-bold ${statusFilter === f.k ? 'text-white/80' : 'text-gray-400'}`}>{f.n}</span>
                                    </button>
                                ))}
                            </div>

                            {/* Selector de canal. Aparece SOLO si hay más de uno
                                en juego: mientras todo entra por WhatsApp, un
                                filtro que no filtra nada es ruido. */}
                            {canalesPresentes.length > 1 && (
                                <div className="flex flex-wrap gap-1 pt-1">
                                    {['all', ...canalesPresentes].map((c) => (
                                        <button
                                            key={c}
                                            onClick={() => setChannelFilter(c)}
                                            className={`inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold transition-all ${
                                                channelFilter === c
                                                    ? 'bg-gray-900 text-white'
                                                    : 'text-gray-600 hover:bg-gray-100'
                                            }`}
                                        >
                                            {c === 'all' ? 'Todos los canales' : (
                                                <>
                                                    <span aria-hidden="true">{channelMeta(c).icon}</span>
                                                    {channelMeta(c).label}
                                                </>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="flex-1 overflow-y-auto">
                            {/* Resultados de búsqueda full-text (encima de la lista normal) */}
                            {searchResults && searchResults.length > 0 && (
                                <div className="border-b-4 border-double border-emerald-100 bg-emerald-50/30">
                                    <div className="px-4 py-2 flex items-center justify-between">
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-700">
                                            🔍 Encontrados en el historial ({searchResults.length})
                                        </span>
                                    </div>
                                    {searchResults.map((res) => {
                                        const conv = res.conversation;
                                        const name = conv.contact?.name || conv.contact?.phone || 'Desconocido';
                                        return (
                                            <button
                                                key={conv.id}
                                                onClick={() => openConversation(conv)}
                                                className="w-full text-left px-4 py-2.5 border-b border-emerald-100 hover:bg-white flex items-start gap-3"
                                            >
                                                <Avatar name={name} size="sm" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="text-sm font-semibold text-gray-800 truncate">{name}</span>
                                                        <span className="text-[10px] text-gray-400 shrink-0">{timeAgo(res.match_at)}</span>
                                                    </div>
                                                    <p className="text-xs text-gray-600 truncate italic mt-0.5">
                                                        {res.snippet}
                                                    </p>
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                            {filtered.length === 0 && !searchResults?.length && (
                                <div className="p-8 text-center">
                                    <div className="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                                        <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                        </svg>
                                    </div>
                                    <p className="text-sm text-gray-500">
                                        {search || statusFilter !== 'all' ? 'Sin coincidencias' : 'Sin conversaciones. Llegarán cuando un cliente te escriba.'}
                                    </p>
                                </div>
                            )}
                            {filtered.map((conv) => {
                                const name = conv.contact?.name || conv.contact?.phone || 'Desconocido';
                                const isActive = selectedId === conv.id;
                                const typing = typingByConv[conv.id];
                                const isChecked = selectedIds.has(conv.id);
                                return (
                                    <div
                                        key={conv.id}
                                        onClick={() => openConversation(conv)}
                                        className={`w-full text-left px-4 py-3 border-b border-gray-100 transition-all flex items-start gap-3 cursor-pointer ${
                                            isActive ? 'bg-gradient-to-r from-[#045474]/5 to-transparent border-l-4 border-l-[#045474]' : isChecked ? 'bg-emerald-50/60' : 'hover:bg-white'
                                        }`}
                                    >
                                        {isAdmin && (
                                            <input
                                                type="checkbox"
                                                checked={isChecked}
                                                onClick={(e) => e.stopPropagation()}
                                                onChange={() => toggleSelected(conv.id)}
                                                className="mt-3 w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shrink-0"
                                            />
                                        )}
                                        <Avatar name={name} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className={`truncate ${conv.unread_count > 0 ? 'font-bold text-gray-900' : 'font-semibold text-gray-700'}`}>{name}</span>
                                                <span className="shrink-0 text-[11px] text-gray-400 font-medium">{timeAgo(conv.last_message_at)}</span>
                                            </div>
                                            <p className="text-[11px] text-gray-400 font-mono truncate">{conv.contact?.phone}</p>
                                            <div className="mt-0.5 flex items-center justify-between gap-2">
                                                {typing ? (
                                                    <span className="truncate text-sm text-emerald-600 font-medium flex items-center gap-1.5">
                                                        <TypingDots compact /> escribiendo…
                                                    </span>
                                                ) : (
                                                    <span className={`truncate text-sm ${conv.unread_count > 0 ? 'text-gray-800 font-medium' : 'text-gray-500'}`}>
                                                        {conv.last_message_text || '—'}
                                                    </span>
                                                )}
                                                {conv.unread_count > 0 && (
                                                    <span className="ml-auto shrink-0 min-w-[20px] h-5 px-1.5 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white text-xs font-bold flex items-center justify-center shadow-sm">
                                                        {conv.unread_count}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-1.5 flex items-center gap-1.5">
                                                <span className={`w-1.5 h-1.5 rounded-full ${STATUS_META[conv.status]?.dot ?? 'bg-gray-300'}`} />
                                                <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{STATUS_LABELS[conv.status]}</span>
                                                <ChannelBadge channel={conv.channel} />
                                                <ServiceWindowBadge window={conv.service_window} />
                                                {conv.assigned_agent && (
                                                    <span className="ml-auto text-[10px] text-gray-400 truncate">👤 {conv.assigned_agent.name}</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Barra flotante de acciones en lote */}
                        {selectedIds.size > 0 && (
                            <div className="border-t-2 border-emerald-500 bg-emerald-50 px-3 py-2 flex flex-wrap items-center gap-2">
                                <span className="text-xs font-bold text-emerald-800">{selectedIds.size} seleccionadas</span>
                                <button onClick={() => bulkDo('mark_read')} disabled={bulkBusy} title="Marcar leídas" className="px-2 py-1 text-xs bg-white border border-emerald-300 rounded-lg text-emerald-700 hover:bg-emerald-100 disabled:opacity-50">✓ Leídas</button>
                                <button onClick={() => bulkDo('close')} disabled={bulkBusy} title="Cerrar" className="px-2 py-1 text-xs bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 disabled:opacity-50">Cerrar</button>
                                <button onClick={() => bulkDo('pending')} disabled={bulkBusy} title="Pendiente" className="px-2 py-1 text-xs bg-white border border-amber-300 rounded-lg text-amber-700 hover:bg-amber-100 disabled:opacity-50">Pend.</button>
                                <select
                                    onChange={(e) => { if (e.target.value) bulkDo('assign', e.target.value); e.target.value = ''; }}
                                    disabled={bulkBusy}
                                    className="text-xs px-2 py-1 bg-white border border-gray-300 rounded-lg text-gray-700 focus:ring-emerald-500"
                                    defaultValue=""
                                >
                                    <option value="">Asignar…</option>
                                    <option value="null" onClick={(e) => { e.preventDefault(); bulkDo('assign', null); }}>Sin asignar</option>
                                    {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </select>
                                <button onClick={clearSelection} className="ml-auto text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
                            </div>
                        )}
                    </aside>

                    {/* COLUMNA 2: chat */}
                    <section className="flex min-w-0 flex-1 flex-col bg-gradient-to-b from-gray-50 to-gray-100">
                        {!selected ? (
                            <div className="flex flex-1 items-center justify-center">
                                <div className="text-center">
                                    <div className="mx-auto w-20 h-20 rounded-2xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center shadow-lg shadow-[#045474]/30 mb-4">
                                        <svg className="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                        </svg>
                                    </div>
                                    <p className="text-gray-700 font-semibold">Selecciona una conversación</p>
                                    <p className="text-xs text-gray-400 mt-1">O espera a que un cliente te escriba</p>
                                </div>
                            </div>
                        ) : (
                            <>
                                {/* Header del chat */}
                                <header className="flex items-center justify-between border-b border-gray-100 bg-white px-4 py-3 shadow-sm">
                                    <div className="flex items-center gap-3 min-w-0">
                                        {!showConversationList && (
                                            <button
                                                type="button"
                                                onClick={() => setShowConversationList(true)}
                                                title="Mostrar conversaciones"
                                                className="p-2 text-gray-500 hover:text-[#045474] hover:bg-gray-100 rounded-lg transition-colors shrink-0"
                                            >
                                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                                </svg>
                                            </button>
                                        )}
                                        <Avatar name={selected.contact?.name || selected.contact?.phone} size="lg" />
                                        <div className="min-w-0">
                                            <p className="font-bold text-gray-900 truncate">{selected.contact?.name || selected.contact?.phone}</p>
                                            <div className="flex items-center gap-2 mt-0.5">
                                                {typingByConv[selected.id] ? (
                                                    <span className="text-xs text-emerald-600 font-semibold flex items-center gap-1.5">
                                                        <TypingDots compact /> {typingByConv[selected.id].name} está escribiendo…
                                                    </span>
                                                ) : (
                                                    <>
                                                        <span className="text-xs text-gray-500 font-mono">{selected.contact?.phone}</span>
                                                        <StatusBadge status={selected.status} />
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {hasAi && (
                                            <button
                                                type="button"
                                                onClick={toggleAiMode}
                                                title={selected.ai_autoreply_disabled ? 'Cambiar a IA (auto-respuesta)' : 'Cambiar a Humano (silenciar IA)'}
                                                className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold border transition-all shadow-sm ${
                                                    selected.ai_autoreply_disabled
                                                        ? 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                                                        : 'border-violet-300 bg-gradient-to-br from-violet-50 to-purple-50 text-violet-700 shadow-violet-500/10'
                                                }`}
                                            >
                                                {selected.ai_autoreply_disabled ? (
                                                    <>
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                        </svg>
                                                        Humano
                                                    </>
                                                ) : (
                                                    <>
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                                        </svg>
                                                        IA activa
                                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                                    </>
                                                )}
                                            </button>
                                        )}
                                        <ChannelBadge channel={selected.channel} size="md" />
                                        <ServiceWindowBadge window={selected.service_window} size="md" />
                                        <span
                                            title={isAdmin ? 'La asignación se cambia desde el lead en Komo' : 'Solo el admin puede reasignar (desde Komo)'}
                                            className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold border ${
                                                selected.assigned_agent
                                                    ? 'bg-gray-50 border-gray-200 text-gray-700'
                                                    : 'bg-amber-50 border-amber-200 text-amber-700'
                                            }`}
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                            {selected.assigned_agent?.name ?? 'Sin asignar'}
                                        </span>
                                        <select
                                            value={selected.status}
                                            onChange={(e) => setStatus(e.target.value)}
                                            className="rounded-xl border-gray-200 text-sm bg-gray-50 focus:ring-[#045474] focus:border-[#045474]"
                                        >
                                            {Object.entries(STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                        </select>
                                        <button
                                            type="button"
                                            onClick={() => setShowContactPanel(!showContactPanel)}
                                            title={showContactPanel ? 'Ocultar panel' : 'Mostrar panel'}
                                            className={`rounded-xl border p-2 transition-all ${showContactPanel ? 'border-[#045474] bg-[#045474]/5 text-[#045474]' : 'border-gray-200 text-gray-500 hover:bg-gray-50'}`}
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </button>
                                    </div>
                                </header>

                                {/* Hilo de mensajes */}
                                <div className="flex-1 overflow-y-auto px-6 py-4 space-y-3">
                                    {messageGroups.map((g, gi) => (
                                        <div key={gi} className="space-y-2">
                                            <DateSeparator label={g.label} />
                                            {g.items.map((msg) => (
                                                <MessageBubble key={msg.id} msg={msg} onReply={setReplyTo} onReact={reactTo} onOpenImage={setLightbox} />
                                            ))}
                                        </div>
                                    ))}
                                    {selected.ai_pending && (
                                        <div className="flex flex-col items-end">
                                            <span className="text-[10px] font-bold mb-0.5 mr-2 text-violet-600">✨ IA</span>
                                            <div className="rounded-2xl px-4 py-3 text-sm bg-gradient-to-br from-violet-500/90 to-purple-600/90 text-white rounded-br-md shadow-md shadow-violet-500/20 flex items-center gap-2">
                                                <span className="flex items-center gap-1">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '0ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '150ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '300ms' }} />
                                                </span>
                                                <span className="italic text-white/90">Pensando respuesta…</span>
                                            </div>
                                        </div>
                                    )}
                                    <AiPausedNotice
                                        pausedUntil={selected.ai_paused_until}
                                        replyCount={selected.ai_reply_count}
                                        onResume={selected.ai_autoreply_disabled ? null : resumeAiNow}
                                    />
                                    <div ref={bottomRef} />
                                </div>

                                {/* Composer */}
                                <form onSubmit={send} className="border-t border-gray-100 bg-white p-4">
                                    {error && (
                                        <div className="mb-3 flex items-center gap-2 rounded-xl bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
                                            <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01M12 21a9 9 0 100-18 9 9 0 000 18z" /></svg>
                                            <span className="flex-1">{error}</span>
                                            <button type="button" onClick={() => setError(null)} className="text-red-500 hover:text-red-700">×</button>
                                        </div>
                                    )}
                                    {replyTo && (
                                        <div className="mb-3 flex items-center justify-between rounded-xl border-l-4 border-[#045474] bg-gray-50 px-3 py-2 text-xs">
                                            <div className="min-w-0">
                                                <p className="font-semibold text-[#045474] uppercase text-[10px] tracking-wider">Respondiendo</p>
                                                <p className="truncate text-gray-600 mt-0.5">{replyTo.content_text || `[${replyTo.content_type}]`}</p>
                                            </div>
                                            <button type="button" onClick={() => setReplyTo(null)} className="ml-2 shrink-0 w-6 h-6 rounded-full hover:bg-gray-200 text-gray-500 flex items-center justify-center">×</button>
                                        </div>
                                    )}
                                    <div className="flex items-end gap-2">
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            className="hidden"
                                            accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                                            onChange={(e) => sendFile(e.target.files[0])}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => fileInputRef.current?.click()}
                                            disabled={!hasWhatsappConfig || uploading}
                                            title="Adjuntar archivo"
                                            className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-600 hover:bg-gray-50 hover:border-gray-300 disabled:opacity-50 transition-all shadow-sm"
                                        >
                                            {uploading ? (
                                                <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                            ) : (
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                                </svg>
                                            )}
                                        </button>

                                        <VoiceRecorder onSend={sendFile} disabled={!hasWhatsappConfig} />

                                        {/* Plantillas rápidas — botón */}
                                        <div className="relative">
                                            <button
                                                type="button"
                                                onClick={() => setShowQuickReplies(!showQuickReplies)}
                                                disabled={!hasWhatsappConfig || quickReplies.length === 0}
                                                title={quickReplies.length === 0 ? 'Crea plantillas en Ajustes → Plantillas' : 'Insertar plantilla'}
                                                className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-600 hover:bg-emerald-50 hover:border-emerald-300 hover:text-emerald-700 disabled:opacity-50 transition-all shadow-sm"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                                </svg>
                                            </button>
                                            {showQuickReplies && (
                                                <div className="absolute bottom-14 left-0 z-20 w-72 max-h-64 overflow-y-auto bg-white rounded-xl shadow-2xl border border-gray-100 py-2">
                                                    <div className="px-3 py-1.5 flex items-center justify-between border-b border-gray-100">
                                                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Plantillas</span>
                                                        <button type="button" onClick={() => setShowQuickReplies(false)} className="text-gray-400 hover:text-gray-600">×</button>
                                                    </div>
                                                    {quickReplies.map((r) => (
                                                        <button
                                                            key={r.id}
                                                            type="button"
                                                            onClick={() => insertQuickReply(r)}
                                                            className="w-full text-left px-3 py-2 hover:bg-emerald-50 transition-colors"
                                                        >
                                                            <code className="inline-block px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold font-mono">/{r.shortcut}</code>
                                                            <p className="text-xs text-gray-600 mt-1 truncate">{r.content}</p>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="relative flex-1">
                                            <textarea
                                                value={draft}
                                                onChange={(e) => {
                                                    const v = e.target.value;
                                                    setDraft(v);
                                                    if (v) announceTyping();
                                                    // Detecta "/query" al final: abre autocomplete filtrando por atajo
                                                    const m = v.match(/(?:^|\s)\/(\S*)$/);
                                                    setSlashQuery(m ? m[1].toLowerCase() : null);
                                                }}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
                                                    if (e.key === 'Escape') setSlashQuery(null);
                                                }}
                                                placeholder="Escribe un mensaje…"
                                                rows={1}
                                                className="w-full resize-none px-4 py-2.5 pr-10 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white transition-all max-h-32"
                                                ref={draftRef}
                                                disabled={!hasWhatsappConfig || sending}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowEmojiPicker(!showEmojiPicker)}
                                                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-amber-500 p-1"
                                                title="Emoji"
                                            >
                                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </button>
                                            {/* Autocomplete slash: filtra plantillas por atajo mientras tipeás /xxx */}
                                            {slashQuery !== null && (() => {
                                                const matches = quickReplies.filter((r) => r.shortcut.toLowerCase().includes(slashQuery));
                                                if (matches.length === 0) return null;
                                                return (
                                                    <div className="absolute bottom-14 left-0 z-20 w-72 max-h-56 overflow-y-auto bg-white rounded-xl shadow-2xl border border-emerald-200 py-1.5">
                                                        <div className="px-3 pb-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700">↹ Enter para insertar</div>
                                                        {matches.map((r) => (
                                                            <button
                                                                key={r.id}
                                                                type="button"
                                                                onMouseDown={(e) => { e.preventDefault(); insertQuickReply(r); }}
                                                                className="w-full text-left px-3 py-1.5 hover:bg-emerald-50 transition-colors"
                                                            >
                                                                <code className="inline-block px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold font-mono">/{r.shortcut}</code>
                                                                <p className="text-xs text-gray-600 mt-0.5 truncate">{r.content}</p>
                                                            </button>
                                                        ))}
                                                    </div>
                                                );
                                            })()}
                                            {showEmojiPicker && (
                                                <div className="absolute bottom-14 right-0 z-10 flex flex-wrap gap-1 rounded-xl bg-white p-2 shadow-xl border border-gray-100 w-64">
                                                    {['😀','😁','😂','🤣','😊','😍','🤔','😎','😢','😡','👍','👎','❤️','🔥','🎉','🙏','👏','💯','✅','❌'].map((e) => (
                                                        <button key={e} type="button" onClick={() => { setDraft(draft + e); setShowEmojiPicker(false); }} className="text-lg hover:scale-125 transition-transform p-1">{e}</button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {hasAi && (
                                            <button
                                                type="button"
                                                onClick={aiDraft}
                                                disabled={drafting}
                                                title="Generar borrador con IA"
                                                className="rounded-xl border border-violet-200 bg-gradient-to-br from-violet-50 to-purple-50 px-3 py-2.5 text-sm font-semibold text-violet-700 hover:from-violet-100 hover:to-purple-100 disabled:opacity-50 transition-all shadow-sm flex items-center gap-1.5"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                                </svg>
                                                {drafting ? '…' : 'IA'}
                                            </button>
                                        )}
                                        <button
                                            type="submit"
                                            disabled={!hasWhatsappConfig || sending || !draft.trim()}
                                            className="rounded-xl px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-br from-[#045474] to-[#1c486c] hover:from-[#056090] hover:to-[#1c486c] disabled:opacity-50 transition-all shadow-lg shadow-[#045474]/20 flex items-center gap-1.5"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                            </svg>
                                            Enviar
                                        </button>
                                    </div>
                                </form>
                            </>
                        )}
                    </section>

                    {/* COLUMNA 3: panel del contacto */}
                    {selected && showContactPanel && (
                        <aside className="w-80 shrink-0 border-l border-gray-100 bg-white flex flex-col overflow-y-auto">
                            <div className="p-5 text-center border-b border-gray-100 bg-gradient-to-br from-[#045474]/5 to-transparent">
                                <div className="mx-auto mb-3 flex justify-center">
                                    <Avatar name={selected.contact?.name || selected.contact?.phone} size="xl" />
                                </div>
                                <p className="font-bold text-gray-900 truncate px-2">{selected.contact?.name || 'Sin nombre'}</p>
                                <p className="text-sm text-gray-500 font-mono mt-0.5 truncate px-2">{selected.contact?.phone}</p>
                                {selected.contact?.email && <p className="text-xs text-gray-400 mt-1 truncate px-2">{selected.contact.email}</p>}
                                <div className="mt-3 flex justify-center">
                                    <StatusBadge status={selected.status} />
                                </div>
                            </div>

                            {/* Notas internas */}
                            <div className="p-4 border-b border-gray-100">
                                <p className="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2 flex items-center gap-1.5">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                    Notas internas
                                </p>
                                <div className="space-y-2 max-h-48 overflow-y-auto mb-2">
                                    {notes.map((n) => (
                                        <div key={n.id} className="rounded-lg bg-amber-50 border border-amber-100 p-2.5">
                                            {n.note_text && <p className="text-xs text-gray-700 whitespace-pre-wrap">{n.note_text}</p>}
                                            {n.audio_path && (
                                                <audio controls src={`/storage/${n.audio_path}`} className="w-full mt-1 h-8" />
                                            )}
                                            <p className="text-[10px] text-gray-400 mt-1">{n.author?.name ?? '—'} · {new Date(n.created_at).toLocaleDateString()}</p>
                                        </div>
                                    ))}
                                    {notes.length === 0 && <p className="text-xs text-gray-400 italic">Sin notas. Solo las ve tu equipo.</p>}
                                </div>
                                <form onSubmit={addNote} className="space-y-2">
                                    <textarea
                                        rows={2}
                                        value={noteDraft}
                                        onChange={(e) => setNoteDraft(e.target.value)}
                                        placeholder="Añadir nota…"
                                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                                    />
                                    <div className="flex gap-2">
                                        <button type="submit" disabled={!noteDraft.trim()} className="flex-1 px-3 py-1.5 text-xs font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg hover:from-amber-600 hover:to-orange-600 disabled:opacity-50 shadow-sm">
                                            Guardar nota
                                        </button>
                                        <VoiceRecorder onSend={addVoiceNote} />
                                    </div>
                                </form>
                            </div>

                            {/* Metadatos de la conversación */}
                            <div className="p-4 space-y-3 text-xs">
                                <div>
                                    <p className="font-bold uppercase tracking-wider text-gray-500 mb-1.5">Conversación</p>
                                    <dl className="space-y-1.5">
                                        <div className="flex justify-between"><dt className="text-gray-400">Creada</dt><dd className="text-gray-700 font-medium">{new Date(selected.created_at).toLocaleDateString()}</dd></div>
                                        <div className="flex justify-between"><dt className="text-gray-400">Último mensaje</dt><dd className="text-gray-700 font-medium">{timeAgo(selected.last_message_at)}</dd></div>
                                        <div className="flex justify-between"><dt className="text-gray-400">Agente</dt><dd className="text-gray-700 font-medium">{selected.assigned_agent?.name ?? 'Sin asignar'}</dd></div>
                                    </dl>
                                </div>
                                <div className="pt-3 border-t border-gray-100">
                                    <Link href={route('contacts.index')} className="text-xs text-[#045474] font-semibold hover:underline flex items-center gap-1">
                                        Ver ficha completa del contacto →
                                    </Link>
                                </div>
                            </div>
                        </aside>
                    )}
                </div>
            </div>
                </AuthenticatedLayout>
        <ImageModal src={lightbox?.src} alt={lightbox?.alt} onClose={() => setLightbox(null)} />
        </>
    );
}
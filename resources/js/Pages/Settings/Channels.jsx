import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { channelMeta } from '@/Components/ChannelBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Estado de cada canal de la cuenta.
 *
 * WhatsApp se muestra pero no se administra acá: tiene su propia pantalla con
 * la configuración de Meta, que es bastante más que un token. Dos lugares para
 * lo mismo es cómo se termina con dos verdades.
 */

function EstadoPill({ connected }) {
    return connected ? (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
            Conectado
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-gray-500 ring-1 ring-gray-200">
            <span className="h-1.5 w-1.5 rounded-full bg-gray-400" />
            Sin conectar
        </span>
    );
}

function Telegram({ canal, webhookUrl }) {
    const { data, setData, post, processing, errors, reset } = useForm({ bot_token: '' });
    const [abierto, setAbierto] = useState(false);

    const conectar = (e) => {
        e.preventDefault();
        post(route('settings.channels.telegram'), {
            preserveScroll: true,
            onSuccess: () => { reset('bot_token'); setAbierto(false); },
        });
    };

    if (canal.connected) {
        return (
            <div className="space-y-3">
                <p className="text-sm text-gray-600">
                    Bot <strong>@{canal.bot_username ?? '—'}</strong>
                    {canal.connected_at && (
                        <span className="text-gray-400"> · desde {new Date(canal.connected_at).toLocaleDateString('es')}</span>
                    )}
                </p>
                <p className="text-xs text-gray-500">
                    Los clientes te escriben al bot y la conversación aparece en el Inbox.
                    Un bot <strong>no puede escribir primero</strong>: solo responde a quien ya le habló.
                </p>
                <button
                    onClick={() => {
                        if (confirm('¿Desconectar Telegram? El bot va a dejar de recibir mensajes.')) {
                            router.delete(route('settings.channels.telegram.destroy'), { preserveScroll: true });
                        }
                    }}
                    className="text-xs font-semibold text-red-600 hover:text-red-700"
                >
                    Desconectar
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {!abierto ? (
                <>
                    <p className="text-xs text-gray-500">
                        Creá un bot con <span className="font-mono">@BotFather</span> en Telegram y pegá acá el token que te da.
                    </p>
                    <button
                        onClick={() => setAbierto(true)}
                        className="rounded-xl bg-gradient-to-r from-sky-600 to-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-sky-500/20 transition-all hover:from-sky-500 hover:to-blue-500"
                    >
                        Conectar Telegram
                    </button>
                </>
            ) : (
                <form onSubmit={conectar} className="space-y-2">
                    <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500">Bot token</label>
                    <input
                        value={data.bot_token}
                        onChange={(e) => setData('bot_token', e.target.value)}
                        placeholder="123456789:AAE..."
                        autoComplete="off"
                        className="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2 font-mono text-sm transition-all focus:border-sky-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/30"
                    />
                    {errors.bot_token && <p className="text-xs text-red-600">{errors.bot_token}</p>}
                    <p className="text-[11px] text-gray-400">
                        Se valida contra Telegram antes de guardarlo, y se registra el webhook automáticamente.
                    </p>
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-gradient-to-r from-sky-600 to-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {processing ? 'Conectando…' : 'Conectar'}
                        </button>
                        <button type="button" onClick={() => setAbierto(false)} className="px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                            Cancelar
                        </button>
                    </div>
                </form>
            )}

            <details className="text-[11px] text-gray-400">
                <summary className="cursor-pointer hover:text-gray-600">URL del webhook</summary>
                {/* Se muestra porque cuando algo no llega, lo primero que hay que
                    poder mirar es si esta URL es la que el bot tiene registrada. */}
                <code className="mt-1 block break-all rounded-lg bg-gray-50 p-2 font-mono">{webhookUrl}</code>
            </details>
        </div>
    );
}

export default function Channels({ channels, telegramWebhookUrl }) {
    return (
        <AuthenticatedLayout>
            <Head title="Canales" />

            <div className="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <div>
                    <h1 className="text-xl font-bold text-gray-900">Canales</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Por dónde pueden escribirte los clientes. Todo lo que entre acá aparece en el mismo Inbox
                        y crea el mismo lead, sin importar el canal.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {channels.map((canal) => {
                        const meta = channelMeta(canal.channel);

                        return (
                            <div key={canal.channel} className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                                <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                                    <div className="flex items-center gap-2.5">
                                        <span className="text-xl" aria-hidden="true">{meta.icon}</span>
                                        <h2 className="text-base font-bold text-gray-900">{meta.label}</h2>
                                    </div>
                                    <EstadoPill connected={canal.connected} />
                                </div>

                                <div className="px-5 py-4">
                                    {canal.managed_elsewhere ? (
                                        <div className="space-y-2">
                                            <p className="text-xs text-gray-500">
                                                Se configura en su propia pantalla: número, token de Meta y webhook.
                                            </p>
                                            <Link href={canal.managed_elsewhere} className="text-sm font-semibold text-emerald-600 hover:text-emerald-700">
                                                Ir a la configuración →
                                            </Link>
                                        </div>
                                    ) : (
                                        <Telegram canal={canal} webhookUrl={telegramWebhookUrl} />
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function WhatsApp({ config, webhookUrl }) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(null);

    const { data, setData, post, processing, errors, recentlySuccessful } = useForm({
        phone_number_id: config?.phone_number_id ?? '',
        waba_id: config?.waba_id ?? '',
        access_token: '',
    });

    const copy = (text, id) => {
        navigator.clipboard.writeText(text);
        setCopied(id);
        setTimeout(() => setCopied(null), 2000);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.whatsapp.update'), { preserveScroll: true });
    };

    const isConnected = config?.status === 'connected';

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">WhatsApp</h2>}>
            <Head title="WhatsApp" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">WhatsApp Business API</h1>
                    <p className="text-sm text-gray-400 mt-1">Conecta tu número oficial de WhatsApp para enviar y recibir mensajes</p>
                </div>

                {config && (
                    <div className={`rounded-2xl border p-5 shadow-sm ${
                        isConnected
                            ? 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50'
                            : 'border-red-200 bg-gradient-to-br from-red-50 to-rose-50'
                    }`}>
                        <div className="flex items-center gap-4">
                            <div className={`w-12 h-12 rounded-2xl bg-gradient-to-br flex items-center justify-center text-white shadow-lg ${
                                isConnected
                                    ? 'from-emerald-500 to-teal-600 shadow-emerald-500/30'
                                    : 'from-red-500 to-rose-600 shadow-red-500/30'
                            }`}>
                                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    {isConnected ? (
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    ) : (
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    )}
                                </svg>
                            </div>
                            <div>
                                <p className={`font-bold ${isConnected ? 'text-emerald-900' : 'text-red-900'}`}>
                                    {isConnected ? 'Conectado' : 'Desconectado'}
                                </p>
                                {config.connected_at && (
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        Desde el {new Date(config.connected_at).toLocaleString('es', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-center gap-3 shadow-sm">
                        <div className="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center flex-shrink-0">
                            <svg className="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        {flash.success}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2 items-start">

                {/* Credenciales */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Credenciales</h3>
                            <p className="text-xs text-gray-400 mt-0.5">De Meta for Developers → tu app → WhatsApp → API Setup</p>
                        </div>
                    </div>

                    <form onSubmit={submit} className="p-5 sm:p-6 space-y-4">
                        <div>
                            <label htmlFor="phone_number_id" className="block text-sm font-semibold text-gray-700 mb-1.5">Phone Number ID</label>
                            <input
                                id="phone_number_id"
                                value={data.phone_number_id}
                                onChange={(e) => setData('phone_number_id', e.target.value)}
                                required
                                className={`w-full px-3.5 py-2.5 border rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
                                    errors.phone_number_id ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white focus:bg-white'
                                }`}
                            />
                            {errors.phone_number_id && <p className="mt-1 text-xs text-red-500 font-medium">{errors.phone_number_id}</p>}
                        </div>

                        <div>
                            <label htmlFor="waba_id" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                WABA ID <span className="text-gray-400 font-normal">(opcional, requerido para plantillas)</span>
                            </label>
                            <input
                                id="waba_id"
                                value={data.waba_id}
                                onChange={(e) => setData('waba_id', e.target.value)}
                                className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-mono bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all"
                            />
                        </div>

                        <div>
                            <label htmlFor="access_token" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                Access Token {config && <span className="text-gray-400 font-normal">(vacío = conservar el actual)</span>}
                            </label>
                            <input
                                id="access_token"
                                type="password"
                                value={data.access_token}
                                onChange={(e) => setData('access_token', e.target.value)}
                                placeholder={config ? '••••••••••••' : 'EAAG…'}
                                className={`w-full px-3.5 py-2.5 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
                                    errors.access_token ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white focus:bg-white'
                                }`}
                            />
                            {errors.access_token && <p className="mt-1 text-xs text-red-500 font-medium">{errors.access_token}</p>}
                            <p className="mt-1.5 text-xs text-gray-400 flex items-center gap-1">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                Se guarda cifrado; nunca vuelve a mostrarse
                            </p>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                            >
                                {processing ? 'Guardando…' : 'Guardar y probar conexión'}
                            </button>
                            {recentlySuccessful && (
                                <span className="text-sm text-emerald-600 font-medium">✓ Guardado</span>
                            )}
                        </div>
                    </form>
                </div>

                {/* Webhook */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-purple-500/20">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Webhook</h3>
                            <p className="text-xs text-gray-400 mt-0.5">Configura esto en Meta → WhatsApp → Configuration</p>
                        </div>
                    </div>

                    <div className="p-5 sm:p-6 space-y-4">
                        <div>
                            <div className="flex items-center justify-between mb-1.5">
                                <label className="block text-sm font-semibold text-gray-700">Callback URL</label>
                                <button
                                    onClick={() => copy(webhookUrl, 'url')}
                                    className="text-xs text-emerald-600 hover:text-emerald-700 font-medium"
                                >
                                    {copied === 'url' ? '✓ Copiado' : 'Copiar'}
                                </button>
                            </div>
                            <div className="px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono text-xs text-gray-700 select-all break-all">
                                {webhookUrl}
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center justify-between mb-1.5">
                                <label className="block text-sm font-semibold text-gray-700">Verify token</label>
                                {config?.verify_token && (
                                    <button
                                        onClick={() => copy(config.verify_token, 'token')}
                                        className="text-xs text-emerald-600 hover:text-emerald-700 font-medium"
                                    >
                                        {copied === 'token' ? '✓ Copiado' : 'Copiar'}
                                    </button>
                                )}
                            </div>
                            <div className="px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono text-xs text-gray-700 select-all break-all">
                                {config?.verify_token ?? <span className="text-gray-400 italic">Se genera al guardar la configuración</span>}
                            </div>
                        </div>

                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800 flex items-start gap-2">
                            <svg className="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <span>
                                Recuerda definir <code className="bg-amber-100 px-1.5 py-0.5 rounded text-[10px] font-mono">META_APP_SECRET</code> en el archivo{' '}
                                <code className="bg-amber-100 px-1.5 py-0.5 rounded text-[10px] font-mono">.env</code> del servidor — sin él, el webhook rechaza todos los eventos.
                                Suscríbete al campo <code className="bg-amber-100 px-1.5 py-0.5 rounded text-[10px] font-mono">messages</code>.
                            </span>
                        </div>
                    </div>
                </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}

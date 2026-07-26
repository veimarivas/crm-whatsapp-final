import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ROLE_META = {
    owner: { label: 'Owner', gradient: 'from-amber-500 to-orange-600', ringColor: 'ring-amber-200', bg: 'bg-amber-50', text: 'text-amber-800' },
    admin: { label: 'Admin', gradient: 'from-purple-500 to-violet-600', ringColor: 'ring-purple-200', bg: 'bg-purple-50', text: 'text-purple-800' },
    agent: { label: 'Agente', gradient: 'from-emerald-500 to-teal-600', ringColor: 'ring-emerald-200', bg: 'bg-emerald-50', text: 'text-emerald-800' },
    viewer: { label: 'Solo lectura', gradient: 'from-gray-400 to-gray-500', ringColor: 'ring-gray-200', bg: 'bg-gray-50', text: 'text-gray-700' },
};

const ALL_SCOPES = [
    'contacts:read', 'contacts:write', 'conversations:read', 'conversations:write',
    'messages:write', 'broadcasts:read', 'broadcasts:write', 'team:write',
];

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';

const tabs = [
    { key: 'members', label: 'Miembros', icon: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z' },
    { key: 'api', label: 'API Keys', icon: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z' },
    { key: 'webhooks', label: 'Webhooks', icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244' },
];

function CopyBox({ value, label, color = 'emerald' }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className={`rounded-2xl border p-5 shadow-sm bg-gradient-to-br ${
            color === 'emerald' ? 'border-emerald-200 from-emerald-50 to-teal-50' : 'border-amber-200 from-amber-50 to-orange-50'
        }`}>
            <div className="flex items-start gap-3">
                <div className={`w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br flex items-center justify-center text-white shadow-lg ${
                    color === 'emerald' ? 'from-emerald-500 to-teal-600 shadow-emerald-500/30' : 'from-amber-500 to-orange-600 shadow-amber-500/30'
                }`}>
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                    </svg>
                </div>
                <div className="flex-1 min-w-0">
                    <p className={`text-sm font-bold ${color === 'emerald' ? 'text-emerald-900' : 'text-amber-900'}`}>{label}</p>
                    <p className={`text-xs mb-2 mt-0.5 ${color === 'emerald' ? 'text-emerald-700' : 'text-amber-700'}`}>Cópialo ahora — no volverá a mostrarse</p>
                    <div className="flex items-center gap-2">
                        <code className="flex-1 block select-all break-all rounded-lg bg-white px-3 py-2 text-xs font-mono ring-1 ring-inset ring-gray-200">
                            {value}
                        </code>
                        <button
                            onClick={copy}
                            className="px-3 py-2 text-xs font-semibold text-white bg-gradient-to-r from-gray-700 to-gray-900 rounded-lg hover:from-gray-600 hover:to-gray-800 transition-all shadow-sm shrink-0"
                        >
                            {copied ? '✓' : 'Copiar'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Team({
    members, invitations, apiKeys, webhooks, webhookEvents, isAdmin, isOwner,
    newInviteUrl, newApiKey, newWebhookSecret,
}) {
    const { flash, errors, auth } = usePage().props;

    const inviteForm = useForm({ role: 'agent', label: '' });
    const keyForm = useForm({ name: '', scopes: ['contacts:read'] });
    const webhookForm = useForm({ url: '', events: ['message.received'] });
    const [editingWebhookId, setEditingWebhookId] = useState(null);
    const editWebhookForm = useForm({ url: '', events: [] });

    const startEditWebhook = (hook) => {
        editWebhookForm.setData({ url: hook.url, events: hook.events ?? [] });
        setEditingWebhookId(hook.id);
    };
    const cancelEditWebhook = () => { setEditingWebhookId(null); editWebhookForm.reset(); };
    const toggleEditEvent = (event) => {
        editWebhookForm.setData(
            'events',
            editWebhookForm.data.events.includes(event)
                ? editWebhookForm.data.events.filter((s) => s !== event)
                : [...editWebhookForm.data.events, event],
        );
    };
    const saveEditWebhook = (e, hookId) => {
        e.preventDefault();
        editWebhookForm.patch(route('team.webhooks.update', hookId), {
            preserveScroll: true,
            onSuccess: () => { setEditingWebhookId(null); editWebhookForm.reset(); },
        });
    };

    const invite = (e) => { e.preventDefault(); inviteForm.post(route('team.invite'), { preserveScroll: true }); };
    const createKey = (e) => { e.preventDefault(); keyForm.post(route('team.api-keys.store'), { preserveScroll: true, onSuccess: () => keyForm.reset() }); };
    const createWebhook = (e) => { e.preventDefault(); webhookForm.post(route('team.webhooks.store'), { preserveScroll: true, onSuccess: () => webhookForm.reset() }); };

    const toggleScope = (scope) =>
        keyForm.setData(
            'scopes',
            keyForm.data.scopes.includes(scope) ? keyForm.data.scopes.filter((s) => s !== scope) : [...keyForm.data.scopes, scope],
        );

    const toggleWebhookEvent = (event) =>
        webhookForm.setData(
            'events',
            webhookForm.data.events.includes(event) ? webhookForm.data.events.filter((s) => s !== event) : [...webhookForm.data.events, event],
        );

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Equipo y API</h2>}>
            <Head title="Equipo" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                {/* Header */}
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20 shrink-0">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                    </div>
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Equipo y API</h1>
                        <p className="text-sm text-gray-500 mt-1">Miembros, invitaciones, claves y webhooks</p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-center gap-3 shadow-sm mb-6">
                        <div className="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                            <svg className="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        {flash.success}
                    </div>
                )}
                {errors?.member && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{errors.member}</div>
                )}

                {newInviteUrl && <div className="mb-6"><CopyBox value={newInviteUrl} label="Link de invitación creado" color="emerald" /></div>}
                {newApiKey && <div className="mb-6"><CopyBox value={newApiKey} label="API key creada" color="emerald" /></div>}
                {newWebhookSecret && <div className="mb-6"><CopyBox value={newWebhookSecret} label="Secreto de firma del webhook" color="amber" /></div>}

                <TabGroup>
                    <TabList className="flex gap-1 p-1 bg-gray-100 rounded-2xl mb-6 overflow-x-auto">
                        {tabs.map((tab) => (
                            <Tab
                                key={tab.key}
                                className="data-[selected]:bg-white data-[selected]:shadow-sm data-[selected]:text-[#045474] px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 transition-all whitespace-nowrap flex items-center gap-2 outline-none"
                            >
                                <svg className="w-4 h-4 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d={tab.icon} />
                                </svg>
                                {tab.label}
                            </Tab>
                        ))}
                    </TabList>

                    <TabPanels>
                        {/* Members */}
                        <TabPanel>
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-md">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-base font-bold text-gray-900">Miembros</h3>
                                        <p className="text-xs text-gray-400 mt-0.5">{members.length} miembro{members.length !== 1 ? 's' : ''} en tu equipo</p>
                                    </div>
                                </div>

                                <ul className="divide-y divide-gray-50">
                                    {members.map((member) => {
                                        const role = ROLE_META[member.account_role] ?? ROLE_META.viewer;
                                        return (
                                            <li key={member.id} className="flex items-center justify-between px-5 sm:px-6 py-3.5 hover:bg-gray-50 transition-colors">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <div className="relative shrink-0">
                                                        <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${role.gradient} flex items-center justify-center text-white text-sm font-bold shadow-md`}>
                                                            {member.name.charAt(0).toUpperCase()}
                                                        </div>
                                                        <span
                                                            title={member.online ? 'En línea' : 'Desconectado'}
                                                            className={`absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full ring-2 ring-white ${member.online ? 'bg-emerald-500' : 'bg-gray-300'}`}
                                                        />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-gray-900 text-sm truncate">
                                                            {member.name}
                                                            {member.id === auth.user.id && <span className="ml-2 text-xs text-gray-400 font-normal">(tú)</span>}
                                                        </p>
                                                        <p className="text-xs text-gray-500 truncate">{member.email}</p>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0 ml-3">
                                                    {isAdmin && member.account_role !== 'owner' ? (
                                                        <>
                                                            <select
                                                                value={member.account_role}
                                                                onChange={(e) =>
                                                                    router.patch(route('team.members.update', member.id), { account_role: e.target.value }, { preserveScroll: true })
                                                                }
                                                                className="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs font-medium bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all"
                                                            >
                                                                <option value="admin">Admin</option>
                                                                <option value="agent">Agente</option>
                                                                <option value="viewer">Solo lectura</option>
                                                            </select>
                                                            {isOwner && member.id !== auth.user.id && (
                                                                <button
                                                                    onClick={() => {
                                                                        if (confirm(`¿Transferir la propiedad de la cuenta a ${member.name}? Tú pasarás a ser admin.`)) {
                                                                            router.post(route('team.members.transfer', member.id), {}, { preserveScroll: true });
                                                                        }
                                                                    }}
                                                                    className="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                                                    title="Hacer owner"
                                                                >
                                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                            {member.id !== auth.user.id && (
                                                                <button
                                                                    onClick={() => {
                                                                        if (confirm(`¿Expulsar a ${member.name}?`)) {
                                                                            router.delete(route('team.members.remove', member.id), { preserveScroll: true });
                                                                        }
                                                                    }}
                                                                    className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                                    title="Expulsar"
                                                                >
                                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 ${role.bg} ${role.text} ${role.ringColor}`}>
                                                            <span className={`w-1.5 h-1.5 rounded-full bg-gradient-to-br ${role.gradient}`} />
                                                            {role.label}
                                                        </span>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>

                                {isAdmin && (
                                    <form onSubmit={invite} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 flex flex-wrap items-end gap-3">
                                        <div>
                                            <label className="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Rol</label>
                                            <select
                                                value={inviteForm.data.role}
                                                onChange={(e) => inviteForm.setData('role', e.target.value)}
                                                className={inputClass}
                                            >
                                                <option value="admin">Admin</option>
                                                <option value="agent">Agente</option>
                                                <option value="viewer">Solo lectura</option>
                                            </select>
                                        </div>
                                        <div className="flex-1 min-w-[200px]">
                                            <label className="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Etiqueta (opcional)</label>
                                            <input
                                                value={inviteForm.data.label}
                                                onChange={(e) => inviteForm.setData('label', e.target.value)}
                                                placeholder="ej. Equipo de ventas"
                                                className={inputClass}
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={inviteForm.processing}
                                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20 inline-flex items-center gap-1.5"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                                            </svg>
                                            Generar link
                                        </button>
                                    </form>
                                )}

                                {invitations.length > 0 && (
                                    <div className="p-5 sm:p-6 border-t border-gray-100 space-y-2">
                                        <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Invitaciones activas</p>
                                        {invitations.map((inv) => (
                                            <div key={inv.id} className="flex items-center justify-between rounded-xl bg-gray-50 p-3 text-sm">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold ring-1 shrink-0 ${ROLE_META[inv.role]?.bg} ${ROLE_META[inv.role]?.text} ${ROLE_META[inv.role]?.ringColor}`}>
                                                        {ROLE_META[inv.role]?.label ?? inv.role}
                                                    </span>
                                                    <span className="text-gray-600 truncate">
                                                        {inv.label || 'Sin etiqueta'}
                                                        <span className="text-gray-400 ml-2 text-xs">expira {new Date(inv.expires_at).toLocaleDateString('es', { day: 'numeric', month: 'short' })}</span>
                                                    </span>
                                                </div>
                                                {isAdmin && (
                                                    <button
                                                        onClick={() => router.delete(route('team.invitations.revoke', inv.id), { preserveScroll: true })}
                                                        className="text-xs text-red-600 hover:text-red-700 font-medium shrink-0"
                                                    >
                                                        Revocar
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </TabPanel>

                        {/* API Keys */}
                        <TabPanel>
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-md">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-base font-bold text-gray-900">API keys</h3>
                                        <p className="text-xs text-gray-400 mt-0.5">
                                            Para la API pública <code className="bg-gray-100 px-1.5 py-0.5 rounded text-[10px] font-mono">/api/v1</code>. Se envían como <code className="bg-gray-100 px-1.5 py-0.5 rounded text-[10px] font-mono">Authorization: Bearer …</code>
                                        </p>
                                    </div>
                                </div>

                                <ul className="divide-y divide-gray-50">
                                    {apiKeys.map((key) => (
                                        <li key={key.id} className="flex items-center justify-between px-5 sm:px-6 py-3 hover:bg-gray-50 transition-colors">
                                            <div className="min-w-0">
                                                <p className="font-semibold text-gray-900 text-sm flex items-center gap-2">
                                                    {key.name}
                                                    {key.revoked_at && (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-700 ring-1 ring-red-200">
                                                            Revocada
                                                        </span>
                                                    )}
                                                </p>
                                                <p className="text-xs text-gray-400 font-mono mt-0.5 truncate">{key.key_prefix}… · {(key.scopes ?? []).join(', ')}</p>
                                            </div>
                                            {isAdmin && !key.revoked_at && (
                                                <button
                                                    onClick={() => {
                                                        if (confirm('¿Revocar esta API key?')) {
                                                            router.delete(route('team.api-keys.revoke', key.id), { preserveScroll: true });
                                                        }
                                                    }}
                                                    className="text-xs text-red-600 hover:text-red-700 font-medium shrink-0 ml-3"
                                                >
                                                    Revocar
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                    {apiKeys.length === 0 && (
                                        <li className="px-5 sm:px-6 py-10 text-center text-sm text-gray-400">Sin API keys</li>
                                    )}
                                </ul>

                                {isAdmin && (
                                    <form onSubmit={createKey} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 space-y-3">
                                        <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Crear nueva API key</p>
                                        <input
                                            value={keyForm.data.name}
                                            onChange={(e) => keyForm.setData('name', e.target.value)}
                                            required
                                            placeholder="ej. Integración con mi web"
                                            className={inputClass}
                                        />
                                        <div className="flex flex-wrap gap-1.5">
                                            {ALL_SCOPES.map((scope) => {
                                                const selected = keyForm.data.scopes.includes(scope);
                                                return (
                                                    <button
                                                        key={scope}
                                                        type="button"
                                                        onClick={() => toggleScope(scope)}
                                                        className={`px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition-all ${
                                                            selected
                                                                ? 'bg-purple-100 text-purple-700 ring-1 ring-purple-200'
                                                                : 'bg-white text-gray-500 ring-1 ring-gray-200 hover:ring-gray-300'
                                                        }`}
                                                    >
                                                        {scope}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={keyForm.processing}
                                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-violet-600 rounded-xl hover:from-purple-500 hover:to-violet-500 disabled:opacity-50 transition-all shadow-lg shadow-purple-500/20"
                                        >
                                            Crear API key
                                        </button>
                                    </form>
                                )}
                            </div>
                        </TabPanel>

                        {/* Webhooks */}
                        <TabPanel>
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white shadow-md">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-base font-bold text-gray-900">Webhooks salientes</h3>
                                        <p className="text-xs text-gray-400 mt-0.5">Recibe eventos del CRM firmados con HMAC-SHA256</p>
                                    </div>
                                </div>

                                <ul className="divide-y divide-gray-50">
                                    {webhooks.map((hook) => (
                                        <li key={hook.id} className={`transition-colors ${editingWebhookId === hook.id ? 'bg-amber-50/50' : 'hover:bg-gray-50'}`}>
                                            {editingWebhookId === hook.id ? (
                                                <form onSubmit={(e) => saveEditWebhook(e, hook.id)} className="p-5 sm:px-6 space-y-3">
                                                    <p className="text-xs font-bold uppercase tracking-wider text-amber-700">Editando webhook</p>
                                                    <input
                                                        type="url"
                                                        value={editWebhookForm.data.url}
                                                        onChange={(e) => editWebhookForm.setData('url', e.target.value)}
                                                        required
                                                        className={`w-full px-3.5 py-2.5 border rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 bg-white ${
                                                            editWebhookForm.errors.url ? 'border-red-300 bg-red-50' : 'border-gray-200'
                                                        }`}
                                                    />
                                                    {editWebhookForm.errors.url && <p className="text-xs text-red-500 font-medium">{editWebhookForm.errors.url}</p>}
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {webhookEvents.map((event) => {
                                                            const selected = editWebhookForm.data.events.includes(event);
                                                            return (
                                                                <button
                                                                    key={event}
                                                                    type="button"
                                                                    onClick={() => toggleEditEvent(event)}
                                                                    className={`rounded-full px-2.5 py-1 text-xs font-mono font-semibold transition-all ${
                                                                        selected ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-300' : 'bg-white text-gray-500 ring-1 ring-gray-200 hover:bg-gray-50'
                                                                    }`}
                                                                >
                                                                    {event}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                    {editWebhookForm.errors.events && <p className="text-xs text-red-500 font-medium">{editWebhookForm.errors.events}</p>}
                                                    <div className="flex gap-2 justify-end">
                                                        <button type="button" onClick={cancelEditWebhook} className="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-all">
                                                            Cancelar
                                                        </button>
                                                        <button type="submit" disabled={editWebhookForm.processing} className="px-4 py-1.5 text-xs font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg hover:from-amber-600 hover:to-orange-600 disabled:opacity-50 shadow-sm transition-all">
                                                            {editWebhookForm.processing ? 'Guardando…' : 'Guardar cambios'}
                                                        </button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <div className="flex items-center justify-between px-5 sm:px-6 py-3.5">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate font-mono text-xs text-gray-800 font-semibold">{hook.url}</p>
                                                        <div className="flex flex-wrap items-center gap-2 mt-1">
                                                            {(hook.events ?? []).map((e) => (
                                                                <span key={e} className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{e}</span>
                                                            ))}
                                                            {hook.failure_count > 0 && (
                                                                <span className="text-[10px] font-semibold text-red-500">{hook.failure_count} fallos seguidos</span>
                                                            )}
                                                            {hook.last_delivery_at && (
                                                                <span className="text-[10px] text-gray-400">última: {new Date(hook.last_delivery_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    {isAdmin && (
                                                        <div className="ml-3 flex shrink-0 items-center gap-2">
                                                            <button
                                                                onClick={() => router.post(route('team.webhooks.toggle', hook.id), {}, { preserveScroll: true })}
                                                                className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 transition-all ${
                                                                    hook.is_active
                                                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                                                        : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                                                                }`}
                                                            >
                                                                <span className={`w-1.5 h-1.5 rounded-full ${hook.is_active ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                                                {hook.is_active ? 'Activo' : 'Inactivo'}
                                                            </button>
                                                            <button onClick={() => startEditWebhook(hook)} title="Editar" className="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors">
                                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                                                                </svg>
                                                            </button>
                                                            <button
                                                                onClick={() => { if (confirm('¿Eliminar este webhook?')) { router.delete(route('team.webhooks.destroy', hook.id), { preserveScroll: true }); } }}
                                                                className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                            >
                                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                    {webhooks.length === 0 && (
                                        <li className="px-5 sm:px-6 py-10 text-center text-sm text-gray-400">Sin webhooks configurados</li>
                                    )}
                                </ul>

                                {isAdmin && (
                                    <form onSubmit={createWebhook} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 space-y-3">
                                        <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Crear nuevo webhook</p>
                                        <input
                                            type="url"
                                            value={webhookForm.data.url}
                                            onChange={(e) => webhookForm.setData('url', e.target.value)}
                                            required
                                            placeholder="https://tu-servidor.com/webhooks/crm"
                                            className={`${inputClass} ${webhookForm.errors.url ? 'border-red-300 bg-red-50' : ''}`}
                                        />
                                        {webhookForm.errors.url && <p className="text-xs text-red-500 font-medium">{webhookForm.errors.url}</p>}
                                        <div className="flex flex-wrap gap-1.5">
                                            {webhookEvents.map((event) => {
                                                const selected = webhookForm.data.events.includes(event);
                                                return (
                                                    <button
                                                        key={event}
                                                        type="button"
                                                        onClick={() => toggleWebhookEvent(event)}
                                                        className={`px-3 py-1.5 rounded-lg text-xs font-mono font-semibold transition-all ${
                                                            selected
                                                                ? 'bg-amber-100 text-amber-700 ring-1 ring-amber-200'
                                                                : 'bg-white text-gray-500 ring-1 ring-gray-200 hover:ring-gray-300'
                                                        }`}
                                                    >
                                                        {event}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={webhookForm.processing}
                                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-amber-600 to-orange-600 rounded-xl hover:from-amber-500 hover:to-orange-500 disabled:opacity-50 transition-all shadow-lg shadow-amber-500/20"
                                        >
                                            Crear webhook
                                        </button>
                                    </form>
                                )}
                            </div>
                        </TabPanel>
                    </TabPanels>
                </TabGroup>
            </div>
        </AuthenticatedLayout>
    );
}

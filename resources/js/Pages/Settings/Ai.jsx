import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const DEFAULT_MODELS = {
    openai: 'gpt-4o-mini',
    anthropic: 'claude-sonnet-5',
    ollama: 'qwen2.5:7b',
};

const PROVIDER_META = {
    openai: { name: 'OpenAI', gradient: 'from-emerald-500 to-teal-600' },
    anthropic: { name: 'Anthropic', gradient: 'from-orange-500 to-amber-600' },
    ollama: { name: 'Ollama', gradient: 'from-sky-500 to-indigo-600' },
};

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

const tabs = [
    { key: 'provider', label: 'Proveedor', icon: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z' },
    { key: 'behavior', label: 'Comportamiento', icon: 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z' },
    { key: 'hours', label: 'Horario', icon: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z' },
    { key: 'knowledge', label: 'Base de conocimiento', icon: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25' },
];

export default function Ai({ config, documents }) {
    const { flash } = usePage().props;

    const DEFAULT_HOURS = {
        mon: [['08:00', '19:00']], tue: [['08:00', '19:00']], wed: [['08:00', '19:00']],
        thu: [['08:00', '19:00']], fri: [['08:00', '19:00']], sat: [['09:00', '13:00']], sun: [],
    };

    const form = useForm({
        provider: config?.provider ?? 'openai',
        model: config?.model ?? DEFAULT_MODELS.openai,
        base_url: config?.base_url ?? 'http://127.0.0.1:11434',
        api_key: '',
        embeddings_api_key: '',
        system_prompt: config?.system_prompt ?? '',
        is_active: config?.is_active ?? false,
        auto_reply_enabled: config?.auto_reply_enabled ?? false,
        auto_reply_max_per_conversation: config?.auto_reply_max_per_conversation ?? 3,
        auto_reply_cooldown_hours: config?.auto_reply_cooldown_hours ?? 3,
        business_hours: config?.business_hours ?? null,
        after_hours_message: config?.after_hours_message ?? '',
        timezone: config?.timezone ?? 'America/La_Paz',
    });

    const hoursEnabled = form.data.business_hours !== null;
    const hours = form.data.business_hours ?? DEFAULT_HOURS;
    const DAY_LABELS = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };

    const setDayRange = (day, start, end) => {
        const next = { ...(form.data.business_hours ?? DEFAULT_HOURS) };
        next[day] = start && end ? [[start, end]] : [];
        form.setData('business_hours', next);
    };
    const toggleHours = () => form.setData('business_hours', hoursEnabled ? null : DEFAULT_HOURS);

    const isOllama = form.data.provider === 'ollama';

    const docForm = useForm({ title: '', content: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('settings.ai.update'), { preserveScroll: true });
    };

    const addDocument = (e) => {
        e.preventDefault();
        docForm.post(route('settings.ai.documents.store'), {
            preserveScroll: true,
            onSuccess: () => docForm.reset(),
        });
    };

    const provider = PROVIDER_META[form.data.provider];

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Asistente IA</h2>}>
            <Head title="IA" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                {/* Header */}
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20 shrink-0">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        </svg>
                    </div>
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Asistente IA</h1>
                        <p className="text-sm text-gray-500 mt-1">Borradores en el inbox y auto-respuesta con tu propia clave del proveedor</p>
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

                <form onSubmit={submit}>
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
                            {/* Provider */}
                            <TabPanel>
                                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                        <div className={`w-9 h-9 rounded-xl bg-gradient-to-br ${provider.gradient} flex items-center justify-center text-white shadow-md`}>
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 className="text-base font-bold text-gray-900">Proveedor</h3>
                                            <p className="text-xs text-gray-400 mt-0.5">Tu propia clave — se guarda cifrada, las llamadas van directas al proveedor</p>
                                        </div>
                                    </div>

                                    <div className="p-5 sm:p-6 space-y-5">
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-sm font-semibold text-gray-700 mb-1.5">Proveedor</label>
                                                <select
                                                    value={form.data.provider}
                                                    onChange={(e) => {
                                                        form.setData((d) => ({ ...d, provider: e.target.value, model: DEFAULT_MODELS[e.target.value] }));
                                                    }}
                                                    className={inputClass}
                                                >
                                                    <option value="openai">OpenAI</option>
                                                    <option value="anthropic">Anthropic</option>
                                                    <option value="ollama">Ollama (local)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label htmlFor="model" className="block text-sm font-semibold text-gray-700 mb-1.5">Modelo</label>
                                                <input
                                                    id="model"
                                                    value={form.data.model}
                                                    onChange={(e) => form.setData('model', e.target.value)}
                                                    required
                                                    className={`${inputClass} font-mono`}
                                                />
                                            </div>
                                        </div>

                                        {isOllama && (
                                            <div>
                                                <label htmlFor="base_url" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                                    Base URL <span className="text-gray-400 font-normal">— endpoint de Ollama</span>
                                                </label>
                                                <input
                                                    id="base_url"
                                                    value={form.data.base_url}
                                                    onChange={(e) => form.setData('base_url', e.target.value)}
                                                    placeholder="http://127.0.0.1:11434"
                                                    className={`${inputClass} font-mono`}
                                                />
                                                <p className="mt-1.5 text-xs text-gray-500">Si Ollama corre en el mismo servidor de Laravel, dejá el valor por defecto.</p>
                                            </div>
                                        )}

                                        {!isOllama && (
                                            <div>
                                                <label htmlFor="api_key" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                                    API key {config && <span className="text-gray-400 font-normal">(vacío = conservar la actual)</span>}
                                                </label>
                                                <input
                                                    id="api_key"
                                                    type="password"
                                                    value={form.data.api_key}
                                                    onChange={(e) => form.setData('api_key', e.target.value)}
                                                    placeholder={config ? '••••••••••••' : 'sk-…'}
                                                    className={`${inputClass} ${form.errors.api_key ? 'border-red-300 bg-red-50' : ''}`}
                                                />
                                                {form.errors.api_key && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.api_key}</p>}
                                            </div>
                                        )}

                                        <div>
                                            <label htmlFor="embeddings_api_key" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                                Clave de embeddings <span className="text-gray-400 font-normal">— OpenAI, opcional</span>
                                            </label>
                                            <input
                                                id="embeddings_api_key"
                                                type="password"
                                                value={form.data.embeddings_api_key}
                                                onChange={(e) => form.setData('embeddings_api_key', e.target.value)}
                                                placeholder={config?.has_embeddings_key ? '••••••••••••' : 'sk-…'}
                                                className={inputClass}
                                            />
                                            <p className="mt-1.5 text-xs text-gray-500">
                                                Sin esta clave, la base de conocimiento usa búsqueda por palabras. Con ella, busca por significado.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="px-5 sm:px-6 py-4 bg-gray-50/80 border-t border-gray-100 flex justify-end">
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                                        >
                                            {form.processing ? 'Guardando…' : 'Guardar configuración'}
                                        </button>
                                    </div>
                                </div>
                            </TabPanel>

                            {/* Behavior */}
                            <TabPanel>
                                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-md">
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 className="text-base font-bold text-gray-900">Comportamiento</h3>
                                            <p className="text-xs text-gray-400 mt-0.5">Configuración del contexto, draft IA y auto-respuesta</p>
                                        </div>
                                    </div>

                                    <div className="p-5 sm:p-6 space-y-5">
                                        <div>
                                            <label htmlFor="system_prompt" className="block text-sm font-semibold text-gray-700 mb-1.5">Contexto del negocio (system prompt)</label>
                                            <textarea
                                                id="system_prompt"
                                                rows={5}
                                                value={form.data.system_prompt}
                                                onChange={(e) => form.setData('system_prompt', e.target.value)}
                                                placeholder="Somos una tienda de… Nuestro horario es… El tono debe ser…"
                                                className={inputClass}
                                            />
                                        </div>

                                        <div className="space-y-4 pt-2 border-t border-gray-100">
                                            <label className="flex items-center gap-3 cursor-pointer group">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.is_active}
                                                    onChange={(e) => form.setData('is_active', e.target.checked)}
                                                    className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                <div>
                                                    <p className="text-sm font-semibold text-gray-700 group-hover:text-gray-900 transition-colors">IA activa</p>
                                                    <p className="text-xs text-gray-400">Habilita el botón "Borrador IA" en el inbox</p>
                                                </div>
                                            </label>

                                            <label className="flex items-center gap-3 cursor-pointer group">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.auto_reply_enabled}
                                                    onChange={(e) => form.setData('auto_reply_enabled', e.target.checked)}
                                                    className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                <div>
                                                    <p className="text-sm font-semibold text-gray-700 group-hover:text-gray-900 transition-colors">Auto-respuesta</p>
                                                    <p className="text-xs text-gray-400">El bot contesta solo los mensajes entrantes</p>
                                                </div>
                                            </label>

                                            {form.data.auto_reply_enabled && (
                                                <div className="ml-7 rounded-xl bg-gray-50 border border-gray-200 p-3">
                                                    <label className="flex items-center gap-2 text-sm text-gray-700">
                                                        <span className="font-medium">Máximo</span>
                                                        <input
                                                            type="number"
                                                            min="1"
                                                            max="20"
                                                            value={form.data.auto_reply_max_per_conversation}
                                                            onChange={(e) => form.setData('auto_reply_max_per_conversation', Number(e.target.value))}
                                                            className="w-16 px-2 py-1 border border-gray-300 rounded-lg text-sm text-center bg-white focus:ring-emerald-500 focus:border-emerald-500"
                                                        />
                                                        <span className="font-medium">respuestas por conversación</span>
                                                    </label>

                                                    <label className="flex items-center gap-2 text-sm text-gray-700 mt-3">
                                                        <span className="font-medium">Al llegar al tope, pausar</span>
                                                        <input
                                                            type="number"
                                                            min="1"
                                                            max="24"
                                                            value={form.data.auto_reply_cooldown_hours}
                                                            onChange={(e) => form.setData('auto_reply_cooldown_hours', Number(e.target.value))}
                                                            className="w-16 px-2 py-1 border border-gray-300 rounded-lg text-sm text-center bg-white focus:ring-emerald-500 focus:border-emerald-500"
                                                        />
                                                        <span className="font-medium">horas</span>
                                                    </label>

                                                    <p className="mt-2 text-xs text-gray-500 leading-relaxed">
                                                        Pasado ese tiempo el contador se reinicia solo y la IA vuelve a responder.
                                                        <strong> 3 h es lo recomendado</strong>: corta el ida y vuelta con un bot que
                                                        ya no ayuda, pero quien vuelve el mismo día encuentra respuesta — y entra
                                                        holgado en la ventana de 24 h, así que reactivarse no tiene costo.
                                                    </p>
                                                    <p className="mt-2 text-xs text-gray-500">Si un agente responde, el bot se apaga en esa conversación</p>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="px-5 sm:px-6 py-4 bg-gray-50/80 border-t border-gray-100 flex justify-end">
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                                        >
                                            {form.processing ? 'Guardando…' : 'Guardar configuración'}
                                        </button>
                                    </div>
                                </div>
                            </TabPanel>

                            {/* Hours */}
                            <TabPanel>
                                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 flex items-center justify-center text-white shadow-md">
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 className="text-base font-bold text-gray-900">Horario de atención</h3>
                                            <p className="text-xs text-gray-400 mt-0.5">Fuera de horario la IA envía un mensaje predefinido en vez de responder</p>
                                        </div>
                                    </div>

                                    <div className="p-5 sm:p-6 space-y-5">
                                        <label className="flex items-center gap-3 cursor-pointer group">
                                            <input
                                                type="checkbox"
                                                checked={hoursEnabled}
                                                onChange={toggleHours}
                                                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                            />
                                            <div>
                                                <p className="text-sm font-semibold text-gray-700 group-hover:text-gray-900 transition-colors">Activar horario</p>
                                                <p className="text-xs text-gray-400">Mostrar los días y horarios en que la IA debe responder</p>
                                            </div>
                                        </label>

                                        {hoursEnabled && (
                                            <div className="rounded-xl bg-gray-50 border border-gray-200 p-4 sm:p-5 space-y-4">
                                                <div className="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
                                                    {Object.entries(DAY_LABELS).map(([day, label]) => {
                                                        const range = hours[day]?.[0] ?? ['', ''];
                                                        const closed = range[0] === '' || range[1] === '';
                                                        return (
                                                            <div key={day} className={`rounded-lg p-2.5 border ${closed ? 'bg-gray-100/80 border-gray-200' : 'bg-white border-emerald-200'}`}>
                                                                <p className="text-[10px] font-bold uppercase text-gray-500 text-center mb-1.5">{label}</p>
                                                                <input type="time" value={range[0]} onChange={(e) => setDayRange(day, e.target.value, range[1])} className="w-full text-xs border-0 bg-transparent p-0 focus:ring-0 focus:text-emerald-700" />
                                                                <input type="time" value={range[1]} onChange={(e) => setDayRange(day, range[0], e.target.value)} className="w-full text-xs border-0 bg-transparent p-0 focus:ring-0 focus:text-emerald-700" />
                                                                {closed && <p className="text-[9px] text-gray-400 text-center mt-1">Cerrado</p>}
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Mensaje fuera de horario</label>
                                                    <textarea
                                                        rows={3}
                                                        value={form.data.after_hours_message}
                                                        onChange={(e) => form.setData('after_hours_message', e.target.value)}
                                                        placeholder="¡Hola! Nuestro horario de atención es lunes a viernes de 8:00 a 19:00. Te responderemos apenas volvamos. 🙏"
                                                        className={inputClass}
                                                    />
                                                </div>
                                                <p className="text-[10px] text-gray-400">Zona horaria: {form.data.timezone}. Solo se envía una vez por día por conversación.</p>
                                            </div>
                                        )}
                                    </div>

                                    <div className="px-5 sm:px-6 py-4 bg-gray-50/80 border-t border-gray-100 flex justify-end">
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                                        >
                                            {form.processing ? 'Guardando…' : 'Guardar configuración'}
                                        </button>
                                    </div>
                                </div>
                            </TabPanel>

                            {/* Knowledge Base */}
                            <TabPanel>
                                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="p-5 border-b border-gray-100 flex items-center justify-between gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                                        <div className="flex items-center gap-3">
                                            <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-md">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 className="text-base font-bold text-gray-900">Base de conocimiento</h3>
                                                <p className="text-xs text-gray-400 mt-0.5">FAQs, políticas, catálogo — la IA busca aquí antes de responder</p>
                                            </div>
                                        </div>
                                        {documents.length > 0 && (
                                            <button
                                                onClick={() => router.post(route('settings.ai.reindex'), {}, { preserveScroll: true })}
                                                className="px-3.5 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm inline-flex items-center gap-1.5 shrink-0"
                                            >
                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                </svg>
                                                Reindexar
                                            </button>
                                        )}
                                    </div>

                                    <ul className="divide-y divide-gray-50">
                                        {documents.map((doc) => (
                                            <li key={doc.id} className="flex items-center justify-between px-5 sm:px-6 py-3 hover:bg-gray-50 transition-colors group">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <div className="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600 shrink-0">
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                        </svg>
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-gray-900 text-sm truncate">{doc.title}</p>
                                                        <p className="text-xs text-gray-400">{doc.chunks_count} fragmentos indexados</p>
                                                    </div>
                                                </div>
                                                <button
                                                    onClick={() => {
                                                        if (confirm('¿Eliminar este documento?')) {
                                                            router.delete(route('settings.ai.documents.destroy', doc.id), { preserveScroll: true });
                                                        }
                                                    }}
                                                    className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors opacity-0 group-hover:opacity-100 shrink-0"
                                                    title="Eliminar"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </button>
                                            </li>
                                        ))}
                                        {documents.length === 0 && (
                                            <li className="px-5 sm:px-6 py-12 text-center">
                                                <div className="w-12 h-12 mx-auto rounded-xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-3">
                                                    <svg className="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                                                    </svg>
                                                </div>
                                                <p className="text-sm font-semibold text-gray-900">Sin documentos</p>
                                                <p className="text-xs text-gray-500 mt-1">Agregá FAQs, políticas o catálogo para que la IA pueda consultarlos</p>
                                            </li>
                                        )}
                                    </ul>

                                    <form onSubmit={addDocument} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 space-y-3">
                                        <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Añadir documento</p>
                                        <input
                                            id="doc-title"
                                            value={docForm.data.title}
                                            onChange={(e) => docForm.setData('title', e.target.value)}
                                            required
                                            placeholder="Título — ej. Políticas de envío"
                                            className={inputClass}
                                        />
                                        <textarea
                                            id="doc-content"
                                            rows={5}
                                            value={docForm.data.content}
                                            onChange={(e) => docForm.setData('content', e.target.value)}
                                            required
                                            placeholder="Contenido del documento…"
                                            className={inputClass}
                                        />
                                        <button
                                            type="button"
                                            disabled={docForm.processing}
                                            onClick={addDocument}
                                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl hover:from-blue-500 hover:to-indigo-500 disabled:opacity-50 transition-all shadow-lg shadow-blue-500/20"
                                        >
                                            {docForm.processing ? 'Añadiendo…' : 'Añadir documento'}
                                        </button>
                                    </form>
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </TabGroup>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

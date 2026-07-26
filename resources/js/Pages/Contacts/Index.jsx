import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function TagBadge({ tag, onRemove }) {
    return (
        <span
            className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white shadow-sm"
            style={{ backgroundColor: tag.color }}
        >
            {tag.name}
            {onRemove && (
                <button type="button" onClick={onRemove} className="ml-0.5 hover:opacity-70 text-white/80">
                    ×
                </button>
            )}
        </span>
    );
}

function ContactFormModal({ open, onClose, contact, tags, customFields }) {
    const isEdit = !!contact;

    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm({
        phone: contact?.phone ?? '',
        name: contact?.name ?? '',
        email: contact?.email ?? '',
        company: contact?.company ?? '',
        tag_ids: contact?.tags?.map((t) => t.id) ?? [],
        custom_values: Object.fromEntries(
            (contact?.custom_values ?? []).map((v) => [v.custom_field_id, v.value]),
        ),
    });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: close };
        isEdit ? patch(route('contacts.update', contact.id), opts) : post(route('contacts.store'), opts);
    };

    const toggleTag = (id) =>
        setData(
            'tag_ids',
            data.tag_ids.includes(id) ? data.tag_ids.filter((t) => t !== id) : [...data.tag_ids, id],
        );

    return (
        <Modal show={open} onClose={close} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="text-base font-bold text-gray-900">
                                    {isEdit ? 'Editar contacto' : 'Nuevo contacto'}
                                </h2>
                                <p className="text-xs text-gray-400 mt-0.5">
                                    {isEdit ? 'Actualiza los datos del contacto' : 'Ingresa los datos del nuevo contacto'}
                                </p>
                            </div>
                        </div>
                        <button type="button" onClick={close} className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div className="px-6 py-5 space-y-4">
                    {[
                        ['phone', 'Teléfono', true, 'tel'],
                        ['name', 'Nombre', false, 'text'],
                        ['email', 'Email', false, 'email'],
                        ['company', 'Empresa', false, 'text'],
                    ].map(([field, label, required, type]) => (
                        <div key={field}>
                            <label htmlFor={field} className="block text-sm font-semibold text-gray-700 mb-1.5">
                                {label} {required && <span className="text-red-500">*</span>}
                            </label>
                            <input
                                id={field}
                                type={type}
                                value={data[field]}
                                onChange={(e) => setData(field, e.target.value)}
                                className={`w-full px-3.5 py-2.5 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
                                    errors[field] ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white hover:border-gray-300 focus:bg-white'
                                }`}
                                required={required}
                            />
                            {errors[field] && (
                                <p className="mt-1 text-xs text-red-500 font-medium">{errors[field]}</p>
                            )}
                        </div>
                    ))}

                    {tags.length > 0 && (
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-2">Etiquetas</label>
                            <div className="flex flex-wrap gap-2">
                                {tags.map((tag) => (
                                    <button
                                        key={tag.id}
                                        type="button"
                                        onClick={() => toggleTag(tag.id)}
                                        className={`rounded-full px-3 py-1.5 text-xs font-medium transition-all ${
                                            data.tag_ids.includes(tag.id)
                                                ? 'text-white shadow-md scale-105 ring-2 ring-offset-1'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 ring-1 ring-gray-200'
                                        }`}
                                        style={
                                            data.tag_ids.includes(tag.id)
                                                ? { backgroundColor: tag.color, ringColor: tag.color }
                                                : {}
                                        }
                                    >
                                        {tag.name}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {customFields.map((field) => (
                        <div key={field.id}>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                {field.field_name}
                            </label>
                            {field.field_type === 'select' ? (
                                <select
                                    className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white hover:border-gray-300 transition-all"
                                    value={data.custom_values[field.id] ?? ''}
                                    onChange={(e) =>
                                        setData('custom_values', {
                                            ...data.custom_values,
                                            [field.id]: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">—</option>
                                    {(field.field_options ?? []).map((opt) => (
                                        <option key={opt} value={opt}>
                                            {opt}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <input
                                    type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'}
                                    className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white hover:border-gray-300 transition-all"
                                    value={data.custom_values[field.id] ?? ''}
                                    onChange={(e) =>
                                        setData('custom_values', {
                                            ...data.custom_values,
                                            [field.id]: e.target.value,
                                        })
                                    }
                                />
                            )}
                        </div>
                    ))}
                </div>

                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onClick={close} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm">
                        Cancelar
                    </button>
                    <button type="submit" disabled={processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-emerald-500/20">
                        {processing ? (
                            <span className="flex items-center gap-2">
                                <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Guardando...
                            </span>
                        ) : isEdit ? 'Guardar cambios' : 'Crear contacto'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function TagManagerModal({ open, onClose, tags }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        color: '#045474',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('tags.store'), { preserveScroll: true, onSuccess: () => reset('name') });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/20">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="text-base font-bold text-gray-900">Etiquetas</h2>
                                <p className="text-xs text-gray-400 mt-0.5">Administra las etiquetas de tus contactos</p>
                            </div>
                        </div>
                        <button type="button" onClick={onClose} className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div className="px-6 py-5">
                    <div className="flex flex-wrap gap-2 mb-5 min-h-[3rem]">
                        {tags.map((tag) => (
                            <TagBadge
                                key={tag.id}
                                tag={tag}
                                onRemove={() =>
                                    router.delete(route('tags.destroy', tag.id), { preserveScroll: true })
                                }
                            />
                        ))}
                        {tags.length === 0 && (
                            <p className="text-sm text-gray-400 italic">Sin etiquetas todavía.</p>
                        )}
                    </div>

                    <form onSubmit={submit} className="flex items-end gap-2 pt-5 border-t border-gray-100">
                        <div className="flex-1">
                            <label htmlFor="tag-name" className="block text-sm font-semibold text-gray-700 mb-1.5">
                                Nueva etiqueta
                            </label>
                            <input
                                id="tag-name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white hover:border-gray-300 transition-all"
                                placeholder="Nombre de la etiqueta"
                                required
                            />
                            {errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{errors.name}</p>}
                        </div>
                        <div className="relative">
                            <input
                                type="color"
                                value={data.color}
                                onChange={(e) => setData('color', e.target.value)}
                                className="h-11 w-12 cursor-pointer rounded-xl border border-gray-200 bg-gray-50"
                            />
                        </div>
                        <button type="submit" disabled={processing || !data.name.trim()} className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-emerald-500/20">
                            {processing ? '...' : 'Añadir'}
                        </button>
                    </form>
                </div>

                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end">
                    <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm">
                        Cerrar
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function ImportModal({ open, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({ file: null });
    const [dragOver, setDragOver] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post(route('contacts.import'), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-purple-500/20">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="text-base font-bold text-gray-900">Importar CSV</h2>
                                <p className="text-xs text-gray-400 mt-0.5">Sube un archivo CSV con tus contactos</p>
                            </div>
                        </div>
                        <button type="button" onClick={onClose} className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div className="px-6 py-5 space-y-4">
                    <div className="bg-gray-50 rounded-xl p-4 text-sm text-gray-600 leading-relaxed">
                        <p className="font-medium text-gray-700 mb-1">Formato esperado:</p>
                        Archivo CSV con cabecera. Columnas soportadas:{' '}
                        <code className="text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded text-xs font-mono font-semibold">phone</code> (obligatoria),{' '}
                        <code className="text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded text-xs font-mono font-semibold">name</code>,{' '}
                        <code className="text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded text-xs font-mono font-semibold">email</code>,{' '}
                        <code className="text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded text-xs font-mono font-semibold">company</code>.
                        <br />Los teléfonos duplicados se saltan.
                    </div>

                    <div
                        className={`border-2 border-dashed rounded-xl p-8 text-center transition-all cursor-pointer ${
                            dragOver
                                ? 'border-emerald-400 bg-emerald-50'
                                : data.file
                                    ? 'border-emerald-300 bg-emerald-50/50'
                                    : 'border-gray-200 hover:border-emerald-300 hover:bg-gray-50'
                        }`}
                        onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                        onDragLeave={() => setDragOver(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDragOver(false);
                            if (e.dataTransfer.files[0]) setData('file', e.dataTransfer.files[0]);
                        }}
                    >
                        {data.file ? (
                            <div className="flex flex-col items-center gap-2">
                                <div className="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <svg className="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-emerald-700">{data.file.name}</p>
                                <p className="text-xs text-gray-400">{(data.file.size / 1024).toFixed(1)} KB</p>
                                <button type="button" onClick={() => setData('file', null)} className="text-xs text-red-500 hover:text-red-700 font-medium">
                                    Quitar archivo
                                </button>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-2">
                                <div className="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center">
                                    <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-gray-600">
                                    Arrastra tu archivo CSV aquí o{' '}
                                    <span className="text-emerald-600 hover:text-emerald-700 cursor-pointer">selecciónalo</span>
                                </p>
                                <input
                                    type="file"
                                    accept=".csv,.txt"
                                    onChange={(e) => setData('file', e.target.files[0])}
                                    className="hidden"
                                    id="csv-upload"
                                    required
                                />
                                <p className="text-xs text-gray-400">CSV con cabecera, separado por comas</p>
                            </div>
                        )}
                    </div>
                    {errors.file && <p className="text-xs text-red-500 font-medium">{errors.file}</p>}
                </div>

                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm">
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing || !data.file}
                        className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-violet-600 rounded-xl hover:from-purple-500 hover:to-violet-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-purple-500/20"
                    >
                        {processing ? (
                            <span className="flex items-center gap-2">
                                <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Importando...
                            </span>
                        ) : 'Importar'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export default function Index({ contacts, tags, customFields, filters }) {
    const { flash } = usePage().props;
    const [modal, setModal] = useState(null);
    const [search, setSearch] = useState(filters.q ?? '');

    const applyFilters = (extra = {}) =>
        router.get(
            route('contacts.index'),
            { q: search || undefined, tag: filters.tag || undefined, ...extra },
            { preserveState: true, replace: true },
        );

    const totalContacts = contacts.total || 0;
    const taggedCount = contacts.data.filter((c) => c.tags?.length).length;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-lg font-semibold text-gray-900">Contactos</h2>
            }
        >
            <Head title="Contactos" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
                {/* Header */}
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Contactos</h1>
                    <p className="text-sm text-gray-400 mt-1">Gestiona los contactos de tu CRM</p>
                </div>

                {/* Stat Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-5">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20 mb-3">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Total contactos</p>
                        <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{totalContacts}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-blue-500/20 mb-3">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
                            </svg>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Con etiquetas</p>
                        <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{taggedCount}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/20 mb-3">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
                            </svg>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Etiquetas</p>
                        <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{tags.length}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-purple-500/20 mb-3">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                            </svg>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Campos personalizados</p>
                        <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{customFields.length}</p>
                    </div>
                </div>

                {/* Flash */}
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

                {/* Table */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    {/* Toolbar */}
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div className="flex flex-1 items-center gap-2">
                                <div className="relative flex-1 max-w-md">
                                    <svg className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                    <input
                                        type="text"
                                        placeholder="Buscar por nombre, teléfono, email…"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                        className="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white hover:border-gray-300 transition-all"
                                    />
                                </div>
                                <button onClick={() => applyFilters()} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm">
                                    Buscar
                                </button>
                            </div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <select
                                    value={filters.tag ?? ''}
                                    onChange={(e) => applyFilters({ tag: e.target.value || undefined })}
                                    className="px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all"
                                >
                                    <option value="">Todas las etiquetas</option>
                                    {tags.map((tag) => (
                                        <option key={tag.id} value={tag.id}>{tag.name}</option>
                                    ))}
                                </select>
                                <button
                                    onClick={() => setModal('tags')}
                                    className="px-3.5 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm flex items-center gap-1.5"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
                                    </svg>
                                    Etiquetas
                                </button>
                                <button
                                    onClick={() => setModal('import')}
                                    className="px-3.5 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm flex items-center gap-1.5"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                    </svg>
                                    Importar CSV
                                </button>
                                <button
                                    onClick={() => setModal('create')}
                                    className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20 flex items-center gap-1.5"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Nuevo
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Nombre</th>
                                    <th className="text-left px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Teléfono</th>
                                    <th className="text-left px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Email</th>
                                    <th className="text-left px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Empresa</th>
                                    <th className="text-left px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Etiquetas</th>
                                    <th className="text-right px-5 sm:px-6 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider w-24">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {contacts.data.map((contact) => (
                                    <tr key={contact.id} className="hover:bg-gray-50 transition-colors group">
                                        <td className="px-5 sm:px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-bold shadow-sm shadow-[#045474]/20">
                                                    {(contact.name || contact.phone || '?').charAt(0).toUpperCase()}
                                                </div>
                                                <span className="font-semibold text-gray-900">{contact.name || <span className="italic text-gray-400">Sin nombre</span>}</span>
                                                <ServiceWindowBadge window={contact.service_window} />
                                            </div>
                                        </td>
                                        <td className="px-5 sm:px-6 py-4 text-gray-600 font-medium tabular-nums">{contact.phone}</td>
                                        <td className="px-5 sm:px-6 py-4 text-gray-500 hidden sm:table-cell">{contact.email || <span className="italic text-gray-300">—</span>}</td>
                                        <td className="px-5 sm:px-6 py-4 text-gray-500 hidden md:table-cell">{contact.company || <span className="italic text-gray-300">—</span>}</td>
                                        <td className="px-5 sm:px-6 py-4 hidden lg:table-cell">
                                            <div className="flex flex-wrap gap-1">
                                                {contact.tags.map((tag) => (
                                                    <TagBadge key={tag.id} tag={tag} />
                                                ))}
                                                {contact.tags.length === 0 && (
                                                    <span className="text-xs text-gray-300 italic">—</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-5 sm:px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <button
                                                    onClick={() => setModal(contact)}
                                                    className="p-2 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                                    title="Editar"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                    </svg>
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        if (confirm('¿Eliminar este contacto?')) {
                                                            router.delete(route('contacts.destroy', contact.id), { preserveScroll: true });
                                                        }
                                                    }}
                                                    className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                    title="Eliminar"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {contacts.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-16 text-center">
                                            <div className="flex flex-col items-center gap-2">
                                                <svg className="w-10 h-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                                </svg>
                                                <p className="text-sm font-medium text-gray-300">Sin contactos. Crea uno o importa un CSV.</p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {(contacts.prev_page_url || contacts.next_page_url) && (
                        <div className="px-5 sm:px-6 py-4 border-t border-gray-100 flex items-center justify-between">
                            <p className="text-xs text-gray-400 font-medium">
                                {contacts.total} contactos · Página {contacts.current_page} de {contacts.last_page}
                            </p>
                            <div className="flex items-center gap-1">
                                {contacts.prev_page_url && (
                                    <Link
                                        href={contacts.prev_page_url}
                                        preserveState
                                        className="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 hover:border-gray-300 transition-all"
                                    >
                                        ← Anterior
                                    </Link>
                                )}
                                {Array.from({ length: contacts.last_page }, (_, i) => i + 1)
                                    .filter((p) => Math.abs(p - contacts.current_page) <= 2 || p === 1 || p === contacts.last_page)
                                    .map((p, idx, arr) => (
                                        <span key={p} className="flex items-center">
                                            {idx > 0 && arr[idx - 1] !== p - 1 && (
                                                <span className="px-1 text-gray-300 font-medium">…</span>
                                            )}
                                            <Link
                                                href={`${route('contacts.index')}?page=${p}`}
                                                preserveState
                                                className={`px-2.5 py-1.5 text-xs font-bold rounded-lg transition-all ${
                                                    p === contacts.current_page
                                                        ? 'bg-gradient-to-br from-emerald-600 to-teal-600 text-white shadow-md'
                                                        : 'text-gray-600 hover:bg-gray-100'
                                                }`}
                                            >
                                                {p}
                                            </Link>
                                        </span>
                                    ))}
                                {contacts.next_page_url && (
                                    <Link
                                        href={contacts.next_page_url}
                                        preserveState
                                        className="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 hover:border-gray-300 transition-all"
                                    >
                                        Siguiente →
                                    </Link>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <ContactFormModal
                key={modal && typeof modal === 'object' ? modal.id : String(modal)}
                open={modal === 'create' || (modal && typeof modal === 'object')}
                onClose={() => setModal(null)}
                contact={typeof modal === 'object' ? modal : null}
                tags={tags}
                customFields={customFields}
            />
            <TagManagerModal open={modal === 'tags'} onClose={() => setModal(null)} tags={tags} />
            <ImportModal open={modal === 'import'} onClose={() => setModal(null)} />
        </AuthenticatedLayout>
    );
}

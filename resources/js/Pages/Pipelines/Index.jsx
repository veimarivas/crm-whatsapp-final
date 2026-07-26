import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function money(value, currency) {
    return new Intl.NumberFormat('es', {
        style: 'currency',
        currency: currency || 'USD',
        maximumFractionDigits: 0,
    }).format(value || 0);
}

const AVATAR_COLORS = ['from-emerald-500 to-teal-600', 'from-blue-500 to-indigo-600', 'from-purple-500 to-pink-600', 'from-amber-500 to-orange-600', 'from-rose-500 to-red-600', 'from-cyan-500 to-sky-600'];
function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    return { initials, gradient: AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length] };
}

const STATUS_META = {
    won: { icon: '🏆', label: 'Ganado', style: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: 'bg-emerald-500' },
    lost: { icon: '✕', label: 'Perdido', style: 'bg-red-50 text-red-700 border-red-200', dot: 'bg-red-500' },
};

function DealCard({ deal, currency, selected, onToggleSelect, anySelected }) {
    const contactName = deal.contact?.name || deal.contact?.phone || 'Sin contacto';
    const av = avatarFor(contactName);

    return (
        <div
            draggable={!anySelected}
            onDragStart={(e) => { e.dataTransfer.setData('text/deal-id', deal.id); e.dataTransfer.effectAllowed = 'move'; }}
            className={`group relative rounded-xl border bg-white p-3 shadow-sm hover:shadow-md transition-all ${anySelected ? '' : 'cursor-grab active:cursor-grabbing'} ${
                selected
                    ? 'border-emerald-400 ring-2 ring-emerald-200 bg-emerald-50/30'
                    : deal.status !== 'open'
                    ? 'border-gray-100 opacity-60 hover:opacity-80'
                    : 'border-gray-100 hover:border-gray-300'
            }`}
        >
            <div className={`absolute top-2 left-2 z-10 transition-opacity ${selected || anySelected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'}`}>
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={(e) => { e.stopPropagation(); onToggleSelect(deal.id); }}
                    onClick={(e) => e.stopPropagation()}
                    className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shadow"
                />
            </div>
            <div className="block" onClick={(e) => { if (anySelected) { e.preventDefault(); onToggleSelect(deal.id); } }}>
                <div className="flex items-center gap-2 mb-2 pl-5">
                    <div className={`w-7 h-7 rounded-full bg-gradient-to-br ${av.gradient} flex items-center justify-center text-white text-[10px] font-bold shrink-0 shadow-sm`}>
                        {av.initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold text-gray-800 truncate">{contactName}</p>
                    </div>
                    {STATUS_META[deal.status] && (
                        <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold border ${STATUS_META[deal.status].style}`}>
                            <span className={`w-1 h-1 rounded-full ${STATUS_META[deal.status].dot}`} />
                            {STATUS_META[deal.status].label}
                        </span>
                    )}
                </div>

                <p className="text-sm font-bold text-gray-900 group-hover:text-emerald-700 transition-colors line-clamp-2 leading-snug">
                    {deal.title}
                </p>

                <p className="text-base font-extrabold text-gray-900 tabular-nums mt-1">
                    {money(deal.value, deal.currency || currency)}
                </p>

                <div className="flex items-center justify-between mt-2 pt-2 border-t border-gray-50 gap-2">
                    <div className="flex items-center gap-1.5 min-w-0">
                        {deal.assignee ? (
                            <span className="text-[10px] text-gray-500 truncate flex items-center gap-1">
                                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                {deal.assignee.name.split(' ')[0]}
                            </span>
                        ) : (
                            <span className="text-[10px] text-sky-600 font-semibold">Sin asignar</span>
                        )}
                    </div>
                    {deal.expected_close_date && (
                        <span className="text-[10px] text-gray-400 tabular-nums shrink-0">
                            {new Date(deal.expected_close_date).toLocaleDateString('es', { day: 'numeric', month: 'short' })}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

function DealRow({ deal, currency, selected, onToggleSelect, anySelected }) {
    const contactName = deal.contact?.name || deal.contact?.phone || 'Sin contacto';
    const av = avatarFor(contactName);

    return (
        <div className={`flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors border-l-4 ${
            selected ? 'border-emerald-500 bg-emerald-50/40' : deal.status !== 'open' ? 'border-gray-200 bg-gray-50/40' : 'border-transparent'
        }`}>
            <input
                type="checkbox"
                checked={selected}
                onChange={() => onToggleSelect(deal.id)}
                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shrink-0"
            />
            <div onClick={(e) => { if (anySelected) { e.preventDefault(); onToggleSelect(deal.id); } }} className="flex items-center gap-3 flex-1 min-w-0">
                <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${av.gradient} flex items-center justify-center text-white text-xs font-bold shrink-0 shadow-sm`}>
                    {av.initials}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 mb-0.5">
                        <p className="font-semibold text-sm text-gray-900 truncate">{contactName}</p>
                        {deal.status !== 'open' && (
                            <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold border ${STATUS_META[deal.status]?.style}`}>
                                {STATUS_META[deal.status]?.icon} {STATUS_META[deal.status]?.label}
                            </span>
                        )}
                    </div>
                    <p className="text-xs text-gray-600 truncate">{deal.title}</p>
                </div>
                {Number(deal.value) > 0 && (
                    <span className="text-sm font-bold text-gray-900 tabular-nums shrink-0 hidden sm:inline">
                        {money(deal.value, deal.currency || currency)}
                    </span>
                )}
                <div className="hidden sm:flex items-center gap-1.5 text-[11px] text-gray-500 shrink-0 w-24 truncate">
                    {deal.assignee ? (
                        <>👤 {deal.assignee.name.split(' ')[0]}</>
                    ) : (
                        <span className="text-sky-600 font-semibold">Sin asignar</span>
                    )}
                </div>
                {deal.expected_close_date && (
                    <span className="text-[11px] text-gray-400 tabular-nums shrink-0 hidden sm:inline">
                        📅 {new Date(deal.expected_close_date).toLocaleDateString('es', { day: 'numeric', month: 'short' })}
                    </span>
                )}
            </div>
        </div>
    );
}

function BulkBar({ count, onClear, pipeline, members }) {
    const [mode, setMode] = useState(null);

    const perform = (action, payload) => {
        const ids = Array.from(count.ids);
        if (action === 'delete') {
            ids.forEach((id) => router.delete(route('deals.destroy', id), { preserveScroll: true }));
        } else if (action === 'move') {
            ids.forEach((id) => router.patch(route('deals.update', id), { stage_id: payload.stage_id }, { preserveScroll: true }));
        } else if (action === 'assign') {
            ids.forEach((id) => router.patch(route('deals.update', id), { assigned_to: payload.assigned_to }, { preserveScroll: true }));
        }
        onClear();
        setMode(null);
    };

    return (
        <div className="fixed inset-x-0 bottom-4 z-40 pointer-events-none flex justify-center">
            <div className="pointer-events-auto bg-slate-900 text-white rounded-2xl shadow-2xl border border-slate-700 px-4 py-3 flex flex-wrap items-center gap-2 max-w-3xl mx-4">
                <div className="flex items-center gap-2">
                    <span className="w-7 h-7 rounded-lg bg-emerald-500 flex items-center justify-center text-xs font-bold">{count.n}</span>
                    <span className="text-sm font-semibold">seleccionados</span>
                </div>
                <div className="h-6 w-px bg-slate-700 mx-1" />
                {mode === null ? (
                    <>
                        <button onClick={() => setMode('move')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 transition-colors">
                            ➡ Mover a etapa
                        </button>
                        <button onClick={() => setMode('assign')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 transition-colors">
                            👤 Asignar
                        </button>
                        <button
                            onClick={() => { if (confirm(`¿Eliminar ${count.n} deals?`)) perform('delete', {}); }}
                            className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-700 hover:bg-red-600 transition-colors"
                        >
                            🗑 Eliminar
                        </button>
                    </>
                ) : mode === 'move' ? (
                    <>
                        <select
                            onChange={(e) => e.target.value && perform('move', { stage_id: e.target.value })}
                            defaultValue=""
                            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        >
                            <option value="" disabled>Elegir etapa…</option>
                            {pipeline?.stages?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                        <button onClick={() => setMode(null)} className="text-xs text-slate-400 hover:text-white px-2">← Volver</button>
                    </>
                ) : mode === 'assign' ? (
                    <>
                        <select
                            onChange={(e) => e.target.value && perform('assign', { assigned_to: e.target.value })}
                            defaultValue=""
                            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        >
                            <option value="" disabled>Elegir responsable…</option>
                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                        <button onClick={() => setMode(null)} className="text-xs text-slate-400 hover:text-white px-2">← Volver</button>
                    </>
                ) : null}
                <div className="h-6 w-px bg-slate-700 mx-1" />
                <button onClick={onClear} className="text-xs text-slate-400 hover:text-white px-2">Cancelar</button>
            </div>
        </div>
    );
}

function DealFormModal({ open, onClose, deal, pipeline, contacts, members, currency }) {
    const isEdit = !!deal;

    const { data, setData, reset, clearErrors, errors, processing } = useForm({
        pipeline_id: pipeline?.id ?? '',
        stage_id: deal?.stage_id ?? pipeline?.stages?.[0]?.id ?? '',
        contact_id: deal?.contact_id ?? '',
        assigned_to: deal?.assigned_to ?? '',
        title: deal?.title ?? '',
        value: deal?.value ?? '',
        currency: deal?.currency ?? currency ?? 'USD',
        notes: deal?.notes ?? '',
        expected_close_date: deal?.expected_close_date?.slice(0, 10) ?? '',
        status: deal?.status ?? 'open',
    });

    const close = () => { reset(); clearErrors(); onClose(); };

    const submit = (e) => {
        e.preventDefault();
        const transform = (d) => ({
            ...d,
            contact_id: d.contact_id || null,
            assigned_to: d.assigned_to || null,
            value: d.value === '' ? 0 : d.value,
            expected_close_date: d.expected_close_date || null,
        });
        const opts = { preserveScroll: true, onSuccess: close };
        if (isEdit) router.patch(route('deals.update', deal.id), transform(data), opts);
        else router.post(route('deals.store'), transform(data), opts);
    };

    const remove = () => {
        if (confirm('¿Eliminar este deal?')) {
            router.delete(route('deals.destroy', deal.id), { preserveScroll: true, onSuccess: close });
        }
    };

    const inputClass = (field) => `w-full px-3.5 py-2.5 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
        errors[field] ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white focus:bg-white'
    }`;

    return (
        <Modal show={open} onClose={close} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-gray-900">{isEdit ? 'Editar deal' : 'Nuevo deal'}</h2>
                            <p className="text-xs text-gray-400 mt-0.5">Oportunidad de venta en el pipeline</p>
                        </div>
                    </div>
                    <button type="button" onClick={close} className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="px-6 py-5 space-y-4">
                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Título <span className="text-red-500">*</span></label>
                        <input value={data.title} onChange={(e) => setData('title', e.target.value)} required className={inputClass('title')} />
                        {errors.title && <p className="mt-1 text-xs text-red-500 font-medium">{errors.title}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Valor ({data.currency})</label>
                            <input type="number" step="0.01" min="0" value={data.value} onChange={(e) => setData('value', e.target.value)} className={inputClass('value')} />
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Etapa</label>
                            <select value={data.stage_id} onChange={(e) => setData('stage_id', e.target.value)} className={inputClass('stage_id')}>
                                {pipeline?.stages?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Contacto</label>
                            <select value={data.contact_id} onChange={(e) => setData('contact_id', e.target.value)} className={inputClass('contact_id')}>
                                <option value="">— Sin contacto —</option>
                                {contacts.map((c) => <option key={c.id} value={c.id}>{c.name || c.phone}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Asignado a</label>
                            <select value={data.assigned_to} onChange={(e) => setData('assigned_to', e.target.value)} className={inputClass('assigned_to')}>
                                <option value="">— Nadie —</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Fecha estimada de cierre</label>
                            <input type="date" value={data.expected_close_date} onChange={(e) => setData('expected_close_date', e.target.value)} className={inputClass('expected_close_date')} />
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Estado</label>
                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={inputClass('status')}>
                                <option value="open">Abierto</option>
                                <option value="won">Ganado</option>
                                <option value="lost">Perdido</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Notas</label>
                        <textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className={inputClass('notes')} />
                    </div>
                </div>

                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex items-center justify-between">
                    {isEdit ? (
                        <button type="button" onClick={remove} className="text-sm font-medium text-red-600 hover:text-red-700">
                            Eliminar deal
                        </button>
                    ) : <span />}
                    <div className="flex gap-2 ml-auto">
                        <button type="button" onClick={close} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                            Cancelar
                        </button>
                        <button type="submit" disabled={processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                            {isEdit ? 'Guardar' : 'Crear deal'}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

function StageManagerModal({ open, onClose, pipeline }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', color: '#10b981' });
    const { errors: pageErrors } = usePage().props;

    const submit = (e) => {
        e.preventDefault();
        post(route('stages.store', pipeline.id), { preserveScroll: true, onSuccess: () => reset('name') });
    };

    return (
        <Modal show={open} onClose={onClose}>
            <div>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-purple-500/20">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </div>
                    <div>
                        <h2 className="text-base font-bold text-gray-900">Etapas de {pipeline?.name}</h2>
                        <p className="text-xs text-gray-400 mt-0.5">Reordena, edita colores o elimina etapas</p>
                    </div>
                </div>

                <div className="px-6 py-5">
                    {pageErrors?.stage && (
                        <p className="mb-3 text-xs text-red-600 bg-red-50 px-3 py-2 rounded-lg">{pageErrors.stage}</p>
                    )}

                    <ul className="space-y-2 mb-4">
                        {pipeline?.stages?.map((stage, i) => (
                            <li key={stage.id} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-gray-50 border border-gray-100">
                                <span className="w-4 h-4 rounded-full shrink-0" style={{ backgroundColor: stage.color }} />
                                <span className="flex-1 text-sm font-medium text-gray-700">{stage.name}</span>
                                <button
                                    disabled={i === 0}
                                    onClick={() => router.patch(route('stages.move', stage.id), { direction: 'up' }, { preserveScroll: true })}
                                    className="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                                    title="Subir"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                                    </svg>
                                </button>
                                <button
                                    disabled={i === pipeline.stages.length - 1}
                                    onClick={() => router.patch(route('stages.move', stage.id), { direction: 'down' }, { preserveScroll: true })}
                                    className="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                                    title="Bajar"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                <button
                                    onClick={() => router.delete(route('stages.destroy', stage.id), { preserveScroll: true })}
                                    className="p-1 text-gray-400 hover:text-red-600"
                                    title="Eliminar"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </li>
                        ))}
                    </ul>

                    <form onSubmit={submit} className="flex items-end gap-2 pt-4 border-t border-gray-100">
                        <div className="flex-1">
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Nueva etapa</label>
                            <input value={data.name} onChange={(e) => setData('name', e.target.value)} required className="w-full px-3.5 py-2 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all" />
                            {errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{errors.name}</p>}
                        </div>
                        <input type="color" value={data.color} onChange={(e) => setData('color', e.target.value)} className="h-10 w-12 cursor-pointer rounded-xl border border-gray-200 bg-gray-50" />
                        <button type="submit" disabled={processing} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                            Añadir
                        </button>
                    </form>
                </div>

                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end">
                    <button onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                        Cerrar
                    </button>
                </div>
            </div>
        </Modal>
    );
}

export default function Index({ pipelines, pipeline, deals, members, contacts, currency }) {
    const { flash, auth } = usePage().props;
    const [modal, setModal] = useState(null);
    const [dragOver, setDragOver] = useState(null);
    const [view, setView] = useState(() => localStorage.getItem('pipelines.view') || 'kanban');
    const [query, setQuery] = useState('');
    const [filterResponsible, setFilterResponsible] = useState('');
    const [filterStatus, setFilterStatus] = useState('');
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const newPipelineForm = useForm({ name: '' });

    const toggleSelect = (id) => setSelectedIds((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
    });
    const clearSelection = () => setSelectedIds(new Set());
    const anySelected = selectedIds.size > 0;

    useEffect(() => { localStorage.setItem('pipelines.view', view); }, [view]);

    const [debouncedQuery, setDebouncedQuery] = useState('');
    useEffect(() => {
        const t = setTimeout(() => setDebouncedQuery(query), 300);
        return () => clearTimeout(t);
    }, [query]);

    const filteredDeals = deals.filter((d) => {
        if (debouncedQuery) {
            const q = debouncedQuery.toLowerCase();
            const contactName = d.contact?.name?.toLowerCase() || '';
            const contactPhone = d.contact?.phone || '';
            const title = d.title?.toLowerCase() || '';
            if (!contactName.includes(q) && !contactPhone.includes(q) && !title.includes(q)) return false;
        }
        if (filterResponsible === 'none') { if (d.assigned_to) return false; }
        else if (filterResponsible && d.assigned_to !== filterResponsible) return false;
        if (filterStatus && d.status !== filterStatus) return false;
        return true;
    });

    const openDeals = deals.filter((d) => d.status === 'open');
    const totalOpen = openDeals.reduce((sum, d) => sum + Number(d.value || 0), 0);
    const wonDeals = deals.filter((d) => d.status === 'won');
    const totalWon = wonDeals.reduce((sum, d) => sum + Number(d.value || 0), 0);

    const dropOnStage = (e, stageId) => {
        e.preventDefault();
        setDragOver(null);
        const dealId = e.dataTransfer.getData('text/deal-id');
        const deal = deals.find((d) => d.id === dealId);
        if (deal && deal.stage_id !== stageId) {
            router.patch(route('deals.update', dealId), { stage_id: stageId }, { preserveScroll: true });
        }
    };

    const createPipeline = (e) => {
        e.preventDefault();
        newPipelineForm.post(route('pipelines.store'), {
            onSuccess: () => { newPipelineForm.reset(); setModal(null); },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Pipelines" />

            <div className="mx-auto max-w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                {/* Header */}
                <div className="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20 shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                            </svg>
                        </div>
                        <div>
                            <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Pipelines</h1>
                            <p className="text-sm text-gray-500 mt-1 flex flex-wrap gap-x-3 gap-y-1 items-center">
                                <span><strong className="text-gray-800">{openDeals.length}</strong> abiertos</span>
                                <span className="text-gray-300">·</span>
                                <span><strong className="text-gray-800">{money(totalOpen, currency)}</strong> en juego</span>
                                {wonDeals.length > 0 && (
                                    <>
                                        <span className="text-gray-300">·</span>
                                        <span><strong className="text-gray-800">{wonDeals.length}</strong> ganados</span>
                                        <span className="text-gray-300">·</span>
                                        <span><strong className="text-emerald-600">{money(totalWon, currency)}</strong> cerrados</span>
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <div className="inline-flex bg-white border border-gray-200 rounded-xl shadow-sm p-0.5">
                            <button
                                onClick={() => setView('kanban')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all inline-flex items-center gap-1 ${view === 'kanban' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                                Kanban
                            </button>
                            <button
                                onClick={() => setView('list')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all inline-flex items-center gap-1 ${view === 'list' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 17.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                Lista
                            </button>
                        </div>
                        <select
                            value={pipeline?.id ?? ''}
                            onChange={(e) => router.get(route('pipelines.index'), { pipeline: e.target.value })}
                            className="px-3 py-2 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 shadow-sm"
                        >
                            {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                        <button onClick={() => setModal('new-pipeline')} className="px-3 py-2 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Pipeline
                        </button>
                        {pipeline && (
                            <>
                                <button onClick={() => setModal('stages')} className="px-3 py-2 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm inline-flex items-center gap-1.5">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                    </svg>
                                    Etapas
                                </button>
                                <button onClick={() => setModal('new-deal')} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 shadow-lg shadow-emerald-500/20 inline-flex items-center gap-1.5">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    Nuevo
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Barra de filtros */}
                {pipeline && (
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap gap-2 items-center">
                        <div className="relative flex-1 min-w-[200px] max-w-xs">
                            <svg className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Buscar por nombre, título, teléfono…"
                                className="w-full pl-9 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                            />
                        </div>
                        <select value={filterResponsible} onChange={(e) => setFilterResponsible(e.target.value)} className="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500/30">
                            <option value="">Todos los responsables</option>
                            <option value="none">Sin asignar</option>
                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                        <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500/30">
                            <option value="">Todos los estados</option>
                            <option value="open">Abiertos</option>
                            <option value="won">Ganados</option>
                            <option value="lost">Perdidos</option>
                        </select>
                        {(debouncedQuery || filterResponsible || filterStatus) && (
                            <button
                                onClick={() => { setQuery(''); setDebouncedQuery(''); setFilterResponsible(''); setFilterStatus(''); }}
                                className="text-xs font-semibold text-gray-500 hover:text-gray-700 px-2 underline"
                            >
                                Limpiar
                            </button>
                        )}
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

                {!pipeline ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
                        <div className="mx-auto w-14 h-14 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-4">
                            <svg className="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22" />
                            </svg>
                        </div>
                        <p className="text-sm font-semibold text-gray-900">No tienes pipelines todavía</p>
                        <p className="text-xs text-gray-500 mt-1">Crea el primero — se siembra con 5 etapas típicas de venta</p>
                        <button onClick={() => setModal('new-pipeline')} className="mt-4 px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20">
                            Crear pipeline
                        </button>
                    </div>
                ) : view === 'kanban' ? (
                    <div className="flex gap-4 overflow-x-auto pb-4 -mx-4 px-4 sm:mx-0 sm:px-0">
                        {pipeline.stages.map((stage) => {
                            const stageDeals = filteredDeals.filter((d) => d.stage_id === stage.id);
                            const stageTotal = stageDeals.filter((d) => d.status === 'open').reduce((sum, d) => sum + Number(d.value || 0), 0);

                            return (
                                <div
                                    key={stage.id}
                                    onDragOver={(e) => { e.preventDefault(); setDragOver(stage.id); }}
                                    onDragLeave={() => setDragOver(null)}
                                    onDrop={(e) => dropOnStage(e, stage.id)}
                                    className={`flex w-72 shrink-0 flex-col rounded-2xl border-2 transition-all bg-gray-50 ${dragOver === stage.id ? 'border-emerald-400 bg-emerald-50/50 scale-[1.02]' : 'border-transparent'}`}
                                >
                                    <div className="rounded-t-2xl px-4 py-3 text-white" style={{ background: `linear-gradient(135deg, ${stage.color} 0%, ${stage.color}dd 100%)` }}>
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-sm">{stage.name}</span>
                                            <span className="text-[10px] font-bold bg-white/25 rounded-full px-2 py-0.5 backdrop-blur-sm">{stageDeals.length}</span>
                                        </div>
                                        <p className="text-xs font-medium text-white/80 mt-1 tabular-nums">{money(stageTotal, currency)}</p>
                                    </div>
                                    <div className="flex flex-1 flex-col gap-2 p-2.5 min-h-[180px]">
                                        {stageDeals.map((deal) => <DealCard key={deal.id} deal={deal} currency={currency} selected={selectedIds.has(deal.id)} onToggleSelect={toggleSelect} anySelected={anySelected} />)}
                                        {stageDeals.length === 0 && (
                                            <p className="py-8 text-center text-xs text-gray-400 font-medium">Arrastra deals aquí</p>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        {filteredDeals.length === 0 ? (
                            <div className="p-14 text-center text-sm text-gray-400">Sin deals con estos filtros</div>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {filteredDeals.map((d) => <li key={d.id}><DealRow deal={d} currency={currency} selected={selectedIds.has(d.id)} onToggleSelect={toggleSelect} anySelected={anySelected} /></li>)}
                            </ul>
                        )}
                    </div>
                )}

                {pipeline && (
                    <div className="flex justify-end">
                        <button
                            onClick={() => {
                                if (confirm('¿Eliminar este pipeline con todos sus deals?')) {
                                    router.delete(route('pipelines.destroy', pipeline.id));
                                }
                            }}
                            className="text-xs text-red-500 hover:text-red-700 font-medium"
                        >
                            Eliminar pipeline
                        </button>
                    </div>
                )}
            </div>

            <Modal show={modal === 'new-pipeline'} onClose={() => setModal(null)}>
                <form onSubmit={createPipeline}>
                    <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-gray-900">Nuevo pipeline</h2>
                            <p className="text-xs text-gray-400 mt-0.5">Se creará con 5 etapas típicas de venta</p>
                        </div>
                    </div>
                    <div className="px-6 py-5">
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Nombre</label>
                        <input
                            value={newPipelineForm.data.name}
                            onChange={(e) => newPipelineForm.setData('name', e.target.value)}
                            required
                            placeholder="ej. Ventas B2B"
                            className={`w-full px-3.5 py-2.5 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all ${
                                newPipelineForm.errors.name ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50 hover:bg-white focus:bg-white'
                            }`}
                        />
                        {newPipelineForm.errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{newPipelineForm.errors.name}</p>}
                    </div>
                    <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                        <button type="button" onClick={() => setModal(null)} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                            Cancelar
                        </button>
                        <button type="submit" disabled={newPipelineForm.processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                            Crear pipeline
                        </button>
                    </div>
                </form>
            </Modal>

            <StageManagerModal open={modal === 'stages'} onClose={() => setModal(null)} pipeline={pipeline} />

            <DealFormModal
                key={modal && typeof modal === 'object' ? modal.id : String(modal)}
                open={modal === 'new-deal' || (modal && typeof modal === 'object')}
                onClose={() => setModal(null)}
                deal={typeof modal === 'object' ? modal : null}
                pipeline={pipeline}
                contacts={contacts}
                members={members}
                currency={currency}
            />

            {anySelected && (
                <BulkBar
                    count={{ n: selectedIds.size, ids: selectedIds }}
                    onClear={clearSelection}
                    pipeline={pipeline}
                    members={members}
                />
            )}
        </AuthenticatedLayout>
    );
}

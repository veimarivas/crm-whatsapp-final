import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 focus:bg-white transition-all';

export default function DeleteUserForm() {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset();
    };

    return (
        <section className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-md">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                </div>
                <div>
                    <h3 className="text-base font-bold text-gray-900">Eliminar cuenta</h3>
                    <p className="text-xs text-gray-400 mt-0.5">Una vez eliminada tu cuenta, todos sus recursos y datos se perderán permanentemente.</p>
                </div>
            </div>

            <div className="p-5 sm:p-8">
                <p className="text-sm text-gray-600 mb-5">
                    Antes de eliminar tu cuenta, descargá cualquier información que quieras conservar.
                </p>

                <button
                    type="button"
                    onClick={confirmUserDeletion}
                    className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-red-600 to-rose-600 rounded-xl hover:from-red-500 hover:to-rose-500 transition-all shadow-lg shadow-red-500/20"
                >
                    Eliminar cuenta
                </button>
            </div>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser}>
                    <div className="p-6">
                        <h2 className="text-base font-bold text-gray-900">
                            ¿Estás seguro de que querés eliminar tu cuenta?
                        </h2>

                        <p className="mt-1 text-sm text-gray-600">
                            Una vez eliminada tu cuenta, todos los recursos y datos se perderán permanentemente. Ingresá tu contraseña para confirmar.
                        </p>

                        <div className="mt-6">
                            <InputLabel
                                htmlFor="password"
                                value="Contraseña"
                                className="sr-only"
                            />

                            <input
                                id="password"
                                type="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                                className={inputClass}
                                placeholder="Contraseña"
                                autoFocus
                            />

                            <InputError
                                message={errors.password}
                                className="mt-2"
                            />
                        </div>
                    </div>

                    <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={closeModal}
                            className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-red-600 to-rose-600 rounded-xl hover:from-red-500 hover:to-rose-500 disabled:opacity-50 transition-all shadow-lg shadow-red-500/20"
                        >
                            {processing ? 'Eliminando...' : 'Eliminar cuenta'}
                        </button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}

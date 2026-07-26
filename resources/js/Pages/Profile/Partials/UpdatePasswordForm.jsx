import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

export default function UpdatePasswordForm() {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current.focus();
                }
            },
        });
    };

    return (
        <section className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-md">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <div>
                    <h3 className="text-base font-bold text-gray-900">Cambiar contraseña</h3>
                    <p className="text-xs text-gray-400 mt-0.5">Asegurate de usar una contraseña segura y difícil de adivinar.</p>
                </div>
            </div>

            <div className="p-5 sm:p-8">
                <form onSubmit={updatePassword} className="space-y-6">
                    <div>
                        <InputLabel
                            htmlFor="current_password"
                            value="Contraseña actual"
                        />

                        <input
                            id="current_password"
                            ref={currentPasswordInput}
                            value={data.current_password}
                            onChange={(e) =>
                                setData('current_password', e.target.value)
                            }
                            type="password"
                            className={`mt-1.5 ${inputClass}`}
                            autoComplete="current-password"
                        />

                        <InputError
                            message={errors.current_password}
                            className="mt-2"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="password" value="Nueva contraseña" />

                        <input
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            className={`mt-1.5 ${inputClass}`}
                            autoComplete="new-password"
                        />

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirmar contraseña"
                        />

                        <input
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) =>
                                setData('password_confirmation', e.target.value)
                            }
                            type="password"
                            className={`mt-1.5 ${inputClass}`}
                            autoComplete="new-password"
                        />

                        <InputError
                            message={errors.password_confirmation}
                            className="mt-2"
                        />
                    </div>

                    <div className="flex items-center gap-4 pt-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                        >
                            {processing ? 'Guardando...' : 'Guardar'}
                        </button>

                        <Transition
                            show={recentlySuccessful}
                            enter="transition ease-in-out"
                            enterFrom="opacity-0"
                            leave="transition ease-in-out"
                            leaveTo="opacity-0"
                        >
                            <p className="text-sm text-emerald-600 font-medium">
                                Guardado.
                            </p>
                        </Transition>
                    </div>
                </form>
            </div>
        </section>
    );
}

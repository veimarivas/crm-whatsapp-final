import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Login({ status, canResetPassword }) {
    const [passwordShow, setPasswordShow] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Iniciar Sesión" />

            <div className="w-full max-w-md">
                <div className="bg-white rounded-2xl shadow-2xl shadow-black/25 overflow-hidden">
                    <div className="p-8 sm:p-10">
                        <div className="text-center mb-8">
                            <img
                                src="/conecta.png"
                                alt="Conecta"
                                className="h-28 w-auto mx-auto mb-4"
                            />
                            <h4 className="text-2xl font-bold text-[#042048]">
                                ¡Bienvenido al CRM WhatsApp!
                            </h4>
                            <p className="text-sm text-[#718ca4] mt-1.5">
                                Inicia sesión para continuar
                            </p>
                        </div>

                        {status && (
                            <div className="mb-6 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                                {status}
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label
                                    htmlFor="email"
                                    className="block text-sm font-medium text-gray-700 mb-1.5"
                                >
                                    Email <span className="text-red-500">*</span>
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) =>
                                        setData('email', e.target.value)
                                    }
                                    className={`w-full px-4 py-2.5 border rounded-lg text-sm transition-colors
                                        ${
                                            errors.email
                                                ? 'border-red-300 focus:ring-red-500 focus:border-red-500'
                                                : 'border-gray-300 focus:ring-[#045474] focus:border-[#045474]'
                                        }
                                        focus:outline-none focus:ring-2 placeholder:text-gray-400`}
                                    placeholder="Ingresa tu email"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                />
                                {errors.email && (
                                    <p className="mt-1.5 text-xs text-red-500">
                                        {errors.email}
                                    </p>
                                )}
                            </div>

                            <div>
                                <div className="flex justify-between items-center mb-1.5">
                                    <label
                                        htmlFor="password"
                                        className="block text-sm font-medium text-gray-700"
                                    >
                                        Contraseña{' '}
                                        <span className="text-red-500">*</span>
                                    </label>
                                    {canResetPassword && (
                                        <Link
                                            href={route('password.request')}
                                            className="text-xs text-[#045474] hover:text-[#1c486c] font-medium"
                                        >
                                            ¿Olvidaste tu contraseña?
                                        </Link>
                                    )}
                                </div>
                                <div className="relative">
                                    <input
                                        id="password"
                                        type={passwordShow ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={(e) =>
                                            setData('password', e.target.value)
                                        }
                                        className={`w-full px-4 py-2.5 border rounded-lg text-sm pr-11 transition-colors
                                            ${
                                                errors.password
                                                    ? 'border-red-300 focus:ring-red-500 focus:border-red-500'
                                        : 'border-gray-300 focus:ring-[#045474] focus:border-[#045474]'
                                    }
                                    focus:outline-none focus:ring-2 placeholder:text-gray-400`}
                                    placeholder="Ingresa tu contraseña"
                                    autoComplete="current-password"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setPasswordShow(!passwordShow)
                                        }
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                                        tabIndex={-1}
                                    >
                                        {passwordShow ? (
                                            <svg
                                                className="w-4 h-4"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                strokeWidth={1.5}
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
                                                />
                                            </svg>
                                        ) : (
                                            <svg
                                                className="w-4 h-4"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                strokeWidth={1.5}
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                                                />
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                {errors.password && (
                                    <p className="mt-1.5 text-xs text-red-500">
                                        {errors.password}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center">
                                <input
                                    id="remember"
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={(e) =>
                                        setData('remember', e.target.checked)
                                    }
                                    className="h-4 w-4 text-[#045474] rounded border-gray-300 focus:ring-[#045474]"
                                />
                                <label
                                    htmlFor="remember"
                                    className="ml-2.5 text-sm text-gray-600 select-none"
                                >
                                    Recordarme
                                </label>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-2.5 px-4 bg-gradient-to-r from-[#042048] to-[#045474] text-white text-sm font-medium rounded-lg
                                    hover:from-[#1c486c] hover:to-[#045474] focus:outline-none focus:ring-2 focus:ring-[#045474] focus:ring-offset-2
                                    disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-[#042048]/25"
                            >
                                {processing
                                    ? 'Iniciando sesión...'
                                    : 'Iniciar Sesión'}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </GuestLayout>
    );
}

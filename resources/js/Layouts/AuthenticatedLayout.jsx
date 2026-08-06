import ApplicationLogo from '@/Components/ApplicationLogo';
import LiveInbound from '@/Components/LiveInbound';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const navigation = [
    {
        name: 'Dashboard',
        pattern: 'dashboard',
        routeName: 'dashboard',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
        ),
    },
    {
        name: 'Inbox',
        pattern: 'inbox',
        routeName: 'inbox',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859M12 3v8.25m0 0l-3-3m3 3l3-3" />
            </svg>
        ),
    },
    {
        name: 'Contactos',
        pattern: 'contacts.*',
        routeName: 'contacts.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
        ),
    },
    {
        name: 'Pipelines',
        pattern: 'pipelines.*',
        routeName: 'pipelines.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
        ),
    },
    {
        name: 'Seguimiento',
        pattern: 'supervision.*',
        routeName: 'supervision.index',
        adminOnly: true,
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        ),
    },
    {
        name: 'Plantillas',
        pattern: 'templates.*',
        routeName: 'templates.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
        ),
    },
    {
        name: 'Broadcasts',
        pattern: 'broadcasts.*',
        routeName: 'broadcasts.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38a.502.502 0 01-.673-.223 12.476 12.476 0 01-1.372-4.45m5.598-7.93c.162.38.332.752.511 1.116m-8.766 6.814c-1.134-.528-2.005-1.636-2.196-2.974m15.066-1.272a5.25 5.25 0 010 6.372m0 0a5.25 5.25 0 01-3.023 1.802m3.023-6.802a5.25 5.25 0 00-3.023-1.802m-1.03 5.203a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
            </svg>
        ),
    },
    {
        name: 'Automatizaciones',
        pattern: 'automations.*',
        routeName: 'automations.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
            </svg>
        ),
    },
    {
        name: 'Flows',
        pattern: 'flows.*',
        routeName: 'flows.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
            </svg>
        ),
    },
    {
        name: 'Plantillas rápidas',
        pattern: 'quick-replies.*',
        routeName: 'quick-replies.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M13 5l7 7-7 7M5 12h15" />
            </svg>
        ),
    },
    {
        name: 'Auto-tags',
        pattern: 'auto-tags.*',
        routeName: 'auto-tags.index',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
            </svg>
        ),
    },
    {
        name: 'WhatsApp',
        pattern: 'settings.whatsapp',
        routeName: 'settings.whatsapp',
        icon: (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
            </svg>
        ),
    },
];

export default function AuthenticatedLayout({ header, children }) {
    const { url, props } = usePage();
    const user = props.auth.user;
    const unread = props.unreadNotifications ?? 0;

    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

    // Heartbeat de presencia: marca al usuario como en línea cada 60s.
    useEffect(() => {
        const ping = () =>
            fetch(route('presence.ping'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            }).catch(() => {});
        ping();
        const t = setInterval(ping, 60000);
        return () => clearInterval(t);
    }, []);

    const sidebarWidth = sidebarCollapsed ? 'w-20' : 'w-64';

    const isActive = (pattern) => {
        if (pattern === 'dashboard') return url === '/dashboard';
        if (pattern === 'inbox') return url === '/inbox' || url.startsWith('/inbox/');
        return route().current(pattern);
    };

    // Seguimiento es admin-only: el agente no compara su desempeño con el
    // del resto. El servidor lo corta igual con 403.
    const isAdmin = user?.account_role === 'owner' || user?.account_role === 'admin';
    const visibleNav = navigation.filter((item) => ! item.adminOnly || isAdmin);

    // Los 5 primeros son la navegación de trabajo; el resto, configuración.
    const mainNav = visibleNav.slice(0, isAdmin ? 5 : 4);
    const configNav = visibleNav.slice(isAdmin ? 5 : 4);

    return (
        <div className="min-h-screen bg-gray-50">
            <div className="flex h-screen overflow-hidden">
                <aside
                    className={`fixed inset-y-0 left-0 z-30 ${sidebarWidth} bg-[#042048] transform transition-all duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-auto ${
                        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
                >
                    <div className="flex flex-col h-full">
                        <div className="flex items-center justify-center h-16 px-4 border-b border-white/10">
                            <Link href={route('dashboard')} className="flex items-center gap-2.5 min-w-0">
                                <img
                                    src="/conecta.png"
                                    alt="Logo"
                                    className="h-12 w-auto shrink-0"
                                />
                                {!sidebarCollapsed && (
                                    <span className="text-white font-bold text-sm tracking-tight truncate">CRM Whatsapp</span>
                                )}
                            </Link>
                        </div>

                        <nav className="flex-1 px-3 py-4 overflow-y-auto scrollbar-none">
                            <div className="space-y-1">
                                {mainNav.map((item) => {
                                    const active = isActive(item.pattern);
                                    return (
                                        <Link
                                            key={item.name}
                                            href={route(item.routeName)}
                                            onClick={() => setSidebarOpen(false)}
                                            className={`group relative flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 ${
                                                sidebarCollapsed ? 'justify-center' : ''
                                            } ${
                                                active
                                                    ? 'bg-[#045474]/30 text-white shadow-sm'
                                                    : 'text-gray-300 hover:bg-white/5 hover:text-white'
                                            }`}
                                            title={sidebarCollapsed ? item.name : undefined}
                                        >
                                            {active && !sidebarCollapsed && (
                                                <span className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 rounded-r-full bg-[#e6dd5e]" />
                                            )}
                                            <span
                                                className={`flex-shrink-0 ${
                                                    active
                                                        ? 'text-[#e6dd5e]'
                                                        : 'text-gray-400 group-hover:text-gray-200'
                                                }`}
                                            >
                                                {item.icon}
                                            </span>
                                            {!sidebarCollapsed && (
                                                <>
                                                    <span className="truncate">{item.name}</span>
                                                    {active && (
                                                        <span className="ml-auto w-1.5 h-1.5 rounded-full bg-[#e6dd5e]" />
                                                    )}
                                                </>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>

                            {!sidebarCollapsed && configNav.length > 0 && (
                                <div className="mt-6 pt-4 border-t border-white/10">
                                    <div className="px-3 mb-1.5">
                                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-500">
                                            Configuración
                                        </span>
                                    </div>
                                    <div className="space-y-0.5">
                                        {configNav.map((item) => {
                                            const active = isActive(item.pattern);
                                            return (
                                                <Link
                                                    key={item.name}
                                                    href={route(item.routeName)}
                                                    onClick={() => setSidebarOpen(false)}
                                                    className={`group relative flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all duration-200 ${
                                                        active
                                                            ? 'bg-[#045474]/20 text-white/90 shadow-sm'
                                                            : 'text-gray-400 hover:bg-white/5 hover:text-gray-200'
                                                    }`}
                                                >
                                                    {active && (
                                                        <span className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-4 rounded-r-full bg-[#e6dd5e]/80" />
                                                    )}
                                                    <span
                                                        className={`flex-shrink-0 ${
                                                            active ? 'text-[#e6dd5e]/80' : 'text-gray-500 group-hover:text-gray-300'
                                                        }`}
                                                    >
                                                        {item.icon}
                                                    </span>
                                                    <span className="truncate">{item.name}</span>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </nav>

                        <div className={`p-3 border-t border-white/10 ${sidebarCollapsed ? 'text-center' : ''}`}>
                            <div className={`flex items-center gap-3 px-3 py-2 text-gray-400 ${sidebarCollapsed ? 'justify-center' : ''}`}>
                                <div className="w-8 h-8 rounded-full bg-[#045474] flex items-center justify-center text-white text-xs font-semibold">
                                    {user.name?.charAt(0).toUpperCase() || 'U'}
                                </div>
                                {!sidebarCollapsed && (
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs font-medium text-white truncate">
                                            {user.name}
                                        </p>
                                        <p className="text-[10px] text-gray-400 truncate">
                                            {user.email}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </aside>

                {sidebarOpen && (
                    <div
                        className="fixed inset-0 z-20 bg-black/50 lg:hidden"
                        onClick={() => setSidebarOpen(false)}
                    />
                )}

                <div className="flex flex-col flex-1 min-w-0">
                    <header className="sticky top-0 z-10 bg-white border-b border-gray-200 shadow-sm">
                        <div className="flex items-center justify-between h-16 px-4 sm:px-6">
                            <div className="flex items-center gap-3">
                                <button
                                    onClick={() => {
                                        if (window.innerWidth < 1024) {
                                            setSidebarOpen(!sidebarOpen);
                                        } else {
                                            setSidebarCollapsed(!sidebarCollapsed);
                                        }
                                    }}
                                    className="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                                    title={sidebarCollapsed ? 'Expandir menú' : 'Colapsar menú'}
                                >
                                    {sidebarCollapsed ? (
                                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                        </svg>
                                    ) : (
                                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                        </svg>
                                    )}
                                </button>
                                {header && (
                                    <div className="hidden sm:block">{header}</div>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Link
                                    href={route('notifications')}
                                    className="relative p-2 text-gray-400 hover:text-[#045474] hover:bg-gray-100 rounded-lg transition-colors"
                                    title="Notificaciones"
                                >
                                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                    </svg>
                                    {unread > 0 && (
                                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                                            {unread > 9 ? '9+' : unread}
                                        </span>
                                    )}
                                </Link>

                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button className="flex items-center gap-2 p-1.5 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-semibold">
                                                {user.name?.charAt(0).toUpperCase() || 'U'}
                                            </div>
                                            <span className="hidden sm:block text-sm font-medium">
                                                {user.name}
                                            </span>
                                            <svg className="hidden sm:block w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content align="right" width="48">
                                        <Dropdown.Link href={route('profile.edit')}>
                                            Perfil
                                        </Dropdown.Link>
                                        <Dropdown.Link href={route('settings.ai')}>
                                            Asistente IA
                                        </Dropdown.Link>
                                        <Dropdown.Link href={route('settings.team')}>
                                            Equipo y API
                                        </Dropdown.Link>
                                        <div className="border-t border-gray-100" />
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Cerrar sesión
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>
                        {typeof header !== 'undefined' && (
                            <div className="sm:hidden px-4 pb-3">{header}</div>
                        )}
                    </header>

                    <main className="flex-1 overflow-y-auto bg-gray-50">
                        {children}
                    </main>
                </div>
            </div>

            {/* Aviso en vivo de mensajes entrantes desde cualquier pantalla.
                En el Inbox se desactiva: ahí ya se ven llegar, y un toast
                encima de la conversación abierta molesta más que ayuda. */}
            <LiveInbound enabled={!isActive('inbox')} />
        </div>
    );
}

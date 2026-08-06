export default function GuestLayout({ children }) {
    return (
        <div className="relative min-h-screen bg-gradient-to-br from-[#042048] via-[#1c486c] to-[#045474] overflow-hidden">
            <div className="absolute inset-0">
                <div
                    className="absolute inset-0 bg-cover bg-center opacity-[0.08]"
                    style={{ backgroundImage: "url('/auth-one-bg.jpg')" }}
                />
                <div className="absolute inset-0 bg-gradient-to-br from-[#042048]/90 via-[#1c486c]/80 to-[#045474]/80" />
            </div>

            <div className="absolute -top-40 -right-40 w-[500px] h-[500px] rounded-full bg-[#045474]/20 blur-3xl" />
            <div className="absolute -bottom-40 -left-40 w-[500px] h-[500px] rounded-full bg-[#1c486c]/20 blur-3xl" />

            <svg
                className="absolute bottom-0 left-0 right-0 w-full h-auto pointer-events-none"
                viewBox="0 0 1440 120"
                fill="white"
                fillOpacity="0.04"
            >
                <path d="M 0,36 C 144,53.6 432,123.2 720,124 C 1008,124.8 1296,56.8 1440,40 L 1440 140 L 0 140 Z" />
            </svg>

            <div className="relative flex flex-col min-h-screen">
                <div className="flex-1 flex items-center justify-center px-4 py-8 sm:py-12">
                    {children}
                </div>

                <div className="relative text-center pb-6">
                    <p className="text-white/40 text-sm">
                        &copy; {new Date().getFullYear()} Derechos reservados
                    </p>
                </div>
            </div>
        </div>
    );
}

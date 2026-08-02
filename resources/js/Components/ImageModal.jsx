import { useEffect } from 'react';

export default function ImageModal({ src, alt, onClose }) {
    useEffect(() => {
        if (!src) return;
        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [src, onClose]);

    if (!src) return null;

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
        >
            <button
                type="button"
                onClick={onClose}
                title="Cerrar (Esc)"
                className="absolute right-4 top-4 z-10 rounded-full bg-white/10 p-2 text-white transition hover:bg-white/25"
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <img
                src={src}
                alt={alt || 'Imagen'}
                className="max-h-[90vh] max-w-[92vw] rounded-lg object-contain shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            />
        </div>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Perfil
                </h2>
            }
        >
            <Head title="Perfil" />

            <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Perfil</h1>
                    <p className="text-sm text-gray-500 mt-1">Gestioná tu cuenta y preferencias</p>
                </div>

                <UpdateProfileInformationForm
                    mustVerifyEmail={mustVerifyEmail}
                    status={status}
                />

                <UpdatePasswordForm />

                <DeleteUserForm />
            </div>
        </AuthenticatedLayout>
    );
}

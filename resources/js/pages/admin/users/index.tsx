import { Form, Head, Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/admin/users';

type Props = {
    users: { id: number; name: string; email: string; created_at: string }[];
};

const inputClass =
    'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950';

export default function UsersIndex({ users }: Props) {
    const { auth } = usePage().props;

    return (
        <AdminLayout title="Usuários">
            <Head title="Usuários" />

            <div className="grid gap-6 md:grid-cols-[1fr_320px]">
                <ul className="divide-y divide-zinc-100 self-start overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                    {users.map((user) => (
                        <li
                            key={user.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-zinc-900 dark:text-zinc-50">
                                    {user.name}
                                    {user.id === auth.user?.id && (
                                        <span className="ml-2 text-xs font-normal text-zinc-500">
                                            (você)
                                        </span>
                                    )}
                                </p>
                                <p className="truncate text-xs text-zinc-500">
                                    {user.email} · criado em{' '}
                                    {formatDateTime(user.created_at)}
                                </p>
                            </div>
                            {user.id !== auth.user?.id && (
                                <Link
                                    href={destroy(user.id)}
                                    as="button"
                                    preserveScroll
                                    onBefore={() =>
                                        confirm(
                                            `Excluir o usuário ${user.email}?`,
                                        )
                                    }
                                    className="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950"
                                >
                                    Excluir
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>

                <Form
                    {...store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="space-y-3 self-start rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
                >
                    {({ errors, processing }) => (
                        <>
                            <h2 className="font-semibold text-zinc-900 dark:text-zinc-50">
                                Novo usuário
                            </h2>
                            {(
                                [
                                    ['name', 'Nome', 'text', 'name'],
                                    ['email', 'E-mail', 'email', 'email'],
                                    [
                                        'password',
                                        'Senha',
                                        'password',
                                        'new-password',
                                    ],
                                    [
                                        'password_confirmation',
                                        'Confirme a senha',
                                        'password',
                                        'new-password',
                                    ],
                                ] as const
                            ).map(([name, label, type, autoComplete]) => (
                                <label key={name} className="block text-sm">
                                    <span className="mb-1 block font-medium">
                                        {label}
                                    </span>
                                    <input
                                        name={name}
                                        type={type}
                                        required
                                        autoComplete={autoComplete}
                                        className={inputClass}
                                    />
                                    {errors[name] && (
                                        <span className="mt-1 block text-xs text-red-600">
                                            {errors[name]}
                                        </span>
                                    )}
                                </label>
                            ))}
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Criar usuário
                            </button>
                        </>
                    )}
                </Form>
            </div>
        </AdminLayout>
    );
}

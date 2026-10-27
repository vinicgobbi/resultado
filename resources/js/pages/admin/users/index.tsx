import { Form, Head, Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/admin/users';

type Props = {
    users: { id: number; name: string; email: string; created_at: string }[];
};

const inputClass =
    'w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm';

export default function UsersIndex({ users }: Props) {
    const { auth } = usePage().props;

    return (
        <AdminLayout title="Usuários">
            <Head title="Usuários" />

            <div className="grid gap-6 md:grid-cols-[1fr_320px]">
                <ul className="divide-y divide-line self-start overflow-hidden rounded-xl border border-line bg-surface">
                    {users.map((user) => (
                        <li
                            key={user.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-ink">
                                    {user.name}
                                    {user.id === auth.user?.id && (
                                        <span className="ml-2 text-xs font-normal text-muted">
                                            (você)
                                        </span>
                                    )}
                                </p>
                                <p className="truncate text-xs text-muted">
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
                                    className="rounded-lg border border-live px-3 py-1.5 text-sm text-live hover:bg-live-soft"
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
                    className="space-y-3 self-start rounded-xl border border-line bg-surface p-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <h2 className="font-semibold text-ink">
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
                                        <span className="mt-1 block text-xs text-live">
                                            {errors[name]}
                                        </span>
                                    )}
                                </label>
                            ))}
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary-hover disabled:opacity-50"
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

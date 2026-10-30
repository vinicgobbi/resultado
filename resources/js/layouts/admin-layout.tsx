import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { index as adminIndex } from '@/routes/admin';
import { index as candidatesIndex } from '@/routes/admin/candidates';
import { index as usersIndex } from '@/routes/admin/users';

const navigation = [
    { label: 'Painel', href: adminIndex.url() },
    { label: 'Candidatos', href: candidatesIndex.url() },
    { label: 'Usuários', href: usersIndex.url() },
];

export default function AdminLayout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const { auth, flash } = usePage().props;
    const { url } = usePage();

    /** The dashboard only on its own page; the others on their subpages too. */
    const isActive = (href: string) =>
        href === adminIndex.url() ? url === href : url.startsWith(href);

    return (
        <div className="min-h-screen bg-subtle text-ink">
            <header className="border-b border-line bg-surface">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-4 py-3">
                    <Link
                        href={adminIndex.url()}
                        className="font-bold text-ink"
                    >
                        Admin · Eleições
                    </Link>
                    <nav className="flex gap-1">
                        {navigation.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-current={
                                    isActive(item.href) ? 'page' : undefined
                                }
                                className={cn(
                                    'rounded-lg px-3 py-1.5 text-sm',
                                    isActive(item.href)
                                        ? 'bg-subtle font-medium text-ink'
                                        : 'text-muted hover:text-ink',
                                )}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <Link
                            href={home.url()}
                            className="text-muted hover:text-ink"
                        >
                            Ver site
                        </Link>
                        <span className="hidden text-muted sm:inline">
                            {auth.user?.name}
                        </span>
                        <Link
                            href={logout()}
                            as="button"
                            className="rounded-lg border border-line-strong px-3 py-1.5 hover:bg-subtle"
                        >
                            Sair
                        </Link>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl space-y-4 px-4 py-6">
                <h1 className="text-xl font-bold text-ink">{title}</h1>
                {flash.success && (
                    <div className="rounded-lg border border-success bg-success-soft px-4 py-2 text-sm text-success">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg border border-live bg-live-soft px-4 py-2 text-sm text-live">
                        {flash.error}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}

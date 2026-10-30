import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { index as adminIndex } from '@/routes/admin';
import { index as candidatesIndex } from '@/routes/admin/candidates';
import { index as partiesIndex } from '@/routes/admin/parties';
import { index as noticesIndex } from '@/routes/admin/site-notices';
import { index as usersIndex } from '@/routes/admin/users';

const navigation = [
    { label: 'Painel', href: adminIndex.url() },
    { label: 'Avisos', href: noticesIndex.url() },
    { label: 'Candidatos', href: candidatesIndex.url() },
    { label: 'Partidos', href: partiesIndex.url() },
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
                <div className="mx-auto max-w-5xl px-4">
                    <div className="flex items-center gap-3 py-3">
                        <Link
                            href={adminIndex.url()}
                            className="font-bold whitespace-nowrap text-ink"
                        >
                            Admin · Eleições
                        </Link>
                        <div className="ml-auto flex items-center gap-1 text-sm sm:gap-3">
                            <Link
                                href={home.url()}
                                className="flex min-h-10 items-center rounded-lg px-2 whitespace-nowrap text-muted hover:text-ink"
                            >
                                Ver site
                            </Link>
                            <span className="hidden text-muted sm:inline">
                                {auth.user?.name}
                            </span>
                            <Link
                                href={logout()}
                                as="button"
                                className="min-h-10 rounded-lg border border-line-strong px-3 hover:bg-subtle"
                            >
                                Sair
                            </Link>
                        </div>
                    </div>
                    {/* Scrolls sideways on narrow screens instead of wrapping. */}
                    <nav
                        aria-label="Seções do admin"
                        className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-2"
                    >
                        {navigation.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-current={
                                    isActive(item.href) ? 'page' : undefined
                                }
                                className={cn(
                                    'flex min-h-10 shrink-0 items-center rounded-lg px-3 text-sm',
                                    isActive(item.href)
                                        ? 'bg-primary-soft font-semibold text-primary'
                                        : 'text-muted hover:bg-subtle hover:text-ink',
                                )}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-5xl space-y-4 px-4 py-5 sm:py-6">
                <h1 className="text-xl font-bold text-ink sm:text-2xl">
                    {title}
                </h1>
                {flash.success && (
                    <div
                        role="status"
                        className="rounded-lg border border-success bg-success-soft px-4 py-2 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div
                        role="alert"
                        className="rounded-lg border border-live bg-live-soft px-4 py-2 text-sm text-live"
                    >
                        {flash.error}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}

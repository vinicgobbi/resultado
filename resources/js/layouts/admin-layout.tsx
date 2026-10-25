import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { index as candidatesIndex } from '@/routes/admin/candidates';
import { index as usersIndex } from '@/routes/admin/users';

const navigation = [
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

    return (
        <div className="min-h-screen bg-zinc-50 text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
            <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-4 py-3">
                    <Link
                        href={candidatesIndex.url()}
                        className="font-bold text-zinc-900 dark:text-zinc-50"
                    >
                        Admin · Eleições
                    </Link>
                    <nav className="flex gap-1">
                        {navigation.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'rounded-lg px-3 py-1.5 text-sm',
                                    url.startsWith(item.href)
                                        ? 'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-50'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100',
                                )}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <Link
                            href={home.url()}
                            className="text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
                        >
                            Ver site
                        </Link>
                        <span className="hidden text-zinc-400 sm:inline">
                            {auth.user?.name}
                        </span>
                        <Link
                            href={logout()}
                            as="button"
                            className="rounded-lg border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            Sair
                        </Link>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl space-y-4 px-4 py-6">
                <h1 className="text-xl font-bold text-zinc-900 dark:text-zinc-50">
                    {title}
                </h1>
                {flash.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                        {flash.error}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}

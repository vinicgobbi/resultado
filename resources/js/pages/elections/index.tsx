import { Head, Link } from '@inertiajs/react';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/elections';
import type { Election, ElectionStatus } from '@/types';

type Props = {
    elections: (Election & { offices: string[] })[];
};

const STATUS: Record<ElectionStatus, { label: string; className: string }> = {
    agendada: {
        label: 'Agendada',
        className:
            'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    },
    apuracao: {
        label: 'Em apuração',
        className:
            'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
    },
    concluida: {
        label: 'Concluída',
        className:
            'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    },
    pendente: {
        label: 'Importando',
        className:
            'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    },
};

export default function ElectionsIndex({ elections }: Props) {
    const years = [...new Set(elections.map((election) => election.year))];

    return (
        <>
            <Head title="Eleições" />

            <div className="min-h-screen bg-zinc-50 text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
                <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4">
                        <img
                            src="/app-icons/icon-192.png"
                            alt=""
                            className="size-9 rounded-lg"
                        />
                        <div>
                            <h1 className="text-lg leading-tight font-bold text-zinc-900 dark:text-zinc-50">
                                Eleições
                            </h1>
                            <p className="text-xs text-zinc-500">
                                Eleições ordinárias, com os dados oficiais do
                                TSE
                            </p>
                        </div>
                        <Link
                            href={home()}
                            className="ml-auto text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                        >
                            Eleição atual
                        </Link>
                    </div>
                </header>

                <main className="mx-auto max-w-5xl space-y-8 px-4 py-6">
                    {elections.length === 0 && (
                        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500 dark:border-zinc-700">
                            Nenhuma eleição cadastrada ainda. Rode{' '}
                            <code>php artisan tse:descobrir</code>.
                        </div>
                    )}

                    {years.map((year) => (
                        <section key={year} className="space-y-3">
                            <h2 className="text-2xl font-bold text-zinc-900 dark:text-zinc-50">
                                {year}
                            </h2>
                            <ul className="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                                {elections
                                    .filter(
                                        (election) => election.year === year,
                                    )
                                    .map((election) => (
                                        <li key={election.code}>
                                            <Link
                                                href={show(election.code)}
                                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                            >
                                                <span className="w-24 shrink-0 text-sm text-zinc-500 tabular-nums">
                                                    {formatDate(election.date)}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block font-medium text-zinc-900 dark:text-zinc-50">
                                                        {election.name}
                                                    </span>
                                                    <span className="block text-xs text-zinc-500">
                                                        {election.offices.join(
                                                            ', ',
                                                        )}
                                                    </span>
                                                </span>
                                                <span
                                                    className={cn(
                                                        'rounded-full px-2 py-0.5 text-xs font-medium',
                                                        STATUS[election.status]
                                                            .className,
                                                    )}
                                                >
                                                    {
                                                        STATUS[election.status]
                                                            .label
                                                    }
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                            </ul>
                        </section>
                    ))}
                </main>
            </div>
        </>
    );
}

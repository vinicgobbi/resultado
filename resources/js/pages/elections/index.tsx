import { Head, Link } from '@inertiajs/react';
import SkipLink from '@/components/skip-link';
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
        className: 'bg-primary-soft text-primary',
    },
    apuracao: {
        label: 'Em apuração',
        className: 'bg-live-soft text-live',
    },
    concluida: {
        label: 'Concluída',
        className: 'bg-track text-ink',
    },
    pendente: {
        label: 'Importando',
        className: 'bg-warning-soft text-warning',
    },
};

export default function ElectionsIndex({ elections }: Props) {
    const years = [...new Set(elections.map((election) => election.year))];

    return (
        <>
            <Head title="Eleições" />

            <SkipLink />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4">
                        <img
                            src="/app-icons/icon-192.png"
                            alt=""
                            className="size-9 rounded-lg"
                        />
                        <div>
                            <h1 className="text-lg leading-tight font-bold text-ink">
                                Eleições
                            </h1>
                            <p className="text-xs text-muted">
                                Eleições ordinárias, com os dados oficiais do
                                TSE
                            </p>
                        </div>
                        <Link
                            href={home()}
                            className="ml-auto text-sm font-medium text-muted hover:text-ink"
                        >
                            Eleição atual
                        </Link>
                    </div>
                </header>

                <main
                    id="conteudo"
                    tabIndex={-1}
                    className="mx-auto max-w-5xl space-y-8 px-4 py-6 focus:outline-none"
                >
                    {elections.length === 0 && (
                        <div className="rounded-xl border border-dashed border-line-strong p-10 text-center text-muted">
                            Ainda não há eleições disponíveis.
                        </div>
                    )}

                    {years.map((year) => (
                        <section key={year} className="space-y-3">
                            <h2 className="text-2xl font-bold text-ink">
                                {year}
                            </h2>
                            <ul className="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                                {elections
                                    .filter(
                                        (election) => election.year === year,
                                    )
                                    .map((election) => (
                                        <li key={election.code}>
                                            <Link
                                                href={show(election.code)}
                                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-subtle"
                                            >
                                                <span className="w-24 shrink-0 text-sm text-muted tabular-nums">
                                                    {formatDate(election.date)}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block font-medium text-ink">
                                                        {election.name}
                                                    </span>
                                                    <span className="block text-xs text-muted">
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

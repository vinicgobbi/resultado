import { Head, Link } from '@inertiajs/react';
import Logo from '@/components/logo';
import SkipLink from '@/components/skip-link';
import StatusBadge from '@/components/status-badge';
import { STATUS } from '@/lib/election';
import { formatLongDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/elections';
import type { ElectionCycle } from '@/types';

type Props = {
    cycles: ElectionCycle[];
};

/**
 * Every election, one per year with its rounds: the TSE publishes each
 * round separately, but for whoever follows it they are the same election.
 */
export default function ElectionsIndex({ cycles }: Props) {
    return (
        <>
            <Head title="Escolha uma eleição" />

            <SkipLink />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4">
                        <Link
                            href={home()}
                            className="flex items-center gap-3 rounded-lg"
                        >
                            <Logo />
                            <span className="text-lg font-bold">
                                Resultados das Eleições
                            </span>
                        </Link>
                    </div>
                </header>

                <main
                    id="conteudo"
                    tabIndex={-1}
                    className="mx-auto max-w-5xl space-y-8 px-4 py-8 focus:outline-none"
                >
                    <div className="space-y-2">
                        <h1 className="text-3xl font-bold">
                            Qual eleição você quer ver?
                        </h1>
                        <p className="text-lg text-muted">
                            Resultados oficiais do Tribunal Superior Eleitoral
                            (TSE), de todo o Brasil. Escolha o turno dentro de
                            cada eleição.
                        </p>
                    </div>

                    {cycles.length === 0 && (
                        <p className="rounded-xl border border-dashed border-line-strong p-10 text-center text-lg text-muted">
                            Ainda não há eleições disponíveis.
                        </p>
                    )}

                    <ul className="grid gap-4 md:grid-cols-2">
                        {cycles.map((cycle, index) => (
                            <li
                                key={cycle.cycle}
                                className="animate-fade-up"
                                style={{ animationDelay: `${index * 70}ms` }}
                            >
                                <section
                                    aria-labelledby={`eleicao-${cycle.cycle}`}
                                    className={cn(
                                        'flex h-full flex-col gap-4 rounded-2xl border-2 bg-surface p-5',
                                        cycle.status === 'apuracao'
                                            ? 'border-live'
                                            : 'border-line',
                                    )}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h2
                                            id={`eleicao-${cycle.cycle}`}
                                            className="text-2xl font-bold"
                                        >
                                            Eleições {cycle.year}
                                        </h2>
                                        <StatusBadge status={cycle.status} />
                                    </div>
                                    <p>
                                        <span className="sr-only">
                                            Cargos:{' '}
                                        </span>
                                        {cycle.offices.join(', ')}
                                    </p>
                                    <ul className="mt-auto grid gap-2 sm:grid-cols-2">
                                        {cycle.rounds.map((round) => (
                                            <li key={round.round}>
                                                <Link
                                                    href={show(round.code)}
                                                    viewTransition
                                                    className="group flex h-full flex-col gap-0.5 rounded-xl border-2 border-line-strong px-4 py-3 transition hover:-translate-y-0.5 hover:border-primary hover:bg-primary-soft"
                                                >
                                                    <span className="flex items-center justify-between gap-2 font-bold">
                                                        {round.round}º turno
                                                        <span
                                                            aria-hidden="true"
                                                            className="text-primary transition-transform group-hover:translate-x-1"
                                                        >
                                                            →
                                                        </span>
                                                    </span>
                                                    <span className="text-sm text-muted">
                                                        {formatLongDate(
                                                            round.date,
                                                        )}
                                                    </span>
                                                    <span className="text-sm">
                                                        <span aria-hidden="true">
                                                            {
                                                                STATUS[
                                                                    round.status
                                                                ].icon
                                                            }
                                                        </span>{' '}
                                                        {
                                                            STATUS[round.status]
                                                                .label
                                                        }
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            </li>
                        ))}
                    </ul>
                </main>
            </div>
        </>
    );
}

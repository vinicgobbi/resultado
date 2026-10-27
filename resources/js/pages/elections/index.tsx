import { Head, Link } from '@inertiajs/react';
import SkipLink from '@/components/skip-link';
import StatusBadge from '@/components/status-badge';
import { dayLabel } from '@/lib/election';
import { formatLongDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/elections';
import type { ElectionDay } from '@/types';

type Props = {
    days: ElectionDay[];
};

export default function ElectionsIndex({ days }: Props) {
    const years = [...new Set(days.map((day) => day.year))];

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
                            <img
                                src="/app-icons/icon-192.png"
                                alt=""
                                className="size-10 rounded-lg"
                            />
                            <span className="text-lg font-bold">
                                Resultados das Eleições
                            </span>
                        </Link>
                    </div>
                </header>

                <main
                    id="conteudo"
                    tabIndex={-1}
                    className="mx-auto max-w-5xl space-y-10 px-4 py-8 focus:outline-none"
                >
                    <div className="space-y-2">
                        <h1 className="text-3xl font-bold">
                            Qual eleição você quer ver?
                        </h1>
                        <p className="text-lg text-muted">
                            Resultados oficiais do Tribunal Superior Eleitoral
                            (TSE), de todo o Brasil.
                        </p>
                    </div>

                    {days.length === 0 && (
                        <p className="rounded-xl border border-dashed border-line-strong p-10 text-center text-lg text-muted">
                            Ainda não há eleições disponíveis.
                        </p>
                    )}

                    {years.map((year) => (
                        <section
                            key={year}
                            aria-labelledby={`ano-${year}`}
                            className="space-y-4"
                        >
                            <h2
                                id={`ano-${year}`}
                                className="text-2xl font-bold"
                            >
                                Eleições {year}
                            </h2>
                            <ul className="grid gap-4 sm:grid-cols-2">
                                {days
                                    .filter((day) => day.year === year)
                                    .map((day, index) => (
                                        <li
                                            key={day.code}
                                            className="animate-fade-up"
                                            style={{
                                                animationDelay: `${index * 70}ms`,
                                            }}
                                        >
                                            <Link
                                                href={show(day.code)}
                                                className={cn(
                                                    'group flex h-full flex-col gap-3 rounded-2xl border-2 bg-surface p-5 transition duration-200 hover:-translate-y-0.5 hover:border-primary hover:bg-primary-soft hover:shadow-lg',
                                                    day.status === 'apuracao'
                                                        ? 'border-live'
                                                        : 'border-line',
                                                )}
                                            >
                                                <span className="flex flex-wrap items-start justify-between gap-2">
                                                    <span>
                                                        <span className="block text-2xl font-bold">
                                                            {day.round}º turno
                                                        </span>
                                                        <span className="block text-muted">
                                                            {formatLongDate(
                                                                day.date,
                                                            )}
                                                        </span>
                                                    </span>
                                                    <StatusBadge
                                                        status={day.status}
                                                    />
                                                </span>
                                                <span className="text-base">
                                                    <span className="sr-only">
                                                        Cargos:{' '}
                                                    </span>
                                                    {day.offices.join(', ')}
                                                </span>
                                                <span
                                                    aria-hidden="true"
                                                    className="mt-auto font-semibold text-primary transition-transform duration-200 group-hover:translate-x-1"
                                                >
                                                    Ver resultados de{' '}
                                                    {dayLabel(day)} →
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

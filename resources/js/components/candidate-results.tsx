import { useState } from 'react';
import AnimatedNumber from '@/components/animated-number';
import CandidatePhoto from '@/components/candidate-photo';
import { useFlipList } from '@/lib/animation';
import { contrastText, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Candidate, CandidateResult, Winner } from '@/types';

type Props = {
    results: CandidateResult[];
    candidates: Map<number, Candidate>;
    winners: Winner[];
    /** Deputies: hundreds of candidates, seats split by party. */
    proportional?: boolean;
    /** Shows the celebration again; without it the badge is only a label. */
    onCelebrate?: () => void;
};

const PAGE_SIZE = 30;

const badgeClass =
    'shrink-0 animate-badge-in rounded-full bg-success px-2.5 py-0.5 text-xs font-bold text-on-success';

function winnerLabel(winner: Winner): string {
    return winner.status === 'elected' ? 'Eleito' : 'Matematicamente eleito';
}

export default function CandidateResults({
    results,
    candidates,
    winners,
    proportional = false,
    onCelebrate,
}: Props) {
    const [onlyWinners, setOnlyWinners] = useState(true);
    const [visible, setVisible] = useState(PAGE_SIZE);
    const winnersById = new Map(
        winners.map((winner) => [winner.candidate_id, winner]),
    );

    const allRows = results
        .map((result) => ({
            ...result,
            candidate: candidates.get(result.candidate_id),
        }))
        .filter((row) => row.candidate !== undefined);
    const filtered =
        proportional && onlyWinners && winners.length > 0
            ? allRows.filter((row) => winnersById.has(row.candidate_id))
            : allRows;
    const rows = proportional ? filtered.slice(0, visible) : filtered;
    const listRef = useFlipList<HTMLUListElement>(
        rows.map((row) => row.candidate_id).join(),
    );

    const seatsByParty = [
        ...winners
            .reduce((parties, winner) => {
                const party = candidates.get(winner.candidate_id)?.party;

                return party
                    ? parties.set(party, (parties.get(party) ?? 0) + 1)
                    : parties;
            }, new Map<string, number>())
            .entries(),
    ].sort((a, b) => b[1] - a[1]);

    return (
        <div className="space-y-4">
            {proportional ? (
                <div className="flex flex-wrap items-center gap-2">
                    {winners.length > 0 && (
                        <div
                            role="group"
                            aria-label="Mostrar"
                            className="flex rounded-full bg-subtle p-1"
                        >
                            {[
                                [true, `Eleitos (${winners.length})`],
                                [false, `Todos (${allRows.length})`],
                            ].map(([value, label]) => (
                                <button
                                    key={String(value)}
                                    type="button"
                                    aria-pressed={onlyWinners === value}
                                    onClick={() => {
                                        setOnlyWinners(value as boolean);
                                        setVisible(PAGE_SIZE);
                                    }}
                                    className={cn(
                                        'min-h-10 rounded-full px-4 font-semibold',
                                        onlyWinners === value
                                            ? 'bg-surface text-ink shadow-sm'
                                            : 'text-muted hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                    {seatsByParty.length > 0 && (
                        <p className="flex flex-wrap items-center gap-1.5 text-sm text-muted">
                            <span className="font-semibold text-ink">
                                Vagas por partido:
                            </span>
                            {seatsByParty.map(([party, seats]) => (
                                <span
                                    key={party}
                                    className="rounded-full border border-line px-2 py-0.5"
                                >
                                    {party}{' '}
                                    <strong className="text-ink tabular-nums">
                                        {seats}
                                    </strong>
                                </span>
                            ))}
                        </p>
                    )}
                </div>
            ) : (
                <div
                    aria-hidden="true"
                    className="flex h-4 origin-left animate-grow-x overflow-hidden rounded-full bg-track"
                >
                    {rows.map((row) => (
                        <div
                            key={row.candidate_id}
                            className="h-full transition-all duration-700"
                            style={{
                                width: `${row.percent}%`,
                                backgroundColor: row.candidate!.color,
                            }}
                            title={`${row.candidate!.name}: ${formatPercent(row.percent)}`}
                        />
                    ))}
                </div>
            )}

            <ul
                ref={listRef}
                className={cn(
                    'grid gap-3 sm:grid-cols-2',
                    proportional && 'lg:grid-cols-3',
                )}
            >
                {rows.map((row, index) => {
                    const candidate = row.candidate!;
                    const winner = winnersById.get(candidate.id);
                    const numberBadge = (
                        <span
                            className="flex size-11 shrink-0 items-center justify-center rounded-lg text-sm font-bold tabular-nums"
                            style={{
                                backgroundColor: candidate.color,
                                color: contrastText(candidate.color),
                            }}
                        >
                            {candidate.number}
                        </span>
                    );

                    return (
                        <li
                            key={candidate.id}
                            data-flip-key={candidate.id}
                            className="relative animate-fade-up overflow-hidden rounded-xl border border-line bg-surface p-4"
                            style={{
                                // The first ones arrive one after the other.
                                animationDelay: `${Math.min(index, 8) * 45}ms`,
                            }}
                        >
                            <span
                                className="absolute inset-y-0 left-0 w-1.5"
                                style={{ backgroundColor: candidate.color }}
                            />
                            <div className="flex items-start gap-3 pl-2">
                                <CandidatePhoto
                                    key={candidate.photo_url}
                                    candidate={candidate}
                                    fallback={numberBadge}
                                    className="size-11 shrink-0 rounded-lg object-cover object-top"
                                    style={{
                                        boxShadow: `0 0 0 2px ${candidate.color}`,
                                    }}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-lg leading-tight font-bold text-ink">
                                        <span className="sr-only">
                                            {index + 1}º lugar:{' '}
                                        </span>
                                        <span className="min-w-0 break-words">
                                            {candidate.name}
                                        </span>
                                        {winner &&
                                            (onCelebrate ? (
                                                <button
                                                    type="button"
                                                    onClick={onCelebrate}
                                                    title="Comemorar de novo"
                                                    className={cn(
                                                        badgeClass,
                                                        'hover:bg-success-hover',
                                                    )}
                                                >
                                                    🎉 {winnerLabel(winner)}
                                                </button>
                                            ) : (
                                                <span className={badgeClass}>
                                                    {winnerLabel(winner)}
                                                </span>
                                            ))}
                                    </p>
                                    <p className="mt-0.5 text-sm text-muted">
                                        {candidate.party} · número{' '}
                                        {candidate.number}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p
                                        className={
                                            index < 2 && !proportional
                                                ? 'text-2xl font-bold text-ink tabular-nums'
                                                : 'text-lg font-semibold text-ink tabular-nums'
                                        }
                                    >
                                        <AnimatedNumber
                                            value={row.percent}
                                            format={formatPercent}
                                        />
                                    </p>
                                    <p className="text-sm text-muted tabular-nums">
                                        <AnimatedNumber
                                            value={row.votes}
                                            format={(value) =>
                                                formatNumber(Math.round(value))
                                            }
                                        />{' '}
                                        votos
                                    </p>
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>

            {proportional && filtered.length > rows.length && (
                <button
                    type="button"
                    onClick={() => setVisible((count) => count + PAGE_SIZE)}
                    className="min-h-12 w-full rounded-xl border-2 border-line-strong font-semibold text-ink hover:border-primary hover:bg-primary-soft"
                >
                    Mostrar mais ({formatNumber(filtered.length - rows.length)}{' '}
                    restantes)
                </button>
            )}
        </div>
    );
}

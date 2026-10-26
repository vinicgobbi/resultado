import { useState } from 'react';
import CandidatePhoto from '@/components/candidate-photo';
import { contrastText, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Candidate, CandidateResult, Winner } from '@/types';

type Props = {
    results: CandidateResult[];
    candidates: Map<number, Candidate>;
    winners: Winner[];
    /** Deputies: hundreds of candidates, seats split by party. */
    proportional?: boolean;
    onCelebrate?: () => void;
};

const PAGE_SIZE = 30;

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
                        <div className="flex rounded-full bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
                            {[
                                [true, `Eleitos (${winners.length})`],
                                [false, `Todos (${allRows.length})`],
                            ].map(([value, label]) => (
                                <button
                                    key={String(value)}
                                    type="button"
                                    onClick={() => {
                                        setOnlyWinners(value as boolean);
                                        setVisible(PAGE_SIZE);
                                    }}
                                    className={cn(
                                        'rounded-full px-3 py-1 font-medium',
                                        onlyWinners === value
                                            ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-950 dark:text-zinc-50'
                                            : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                    {seatsByParty.length > 0 && (
                        <p className="flex flex-wrap gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            {seatsByParty.map(([party, seats]) => (
                                <span
                                    key={party}
                                    className="rounded-full border border-zinc-200 px-2 py-0.5 dark:border-zinc-700"
                                >
                                    {party}{' '}
                                    <strong className="text-zinc-900 tabular-nums dark:text-zinc-50">
                                        {seats}
                                    </strong>
                                </span>
                            ))}
                        </p>
                    )}
                </div>
            ) : (
                <div className="flex h-4 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
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
                            className="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
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
                                    <p className="flex items-center gap-2 font-semibold text-zinc-900 dark:text-zinc-50">
                                        <span className="truncate">
                                            {candidate.name}
                                        </span>
                                        {winner && (
                                            <button
                                                type="button"
                                                onClick={onCelebrate}
                                                title="Comemorar de novo"
                                                className="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white uppercase hover:bg-emerald-700"
                                            >
                                                🎉{' '}
                                                {winner.status === 'elected'
                                                    ? 'Eleito'
                                                    : 'Matematicamente eleito'}
                                            </button>
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-zinc-500">
                                        {candidate.party} · {candidate.number}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p
                                        className={
                                            index < 2 && !proportional
                                                ? 'text-2xl font-bold text-zinc-900 tabular-nums dark:text-zinc-50'
                                                : 'text-lg font-semibold text-zinc-800 tabular-nums dark:text-zinc-200'
                                        }
                                    >
                                        {formatPercent(row.percent)}
                                    </p>
                                    <p className="text-xs text-zinc-500 tabular-nums">
                                        {formatNumber(row.votes)} votos
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
                    className="w-full rounded-xl border border-zinc-200 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-800/50"
                >
                    Mostrar mais ({formatNumber(filtered.length - rows.length)}{' '}
                    restantes)
                </button>
            )}
        </div>
    );
}

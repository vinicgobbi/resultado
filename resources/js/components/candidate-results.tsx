import { contrastText, formatNumber, formatPercent } from '@/lib/format';
import type { Candidate, CandidateResult, Winner } from '@/types';

type Props = {
    results: CandidateResult[];
    candidates: Map<number, Candidate>;
    winner: Winner | null;
    onCelebrate?: () => void;
};

export default function CandidateResults({
    results,
    candidates,
    winner,
    onCelebrate,
}: Props) {
    const rows = results
        .map((result) => ({
            ...result,
            candidate: candidates.get(result.candidate_id),
        }))
        .filter((row) => row.candidate !== undefined);

    return (
        <div className="space-y-4">
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

            <ul className="grid gap-3 sm:grid-cols-2">
                {rows.map((row, index) => {
                    const candidate = row.candidate!;
                    const isWinner = winner?.candidate_id === candidate.id;

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
                                {candidate.photo_url ? (
                                    <span className="relative shrink-0">
                                        <img
                                            src={candidate.photo_url}
                                            alt=""
                                            className="size-11 rounded-lg object-cover object-top"
                                            style={{
                                                boxShadow: `0 0 0 2px ${candidate.color}`,
                                            }}
                                        />
                                        <span
                                            className="absolute -right-1.5 -bottom-1.5 rounded px-1 text-[10px] font-bold tabular-nums"
                                            style={{
                                                backgroundColor:
                                                    candidate.color,
                                                color: contrastText(
                                                    candidate.color,
                                                ),
                                            }}
                                        >
                                            {candidate.number}
                                        </span>
                                    </span>
                                ) : (
                                    <span
                                        className="flex size-11 shrink-0 items-center justify-center rounded-lg text-lg font-bold tabular-nums"
                                        style={{
                                            backgroundColor: candidate.color,
                                            color: contrastText(
                                                candidate.color,
                                            ),
                                        }}
                                    >
                                        {candidate.number}
                                    </span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="flex items-center gap-2 truncate font-semibold text-zinc-900 dark:text-zinc-50">
                                        {candidate.name}
                                        {isWinner && (
                                            <button
                                                type="button"
                                                onClick={onCelebrate}
                                                title="Comemorar de novo"
                                                className="rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white uppercase hover:bg-emerald-700"
                                            >
                                                🎉{' '}
                                                {winner.status === 'elected'
                                                    ? 'Eleito'
                                                    : 'Matematicamente eleito'}
                                            </button>
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-zinc-500">
                                        {candidate.party}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p
                                        className={
                                            index < 2
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
        </div>
    );
}

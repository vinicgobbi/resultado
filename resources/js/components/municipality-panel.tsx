import { formatDateTime, formatNumber, formatPercent } from '@/lib/format';
import type { MunicipalityDetail } from '@/types';

type Props = {
    detail: MunicipalityDetail | null;
    loading: boolean;
    onClose: () => void;
};

export default function MunicipalityPanel({ detail, loading, onClose }: Props) {
    if (!detail && !loading) {
        return null;
    }

    const candidates = new Map(
        (detail?.candidates ?? []).map((candidate) => [
            candidate.id,
            candidate,
        ]),
    );

    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3 flex items-start justify-between gap-2">
                {loading || !detail ? (
                    <div className="h-6 w-40 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                ) : (
                    <div>
                        <h3 className="font-semibold text-zinc-900 dark:text-zinc-50">
                            {detail.name} – {detail.state}
                            {detail.is_capital && (
                                <span className="ml-2 text-xs font-normal text-zinc-500">
                                    capital
                                </span>
                            )}
                        </h3>
                        {detail.result && (
                            <p className="text-xs text-zinc-500">
                                {formatPercent(detail.result.counted_percent)}{' '}
                                apurado · atualizado{' '}
                                {formatDateTime(detail.result.updated_at)}
                            </p>
                        )}
                    </div>
                )}
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-md px-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    aria-label="Fechar"
                >
                    ✕
                </button>
            </div>

            {loading || !detail ? (
                <div className="space-y-2">
                    <div className="h-4 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                    <div className="h-4 w-2/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                </div>
            ) : !detail.result ? (
                <p className="text-sm text-zinc-500">
                    Nenhuma seção apurada neste município ainda.
                </p>
            ) : (
                <>
                    <ul className="space-y-2">
                        {detail.result.results.map((result) => {
                            const candidate = candidates.get(
                                result.candidate_id,
                            );

                            return (
                                <li key={result.candidate_id}>
                                    <div className="flex justify-between text-sm">
                                        <span className="font-medium">
                                            {candidate?.name}
                                        </span>
                                        <span className="tabular-nums">
                                            {formatPercent(result.percent)}{' '}
                                            <span className="text-xs text-zinc-500">
                                                ({formatNumber(result.votes)})
                                            </span>
                                        </span>
                                    </div>
                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                        <div
                                            className="h-full"
                                            style={{
                                                width: `${result.percent}%`,
                                                backgroundColor:
                                                    candidate?.color,
                                            }}
                                        />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    <dl className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        {[
                            ['Brancos', detail.result.blank_votes],
                            ['Nulos', detail.result.null_votes],
                            ['Abstenções', detail.result.abstentions],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60"
                            >
                                <dt className="text-zinc-500">{label}</dt>
                                <dd className="font-semibold tabular-nums">
                                    {formatNumber(Number(value))}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </>
            )}
        </div>
    );
}

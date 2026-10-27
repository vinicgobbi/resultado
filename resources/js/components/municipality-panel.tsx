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
        <section
            aria-label="Município selecionado no mapa"
            aria-live="polite"
            className="rounded-xl border border-line bg-surface p-4"
        >
            {/* Each municipality slides in, so a new choice is noticed. */}
            <div key={detail?.name ?? 'carregando'} className="animate-fade-up">
                <div className="mb-3 flex items-start justify-between gap-2">
                    {loading || !detail ? (
                        <div className="h-6 w-40 animate-pulse rounded bg-track" />
                    ) : (
                        <div>
                            <h3 className="font-semibold text-ink">
                                {detail.name} – {detail.state}
                                {detail.is_capital && (
                                    <span className="ml-2 text-sm font-normal text-muted">
                                        capital
                                    </span>
                                )}
                            </h3>
                            {detail.result && (
                                <p className="text-sm text-muted">
                                    {formatPercent(
                                        detail.result.counted_percent,
                                    )}{' '}
                                    apurado · atualizado{' '}
                                    {formatDateTime(detail.result.updated_at)}
                                </p>
                            )}
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md px-2 text-muted hover:bg-subtle"
                        aria-label="Fechar"
                    >
                        ✕
                    </button>
                </div>

                {loading || !detail ? (
                    <div className="space-y-2">
                        <div className="h-4 animate-pulse rounded bg-track" />
                        <div className="h-4 w-2/3 animate-pulse rounded bg-track" />
                    </div>
                ) : !detail.result ? (
                    <p className="text-sm text-muted">
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
                                                <span className="text-sm text-muted">
                                                    (
                                                    {formatNumber(result.votes)}
                                                    )
                                                </span>
                                            </span>
                                        </div>
                                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-track">
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
                        <dl className="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                            {[
                                ['Brancos', detail.result.blank_votes],
                                ['Nulos', detail.result.null_votes],
                                ['Abstenções', detail.result.abstentions],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-lg bg-subtle p-2"
                                >
                                    <dt className="text-muted">{label}</dt>
                                    <dd className="font-semibold tabular-nums">
                                        {formatNumber(Number(value))}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </>
                )}
            </div>
        </section>
    );
}

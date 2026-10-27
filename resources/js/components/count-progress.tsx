import AnimatedNumber from '@/components/animated-number';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/format';
import type { ResultSummary } from '@/types';

type Props = {
    summary: ResultSummary;
    /** The count is followed live: the page updates by itself. */
    live: boolean;
    /** Seconds between automatic updates. */
    updateEvery: number;
};

/**
 * How much of the count is done, in words and as a bar.
 */
export default function CountProgress({ summary, live, updateEvery }: Props) {
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                <div>
                    <h2 className="text-lg font-semibold">
                        Andamento da apuração
                    </h2>
                    <p className="text-4xl font-bold tabular-nums">
                        <AnimatedNumber
                            value={summary.counted_percent}
                            format={formatPercent}
                        />
                    </p>
                    <p className="text-muted">
                        das urnas apuradas
                        {summary.sections_total > 0 &&
                            ` (${formatNumber(summary.sections_counted)} de ${formatNumber(summary.sections_total)})`}
                    </p>
                </div>
                <div className="space-y-1 sm:text-right">
                    {summary.is_final ? (
                        <p className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-3 py-1 font-semibold text-success">
                            <span aria-hidden="true">✔️</span>
                            Apuração encerrada
                        </p>
                    ) : live ? (
                        <p className="inline-flex items-center gap-2 rounded-full bg-live-soft px-3 py-1 font-semibold text-live">
                            <span
                                aria-hidden="true"
                                className="relative flex size-2.5"
                            >
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-live opacity-75" />
                                <span className="relative inline-flex size-2.5 rounded-full bg-live" />
                            </span>
                            Ao vivo · atualiza sozinho a cada {updateEvery}{' '}
                            segundos
                        </p>
                    ) : (
                        <p className="inline-flex rounded-full bg-warning-soft px-3 py-1 font-semibold text-warning">
                            Resultado parcial
                        </p>
                    )}
                    <p className="text-sm text-muted">
                        Atualizado pelo TSE em{' '}
                        {formatDateTime(summary.updated_at)}
                    </p>
                </div>
            </div>
            <div
                role="progressbar"
                aria-label="Urnas apuradas"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={summary.counted_percent}
                aria-valuetext={`${formatPercent(summary.counted_percent)} das urnas apuradas`}
                className="h-3 overflow-hidden rounded-full bg-track"
            >
                <div
                    className="h-full origin-left animate-grow-x rounded-full bg-success transition-all duration-700"
                    style={{ width: `${summary.counted_percent}%` }}
                />
            </div>
        </div>
    );
}

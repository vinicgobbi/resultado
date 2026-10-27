import AnimatedNumber from '@/components/animated-number';
import { formatNumber, formatPercent } from '@/lib/format';
import type { ResultSummary } from '@/types';

/**
 * Share of a total, or nothing when the total is unknown.
 */
function share(part: number, total: number): string | null {
    return total > 0 ? formatPercent((part / total) * 100) : null;
}

/**
 * Who voted and how, each number explained in plain words.
 */
export default function VoteBreakdown({ summary }: { summary: ResultSummary }) {
    const items = [
        {
            label: 'Podiam votar',
            value: summary.electorate,
            share: null,
            help: 'Eleitores aptos a votar',
        },
        {
            label: 'Foram votar',
            value: summary.turnout,
            share: share(summary.turnout, summary.electorate),
            help: 'dos eleitores',
        },
        {
            label: 'Não foram votar',
            value: summary.abstentions,
            share: share(summary.abstentions, summary.electorate),
            help: 'dos eleitores (abstenção)',
        },
        {
            label: 'Votos válidos',
            value: summary.valid_votes,
            share: share(summary.valid_votes, summary.turnout),
            help: 'dos votos, dados a candidatos. São eles que decidem a eleição',
        },
        {
            label: 'Votos em branco',
            value: summary.blank_votes,
            share: share(summary.blank_votes, summary.turnout),
            help: 'dos votos. Não contam para nenhum candidato',
        },
        {
            label: 'Votos nulos',
            value: summary.null_votes,
            share: share(summary.null_votes, summary.turnout),
            help: 'dos votos. Não contam para nenhum candidato',
        },
    ];

    return (
        <section aria-labelledby="quem-votou" className="space-y-3">
            <h2 id="quem-votou" className="text-xl font-bold">
                Quem votou e como
            </h2>
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {items.map((item, index) => (
                    <div
                        key={item.label}
                        className="animate-fade-up rounded-xl border border-line bg-surface px-4 py-3"
                        style={{ animationDelay: `${index * 50}ms` }}
                    >
                        <dt className="font-semibold">{item.label}</dt>
                        <dd className="text-2xl font-bold tabular-nums">
                            <AnimatedNumber
                                value={item.value}
                                format={(value) =>
                                    formatNumber(Math.round(value))
                                }
                            />
                        </dd>
                        <dd className="text-sm text-muted">
                            {item.share && (
                                <strong className="font-semibold text-ink tabular-nums">
                                    {item.share}{' '}
                                </strong>
                            )}
                            {item.help}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

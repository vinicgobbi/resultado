import { formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Candidate, StateResult } from '@/types';

type Props = {
    states: StateResult[];
    candidates: Map<number, Candidate>;
    selectedState: string;
    onSelect: (code: string) => void;
};

export default function StatesTable({
    states,
    candidates,
    selectedState,
    onSelect,
}: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-surface">
            <table className="w-full text-sm">
                <caption className="sr-only">
                    Quem lidera em cada estado e quanto já foi apurado
                </caption>
                <thead className="bg-subtle text-left text-xs text-muted uppercase">
                    <tr>
                        <th className="px-3 py-2 font-medium">UF</th>
                        <th className="px-3 py-2 font-medium">Votação</th>
                        <th className="px-3 py-2 text-right font-medium">
                            Apurado
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {states.map((state) => {
                        const leader =
                            state.leader_id !== null
                                ? candidates.get(state.leader_id)
                                : undefined;
                        const leaderResult = state.results[0];
                        const selectable = state.code !== 'ZZ';

                        return (
                            <tr
                                key={state.code}
                                onClick={() =>
                                    selectable &&
                                    onSelect(
                                        selectedState === state.code
                                            ? ''
                                            : state.code,
                                    )
                                }
                                className={cn(
                                    selectable &&
                                        'cursor-pointer hover:bg-subtle',
                                    selectedState === state.code && 'bg-subtle',
                                )}
                                title={state.name}
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-semibold text-ink"
                                >
                                    {selectable ? (
                                        <button
                                            type="button"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                onSelect(
                                                    selectedState === state.code
                                                        ? ''
                                                        : state.code,
                                                );
                                            }}
                                            aria-pressed={
                                                selectedState === state.code
                                            }
                                            aria-label={`Ver ${state.name} no mapa`}
                                            className="rounded px-1 underline-offset-2 hover:underline"
                                        >
                                            {state.code}
                                        </button>
                                    ) : (
                                        'Exterior'
                                    )}
                                </th>
                                <td className="w-full px-3 py-2">
                                    <div className="flex items-center gap-2">
                                        <div className="flex h-2 flex-1 overflow-hidden rounded-full bg-track">
                                            {state.results.map((result) => (
                                                <div
                                                    key={result.candidate_id}
                                                    style={{
                                                        width: `${result.percent}%`,
                                                        backgroundColor:
                                                            candidates.get(
                                                                result.candidate_id,
                                                            )?.color,
                                                    }}
                                                />
                                            ))}
                                        </div>
                                        <span className="w-28 truncate text-xs text-muted">
                                            {leader && leaderResult
                                                ? `${leader.name} ${formatPercent(leaderResult.percent)}`
                                                : '—'}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-right text-xs text-muted tabular-nums">
                                    {formatPercent(state.counted_percent)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

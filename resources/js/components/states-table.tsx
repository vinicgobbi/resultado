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
        <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table className="w-full text-sm">
                <thead className="bg-zinc-50 text-left text-xs text-zinc-500 uppercase dark:bg-zinc-800/50">
                    <tr>
                        <th className="px-3 py-2 font-medium">UF</th>
                        <th className="px-3 py-2 font-medium">Votação</th>
                        <th className="px-3 py-2 text-right font-medium">
                            Apurado
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
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
                                        'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
                                    selectedState === state.code &&
                                        'bg-zinc-100 dark:bg-zinc-800',
                                )}
                                title={state.name}
                            >
                                <td className="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {state.code === 'ZZ'
                                        ? 'Exterior'
                                        : state.code}
                                </td>
                                <td className="w-full px-3 py-2">
                                    <div className="flex items-center gap-2">
                                        <div className="flex h-2 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
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
                                        <span className="w-28 truncate text-xs text-zinc-600 dark:text-zinc-400">
                                            {leader && leaderResult
                                                ? `${leader.name} ${formatPercent(leaderResult.percent)}`
                                                : '—'}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-right text-xs text-zinc-500 tabular-nums">
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

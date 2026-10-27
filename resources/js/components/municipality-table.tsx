import { useState } from 'react';
import { formatNumber, formatPercent } from '@/lib/format';
import { normalized } from '@/lib/utils';
import type { Candidate, MapGeometry, MapResults } from '@/types';

type Props = {
    names: MapGeometry['names'];
    results: MapResults | undefined;
    candidates: Map<number, Candidate>;
    /** Only the municipalities of this state, when chosen. */
    selectedState: string;
    onSelect: (ibge: string) => void;
};

const PAGE_SIZE = 50;

const collator = new Intl.Collator('pt-BR');

/**
 * The map as a table: who leads in each municipality, searchable, for
 * those who cannot see or tell apart its colors.
 */
export default function MunicipalityTable({
    names,
    results,
    candidates,
    selectedState,
    onSelect,
}: Props) {
    const [search, setSearch] = useState('');
    const [visible, setVisible] = useState(PAGE_SIZE);
    const query = normalized(search.trim());
    const rows = Object.entries(names)
        .filter(
            ([, [name, state]]) =>
                (!selectedState || state === selectedState) &&
                (!query || normalized(name).includes(query)),
        )
        .sort(([, a], [, b]) => collator.compare(a[0], b[0]));
    const shown = rows.slice(0, visible);

    return (
        <div className="space-y-3">
            <div>
                <label htmlFor="busca-municipio" className="font-semibold">
                    Buscar município
                </label>
                <input
                    id="busca-municipio"
                    type="search"
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setVisible(PAGE_SIZE);
                    }}
                    placeholder="Digite o nome da cidade"
                    className="mt-1 min-h-11 w-full rounded-xl border-2 border-line-strong bg-surface px-3"
                />
                <p className="mt-1 text-sm text-muted" aria-live="polite">
                    {formatNumber(rows.length)}{' '}
                    {rows.length === 1
                        ? 'município encontrado'
                        : 'municípios encontrados'}
                </p>
            </div>

            <div className="overflow-x-auto rounded-xl border border-line">
                <table className="w-full text-left">
                    <caption className="sr-only">
                        Quem lidera em cada município. Escolha um município para
                        ver o resultado completo.
                    </caption>
                    <thead className="bg-subtle text-sm text-muted">
                        <tr>
                            <th scope="col" className="px-3 py-2 font-medium">
                                Município
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                Quem lidera
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                Apurado
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {shown.map(([ibge, [name, state]]) => {
                            const result = results?.[ibge];
                            const leader =
                                result?.[0] != null
                                    ? candidates.get(result[0])
                                    : undefined;

                            return (
                                <tr key={ibge}>
                                    <th scope="row" className="px-3 py-2">
                                        <button
                                            type="button"
                                            onClick={() => onSelect(ibge)}
                                            className="text-left font-semibold text-primary underline underline-offset-2"
                                        >
                                            {name}
                                            {!selectedState && ` – ${state}`}
                                        </button>
                                    </th>
                                    <td className="px-3 py-2">
                                        {leader && result ? (
                                            <span className="flex items-center gap-2">
                                                <span
                                                    aria-hidden="true"
                                                    className="size-3 shrink-0 rounded-sm"
                                                    style={{
                                                        backgroundColor:
                                                            leader.color,
                                                    }}
                                                />
                                                {leader.name}{' '}
                                                <span className="tabular-nums">
                                                    {formatPercent(result[1])}
                                                </span>
                                            </span>
                                        ) : (
                                            <span className="text-muted">
                                                Sem votos apurados
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right text-muted tabular-nums">
                                        {result
                                            ? formatPercent(result[2])
                                            : '—'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {rows.length > shown.length && (
                <button
                    type="button"
                    onClick={() => setVisible((count) => count + PAGE_SIZE)}
                    className="min-h-12 w-full rounded-xl border-2 border-line-strong font-semibold hover:border-primary hover:bg-primary-soft"
                >
                    Mostrar mais ({formatNumber(rows.length - shown.length)}{' '}
                    restantes)
                </button>
            )}
        </div>
    );
}

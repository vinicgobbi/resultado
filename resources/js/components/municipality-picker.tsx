import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { formatPercent } from '@/lib/format';
import type { MunicipalityOption } from '@/types';

type Props = {
    municipalities: MunicipalityOption[];
    /** Page of a municipality. */
    href: (code: string) => InertiaLinkProps['href'];
};

/**
 * Lowercase without accents, so "sao" finds "SÃO PAULO".
 */
function normalized(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/**
 * Searchable list of the municipalities of a state.
 */
export default function MunicipalityPicker({ municipalities, href }: Props) {
    const [search, setSearch] = useState('');
    const query = normalized(search.trim());
    const matches = query
        ? municipalities.filter((municipality) =>
              normalized(municipality.name).includes(query),
          )
        : municipalities;

    return (
        <div className="space-y-3">
            <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar município"
                aria-label="Buscar município"
                className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm sm:max-w-sm dark:border-zinc-700 dark:bg-zinc-900"
            />
            {matches.length === 0 ? (
                <p className="text-sm text-zinc-500">
                    Nenhum município encontrado.
                </p>
            ) : (
                <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {matches.map((municipality) => (
                        <li key={municipality.code}>
                            <Link
                                href={href(municipality.code)}
                                className="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-800 hover:border-emerald-600 hover:bg-emerald-50 dark:border-zinc-800 dark:text-zinc-200 dark:hover:border-emerald-500 dark:hover:bg-emerald-950/40"
                            >
                                <span className="truncate">
                                    {municipality.name}
                                </span>
                                {!municipality.is_final && (
                                    <span className="shrink-0 text-xs text-zinc-500 tabular-nums">
                                        {formatPercent(
                                            municipality.counted_percent,
                                        )}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

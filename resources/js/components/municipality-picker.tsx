import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { formatPercent } from '@/lib/format';
import { normalized } from '@/lib/utils';
import type { MunicipalityOption } from '@/types';

type Props = {
    municipalities: MunicipalityOption[];
    /** Page of a municipality. */
    href: (code: string) => InertiaLinkProps['href'];
};

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
                className="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm sm:max-w-sm"
            />
            {matches.length === 0 ? (
                <p className="text-sm text-muted">
                    Nenhum município encontrado.
                </p>
            ) : (
                <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {matches.map((municipality) => (
                        <li key={municipality.code}>
                            <Link
                                href={href(municipality.code)}
                                viewTransition
                                className="flex items-center justify-between gap-3 rounded-xl border border-line px-3 py-2.5 text-sm font-medium text-ink transition-colors hover:border-primary hover:bg-primary-soft"
                            >
                                <span className="truncate">
                                    {municipality.name}
                                </span>
                                {!municipality.is_final && (
                                    <span className="shrink-0 text-sm text-muted tabular-nums">
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

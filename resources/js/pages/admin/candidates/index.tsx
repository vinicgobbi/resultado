import { Form, Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { contrastText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, update } from '@/routes/admin/candidates';
import type { Candidate, OfficeOption } from '@/types';

type Props = {
    election: number | null;
    elections: OfficeOption[];
    office: string;
    offices: OfficeOption[];
    state: string | null;
    states: OfficeOption[];
    /** TSE code of the municipality, for municipal offices. */
    municipality: string | null;
    municipalities: OfficeOption[];
    candidates: (Candidate & {
        custom_color: string | null;
        party_color: string | null;
    })[];
};

export default function CandidatesIndex({
    election,
    elections,
    office,
    offices,
    state,
    states,
    municipality,
    municipalities,
    candidates,
}: Props) {
    /**
     * Page of a filter, keeping the others when they still apply.
     */
    function filterHref(changes: {
        eleicao?: number;
        cargo?: string;
        uf?: string;
        municipio?: string;
    }) {
        return index({
            query: {
                ...(election && { eleicao: election }),
                cargo: office,
                ...(state && { uf: state }),
                ...(municipality && { municipio: municipality }),
                ...changes,
            },
        });
    }

    return (
        <AdminLayout title="Cores dos candidatos">
            <Head title="Candidatos" />

            <p className="text-sm text-zinc-500">
                As cores são usadas nos gráficos e no mapa de municípios. Sem
                uma cor escolhida aqui, o candidato usa a cor aproximada do
                partido (config/partidos.php) ou uma cor automática. Candidatos
                aparecem aqui após a primeira sincronização com o TSE (
                <code>php artisan tse:sincronizar</code>).
            </p>

            <div className="flex flex-wrap items-center gap-2">
                {elections.length > 1 && (
                    <select
                        value={election ?? ''}
                        onChange={(event) =>
                            router.visit(
                                index({
                                    query: { eleicao: event.target.value },
                                }),
                                { preserveScroll: true },
                            )
                        }
                        aria-label="Eleição"
                        className="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {elections.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
                {offices.map((option) => (
                    <Link
                        key={option.value}
                        href={filterHref({
                            cargo: option.value,
                            municipio: undefined,
                        })}
                        preserveScroll
                        className={cn(
                            'rounded-lg px-3 py-1.5 text-sm font-medium',
                            option.value === office
                                ? 'bg-emerald-600 text-white'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800',
                        )}
                    >
                        {option.label}
                    </Link>
                ))}
                {states.length > 0 && (
                    <select
                        value={state ?? ''}
                        onChange={(event) =>
                            router.visit(
                                filterHref({
                                    uf: event.target.value,
                                    municipio: undefined,
                                }),
                                { preserveScroll: true },
                            )
                        }
                        aria-label="Estado"
                        className="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {states.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
                {municipalities.length > 0 && (
                    <select
                        value={municipality ?? ''}
                        onChange={(event) =>
                            router.visit(
                                filterHref({ municipio: event.target.value }),
                                { preserveScroll: true },
                            )
                        }
                        aria-label="Município"
                        className="max-w-56 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {municipalities.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            {candidates.length === 0 ? (
                <div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    Nenhum candidato sincronizado ainda.
                </div>
            ) : (
                <ul className="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                    {candidates.map((candidate) => (
                        <li key={candidate.id}>
                            <Form
                                {...update.form(candidate.id)}
                                options={{ preserveScroll: true }}
                                className="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <span
                                            className="flex size-10 shrink-0 items-center justify-center rounded-lg font-bold"
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
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium text-zinc-900 dark:text-zinc-50">
                                                {candidate.name}
                                            </p>
                                            <p className="truncate text-xs text-zinc-500">
                                                {candidate.full_name} ·{' '}
                                                {candidate.party}
                                                {!candidate.custom_color &&
                                                    (candidate.party_color
                                                        ? ' · cor do partido'
                                                        : ' · cor automática')}
                                            </p>
                                            {errors.color && (
                                                <p className="text-xs text-red-600">
                                                    {errors.color}
                                                </p>
                                            )}
                                        </div>
                                        <input
                                            type="color"
                                            name="color"
                                            defaultValue={candidate.color}
                                            className="h-10 w-14 cursor-pointer rounded border border-zinc-300 bg-transparent dark:border-zinc-700"
                                            aria-label={`Cor de ${candidate.name}`}
                                        />
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                                        >
                                            Salvar
                                        </button>
                                    </>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}

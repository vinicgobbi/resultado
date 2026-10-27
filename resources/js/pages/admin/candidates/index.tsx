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

            <p className="text-sm text-muted">
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
                        className="rounded-lg border border-line-strong bg-surface px-2 py-1.5 text-sm"
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
                                ? 'bg-primary text-on-primary'
                                : 'text-muted hover:bg-subtle',
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
                        className="rounded-lg border border-line-strong bg-surface px-2 py-1.5 text-sm"
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
                        className="max-w-56 rounded-lg border border-line-strong bg-surface px-2 py-1.5 text-sm"
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
                <div className="rounded-xl border border-dashed border-line-strong p-8 text-center text-sm text-muted">
                    Nenhum candidato sincronizado ainda.
                </div>
            ) : (
                <ul className="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
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
                                            <p className="font-medium text-ink">
                                                {candidate.name}
                                            </p>
                                            <p className="truncate text-xs text-muted">
                                                {candidate.full_name} ·{' '}
                                                {candidate.party}
                                                {!candidate.custom_color &&
                                                    (candidate.party_color
                                                        ? ' · cor do partido'
                                                        : ' · cor automática')}
                                            </p>
                                            {errors.color && (
                                                <p className="text-xs text-live">
                                                    {errors.color}
                                                </p>
                                            )}
                                        </div>
                                        <input
                                            type="color"
                                            name="color"
                                            defaultValue={candidate.color}
                                            className="h-10 w-14 cursor-pointer rounded border border-line-strong bg-transparent"
                                            aria-label={`Cor de ${candidate.name}`}
                                        />
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-on-primary hover:bg-primary-hover disabled:opacity-50"
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

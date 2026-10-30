import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/admin-layout';
import { contrastText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, update } from '@/routes/admin/candidates';
import type { Candidate, OfficeOption } from '@/types';

const selectClass =
    'min-h-11 w-full rounded-lg border border-line-strong bg-surface px-2 text-sm sm:w-auto sm:max-w-64';

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
    candidates: AdminCandidate[];
};

type AdminCandidate = Candidate & {
    /** Ballot name published by the TSE. */
    tse_name: string;
    /** Corrected here, shown instead of the TSE one. */
    custom_name: string | null;
    custom_color: string | null;
    party_color: string | null;
    has_custom_photo: boolean;
};

const inputClass =
    'min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm';

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

            <div className="space-y-2">
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
                        className={selectClass}
                    >
                        {elections.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
                {/* Offices scroll sideways on narrow screens. */}
                <nav
                    aria-label="Cargo"
                    className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0"
                >
                    {offices.map((option) => (
                        <Link
                            key={option.value}
                            href={filterHref({
                                cargo: option.value,
                                municipio: undefined,
                            })}
                            preserveScroll
                            className={cn(
                                'flex min-h-10 shrink-0 items-center rounded-lg px-3 text-sm font-medium whitespace-nowrap',
                                option.value === office
                                    ? 'bg-primary text-on-primary'
                                    : 'text-muted hover:bg-subtle',
                            )}
                        >
                            {option.label}
                        </Link>
                    ))}
                </nav>
                <div className="grid gap-2 sm:flex sm:flex-wrap">
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
                            className={selectClass}
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
                                    filterHref({
                                        municipio: event.target.value,
                                    }),
                                    { preserveScroll: true },
                                )
                            }
                            aria-label="Município"
                            className={selectClass}
                        >
                            {municipalities.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
            </div>

            {candidates.length === 0 ? (
                <div className="rounded-xl border border-dashed border-line-strong p-8 text-center text-sm text-muted">
                    Nenhum candidato sincronizado ainda.
                </div>
            ) : (
                <ul className="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                    {candidates.map((candidate) => (
                        <CandidateRow
                            key={candidate.id}
                            candidate={candidate}
                        />
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}

/**
 * A candidate as shown on the site, with a form to correct the name, send
 * a photo and choose the color.
 */
function CandidateRow({ candidate }: { candidate: AdminCandidate }) {
    const [automaticColor, setAutomaticColor] = useState(
        !candidate.custom_color,
    );

    return (
        <li>
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center gap-3 px-4 py-3 hover:bg-subtle [&::-webkit-details-marker]:hidden">
                    <img
                        src={candidate.photo_url}
                        alt=""
                        loading="lazy"
                        className="size-12 shrink-0 rounded-lg bg-track object-cover"
                    />
                    <span
                        className="flex size-10 shrink-0 items-center justify-center rounded-lg font-bold"
                        style={{
                            backgroundColor: candidate.color,
                            color: contrastText(candidate.color),
                        }}
                    >
                        {candidate.number}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block font-medium text-ink">
                            {candidate.name}
                        </span>
                        <span className="block truncate text-xs text-muted">
                            {candidate.full_name} · {candidate.party}
                            {candidate.custom_name &&
                                ` · nome no TSE: ${candidate.tse_name}`}
                            {candidate.has_custom_photo && ' · foto enviada'}
                        </span>
                    </span>
                    <span className="shrink-0 text-sm font-semibold text-primary">
                        Editar{' '}
                        <span
                            aria-hidden="true"
                            className="inline-block transition-transform group-open:rotate-180"
                        >
                            ▾
                        </span>
                    </span>
                </summary>

                <Form
                    {...update.form(candidate.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['photo', 'remove_photo']}
                    className="grid gap-4 border-t border-line bg-subtle/50 px-4 py-4 sm:grid-cols-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium">
                                    Nome de urna
                                </span>
                                <input
                                    name="ballot_name"
                                    defaultValue={candidate.custom_name ?? ''}
                                    placeholder={candidate.tse_name}
                                    maxLength={60}
                                    className={inputClass}
                                />
                                <span className="mt-1 block text-xs text-muted">
                                    Vazio: o nome publicado pelo TSE.
                                </span>
                                {errors.ballot_name && (
                                    <span className="mt-1 block text-xs text-live">
                                        {errors.ballot_name}
                                    </span>
                                )}
                            </label>

                            <div className="space-y-1 text-sm">
                                <label className="block">
                                    <span className="mb-1 block font-medium">
                                        Foto
                                    </span>
                                    <input
                                        type="file"
                                        name="photo"
                                        accept="image/jpeg,image/png,image/webp"
                                        className="block w-full text-sm file:mr-3 file:min-h-10 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:font-semibold file:text-primary"
                                    />
                                </label>
                                <span className="block text-xs text-muted">
                                    JPEG, PNG ou WebP, até 2 MB. Aparece no
                                    lugar da foto do TSE.
                                </span>
                                {candidate.has_custom_photo && (
                                    <label className="flex min-h-10 cursor-pointer items-center gap-2">
                                        <input
                                            type="checkbox"
                                            name="remove_photo"
                                            value="1"
                                            className="size-5 accent-primary"
                                        />
                                        Voltar à foto do TSE
                                    </label>
                                )}
                                {errors.photo && (
                                    <span className="block text-xs text-live">
                                        {errors.photo}
                                    </span>
                                )}
                            </div>

                            <div className="space-y-1 text-sm">
                                <span className="block font-medium">Cor</span>
                                <div className="flex items-center gap-3">
                                    {/* Disabled, it is not sent: the automatic color. */}
                                    <input
                                        type="color"
                                        name="color"
                                        defaultValue={candidate.color}
                                        disabled={automaticColor}
                                        aria-label={`Cor de ${candidate.name}`}
                                        className="h-11 w-16 shrink-0 cursor-pointer rounded border border-line-strong bg-transparent disabled:cursor-not-allowed disabled:opacity-40"
                                    />
                                    <label className="flex min-h-10 cursor-pointer items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={automaticColor}
                                            onChange={(event) =>
                                                setAutomaticColor(
                                                    event.target.checked,
                                                )
                                            }
                                            className="size-5 accent-primary"
                                        />
                                        Automática
                                    </label>
                                </div>
                                <span className="block text-xs text-muted">
                                    Automática: a cor do partido
                                    {candidate.party_color
                                        ? ''
                                        : ' (sem cor definida, uma da paleta)'}
                                    .
                                </span>
                                {errors.color && (
                                    <span className="block text-xs text-live">
                                        {errors.color}
                                    </span>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="min-h-11 rounded-xl bg-primary px-5 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50 sm:col-span-3 sm:justify-self-start"
                            >
                                Salvar
                            </button>
                        </>
                    )}
                </Form>
            </details>
        </li>
    );
}

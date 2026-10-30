import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/admin-layout';
import { contrastText } from '@/lib/format';
import { normalized } from '@/lib/utils';
import { update } from '@/routes/admin/parties';

type Party = {
    party: string;
    candidates: number;
    /** From config/partidos.php. */
    default_color: string | null;
    /** Chosen here, over the default. */
    custom_color: string | null;
};

type Props = {
    parties: Party[];
};

/** Shown in the color field of a party without any color. */
const NEUTRAL = '#8c8777';

/**
 * Colors of the parties: candidates without a color of their own are
 * shown with the color of their party.
 */
export default function PartiesIndex({ parties }: Props) {
    const [search, setSearch] = useState('');
    const query = normalized(search.trim());
    const shown = query
        ? parties.filter((party) => normalized(party.party).includes(query))
        : parties;

    return (
        <AdminLayout title="Cores dos partidos">
            <Head title="Partidos" />

            <p className="text-sm text-muted">
                Candidatos sem uma cor escolhida aparecem com a cor do partido,
                nos gráficos e no mapa. A cor padrão vem de config/partidos.php;
                a escolhida aqui vale por cima dela, em todas as eleições.
            </p>

            <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar partido"
                aria-label="Buscar partido"
                className="min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 text-sm sm:max-w-xs"
            />

            {parties.length === 0 ? (
                <p className="rounded-2xl border border-dashed border-line-strong p-6 text-center text-muted">
                    Nenhum candidato sincronizado ainda.
                </p>
            ) : (
                <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
                    {shown.map((party) => {
                        const color =
                            party.custom_color ??
                            party.default_color ??
                            NEUTRAL;

                        return (
                            <li key={party.party}>
                                <Form
                                    {...update.form()}
                                    options={{ preserveScroll: true }}
                                    className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-3 px-4 py-3 sm:flex"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="party"
                                                value={party.party}
                                            />
                                            <span
                                                aria-hidden="true"
                                                className="flex size-10 items-center justify-center rounded-lg text-xs font-bold"
                                                style={{
                                                    backgroundColor: color,
                                                    color: contrastText(color),
                                                }}
                                            >
                                                {party.party.slice(0, 4)}
                                            </span>
                                            <div className="min-w-0 sm:flex-1">
                                                <p className="font-semibold">
                                                    {party.party}
                                                </p>
                                                <p className="text-xs text-muted">
                                                    {party.candidates.toLocaleString(
                                                        'pt-BR',
                                                    )}{' '}
                                                    candidatos ·{' '}
                                                    {party.custom_color
                                                        ? 'cor escolhida aqui'
                                                        : party.default_color
                                                          ? 'cor padrão'
                                                          : 'sem cor (usa a automática)'}
                                                </p>
                                                {errors.color && (
                                                    <p className="text-xs text-live">
                                                        {errors.color}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="col-span-2 flex items-center gap-2 sm:col-span-1">
                                                <input
                                                    type="color"
                                                    name="color"
                                                    defaultValue={color}
                                                    aria-label={`Cor do ${party.party}`}
                                                    className="h-11 w-16 shrink-0 cursor-pointer rounded border border-line-strong bg-transparent"
                                                />
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="min-h-11 flex-1 rounded-lg bg-primary px-4 text-sm font-medium text-on-primary hover:bg-primary-hover disabled:opacity-50 sm:flex-none"
                                                >
                                                    Salvar
                                                </button>
                                                {party.custom_color && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.put(
                                                                update.url(),
                                                                {
                                                                    party: party.party,
                                                                    color: null,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        disabled={processing}
                                                        className="min-h-11 rounded-lg border-2 border-line-strong px-3 text-sm font-medium hover:bg-subtle disabled:opacity-50"
                                                    >
                                                        Voltar ao padrão
                                                    </button>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </li>
                        );
                    })}
                </ul>
            )}
            {parties.length > 0 && shown.length === 0 && (
                <p className="text-sm text-muted">Nenhum partido encontrado.</p>
            )}
        </AdminLayout>
    );
}

import { Form, Head, Link, router } from '@inertiajs/react';
import StatusBadge from '@/components/status-badge';
import AdminLayout from '@/layouts/admin-layout';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { feature, reopen, update } from '@/routes/admin/elections';
import type { ElectionStatus } from '@/types';

type CycleElection = {
    code: number;
    name: string;
    round: number;
    date: string;
    status: ElectionStatus;
    completed_at: string | null;
};

type Cycle = {
    cycle: string;
    year: number;
    display_name: string | null;
    /** As shown on the site. */
    name: string;
    is_hidden: boolean;
    is_featured: boolean;
    elections: CycleElection[];
};

type Props = {
    cycles: Cycle[];
};

const inputClass =
    'min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm';

/**
 * The election years as the public sees them: name, archive and home page,
 * and the elections the TSE publishes for each one.
 */
export default function ElectionsIndex({ cycles }: Props) {
    const featured = cycles.find((cycle) => cycle.is_featured)?.cycle ?? '';

    function chooseFeatured(cycle: string) {
        router.post(feature.url(), { cycle }, { preserveScroll: true });
    }

    return (
        <AdminLayout title="Eleições">
            <Head title="Eleições" />

            <p className="text-sm text-muted">
                No site, cada ano aparece como uma eleição só, com seus turnos.
                O nome, o arquivo e a página inicial valem para o ano inteiro; a
                sincronização é de cada eleição publicada pelo TSE.
            </p>

            <fieldset className="space-y-2 rounded-2xl border border-line bg-surface p-4">
                <legend className="sr-only">Página inicial</legend>
                <h2 aria-hidden="true" className="font-semibold">
                    Página inicial
                </h2>
                <p className="text-sm text-muted">
                    Qual eleição aparece para quem abre o site.
                </p>
                <div className="grid gap-2 sm:grid-cols-2">
                    {[
                        {
                            value: '',
                            label: 'Automática',
                            help: 'A eleição em apuração; senão, a mais recente.',
                        },
                        ...cycles
                            .filter((cycle) => !cycle.is_hidden)
                            .map((cycle) => ({
                                value: cycle.cycle,
                                label: cycle.name,
                                help: 'O turno em apuração ou o mais recente dela.',
                            })),
                    ].map((option) => (
                        <label
                            key={option.value || 'automatica'}
                            className="flex cursor-pointer items-start gap-3 rounded-xl border-2 border-line p-3 hover:border-primary has-checked:border-primary has-checked:bg-primary-soft"
                        >
                            <input
                                type="radio"
                                name="featured"
                                value={option.value}
                                checked={featured === option.value}
                                onChange={() => chooseFeatured(option.value)}
                                className="mt-1 size-5 shrink-0 accent-primary"
                            />
                            <span>
                                <span className="block font-semibold">
                                    {option.label}
                                </span>
                                <span className="block text-sm text-muted">
                                    {option.help}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>

            {cycles.length === 0 && (
                <p className="rounded-2xl border border-dashed border-line-strong p-6 text-center text-muted">
                    Nenhuma eleição cadastrada ainda.
                </p>
            )}

            {cycles.map((cycle) => (
                <section
                    key={cycle.cycle}
                    aria-labelledby={`ciclo-${cycle.cycle}`}
                    className={cn(
                        'space-y-4 rounded-2xl border bg-surface p-4',
                        cycle.is_hidden
                            ? 'border-dashed border-line-strong'
                            : 'border-line',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2
                            id={`ciclo-${cycle.cycle}`}
                            className="text-lg font-bold"
                        >
                            {cycle.name}
                        </h2>
                        {cycle.is_featured && (
                            <span className="rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-bold text-primary">
                                Na página inicial
                            </span>
                        )}
                        {cycle.is_hidden && (
                            <span className="rounded-full bg-subtle px-2.5 py-0.5 text-xs font-bold text-muted">
                                Escondida do arquivo
                            </span>
                        )}
                    </div>

                    <Form
                        {...update.form(cycle.cycle)}
                        options={{ preserveScroll: true }}
                        className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
                    >
                        {({ errors, processing }) => (
                            <>
                                <label className="block text-sm">
                                    <span className="mb-1 block font-medium">
                                        Nome no site
                                    </span>
                                    <input
                                        name="display_name"
                                        defaultValue={cycle.display_name ?? ''}
                                        placeholder={`Eleições ${cycle.year}`}
                                        maxLength={60}
                                        className={inputClass}
                                    />
                                    {errors.display_name && (
                                        <span className="mt-1 block text-xs text-live">
                                            {errors.display_name}
                                        </span>
                                    )}
                                </label>
                                <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="is_hidden"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="is_hidden"
                                        value="1"
                                        defaultChecked={cycle.is_hidden}
                                        className="size-5 accent-primary"
                                    />
                                    Esconder do arquivo público
                                </label>
                                <p className="text-xs text-muted sm:col-span-2">
                                    Vazio: "Eleições {cycle.year}". Escondida, a
                                    eleição sai da lista de eleições e da página
                                    inicial; os links diretos continuam
                                    funcionando.
                                </p>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11 rounded-xl bg-primary px-5 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50 sm:col-span-2 sm:justify-self-start"
                                >
                                    Salvar
                                </button>
                            </>
                        )}
                    </Form>

                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            Eleições publicadas pelo TSE
                        </h3>
                        <ul className="divide-y divide-line overflow-hidden rounded-xl border border-line">
                            {cycle.elections.map((election) => (
                                <li
                                    key={election.code}
                                    className="flex flex-col gap-3 p-3 text-sm sm:flex-row sm:items-center"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {election.name}
                                        </p>
                                        <p className="text-muted">
                                            {election.round}º turno ·{' '}
                                            {formatDate(election.date)} · código{' '}
                                            {election.code}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        status={election.status}
                                        className="self-start text-xs sm:self-center"
                                    />
                                    {election.completed_at && (
                                        <Link
                                            href={reopen(election.code)}
                                            as="button"
                                            preserveScroll
                                            onBefore={() =>
                                                confirm(
                                                    `Reabrir a sincronização de "${election.name}"? Os resultados serão baixados de novo do TSE.`,
                                                )
                                            }
                                            className="min-h-10 rounded-lg border-2 border-line-strong px-3 font-semibold whitespace-nowrap hover:border-primary hover:bg-primary-soft"
                                        >
                                            Reabrir sincronização
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            ))}
        </AdminLayout>
    );
}

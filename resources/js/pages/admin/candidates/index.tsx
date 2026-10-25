import { Form, Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { contrastText } from '@/lib/format';
import { update } from '@/routes/admin/candidates';
import type { Candidate } from '@/types';

type Props = {
    candidates: (Candidate & { custom_color: string | null })[];
};

export default function CandidatesIndex({ candidates }: Props) {
    return (
        <AdminLayout title="Cores dos candidatos">
            <Head title="Candidatos" />

            <p className="text-sm text-zinc-500">
                As cores são usadas nos gráficos e no mapa de municípios.
                Candidatos aparecem aqui após a primeira sincronização com o TSE
                (<code>php artisan tse:sincronizar</code>).
            </p>

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
                                                    ' · cor automática'}
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

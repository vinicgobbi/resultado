import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import StatusBadge from '@/components/status-badge';
import AdminLayout from '@/layouts/admin-layout';
import { formatDate, formatDateTime, formatRelative } from '@/lib/format';
import { currentSubscription } from '@/lib/push';
import { cn } from '@/lib/utils';
import { destroy as clearFailedJobs, retry } from '@/routes/admin/failed-jobs';
import { cancel, store } from '@/routes/admin/maintenance-runs';
import { announce, test } from '@/routes/admin/notifications';
import type { ElectionStatus } from '@/types';

type ElectionRow = {
    code: number;
    name: string;
    date: string;
    status: ElectionStatus;
    last_synced_at: string | null;
    completed_at: string | null;
    results: number;
    final_results: number;
};

type Health = {
    tse_paused_until: string | null;
    queued_jobs: number;
    failed_jobs: number;
    last_failure: { at: string; message: string } | null;
    map_ready: boolean;
    push_ready: boolean;
    subscriptions: number;
    follows: number;
};

type Task = {
    value: string;
    label: string;
    button_label: string;
    description: string;
    needs_election: boolean;
};

type RunStatus =
    | 'na-fila'
    | 'executando'
    | 'concluida'
    | 'falhou'
    | 'cancelada';

type Run = {
    id: number;
    task: string;
    task_label: string;
    election_code: number | null;
    status: RunStatus;
    user: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    output: string;
};

type Deliveries = {
    label: string;
    delivered: number;
    failed: number;
    expired: number;
};

type Followed = {
    office: string;
    scope_code: string;
    label: string;
    followers: number;
};

type Props = {
    elections: ElectionRow[];
    health: Health;
    tasks: Task[];
    runs: Run[];
    running: boolean;
    deliveries: Deliveries[];
    followed: Followed[];
};

/** Tasks that take long or download a lot: confirmed before starting. */
const HEAVY = [
    'ressincronizar',
    'importar',
    'fotos',
    'fotos-eleicao',
    'fotos-encerradas',
    'mapa',
];

const RUN_STATUS: Record<RunStatus, { label: string; className: string }> = {
    'na-fila': { label: 'Na fila', className: 'bg-primary-soft text-primary' },
    executando: {
        label: 'Executando',
        className: 'bg-warning-soft text-warning',
    },
    concluida: {
        label: 'Concluída',
        className: 'bg-success-soft text-success',
    },
    falhou: { label: 'Falhou', className: 'bg-live-soft text-live' },
    cancelada: { label: 'Cancelada', className: 'bg-subtle text-muted' },
};

const inputClass =
    'min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm';

const smallButtonClass =
    'min-h-10 rounded-lg border-2 border-line-strong px-3 text-sm font-semibold hover:border-primary hover:bg-primary-soft disabled:opacity-50';

/**
 * A moment as "há 3 minutos", with the exact time on hover. Only the
 * browser knows "now", so the server text may differ.
 */
function Moment({ value }: { value: string | null }) {
    if (!value) {
        return <span>—</span>;
    }

    return (
        <time
            dateTime={value}
            title={formatDateTime(value)}
            suppressHydrationWarning
        >
            {formatRelative(value)}
        </time>
    );
}

function HealthCard({
    title,
    ok,
    children,
}: {
    title: string;
    ok: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-2xl border-2 bg-surface p-4',
                ok ? 'border-line' : 'border-live',
            )}
        >
            <p className="flex items-center gap-2 font-semibold">
                <span aria-hidden="true">{ok ? '✔️' : '⚠️'}</span>
                {title}
                <span className="sr-only">
                    {ok ? '(tudo certo)' : '(precisa de atenção)'}
                </span>
            </p>
            <div className="mt-1 text-sm text-muted">{children}</div>
        </div>
    );
}

/**
 * How many results of an election are final, as a bar and a count.
 */
function ElectionProgress({ election }: { election: ElectionRow }) {
    const percent =
        election.results > 0
            ? (election.final_results / election.results) * 100
            : 0;

    return (
        <div className="space-y-1">
            <div
                role="progressbar"
                aria-label={`Resultados finais de ${election.name}`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(percent)}
                className="h-2 overflow-hidden rounded-full bg-track"
            >
                <div
                    className="h-full rounded-full bg-success transition-all duration-700"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <span className="text-muted tabular-nums">
                {election.final_results.toLocaleString('pt-BR')} de{' '}
                {election.results.toLocaleString('pt-BR')}
            </span>
        </div>
    );
}

export default function Dashboard({
    elections,
    health,
    tasks,
    runs,
    running,
    deliveries,
    followed,
}: Props) {
    const [starting, setStarting] = useState<string | null>(null);
    const [testing, setTesting] = useState(false);

    // Follows a task closely while one runs.
    usePoll(running ? 3000 : 30_000, {
        only: ['elections', 'health', 'runs', 'running', 'deliveries'],
        showProgress: false,
    });

    const busy = new Set(
        runs
            .filter(
                (run) =>
                    run.status === 'na-fila' || run.status === 'executando',
            )
            .map((run) => `${run.task}-${run.election_code ?? ''}`),
    );

    function start(task: Task, election?: ElectionRow) {
        const name = election ? `${task.label} (${election.name})` : task.label;

        if (
            HEAVY.includes(task.value) &&
            !window.confirm(`${name}: pode levar vários minutos. Continuar?`)
        ) {
            return;
        }

        router.post(
            store.url(),
            { task: task.value, election: election?.code ?? null },
            {
                preserveScroll: true,
                onStart: () => setStarting(task.value + (election?.code ?? '')),
                onFinish: () => setStarting(null),
            },
        );
    }

    async function sendTest() {
        setTesting(true);

        const subscription = await currentSubscription().catch(() => null);

        router.post(
            test.url(),
            { endpoint: subscription?.endpoint ?? '' },
            {
                preserveScroll: true,
                onFinish: () => setTesting(false),
            },
        );
    }

    const electionTasks = tasks.filter((task) => task.needs_election);

    const electionActions = (election: ElectionRow) =>
        electionTasks.map((task) => (
            <button
                key={task.value}
                type="button"
                disabled={
                    busy.has(`${task.value}-${election.code}`) ||
                    starting !== null
                }
                onClick={() => start(task, election)}
                aria-label={`${task.label}: ${election.name}`}
                className="min-h-11 rounded-xl border-2 border-line-strong px-3 font-semibold whitespace-nowrap hover:border-primary hover:bg-primary-soft disabled:opacity-50"
            >
                {task.button_label}
            </button>
        ));

    return (
        <AdminLayout title="Painel da apuração">
            <Head title="Painel" />

            <section aria-labelledby="saude" className="space-y-3">
                <h2 id="saude" className="text-lg font-bold">
                    Situação agora
                </h2>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <HealthCard title="TSE" ok={!health.tse_paused_until}>
                        {health.tse_paused_until ? (
                            <>
                                Bloqueou os pedidos (HTTP 429). Volta{' '}
                                <Moment value={health.tse_paused_until} />.
                            </>
                        ) : (
                            'Respondendo normalmente.'
                        )}
                    </HealthCard>
                    <HealthCard title="Fila" ok={health.failed_jobs === 0}>
                        {health.queued_jobs} aguardando · {health.failed_jobs}{' '}
                        com falha
                        {health.last_failure && (
                            <span className="mt-1 block wrap-anywhere">
                                Última falha{' '}
                                <Moment value={health.last_failure.at} />:{' '}
                                {health.last_failure.message}
                            </span>
                        )}
                        {health.failed_jobs > 0 && (
                            <span className="mt-2 flex flex-wrap gap-2">
                                <Link
                                    href={retry()}
                                    as="button"
                                    preserveScroll
                                    className={smallButtonClass}
                                >
                                    Tentar de novo
                                </Link>
                                <Link
                                    href={clearFailedJobs()}
                                    as="button"
                                    preserveScroll
                                    onBefore={() =>
                                        confirm(
                                            `Remover os ${health.failed_jobs} jobs com falha?`,
                                        )
                                    }
                                    className={smallButtonClass}
                                >
                                    Limpar
                                </Link>
                            </span>
                        )}
                    </HealthCard>
                    <HealthCard title="Mapa" ok={health.map_ready}>
                        {health.map_ready
                            ? 'Geometria gerada.'
                            : 'Não gerado: use "Gerar o mapa".'}
                    </HealthCard>
                    <HealthCard
                        title="Notificações push"
                        ok={health.push_ready}
                    >
                        {health.push_ready
                            ? `${health.subscriptions} navegadores inscritos · ${health.follows} resultados seguidos`
                            : 'Chaves VAPID não configuradas.'}
                    </HealthCard>
                </div>
            </section>

            <section aria-labelledby="eleicoes" className="space-y-3">
                <h2 id="eleicoes" className="text-lg font-bold">
                    Eleições
                </h2>
                {/* Small screens: one card per election, nothing to scroll. */}
                <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface md:hidden">
                    {elections.map((election) => (
                        <li
                            key={election.code}
                            className="space-y-3 p-4 text-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-semibold">
                                        {election.name}
                                    </p>
                                    <p className="text-muted">
                                        {formatDate(election.date)} · código{' '}
                                        {election.code}
                                    </p>
                                </div>
                                <StatusBadge
                                    status={election.status}
                                    className="shrink-0 text-xs"
                                />
                            </div>
                            <div>
                                <p className="mb-1 text-muted">
                                    Resultados finais
                                </p>
                                <ElectionProgress election={election} />
                            </div>
                            <p className="text-muted">
                                Última sincronização:{' '}
                                <Moment value={election.last_synced_at} />
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                {electionActions(election)}
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="hidden overflow-x-auto rounded-2xl border border-line bg-surface md:block">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-subtle text-muted">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-2 font-medium"
                                >
                                    Eleição
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2 font-medium"
                                >
                                    Andamento
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2 font-medium"
                                >
                                    Resultados finais
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2 font-medium"
                                >
                                    Última sincronização
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    <span className="sr-only">Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {elections.map((election) => {
                                return (
                                    <tr key={election.code}>
                                        <th
                                            scope="row"
                                            className="px-4 py-3 font-normal"
                                        >
                                            <span className="block font-semibold">
                                                {election.name}
                                            </span>
                                            <span className="text-muted">
                                                {formatDate(election.date)} ·
                                                código {election.code}
                                            </span>
                                        </th>
                                        <td className="px-4 py-3">
                                            <StatusBadge
                                                status={election.status}
                                                className="text-xs"
                                            />
                                        </td>
                                        <td className="min-w-40 px-4 py-3">
                                            <ElectionProgress
                                                election={election}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-muted">
                                            <Moment
                                                value={election.last_synced_at}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                {electionActions(election)}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="tarefas" className="space-y-3">
                <h2 id="tarefas" className="text-lg font-bold">
                    Tarefas
                </h2>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {tasks
                        .filter((task) => !task.needs_election)
                        .map((task) => (
                            <li
                                key={task.value}
                                className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-4"
                            >
                                <div>
                                    <p className="font-semibold">
                                        {task.label}
                                    </p>
                                    <p className="text-sm text-muted">
                                        {task.description}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    disabled={
                                        busy.has(`${task.value}-`) ||
                                        starting !== null
                                    }
                                    onClick={() => start(task)}
                                    className="mt-auto min-h-11 rounded-xl bg-primary px-4 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50"
                                >
                                    {busy.has(`${task.value}-`)
                                        ? 'Em andamento…'
                                        : 'Executar'}
                                </button>
                            </li>
                        ))}
                </ul>
            </section>

            <section aria-labelledby="notificacoes" className="space-y-3">
                <h2 id="notificacoes" className="text-lg font-bold">
                    Notificações
                </h2>
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="space-y-3 rounded-2xl border border-line bg-surface p-4">
                        <h3 className="font-semibold">Envios</h3>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-muted">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="py-1 font-medium"
                                        >
                                            Período
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-1 text-right font-medium"
                                        >
                                            Entregues
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-1 text-right font-medium"
                                        >
                                            Falhas
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-1 text-right font-medium"
                                        >
                                            Expiradas
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line tabular-nums">
                                    {deliveries.map((period) => (
                                        <tr key={period.label}>
                                            <th
                                                scope="row"
                                                className="py-1.5 font-normal"
                                            >
                                                {period.label}
                                            </th>
                                            <td className="py-1.5 text-right">
                                                {period.delivered.toLocaleString(
                                                    'pt-BR',
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    'py-1.5 text-right',
                                                    period.failed > 0 &&
                                                        'font-semibold text-live',
                                                )}
                                            >
                                                {period.failed.toLocaleString(
                                                    'pt-BR',
                                                )}
                                            </td>
                                            <td className="py-1.5 text-right text-muted">
                                                {period.expired.toLocaleString(
                                                    'pt-BR',
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-xs text-muted">
                            Expiradas: inscrições que o navegador cancelou; são
                            removidas sozinhas.
                        </p>
                        <button
                            type="button"
                            disabled={testing || !health.push_ready}
                            onClick={() => void sendTest()}
                            className={cn(
                                smallButtonClass,
                                'min-h-11 w-full sm:w-auto',
                            )}
                        >
                            {testing
                                ? 'Enviando…'
                                : 'Enviar notificação de teste para este aparelho'}
                        </button>
                    </div>

                    <div className="space-y-3 rounded-2xl border border-line bg-surface p-4">
                        <h3 className="font-semibold">
                            Resultados mais seguidos
                        </h3>
                        {followed.length === 0 ? (
                            <p className="text-sm text-muted">
                                Ninguém segue um resultado ainda.
                            </p>
                        ) : (
                            <ol className="divide-y divide-line text-sm">
                                {followed.map((item) => (
                                    <li
                                        key={`${item.office}-${item.scope_code}`}
                                        className="flex justify-between gap-3 py-1.5"
                                    >
                                        <span className="min-w-0">
                                            {item.label}
                                        </span>
                                        <span className="shrink-0 text-muted tabular-nums">
                                            {item.followers.toLocaleString(
                                                'pt-BR',
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </div>

                <Form
                    {...announce.form()}
                    transform={(data) => {
                        const [office, scopeCode] = String(
                            data.target ?? '',
                        ).split('|');

                        return {
                            title: data.title,
                            body: data.body,
                            office: office || null,
                            scope_code: scopeCode || null,
                        };
                    }}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    onBefore={() =>
                        confirm(
                            'Enviar este aviso agora? Não dá para desfazer.',
                        )
                    }
                    className="space-y-3 rounded-2xl border border-line bg-surface p-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <h3 className="font-semibold">Enviar aviso</h3>
                            <p className="text-sm text-muted">
                                Por exemplo: "O TSE está atrasando a
                                divulgação". Chega como notificação e abre o
                                resultado escolhido (ou a página inicial).
                            </p>
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium">
                                    Para
                                </span>
                                <select name="target" className={inputClass}>
                                    <option value="">
                                        Todos os navegadores inscritos (
                                        {health.subscriptions.toLocaleString(
                                            'pt-BR',
                                        )}
                                        )
                                    </option>
                                    {followed.map((item) => (
                                        <option
                                            key={`${item.office}-${item.scope_code}`}
                                            value={`${item.office}|${item.scope_code}`}
                                        >
                                            Quem segue {item.label} (
                                            {item.followers.toLocaleString(
                                                'pt-BR',
                                            )}
                                            )
                                        </option>
                                    ))}
                                </select>
                                {errors.scope_code && (
                                    <span className="mt-1 block text-xs text-live">
                                        {errors.scope_code}
                                    </span>
                                )}
                            </label>
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium">
                                    Título
                                </span>
                                <input
                                    name="title"
                                    required
                                    maxLength={100}
                                    className={inputClass}
                                />
                                {errors.title && (
                                    <span className="mt-1 block text-xs text-live">
                                        {errors.title}
                                    </span>
                                )}
                            </label>
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium">
                                    Mensagem
                                </span>
                                <textarea
                                    name="body"
                                    required
                                    maxLength={300}
                                    rows={2}
                                    className={inputClass}
                                />
                                {errors.body && (
                                    <span className="mt-1 block text-xs text-live">
                                        {errors.body}
                                    </span>
                                )}
                            </label>
                            <button
                                type="submit"
                                disabled={processing || !health.push_ready}
                                className="min-h-11 w-full rounded-xl bg-primary px-4 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50 sm:w-auto"
                            >
                                Enviar aviso
                            </button>
                        </>
                    )}
                </Form>
            </section>

            <section aria-labelledby="execucoes" className="space-y-3">
                <h2 id="execucoes" className="text-lg font-bold">
                    Execuções recentes
                </h2>
                {runs.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-line-strong p-6 text-center text-muted">
                        Nenhuma tarefa foi executada pelo painel ainda.
                    </p>
                ) : (
                    <ul className="space-y-2" aria-live="polite">
                        {runs.map((run, index) => (
                            <li
                                key={run.id}
                                className="rounded-2xl border border-line bg-surface"
                            >
                                <details
                                    open={
                                        index === 0 &&
                                        run.status === 'executando'
                                    }
                                >
                                    <summary className="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                                        <span
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 text-xs font-bold',
                                                RUN_STATUS[run.status]
                                                    .className,
                                            )}
                                        >
                                            {RUN_STATUS[run.status].label}
                                        </span>
                                        <span className="font-semibold">
                                            {run.task_label}
                                            {run.election_code &&
                                                ` · ${run.election_code}`}
                                        </span>
                                        <span className="text-sm text-muted">
                                            {run.user ?? 'alguém'} ·{' '}
                                            <Moment value={run.created_at} />
                                        </span>
                                        {(run.status === 'na-fila' ||
                                            run.status === 'executando') && (
                                            <Link
                                                href={cancel(run.id)}
                                                as="button"
                                                preserveScroll
                                                onBefore={() =>
                                                    confirm(
                                                        `Cancelar "${run.task_label}"?`,
                                                    )
                                                }
                                                className={cn(
                                                    smallButtonClass,
                                                    'ml-auto',
                                                )}
                                            >
                                                Cancelar
                                            </Link>
                                        )}
                                    </summary>
                                    <pre className="max-h-80 overflow-auto border-t border-line bg-subtle px-4 py-3 text-xs wrap-anywhere whitespace-pre-wrap">
                                        {run.output ||
                                            (run.status === 'na-fila'
                                                ? 'Aguardando o início…'
                                                : 'Sem saída.')}
                                    </pre>
                                </details>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AdminLayout>
    );
}

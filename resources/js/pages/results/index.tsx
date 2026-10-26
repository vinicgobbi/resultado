import { Head, Link, useHttp, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import CandidateResults from '@/components/candidate-results';
import MunicipalityPanel from '@/components/municipality-panel';
import NotificationToggle from '@/components/notification-toggle';
import ResultsMap from '@/components/results-map';
import StatesTable from '@/components/states-table';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show as showMunicipality } from '@/routes/municipalities';
import type {
    Candidate,
    MapResults,
    MunicipalityDetail,
    ResultSummary,
    StateResult,
} from '@/types';

type Props = {
    round: number;
    rounds: number[];
    candidates: Candidate[];
    summary: ResultSummary | null;
    states: StateResult[];
    mapAvailable: boolean;
    map?: MapResults;
};

const POLL_INTERVAL = 20_000;

export default function ResultsIndex({
    round,
    rounds,
    candidates,
    summary,
    states,
    mapAvailable,
    map,
}: Props) {
    const [selectedState, setSelectedState] = useState('');
    const [detail, setDetail] = useState<MunicipalityDetail | null>(null);
    const http = useHttp<Record<string, never>, MunicipalityDetail>({});

    usePoll(
        POLL_INTERVAL,
        {
            only: ['candidates', 'summary', 'states', 'map'],
            showProgress: false,
        },
        { mode: 'rest', autoStart: !summary?.is_final },
    );

    const candidatesById = new Map(
        candidates.map((candidate) => [candidate.id, candidate]),
    );

    const leader = summary?.results[0];
    const electedId =
        summary?.is_final && leader && (round === 2 || leader.percent > 50)
            ? leader.candidate_id
            : null;

    function selectMunicipality(ibge: string) {
        setDetail(null);
        void http
            .get(showMunicipality.url(ibge, { query: { turno: round } }))
            .then(setDetail);
    }

    return (
        <>
            <Head title={`Apuração ${round}º turno`} />

            <div className="min-h-screen bg-zinc-50 text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
                <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4">
                        <div className="flex items-center gap-3">
                            <img
                                src="/app-icons/icon-192.png"
                                alt=""
                                className="size-9 rounded-lg"
                            />
                            <div>
                                <h1 className="text-lg leading-tight font-bold text-zinc-900 dark:text-zinc-50">
                                    Eleições 2026 · Presidente
                                </h1>
                                <p className="text-xs text-zinc-500">
                                    Dados oficiais da divulgação do TSE
                                </p>
                            </div>
                        </div>

                        <nav className="flex rounded-full bg-zinc-100 p-1 dark:bg-zinc-800">
                            {rounds.map((value) => (
                                <Link
                                    key={value}
                                    href={home({ query: { turno: value } })}
                                    preserveScroll
                                    className={cn(
                                        'rounded-full px-4 py-1.5 text-sm font-medium',
                                        value === round
                                            ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-950 dark:text-zinc-50'
                                            : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200',
                                    )}
                                >
                                    {value}º turno
                                </Link>
                            ))}
                        </nav>

                        <div className="ml-auto">
                            <NotificationToggle />
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                    {!summary ? (
                        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500 dark:border-zinc-700">
                            Ainda não há resultados sincronizados para o {round}
                            º turno.
                        </div>
                    ) : (
                        <>
                            <section className="space-y-4">
                                <div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                                        <div>
                                            <p className="text-sm text-zinc-500">
                                                Seções apuradas
                                            </p>
                                            <p className="text-4xl font-bold text-zinc-900 tabular-nums dark:text-zinc-50">
                                                {formatPercent(
                                                    summary.counted_percent,
                                                )}
                                            </p>
                                        </div>
                                        <p className="flex items-center gap-2 text-xs text-zinc-500">
                                            {summary.is_final ? (
                                                <span className="rounded-full bg-zinc-200 px-2 py-0.5 font-medium dark:bg-zinc-800">
                                                    Apuração encerrada
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1.5 font-medium text-red-600">
                                                    <span className="relative flex size-2">
                                                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-red-500 opacity-75" />
                                                        <span className="relative inline-flex size-2 rounded-full bg-red-600" />
                                                    </span>
                                                    AO VIVO
                                                </span>
                                            )}
                                            Atualizado pelo TSE em{' '}
                                            {formatDateTime(summary.updated_at)}
                                        </p>
                                    </div>
                                    <div className="mb-6 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                        <div
                                            className="h-full bg-emerald-600 transition-all duration-700"
                                            style={{
                                                width: `${summary.counted_percent}%`,
                                            }}
                                        />
                                    </div>
                                    <CandidateResults
                                        results={summary.results}
                                        candidates={candidatesById}
                                        electedId={electedId}
                                    />
                                </div>

                                <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                                    {[
                                        ['Eleitorado', summary.electorate],
                                        ['Comparecimento', summary.turnout],
                                        ['Abstenções', summary.abstentions],
                                        ['Votos válidos', summary.valid_votes],
                                        ['Brancos', summary.blank_votes],
                                        ['Nulos', summary.null_votes],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900"
                                        >
                                            <dt className="text-xs text-zinc-500">
                                                {label}
                                            </dt>
                                            <dd className="text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-50">
                                                {formatNumber(Number(value))}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>

                            <section className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <h2 className="font-semibold text-zinc-900 dark:text-zinc-50">
                                            Quem lidera em cada município
                                        </h2>
                                        <select
                                            value={selectedState}
                                            onChange={(event) =>
                                                setSelectedState(
                                                    event.target.value,
                                                )
                                            }
                                            className="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                        >
                                            <option value="">Brasil</option>
                                            {states
                                                .filter(
                                                    (state) =>
                                                        state.code !== 'ZZ',
                                                )
                                                .map((state) => (
                                                    <option
                                                        key={state.code}
                                                        value={state.code}
                                                    >
                                                        {state.name}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>

                                    {mapAvailable ? (
                                        <ResultsMap
                                            results={map}
                                            candidates={candidatesById}
                                            selectedState={selectedState}
                                            onSelect={selectMunicipality}
                                        />
                                    ) : (
                                        <p className="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">
                                            Mapa ainda não gerado. Rode{' '}
                                            <code>php artisan mapa:baixar</code>
                                            .
                                        </p>
                                    )}

                                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500">
                                        {candidates.map((candidate) => (
                                            <span
                                                key={candidate.id}
                                                className="flex items-center gap-1.5"
                                            >
                                                <span
                                                    className="size-3 rounded-sm"
                                                    style={{
                                                        backgroundColor:
                                                            candidate.color,
                                                    }}
                                                />
                                                {candidate.name}
                                            </span>
                                        ))}
                                        <span className="flex items-center gap-1.5">
                                            <span className="size-3 rounded-sm bg-zinc-200 dark:bg-zinc-800" />
                                            Sem apuração
                                        </span>
                                        <span className="w-full sm:ml-auto sm:w-auto">
                                            Cor mais forte = vitória mais
                                            folgada
                                        </span>
                                    </div>
                                </div>

                                <div className="space-y-4">
                                    <MunicipalityPanel
                                        detail={detail}
                                        loading={http.processing}
                                        onClose={() => {
                                            http.cancel();
                                            setDetail(null);
                                        }}
                                    />
                                    <StatesTable
                                        states={states}
                                        candidates={candidatesById}
                                        selectedState={selectedState}
                                        onSelect={setSelectedState}
                                    />
                                </div>
                            </section>
                        </>
                    )}
                </main>

                <footer className="mx-auto max-w-7xl px-4 pb-8 text-xs text-zinc-500">
                    Fonte: Tribunal Superior Eleitoral (resultados.tse.jus.br).
                    Atualização automática a cada {POLL_INTERVAL / 1000}{' '}
                    segundos.
                </footer>
            </div>
        </>
    );
}

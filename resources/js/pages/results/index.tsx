import { Head, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CandidateResults from '@/components/candidate-results';
import MunicipalityPanel from '@/components/municipality-panel';
import NotificationToggle from '@/components/notification-toggle';
import ResultsMap from '@/components/results-map';
import StatesTable from '@/components/states-table';
import WinnerCelebration from '@/components/winner-celebration';
import type { CelebratedWinner } from '@/components/winner-celebration';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show as showMunicipality } from '@/routes/municipalities';
import type {
    Candidate,
    MapResults,
    MunicipalityDetail,
    OfficeOption,
    Place,
    ResultSummary,
    StateResult,
} from '@/types';

type Props = {
    round: number;
    rounds: number[];
    office: string;
    offices: OfficeOption[];
    /** Abbreviation of the state of a state office. */
    state: string | null;
    places: Place[];
    candidates: Candidate[];
    summary: ResultSummary | null;
    states: StateResult[];
    mapAvailable: boolean;
    map?: MapResults;
};

const POLL_INTERVAL = 20_000;

const PRESIDENT = 'presidente';

/**
 * "Governador" → "governadores", "Deputado Federal" → "deputados federais".
 */
function plural(label: string): string {
    return label
        .toLowerCase()
        .split(' ')
        .map((word) =>
            word.endsWith('l')
                ? `${word.slice(0, -1)}is`
                : word.endsWith('r')
                  ? `${word}es`
                  : `${word}s`,
        )
        .join(' ');
}

function celebrationTitle(
    officeLabel: string,
    stateName: string | undefined,
    count: number,
    mathematical: boolean,
): string {
    const elected = `${mathematical ? 'Matematicamente ' : ''}${count > 1 ? 'eleitos' : 'eleito'}`;
    const office = count > 1 ? plural(officeLabel) : officeLabel.toLowerCase();
    const title = `${elected.charAt(0).toUpperCase()}${elected.slice(1)} ${office}`;

    return stateName ? `${title} · ${stateName}` : `${title} do Brasil`;
}

export default function ResultsIndex({
    round,
    rounds,
    office,
    offices,
    state,
    places,
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
            only: ['candidates', 'summary', 'states', 'places', 'map'],
            showProgress: false,
        },
        {
            mode: 'rest',
            // A state office shows nothing until a state is picked.
            autoStart:
                (office === PRESIDENT || state !== null) && !summary?.is_final,
        },
    );

    const candidatesById = new Map(
        candidates.map((candidate) => [candidate.id, candidate]),
    );

    const isNationwide = office === PRESIDENT;
    const officeLabel =
        offices.find((option) => option.value === office)?.label ?? '';
    const stateName = places.find((place) => place.code === state)?.name;
    // Deputies: seats split by party, results only per state.
    const proportional = office.startsWith('deputado');

    const winners = summary?.winners ?? [];
    const celebrated: CelebratedWinner[] = winners.flatMap((winner) => {
        const candidate = candidatesById.get(winner.candidate_id);

        return candidate
            ? [
                  {
                      winner,
                      candidate,
                      result: summary?.results.find(
                          (result) =>
                              result.candidate_id === winner.candidate_id,
                      ),
                  },
              ]
            : [];
    });
    const winnerIds = winners.map((winner) => winner.candidate_id).join();
    // Opens on load when the place has winners, and when one appears while
    // the page is open (polling).
    const [celebrating, setCelebrating] = useState(winners.length > 0);
    const previousWinners = useRef(winnerIds);

    useEffect(() => {
        if (winnerIds !== '' && winnerIds !== previousWinners.current) {
            setCelebrating(true);
        }

        previousWinners.current = winnerIds;
    }, [winnerIds]);

    /**
     * Query string of a view, keeping the current office and state.
     */
    function viewQuery(changes: {
        turno?: number;
        cargo?: string;
        uf?: string | null;
    }) {
        const view = {
            turno: round,
            cargo: office,
            uf: state?.toLowerCase() ?? null,
            ...changes,
        };

        return {
            turno: view.turno,
            ...(view.cargo !== PRESIDENT && { cargo: view.cargo }),
            ...(view.cargo !== PRESIDENT && view.uf && { uf: view.uf }),
        };
    }

    function selectMunicipality(ibge: string) {
        setDetail(null);
        void http
            .get(
                showMunicipality.url(ibge, {
                    query: { turno: round, cargo: office },
                }),
            )
            .then(setDetail);
    }

    return (
        <>
            <Head
                title={`${officeLabel}${state ? ` ${state}` : ''} · ${round}º turno`}
            />

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
                                    Eleições 2026 · {officeLabel}
                                    {stateName && ` · ${stateName}`}
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
                                    href={home({
                                        query: viewQuery({ turno: value }),
                                    })}
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

                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 pb-3">
                        <nav className="-mx-1 flex gap-1 overflow-x-auto px-1">
                            {offices.map((option) => (
                                <Link
                                    key={option.value}
                                    href={home({
                                        query: viewQuery({
                                            cargo: option.value,
                                        }),
                                    })}
                                    preserveScroll
                                    className={cn(
                                        'shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                                        option.value === office
                                            ? 'bg-emerald-600 text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800',
                                    )}
                                >
                                    {option.label}
                                </Link>
                            ))}
                        </nav>

                        {!isNationwide && state && (
                            <select
                                value={state ?? ''}
                                onChange={(event) =>
                                    router.visit(
                                        home({
                                            query: viewQuery({
                                                uf: event.target.value.toLowerCase(),
                                            }),
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                                aria-label="Estado"
                                className="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            >
                                {places.map((place) => (
                                    <option key={place.code} value={place.code}>
                                        {place.name}
                                        {!place.is_final &&
                                            ` (${formatPercent(place.counted_percent)})`}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                </header>

                <main className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                    {!isNationwide && !state && places.length > 0 ? (
                        <section className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <h2 className="font-semibold text-zinc-900 dark:text-zinc-50">
                                Escolha o estado
                            </h2>
                            <p className="mb-4 text-sm text-zinc-500">
                                Veja o resultado de {officeLabel} no {round}º
                                turno.
                            </p>
                            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                                {places.map((place) => (
                                    <li key={place.code}>
                                        <Link
                                            href={home({
                                                query: viewQuery({
                                                    uf: place.code.toLowerCase(),
                                                }),
                                            })}
                                            className="flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-800 hover:border-emerald-600 hover:bg-emerald-50 dark:border-zinc-800 dark:text-zinc-200 dark:hover:border-emerald-500 dark:hover:bg-emerald-950/40"
                                        >
                                            <span className="w-7 text-xs font-bold text-zinc-500">
                                                {place.code}
                                            </span>
                                            {place.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : !summary ? (
                        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500 dark:border-zinc-700">
                            Ainda não há resultados de {officeLabel}{' '}
                            sincronizados para o {round}º turno.
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
                                        key={`${office}-${state}`}
                                        results={summary.results}
                                        candidates={candidatesById}
                                        winners={winners}
                                        proportional={proportional}
                                        onCelebrate={() => setCelebrating(true)}
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

                            {!proportional && (
                                <section className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                                    <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <h2 className="font-semibold text-zinc-900 dark:text-zinc-50">
                                                Quem lidera em cada município
                                            </h2>
                                            {isNationwide && (
                                                <select
                                                    value={selectedState}
                                                    onChange={(event) =>
                                                        setSelectedState(
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                                >
                                                    <option value="">
                                                        Brasil
                                                    </option>
                                                    {states
                                                        .filter(
                                                            (state) =>
                                                                state.code !==
                                                                'ZZ',
                                                        )
                                                        .map((state) => (
                                                            <option
                                                                key={state.code}
                                                                value={
                                                                    state.code
                                                                }
                                                            >
                                                                {state.name}
                                                            </option>
                                                        ))}
                                                </select>
                                            )}
                                        </div>

                                        {mapAvailable ? (
                                            <ResultsMap
                                                results={map}
                                                candidates={candidatesById}
                                                selectedState={
                                                    isNationwide
                                                        ? selectedState
                                                        : (state ?? '')
                                                }
                                                restrictToState={!isNationwide}
                                                onSelect={selectMunicipality}
                                            />
                                        ) : (
                                            <p className="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">
                                                Mapa ainda não gerado. Rode{' '}
                                                <code>
                                                    php artisan mapa:baixar
                                                </code>
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
                                        {isNationwide && (
                                            <StatesTable
                                                states={states}
                                                candidates={candidatesById}
                                                selectedState={selectedState}
                                                onSelect={setSelectedState}
                                            />
                                        )}
                                    </div>
                                </section>
                            )}
                        </>
                    )}
                </main>

                <footer className="mx-auto max-w-7xl px-4 pb-8 text-xs text-zinc-500">
                    Fonte: Tribunal Superior Eleitoral (resultados.tse.jus.br).
                    Atualização automática a cada {POLL_INTERVAL / 1000}{' '}
                    segundos.
                </footer>
            </div>

            {celebrating && celebrated.length > 0 && (
                <WinnerCelebration
                    winners={celebrated}
                    title={celebrationTitle(
                        officeLabel,
                        stateName,
                        celebrated.length,
                        celebrated.some(
                            ({ winner }) => winner.status === 'mathematical',
                        ),
                    )}
                    round={round}
                    onClose={() => setCelebrating(false)}
                />
            )}
        </>
    );
}

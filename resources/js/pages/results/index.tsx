import { Head, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CandidateResults from '@/components/candidate-results';
import MunicipalityPanel from '@/components/municipality-panel';
import MunicipalityPicker from '@/components/municipality-picker';
import NotificationToggle from '@/components/notification-toggle';
import ResultsMap from '@/components/results-map';
import SkipLink from '@/components/skip-link';
import StatesTable from '@/components/states-table';
import WinnerCelebration from '@/components/winner-celebration';
import type { CelebratedWinner } from '@/components/winner-celebration';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as electionsIndex, show } from '@/routes/elections';
import { show as showMunicipality } from '@/routes/municipalities';
import type {
    Candidate,
    Election,
    ElectionLevel,
    MapResults,
    MunicipalityDetail,
    MunicipalityOption,
    OfficeOption,
    Place,
    ResultSummary,
    StateResult,
} from '@/types';

type Props = {
    election: Election;
    /** Both rounds of the election, each one its own TSE election. */
    rounds: { round: number; code: number }[];
    office: string;
    offices: OfficeOption[];
    level: ElectionLevel;
    /** Abbreviation of the chosen state. */
    state: string | null;
    municipality: { code: string; name: string } | null;
    /** Changes whenever the synchronized results change. */
    version: number;
    places: Place[];
    municipalities: MunicipalityOption[];
    /** Seats split by party: deputies and councillors. */
    proportional: boolean;
    candidates: Candidate[];
    summary: ResultSummary | null;
    states: StateResult[];
    /** Latest election, until a week after its day. */
    celebrateWinners: boolean;
    mapAvailable: boolean;
    map?: MapResults;
};

const POLL_INTERVAL = 20_000;

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
    placeName: string | undefined,
    count: number,
    mathematical: boolean,
): string {
    const elected = `${mathematical ? 'Matematicamente ' : ''}${count > 1 ? 'eleitos' : 'eleito'}`;
    const office = count > 1 ? plural(officeLabel) : officeLabel.toLowerCase();
    const title = `${elected.charAt(0).toUpperCase()}${elected.slice(1)} ${office}`;

    return placeName ? `${title} · ${placeName}` : `${title} do Brasil`;
}

export default function ResultsIndex({
    election,
    rounds,
    office,
    offices,
    level,
    state,
    municipality,
    version,
    places,
    municipalities,
    proportional,
    candidates,
    summary,
    states,
    celebrateWinners,
    mapAvailable,
    map,
}: Props) {
    const [selectedState, setSelectedState] = useState('');
    const [detail, setDetail] = useState<MunicipalityDetail | null>(null);
    const http = useHttp<Record<string, never>, MunicipalityDetail>({});

    const isNationwide = level === 'country';
    const isMunicipal = level === 'municipality';
    // The place where the office is elected was chosen.
    const placeChosen =
        isNationwide || (isMunicipal ? !!municipality : !!state);
    const live = election.status === 'apuracao';

    // Polls only the version; the results are downloaded again when it
    // changes.
    usePoll(
        POLL_INTERVAL,
        { only: ['version'], showProgress: false },
        {
            mode: 'rest',
            autoStart: live && placeChosen && !summary?.is_final,
        },
    );
    const loadedVersion = useRef(version);

    useEffect(() => {
        if (version === loadedVersion.current) {
            return;
        }

        loadedVersion.current = version;
        router.reload({
            only: [
                'candidates',
                'summary',
                'states',
                'places',
                'municipalities',
                'map',
            ],
            showProgress: false,
        });
    }, [version]);

    const candidatesById = new Map(
        candidates.map((candidate) => [candidate.id, candidate]),
    );

    const officeLabel =
        offices.find((option) => option.value === office)?.label ?? '';
    const stateName = places.find((place) => place.code === state)?.name;
    const placeName = isMunicipal
        ? municipality && `${municipality.name} (${state})`
        : stateName;

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
    // the page is open (polling), only while the winners are celebrated.
    const [celebrating, setCelebrating] = useState(
        celebrateWinners && winners.length > 0,
    );
    const previousWinners = useRef(winnerIds);

    useEffect(() => {
        if (
            celebrateWinners &&
            winnerIds !== '' &&
            winnerIds !== previousWinners.current
        ) {
            setCelebrating(true);
        }

        previousWinners.current = winnerIds;
    }, [winnerIds, celebrateWinners]);

    /**
     * Page of a view of an election, keeping the current office and place.
     */
    function viewHref(
        code: number,
        changes: {
            cargo?: string;
            uf?: string | null;
            municipio?: string | null;
        } = {},
    ) {
        const view = {
            cargo: office,
            uf: state?.toLowerCase() ?? null,
            municipio: municipality?.code ?? null,
            ...changes,
        };

        return show(code, {
            query: {
                cargo: view.cargo,
                ...(view.uf && { uf: view.uf }),
                ...(view.uf && view.municipio && { municipio: view.municipio }),
            },
        });
    }

    function selectMunicipality(ibge: string) {
        setDetail(null);
        void http
            .get(
                showMunicipality.url([election.code, ibge], {
                    query: { cargo: office },
                }),
            )
            .then(setDetail);
    }

    const pickState = !isNationwide && !state && places.length > 0;
    const pickMunicipality = isMunicipal && !!state && !municipality;

    return (
        <>
            <Head
                title={`${officeLabel}${placeName ? ` ${placeName}` : ''} · ${election.year} · ${election.round}º turno`}
            />

            <SkipLink />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4">
                        <div className="flex items-center gap-3">
                            <img
                                src="/app-icons/icon-192.png"
                                alt=""
                                className="size-9 rounded-lg"
                            />
                            <div>
                                <h1 className="text-lg leading-tight font-bold text-ink">
                                    Eleições {election.year} · {officeLabel}
                                    {placeName && ` · ${placeName}`}
                                </h1>
                                <p className="text-xs text-muted">
                                    {formatDate(election.date)} · Dados oficiais
                                    da divulgação do TSE
                                </p>
                            </div>
                        </div>

                        {rounds.length > 1 && (
                            <nav
                                aria-label="Turno"
                                className="flex rounded-full bg-subtle p-1"
                            >
                                {rounds.map((option) => (
                                    <Link
                                        key={option.code}
                                        href={viewHref(option.code)}
                                        preserveScroll
                                        aria-current={
                                            option.code === election.code
                                                ? 'page'
                                                : undefined
                                        }
                                        className={cn(
                                            'rounded-full px-4 py-1.5 text-sm font-medium',
                                            option.code === election.code
                                                ? 'bg-surface text-ink shadow-sm'
                                                : 'text-muted hover:text-ink',
                                        )}
                                    >
                                        {option.round}º turno
                                    </Link>
                                ))}
                            </nav>
                        )}

                        <div className="ml-auto flex items-center gap-3">
                            <Link
                                href={electionsIndex()}
                                className="text-sm font-medium text-muted hover:text-ink"
                            >
                                Outras eleições
                            </Link>
                            <NotificationToggle />
                        </div>
                    </div>

                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 pb-3">
                        <nav
                            aria-label="Cargo"
                            className="-mx-1 flex gap-1 overflow-x-auto px-1"
                        >
                            {offices.map((option) => (
                                <Link
                                    key={option.value}
                                    href={viewHref(
                                        option.election ?? election.code,
                                        { cargo: option.value },
                                    )}
                                    preserveScroll
                                    aria-current={
                                        option.value === office
                                            ? 'page'
                                            : undefined
                                    }
                                    className={cn(
                                        'shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                                        option.value === office
                                            ? 'bg-primary text-on-primary'
                                            : 'text-muted hover:bg-subtle',
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
                                        viewHref(election.code, {
                                            uf: event.target.value.toLowerCase(),
                                            municipio: null,
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                                aria-label="Estado"
                                className="rounded-lg border border-line-strong bg-surface px-2 py-1.5 text-sm"
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

                        {municipality && (
                            <select
                                value={municipality.code}
                                onChange={(event) =>
                                    router.visit(
                                        viewHref(election.code, {
                                            municipio: event.target.value,
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                                aria-label="Município"
                                className="max-w-56 rounded-lg border border-line-strong bg-surface px-2 py-1.5 text-sm"
                            >
                                {municipalities.map((option) => (
                                    <option
                                        key={option.code}
                                        value={option.code}
                                    >
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                </header>

                <main
                    id="conteudo"
                    tabIndex={-1}
                    className="mx-auto max-w-7xl space-y-6 px-4 py-6 focus:outline-none"
                >
                    <p
                        className="sr-only"
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        {summary &&
                            `${formatPercent(summary.counted_percent)} das seções apuradas${summary.is_final ? ', apuração encerrada' : ''}.`}
                    </p>
                    {pickState ? (
                        <section className="rounded-2xl border border-line bg-surface p-5">
                            <h2 className="font-semibold text-ink">
                                Escolha o estado
                            </h2>
                            <p className="mb-4 text-sm text-muted">
                                Veja o resultado de {officeLabel} no{' '}
                                {election.round}º turno de {election.year}.
                            </p>
                            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                                {places.map((place) => (
                                    <li key={place.code}>
                                        <Link
                                            href={viewHref(election.code, {
                                                uf: place.code.toLowerCase(),
                                                municipio: null,
                                            })}
                                            className="flex items-center gap-3 rounded-xl border border-line px-3 py-2.5 text-sm font-medium text-ink hover:border-primary hover:bg-primary-soft"
                                        >
                                            <span className="w-7 text-xs font-bold text-muted">
                                                {place.code}
                                            </span>
                                            {place.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : pickMunicipality ? (
                        <section className="rounded-2xl border border-line bg-surface p-5">
                            <h2 className="font-semibold text-ink">
                                Escolha o município
                            </h2>
                            <p className="mb-4 text-sm text-muted">
                                Veja o resultado de {officeLabel} em {stateName}{' '}
                                no {election.round}º turno de {election.year}.
                            </p>
                            <MunicipalityPicker
                                municipalities={municipalities}
                                href={(code) =>
                                    viewHref(election.code, {
                                        municipio: code,
                                    })
                                }
                            />
                        </section>
                    ) : !summary ? (
                        <div className="rounded-xl border border-dashed border-line-strong p-10 text-center text-muted">
                            {election.status === 'agendada'
                                ? `A apuração começa em ${formatDate(election.date)}, às 17h.`
                                : `Ainda não há resultados de ${officeLabel} sincronizados para esta eleição.`}
                        </div>
                    ) : (
                        <>
                            <section className="space-y-4">
                                <div className="rounded-2xl border border-line bg-surface p-5">
                                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                                        <div>
                                            <p className="text-sm text-muted">
                                                Seções apuradas
                                            </p>
                                            <p className="text-4xl font-bold text-ink tabular-nums">
                                                {formatPercent(
                                                    summary.counted_percent,
                                                )}
                                            </p>
                                        </div>
                                        <p className="flex items-center gap-2 text-xs text-muted">
                                            {summary.is_final ? (
                                                <span className="rounded-full bg-track px-2 py-0.5 font-medium">
                                                    Apuração encerrada
                                                </span>
                                            ) : !live ? (
                                                <span className="rounded-full bg-track px-2 py-0.5 font-medium">
                                                    Resultado parcial
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1.5 font-medium text-live">
                                                    <span className="relative flex size-2">
                                                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-live opacity-75" />
                                                        <span className="relative inline-flex size-2 rounded-full bg-live" />
                                                    </span>
                                                    AO VIVO
                                                </span>
                                            )}
                                            Atualizado pelo TSE em{' '}
                                            {formatDateTime(summary.updated_at)}
                                        </p>
                                    </div>
                                    <div className="mb-6 h-1.5 overflow-hidden rounded-full bg-track">
                                        <div
                                            className="h-full bg-success transition-all duration-700"
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
                                        onCelebrate={
                                            celebrateWinners
                                                ? () => setCelebrating(true)
                                                : undefined
                                        }
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
                                            className="rounded-xl border border-line bg-surface px-4 py-3"
                                        >
                                            <dt className="text-xs text-muted">
                                                {label}
                                            </dt>
                                            <dd className="text-lg font-semibold text-ink tabular-nums">
                                                {formatNumber(Number(value))}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>

                            {!proportional && !isMunicipal && (
                                <section className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                                    <div className="rounded-2xl border border-line bg-surface p-4">
                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <h2 className="font-semibold text-ink">
                                                Quem lidera em cada município
                                            </h2>
                                            {isNationwide && (
                                                <select
                                                    aria-label="Mostrar no mapa"
                                                    value={selectedState}
                                                    onChange={(event) =>
                                                        setSelectedState(
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="rounded-lg border border-line-strong bg-surface px-2 py-1 text-sm"
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
                                            <p className="rounded-xl border border-dashed border-line-strong p-6 text-center text-sm text-muted">
                                                O mapa ainda não está
                                                disponível.
                                            </p>
                                        )}

                                        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
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
                                                <span className="size-3 rounded-sm bg-track" />
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

                <footer className="mx-auto max-w-7xl px-4 pb-8 text-xs text-muted">
                    Fonte: Tribunal Superior Eleitoral (resultados.tse.jus.br).
                    {live &&
                        ` Atualização automática a cada ${POLL_INTERVAL / 1000} segundos.`}
                </footer>
            </div>

            {celebrating && celebrated.length > 0 && (
                <WinnerCelebration
                    winners={celebrated}
                    title={celebrationTitle(
                        officeLabel,
                        placeName ?? undefined,
                        celebrated.length,
                        celebrated.some(
                            ({ winner }) => winner.status === 'mathematical',
                        ),
                    )}
                    round={election.round}
                    onClose={() => setCelebrating(false)}
                />
            )}
        </>
    );
}

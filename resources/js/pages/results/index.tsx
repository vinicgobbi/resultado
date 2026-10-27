import { Head, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CandidateResults from '@/components/candidate-results';
import CountProgress from '@/components/count-progress';
import HowToRead from '@/components/how-to-read';
import MunicipalityPanel from '@/components/municipality-panel';
import MunicipalityPicker from '@/components/municipality-picker';
import NotificationToggle from '@/components/notification-toggle';
import PickerMenu from '@/components/picker-menu';
import ResultsMap from '@/components/results-map';
import SkipLink from '@/components/skip-link';
import StateList from '@/components/state-list';
import StatesTable from '@/components/states-table';
import VoteBreakdown from '@/components/vote-breakdown';
import WinnerCelebration from '@/components/winner-celebration';
import type { CelebratedWinner } from '@/components/winner-celebration';
import { dayLabel, STATUS } from '@/lib/election';
import { formatLongDate, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as electionsIndex, show } from '@/routes/elections';
import { show as showMunicipality } from '@/routes/municipalities';
import type {
    Candidate,
    Election,
    ElectionDay,
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
    /** Every election day, to choose another one. */
    days: ElectionDay[];
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
    days,
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

    const stateHref = (code: string) =>
        viewHref(election.code, { uf: code.toLowerCase(), municipio: null });
    const municipalityHref = (code: string) =>
        viewHref(election.code, { municipio: code });
    const currentDay = days.find(
        (day) => day.date === election.date && day.round === election.round,
    );

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
                    <div
                        className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 pt-4"
                        style={{ viewTransitionName: 'site-header' }}
                    >
                        <Link
                            href={electionsIndex()}
                            className="flex items-center gap-3 rounded-lg"
                        >
                            <img
                                src="/app-icons/icon-192.png"
                                alt=""
                                className="size-10 rounded-lg"
                            />
                            <span className="font-bold">
                                Resultados das Eleições
                            </span>
                        </Link>
                        <div className="ml-auto flex items-center gap-3">
                            <Link
                                href={electionsIndex()}
                                className="font-semibold text-primary underline-offset-4 hover:underline"
                            >
                                Todas as eleições
                            </Link>
                            <NotificationToggle />
                        </div>
                    </div>

                    <div className="mx-auto max-w-7xl space-y-4 px-4 py-5">
                        <div>
                            <h1 className="text-2xl font-bold sm:text-3xl">
                                {officeLabel}
                                {placeName && ` · ${placeName}`}
                            </h1>
                            <p className="text-muted">
                                {dayLabel(election)} ·{' '}
                                {formatLongDate(election.date)}
                            </p>
                        </div>

                        <nav
                            aria-label="Escolha o que ver"
                            className="flex flex-wrap items-end gap-3"
                        >
                            <PickerMenu
                                label="Eleição"
                                value={dayLabel(election)}
                            >
                                <ul className="space-y-1">
                                    {days.map((day) => (
                                        <li key={day.code}>
                                            <Link
                                                href={viewHref(day.code)}
                                                aria-current={
                                                    day.code ===
                                                    currentDay?.code
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className={cn(
                                                    'flex flex-col gap-1 rounded-lg px-3 py-2 hover:bg-primary-soft',
                                                    day.code ===
                                                        currentDay?.code &&
                                                        'bg-primary-soft',
                                                )}
                                            >
                                                <span className="font-semibold">
                                                    {dayLabel(day)}
                                                </span>
                                                <span className="text-sm text-muted">
                                                    {formatLongDate(day.date)} ·{' '}
                                                    {STATUS[day.status].label}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </PickerMenu>

                            {!isNationwide && (
                                <PickerMenu
                                    label="Estado"
                                    value={stateName ?? 'Escolha o estado'}
                                    pending={!state}
                                    wide
                                >
                                    <StateList
                                        places={places}
                                        current={state}
                                        href={stateHref}
                                        className="sm:grid-cols-3"
                                    />
                                </PickerMenu>
                            )}

                            {isMunicipal && state && (
                                <PickerMenu
                                    label="Município"
                                    value={
                                        municipality?.name ??
                                        'Escolha o município'
                                    }
                                    pending={!municipality}
                                    wide
                                >
                                    <MunicipalityPicker
                                        municipalities={municipalities}
                                        href={municipalityHref}
                                    />
                                </PickerMenu>
                            )}
                        </nav>

                        <nav aria-label="Cargo">
                            <p
                                aria-hidden="true"
                                className="mb-1 text-sm text-muted"
                            >
                                Cargo
                            </p>
                            <ul className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                                {offices.map((option) => (
                                    <li key={option.value} className="shrink-0">
                                        <Link
                                            href={viewHref(
                                                option.election ??
                                                    election.code,
                                                { cargo: option.value },
                                            )}
                                            preserveScroll
                                            aria-current={
                                                option.value === office
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={cn(
                                                'relative isolate flex min-h-11 items-center rounded-xl border-2 px-4 font-semibold whitespace-nowrap transition-colors',
                                                option.value === office
                                                    ? 'border-primary text-on-primary'
                                                    : 'border-line-strong hover:border-primary hover:bg-primary-soft',
                                            )}
                                        >
                                            {option.value === office && (
                                                // Slides to the new tab when
                                                // the office changes.
                                                <span
                                                    aria-hidden="true"
                                                    className="absolute -inset-0.5 -z-10 rounded-xl bg-primary"
                                                    style={{
                                                        viewTransitionName:
                                                            'chosen-office',
                                                    }}
                                                />
                                            )}
                                            {option.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </nav>
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
                            <StateList
                                places={places}
                                href={stateHref}
                                className="sm:grid-cols-3 lg:grid-cols-5"
                            />
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
                                href={municipalityHref}
                            />
                        </section>
                    ) : !summary ? (
                        <p className="rounded-2xl border border-dashed border-line-strong p-10 text-center text-lg text-muted">
                            {election.status === 'agendada'
                                ? `A apuração começa em ${formatLongDate(election.date)}, às 17h (horário de Brasília).`
                                : `Os resultados de ${officeLabel} ainda não chegaram do TSE.`}
                        </p>
                    ) : (
                        <>
                            <section
                                aria-label="Resultado"
                                className="space-y-6 rounded-2xl border border-line bg-surface p-5"
                            >
                                <CountProgress
                                    summary={summary}
                                    live={live}
                                    updateEvery={POLL_INTERVAL / 1000}
                                />
                                <div className="space-y-3">
                                    <div>
                                        <h2 className="text-xl font-bold">
                                            {proportional
                                                ? 'Votos por candidato'
                                                : 'Quem está na frente'}
                                        </h2>
                                        <p className="text-muted">
                                            Percentuais sobre os votos válidos.
                                        </p>
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
                            </section>

                            <VoteBreakdown summary={summary} />

                            <HowToRead
                                proportional={proportional}
                                hasMap={
                                    !proportional &&
                                    !isMunicipal &&
                                    mapAvailable
                                }
                            />

                            {!proportional && !isMunicipal && (
                                <section
                                    aria-labelledby="mapa"
                                    className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
                                >
                                    <div className="rounded-2xl border border-line bg-surface p-4">
                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <h2
                                                id="mapa"
                                                className="text-xl font-bold"
                                            >
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
                                                    className="min-h-11 rounded-xl border-2 border-line-strong bg-surface px-3"
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

                                        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                                            {candidates.map((candidate) => (
                                                <span
                                                    key={candidate.id}
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        className="size-3.5 rounded-sm"
                                                        style={{
                                                            backgroundColor:
                                                                candidate.color,
                                                        }}
                                                    />
                                                    {candidate.name}
                                                </span>
                                            ))}
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    aria-hidden="true"
                                                    className="size-3.5 rounded-sm bg-track"
                                                />
                                                Sem apuração
                                            </span>
                                            <span className="w-full sm:ml-auto sm:w-auto">
                                                Quanto mais forte a cor, maior a
                                                vantagem
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

                <footer className="mx-auto max-w-7xl px-4 pb-8 text-sm text-muted">
                    Fonte: Tribunal Superior Eleitoral, em
                    resultados.tse.jus.br. Este site não é do TSE: ele só mostra
                    os dados oficiais divulgados pelo tribunal.
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

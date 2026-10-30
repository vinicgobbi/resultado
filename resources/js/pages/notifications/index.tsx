import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Logo from '@/components/logo';
import {
    DEFAULT_LEVEL,
    NotificationLevelPicker,
} from '@/components/notification-levels';
import SearchSelect from '@/components/search-select';
import SkipLink from '@/components/skip-link';
import {
    currentSubscription,
    isPushSupported,
    listFollows,
    removeFollow,
    saveFollow,
    unsubscribe,
} from '@/lib/push';
import type { Follow, FollowCategories } from '@/lib/push';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as electionsIndex } from '@/routes/elections';

type StateOption = { code: string; name: string };

type MunicipalityChoice = { code: string; name: string };

type OfficeOption = {
    office: string;
    /** Where it is elected: the country, each state or each municipality. */
    scope: 'br' | 'uf' | 'mu';
    label: string;
    description: string;
    /** Municipalities where it is disputed, when not all of them. */
    municipalities: string[] | null;
};

type Props = {
    states: StateOption[];
    /** Where the visitor votes, once chosen. */
    state: string | null;
    offices: OfficeOption[];
    municipalities: MunicipalityChoice[];
};

/** A place where the visitor votes and the offices elected there. */
type Group = {
    title: string;
    hint: string;
    scopeCode: string;
    offices: OfficeOption[];
};

const PLACE_KEY = 'notificacoes.local';

/**
 * Remembers where the visitor votes, only in this browser.
 */
function rememberPlace(place: {
    state: string;
    municipality: MunicipalityChoice | null;
}): void {
    try {
        localStorage.setItem(PLACE_KEY, JSON.stringify(place));
    } catch {
        // Storage may be blocked: the place is just asked again.
    }
}

function rememberedPlace(): {
    state: string;
    municipality: MunicipalityChoice | null;
} | null {
    try {
        return JSON.parse(localStorage.getItem(PLACE_KEY) ?? 'null');
    } catch {
        return null;
    }
}

function keyOf(office: string, scopeCode: string): string {
    return `${office}-${scopeCode}`;
}

/**
 * The single place to manage notifications: the visitor says where they
 * vote, turns on the offices they want to hear about, and sees everything
 * this browser follows.
 */
export default function NotificationsIndex({
    states,
    state,
    offices,
    municipalities,
}: Props) {
    const { push } = usePage().props;
    const [follows, setFollows] = useState<Follow[] | null>(null);
    const [supported, setSupported] = useState(true);
    const [permission, setPermission] =
        useState<NotificationPermission>('default');
    const [municipality, setMunicipality] = useState<MunicipalityChoice | null>(
        null,
    );
    const [busyKey, setBusyKey] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [status, setStatus] = useState('');

    useEffect(() => {
        if (!isPushSupported() || !push.publicKey) {
            setSupported(false);

            return;
        }

        setPermission(Notification.permission);
        void listFollows()
            .then(setFollows)
            .catch(() => {
                setFollows([]);
                setError('Não foi possível carregar suas notificações.');
            });
    }, [push.publicKey]);

    useEffect(() => {
        const remembered = rememberedPlace();

        if (!remembered) {
            return;
        }

        if (state === null) {
            chooseState(remembered.state, remembered.municipality);
        } else if (remembered.state === state) {
            setMunicipality(remembered.municipality);
        }
        // Only where the visitor voted last time, once.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function chooseState(
        code: string,
        chosenMunicipality: MunicipalityChoice | null = null,
    ) {
        setMunicipality(chosenMunicipality);
        rememberPlace({ state: code, municipality: chosenMunicipality });
        router.reload({
            data: { uf: code },
            only: ['state', 'offices', 'municipalities'],
            replace: true,
        });
    }

    function chooseMunicipality(chosen: MunicipalityChoice) {
        setMunicipality(chosen);

        if (state) {
            rememberPlace({ state, municipality: chosen });
        }
    }

    async function run(key: string, action: () => Promise<void>, done: string) {
        setBusyKey(key);
        setError(null);

        try {
            await action();
            setStatus(done);
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Não foi possível salvar.',
            );
        } finally {
            setPermission(Notification.permission);
            setBusyKey(null);
        }
    }

    function follow(office: string, scopeCode: string, label: string) {
        void run(
            keyOf(office, scopeCode),
            async () => {
                await saveFollow(
                    push.publicKey!,
                    office,
                    scopeCode,
                    DEFAULT_LEVEL.categories,
                );
                setFollows(await listFollows());
            },
            `Pronto! Você vai receber notificações de ${label}.`,
        );
    }

    function unfollow(office: string, scopeCode: string, label: string) {
        void run(
            keyOf(office, scopeCode),
            async () => {
                await removeFollow(office, scopeCode);
                setFollows(
                    (current) =>
                        current?.filter(
                            (item) =>
                                keyOf(item.office, item.scope_code) !==
                                keyOf(office, scopeCode),
                        ) ?? null,
                );
            },
            `Você deixou de seguir ${label}.`,
        );
    }

    function changeLevel(followed: Follow, categories: FollowCategories) {
        void run(
            keyOf(followed.office, followed.scope_code),
            async () => {
                await saveFollow(
                    push.publicKey!,
                    followed.office,
                    followed.scope_code,
                    categories,
                );
                setFollows(
                    (current) =>
                        current?.map((item) =>
                            item === followed
                                ? { ...item, ...categories }
                                : item,
                        ) ?? null,
                );
            },
            `${followed.label}: preferências salvas.`,
        );
    }

    function turnOff() {
        void run(
            'all',
            async () => {
                const subscription = await currentSubscription();

                if (subscription) {
                    await unsubscribe(subscription);
                }

                setFollows([]);
            },
            'Notificações desativadas neste navegador.',
        );
    }

    const stateName = states.find((option) => option.code === state)?.name;
    const hasMunicipalOffices = offices.some((office) => office.scope === 'mu');
    const groups: Group[] = [
        {
            title: 'Brasil',
            hint: 'Eleições gerais',
            scopeCode: 'br',
            offices: offices.filter((office) => office.scope === 'br'),
        },
    ];

    if (state && stateName) {
        groups.push({
            title: stateName,
            hint: 'Eleições gerais',
            scopeCode: state,
            offices: offices.filter((office) => office.scope === 'uf'),
        });
    }

    if (municipality) {
        groups.push({
            title: municipality.name,
            hint: 'Eleições municipais',
            scopeCode: municipality.code,
            offices: offices.filter(
                (office) =>
                    office.scope === 'mu' &&
                    (office.municipalities === null ||
                        office.municipalities.includes(municipality.code)),
            ),
        });
    }

    const shown = new Set(
        groups.flatMap((group) =>
            group.offices.map((office) =>
                keyOf(office.office, group.scopeCode),
            ),
        ),
    );
    const others =
        follows?.filter(
            (item) => !shown.has(keyOf(item.office, item.scope_code)),
        ) ?? [];
    const findFollow = (office: string, scopeCode: string) =>
        follows?.find(
            (item) => item.office === office && item.scope_code === scopeCode,
        );
    const rows = groups.flatMap((group) =>
        group.offices.map((office) => ({
            office: office.office,
            scopeCode: group.scopeCode,
        })),
    );
    const followsAll =
        rows.length > 0 &&
        rows.every((row) => findFollow(row.office, row.scopeCode));

    /**
     * Follows every office shown, in the default level, or stops following
     * them all once they are.
     */
    function toggleAll() {
        const stopping = followsAll;

        void run(
            'all',
            async () => {
                try {
                    // One at a time: the first one asks for the permission.
                    for (const row of rows) {
                        const followed = findFollow(row.office, row.scopeCode);

                        if (stopping) {
                            await removeFollow(row.office, row.scopeCode);
                        } else if (!followed) {
                            await saveFollow(
                                push.publicKey!,
                                row.office,
                                row.scopeCode,
                                DEFAULT_LEVEL.categories,
                            );
                        }
                    }
                } finally {
                    // Also after a failure: some may have been saved.
                    setFollows(await listFollows());
                }
            },
            stopping
                ? 'Você deixou de seguir todos os cargos.'
                : 'Pronto! Você vai receber notificações de todos os cargos.',
        );
    }

    return (
        <>
            <Head title="Notificações" />

            <SkipLink />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="border-b border-line bg-surface">
                    <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-4">
                        <Link
                            href={home()}
                            className="flex items-center gap-3 rounded-lg"
                        >
                            <Logo />
                            <span className="text-lg font-bold">
                                Resultados das Eleições
                            </span>
                        </Link>
                        <Link
                            href={electionsIndex()}
                            className="ml-auto font-semibold text-primary underline-offset-4 hover:underline"
                        >
                            Todas as eleições
                        </Link>
                    </div>
                </header>

                <main
                    id="conteudo"
                    tabIndex={-1}
                    className="mx-auto max-w-3xl space-y-8 px-4 py-8 focus:outline-none"
                >
                    <div className="space-y-2">
                        <h1 className="text-3xl font-bold">Notificações</h1>
                        <p className="text-lg text-muted">
                            Diga onde você vota e ligue os cargos que quer
                            acompanhar. Você recebe um aviso no celular ou no
                            computador quando o resultado mudar.
                        </p>
                    </div>

                    <p className="sr-only" aria-live="polite">
                        {status}
                    </p>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-xl bg-live-soft p-4 font-semibold text-live"
                        >
                            {error}
                        </p>
                    )}

                    {!supported ? (
                        <p className="rounded-2xl border border-dashed border-line-strong p-8 text-center text-lg text-muted">
                            Este navegador não recebe notificações. Tente em
                            outro navegador ou instale o site como aplicativo.
                        </p>
                    ) : (
                        <>
                            {permission === 'denied' && (
                                <div className="space-y-1 rounded-xl bg-warning-soft p-4 text-warning">
                                    <p className="font-semibold">
                                        As notificações estão bloqueadas neste
                                        navegador.
                                    </p>
                                    <p>
                                        Libere nas permissões do site (o ícone
                                        ao lado do endereço) e recarregue a
                                        página.
                                    </p>
                                </div>
                            )}

                            <section
                                aria-labelledby="onde-vota"
                                className="space-y-4 rounded-2xl border border-line bg-surface p-5"
                            >
                                <h2
                                    id="onde-vota"
                                    className="text-xl font-bold"
                                >
                                    1. Onde você vota?
                                </h2>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SearchSelect
                                        label="Estado"
                                        options={states}
                                        value={
                                            states.find(
                                                (option) =>
                                                    option.code === state,
                                            ) ?? null
                                        }
                                        onChange={(option) => {
                                            if (option.code !== state) {
                                                chooseState(option.code);
                                            }
                                        }}
                                        placeholder="Digite o nome do estado"
                                        listAll
                                    />
                                    {state && hasMunicipalOffices && (
                                        <SearchSelect
                                            label="Cidade"
                                            options={municipalities}
                                            value={municipality}
                                            onChange={chooseMunicipality}
                                            placeholder="Digite o nome da cidade"
                                        />
                                    )}
                                </div>
                            </section>

                            <section
                                aria-labelledby="o-que"
                                className="space-y-4"
                            >
                                <div className="space-y-1">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <h2
                                            id="o-que"
                                            className="text-xl font-bold"
                                        >
                                            2. O que você quer acompanhar?
                                        </h2>
                                        <button
                                            type="button"
                                            onClick={toggleAll}
                                            disabled={
                                                follows === null ||
                                                busyKey !== null ||
                                                permission === 'denied'
                                            }
                                            className={cn(
                                                'min-h-11 rounded-xl px-4 font-semibold disabled:opacity-50',
                                                followsAll
                                                    ? 'border-2 border-line-strong hover:border-live hover:text-live'
                                                    : 'bg-primary text-on-primary hover:bg-primary-hover',
                                            )}
                                        >
                                            {busyKey === 'all'
                                                ? 'Salvando…'
                                                : followsAll
                                                  ? 'Parar de seguir todos'
                                                  : 'Seguir todos'}
                                        </button>
                                    </div>
                                    {permission === 'default' && (
                                        <p className="text-muted">
                                            Ao ligar o primeiro, o navegador vai
                                            pedir sua permissão para mostrar
                                            notificações.
                                        </p>
                                    )}
                                </div>

                                {groups.map((group) => (
                                    <div
                                        key={group.scopeCode}
                                        className="overflow-hidden rounded-2xl border border-line bg-surface"
                                    >
                                        <h3 className="flex flex-wrap items-baseline justify-between gap-2 border-b border-line bg-subtle px-5 py-3">
                                            <span className="text-lg font-bold">
                                                {group.title}
                                            </span>
                                            <span className="text-sm text-muted">
                                                {group.hint}
                                            </span>
                                        </h3>
                                        <ul className="divide-y divide-line">
                                            {group.offices.map((office) => {
                                                const key = keyOf(
                                                    office.office,
                                                    group.scopeCode,
                                                );
                                                const followed = findFollow(
                                                    office.office,
                                                    group.scopeCode,
                                                );
                                                const label = `${office.label} · ${group.title}`;

                                                return (
                                                    <li
                                                        key={key}
                                                        className="flex items-start gap-4 px-5 py-4"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <p className="font-semibold">
                                                                {office.label}
                                                            </p>
                                                            <p className="text-sm text-muted">
                                                                {
                                                                    office.description
                                                                }
                                                            </p>
                                                            {followed && (
                                                                <NotificationLevelPicker
                                                                    value={
                                                                        followed
                                                                    }
                                                                    disabled={
                                                                        busyKey ===
                                                                        key
                                                                    }
                                                                    onChange={(
                                                                        categories,
                                                                    ) =>
                                                                        changeLevel(
                                                                            followed,
                                                                            categories,
                                                                        )
                                                                    }
                                                                />
                                                            )}
                                                        </div>
                                                        <Switch
                                                            label={`Seguir ${label}`}
                                                            checked={!!followed}
                                                            disabled={
                                                                follows ===
                                                                    null ||
                                                                busyKey !==
                                                                    null ||
                                                                permission ===
                                                                    'denied'
                                                            }
                                                            onChange={(on) =>
                                                                on
                                                                    ? follow(
                                                                          office.office,
                                                                          group.scopeCode,
                                                                          label,
                                                                      )
                                                                    : unfollow(
                                                                          office.office,
                                                                          group.scopeCode,
                                                                          label,
                                                                      )
                                                            }
                                                        />
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>
                                ))}

                                {!state && (
                                    <p className="rounded-2xl border border-dashed border-line-strong p-5 text-center text-muted">
                                        Escolha seu estado para ver governador,
                                        senador e deputados.
                                    </p>
                                )}
                                {state &&
                                    hasMunicipalOffices &&
                                    !municipality && (
                                        <p className="rounded-2xl border border-dashed border-line-strong p-5 text-center text-muted">
                                            Escolha sua cidade para ver prefeito
                                            e vereadores.
                                        </p>
                                    )}
                            </section>

                            {others.length > 0 && (
                                <section
                                    aria-labelledby="outros"
                                    className="space-y-3"
                                >
                                    <div className="space-y-1">
                                        <h2
                                            id="outros"
                                            className="text-xl font-bold"
                                        >
                                            Você também segue
                                        </h2>
                                        <p className="text-muted">
                                            Resultados de outros lugares.
                                        </p>
                                    </div>
                                    <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
                                        {others.map((followed) => {
                                            const key = keyOf(
                                                followed.office,
                                                followed.scope_code,
                                            );

                                            return (
                                                <li
                                                    key={key}
                                                    className="flex flex-wrap items-start gap-4 px-5 py-4"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-semibold">
                                                            {followed.url ? (
                                                                <Link
                                                                    href={
                                                                        followed.url
                                                                    }
                                                                    className="underline-offset-4 hover:underline"
                                                                >
                                                                    {
                                                                        followed.label
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                followed.label
                                                            )}
                                                        </p>
                                                        <NotificationLevelPicker
                                                            value={followed}
                                                            disabled={
                                                                busyKey === key
                                                            }
                                                            onChange={(
                                                                categories,
                                                            ) =>
                                                                changeLevel(
                                                                    followed,
                                                                    categories,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            busyKey !== null
                                                        }
                                                        onClick={() =>
                                                            unfollow(
                                                                followed.office,
                                                                followed.scope_code,
                                                                followed.label,
                                                            )
                                                        }
                                                        className="min-h-11 rounded-xl border-2 border-line-strong px-4 font-semibold hover:border-live hover:text-live disabled:opacity-50"
                                                    >
                                                        Parar de seguir
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </section>
                            )}

                            {follows !== null && follows.length > 0 && (
                                <button
                                    type="button"
                                    onClick={turnOff}
                                    disabled={busyKey !== null}
                                    className="min-h-11 font-semibold text-live underline underline-offset-4 disabled:opacity-50"
                                >
                                    Desativar todas as notificações neste
                                    navegador
                                </button>
                            )}
                        </>
                    )}
                </main>
            </div>
        </>
    );
}

/**
 * An on/off switch: a checkbox announced as a switch.
 */
function Switch({
    label,
    checked,
    disabled,
    onChange,
}: {
    label: string;
    checked: boolean;
    disabled: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label
            className={cn(
                'relative inline-flex min-h-11 shrink-0 cursor-pointer items-center',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <input
                type="checkbox"
                role="switch"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                aria-label={label}
                className="peer sr-only"
            />
            <span
                aria-hidden="true"
                className="h-7 w-12 rounded-full bg-line-strong transition-colors peer-checked:bg-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus"
            />
            <span
                aria-hidden="true"
                className="absolute left-1 size-5 rounded-full bg-surface shadow transition-transform peer-checked:translate-x-5"
            />
        </label>
    );
}

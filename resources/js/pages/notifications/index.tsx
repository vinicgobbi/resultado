import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Logo from '@/components/logo';
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
import { home } from '@/routes';
import { index as electionsIndex } from '@/routes/elections';

const CATEGORIES: { key: keyof FollowCategories; title: string }[] = [
    { key: 'notify_updates', title: 'Atualizações do TSE' },
    { key: 'notify_decided', title: 'Matematicamente definido' },
    { key: 'notify_final', title: 'Resultado final' },
];

/**
 * What this browser follows, to change or stop. The browser is only known
 * by its push subscription, so the list is loaded here.
 */
export default function NotificationsIndex() {
    const { push } = usePage().props;
    const [follows, setFollows] = useState<Follow[] | null>(null);
    const [supported, setSupported] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [status, setStatus] = useState('');

    useEffect(() => {
        if (!isPushSupported() || !push.publicKey) {
            setSupported(false);

            return;
        }

        void listFollows()
            .then(setFollows)
            .catch(() => {
                setFollows([]);
                setError('Não foi possível carregar suas notificações.');
            });
    }, [push.publicKey]);

    async function run(action: () => Promise<void>, done: string) {
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
        }
    }

    function change(follow: Follow, key: keyof FollowCategories) {
        const categories: FollowCategories = {
            notify_updates: follow.notify_updates,
            notify_decided: follow.notify_decided,
            notify_final: follow.notify_final,
            [key]: !follow[key],
        };
        const any = Object.values(categories).some(Boolean);

        void run(async () => {
            await saveFollow(
                push.publicKey!,
                follow.office,
                follow.scope_code,
                categories,
            );
            setFollows(
                (current) =>
                    current
                        ?.map((item) =>
                            item === follow ? { ...item, ...categories } : item,
                        )
                        .filter((item) => item !== follow || any) ?? null,
            );
        }, `${follow.label}: preferências salvas.`);
    }

    function stop(follow: Follow) {
        void run(async () => {
            await removeFollow(follow.office, follow.scope_code);
            setFollows(
                (current) => current?.filter((item) => item !== follow) ?? null,
            );
        }, `Você deixou de seguir ${follow.label}.`);
    }

    function turnOff() {
        void run(async () => {
            const subscription = await currentSubscription();

            if (subscription) {
                await unsubscribe(subscription);
            }

            setFollows([]);
        }, 'Notificações desativadas neste navegador.');
    }

    return (
        <>
            <Head title="Minhas notificações" />

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
                    className="mx-auto max-w-3xl space-y-6 px-4 py-8 focus:outline-none"
                >
                    <div className="space-y-2">
                        <h1 className="text-3xl font-bold">
                            Minhas notificações
                        </h1>
                        <p className="text-lg text-muted">
                            Os resultados que você segue neste navegador e o que
                            quer receber de cada um.
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
                    ) : follows === null ? (
                        <div className="space-y-3" aria-hidden="true">
                            <div className="h-28 animate-pulse rounded-2xl bg-track" />
                            <div className="h-28 animate-pulse rounded-2xl bg-track" />
                        </div>
                    ) : follows.length === 0 ? (
                        <div className="animate-fade-up space-y-4 rounded-2xl border border-dashed border-line-strong p-8 text-center">
                            <p className="text-lg">
                                Você ainda não segue nenhum resultado.
                            </p>
                            <p className="text-muted">
                                Abra o resultado que quer acompanhar e escolha{' '}
                                <strong className="text-ink">🔔 Seguir</strong>.
                            </p>
                            <Link
                                href={home()}
                                className="inline-flex min-h-11 items-center rounded-xl bg-primary px-5 font-semibold text-on-primary hover:bg-primary-hover"
                            >
                                Ver os resultados
                            </Link>
                        </div>
                    ) : (
                        <>
                            <ul className="space-y-3">
                                {follows.map((follow, index) => (
                                    <li
                                        key={`${follow.office}-${follow.scope_code}`}
                                        className="animate-fade-up rounded-2xl border border-line bg-surface p-5"
                                        style={{
                                            animationDelay: `${index * 50}ms`,
                                        }}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <h2 className="text-xl font-bold">
                                                {follow.url ? (
                                                    <Link
                                                        href={follow.url}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {follow.label}
                                                    </Link>
                                                ) : (
                                                    follow.label
                                                )}
                                            </h2>
                                            <button
                                                type="button"
                                                onClick={() => stop(follow)}
                                                className="min-h-11 rounded-xl border-2 border-line-strong px-4 font-semibold hover:border-live hover:text-live"
                                            >
                                                Parar de seguir
                                            </button>
                                        </div>
                                        <fieldset className="mt-3">
                                            <legend className="sr-only">
                                                Notificações de {follow.label}
                                            </legend>
                                            <div className="flex flex-wrap gap-2">
                                                {CATEGORIES.map((category) => (
                                                    <label
                                                        key={category.key}
                                                        className="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-line px-3 hover:bg-subtle"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                follow[
                                                                    category.key
                                                                ]
                                                            }
                                                            onChange={() =>
                                                                change(
                                                                    follow,
                                                                    category.key,
                                                                )
                                                            }
                                                            className="size-5 accent-primary"
                                                        />
                                                        {category.title}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    </li>
                                ))}
                            </ul>

                            <button
                                type="button"
                                onClick={turnOff}
                                className="min-h-11 font-semibold text-live underline underline-offset-4"
                            >
                                Desativar todas as notificações neste navegador
                            </button>
                        </>
                    )}
                </main>
            </div>
        </>
    );
}

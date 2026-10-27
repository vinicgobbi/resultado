import { usePage } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import PickerMenu from '@/components/picker-menu';
import { isPushSupported, listFollows, saveFollow } from '@/lib/push';
import type { FollowCategories } from '@/lib/push';

type Props = {
    office: string;
    /** "br", a state or a TSE municipality code. */
    scopeCode: string;
    /** "Governador · São Paulo". */
    label: string;
};

const CATEGORIES: {
    key: keyof FollowCategories;
    title: string;
    help: string;
}[] = [
    {
        key: 'notify_updates',
        title: 'Atualizações do TSE',
        help: 'Novos resultados, no máximo a cada 10 minutos.',
    },
    {
        key: 'notify_decided',
        title: 'Matematicamente definido',
        help: 'Quando já houver eleito ou 2º turno garantido.',
    },
    {
        key: 'notify_final',
        title: 'Resultado final',
        help: 'Quando a apuração terminar.',
    },
];

const ALL: FollowCategories = {
    notify_updates: true,
    notify_decided: true,
    notify_final: true,
};

const NONE: FollowCategories = {
    notify_updates: false,
    notify_decided: false,
    notify_final: false,
};

/**
 * Follow the result on screen: choose which notifications of it this
 * browser receives.
 */
export default function FollowButton({ office, scopeCode, label }: Props) {
    const { push } = usePage().props;
    // Only known in the browser: checked after mounting.
    const [supported, setSupported] = useState(false);
    const [following, setFollowing] = useState<FollowCategories | null>(null);
    const [choice, setChoice] = useState<FollowCategories>(ALL);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState('');
    const formId = useId();

    useEffect(() => {
        if (!isPushSupported() || !push.publicKey) {
            return;
        }

        setSupported(true);
        void listFollows()
            .then((follows) => {
                const current = follows.find(
                    (follow) =>
                        follow.office === office &&
                        follow.scope_code === scopeCode,
                );

                setFollowing(current ?? null);
                setChoice(current ?? ALL);
            })
            .catch(() => setFollowing(null));
    }, [office, scopeCode, push.publicKey]);

    if (!supported) {
        return null;
    }

    async function save(categories: FollowCategories, close: () => void) {
        setBusy(true);
        setError(null);

        try {
            await saveFollow(push.publicKey!, office, scopeCode, categories);
            const any = Object.values(categories).some(Boolean);

            setFollowing(any ? categories : null);
            setChoice(any ? categories : ALL);
            setSaved(
                any
                    ? `Pronto! Você vai receber notificações de ${label}.`
                    : `Você deixou de seguir ${label}.`,
            );
            close();
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Não foi possível salvar.',
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div>
            <PickerMenu
                label="Notificações"
                value={following ? '🔔 Seguindo' : '🔔 Seguir'}
                pending={following !== null}
            >
                {(close) => (
                    <form
                        id={formId}
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save(choice, close);
                        }}
                        className="space-y-3"
                    >
                        <p className="font-semibold">Avisar sobre {label}:</p>
                        {CATEGORIES.map((category) => (
                            <label
                                key={category.key}
                                className="flex cursor-pointer items-start gap-3 rounded-lg p-2 hover:bg-subtle"
                            >
                                <input
                                    type="checkbox"
                                    checked={choice[category.key]}
                                    onChange={(event) =>
                                        setChoice({
                                            ...choice,
                                            [category.key]:
                                                event.target.checked,
                                        })
                                    }
                                    className="mt-1 size-5 accent-primary"
                                />
                                <span>
                                    <span className="block font-semibold">
                                        {category.title}
                                    </span>
                                    <span className="block text-sm text-muted">
                                        {category.help}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {error && (
                            <p role="alert" className="text-sm text-live">
                                {error}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                disabled={busy}
                                className="min-h-11 flex-1 rounded-xl bg-primary px-4 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50"
                            >
                                {following ? 'Salvar' : 'Seguir'}
                            </button>
                            {following && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => void save(NONE, close)}
                                    className="min-h-11 rounded-xl border-2 border-line-strong px-4 font-semibold hover:border-live hover:text-live disabled:opacity-50"
                                >
                                    Parar de seguir
                                </button>
                            )}
                        </div>
                    </form>
                )}
            </PickerMenu>
            <p className="sr-only" aria-live="polite">
                {saved}
            </p>
        </div>
    );
}

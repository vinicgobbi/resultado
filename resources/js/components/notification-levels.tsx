import { useId } from 'react';
import type { FollowCategories } from '@/lib/push';
import { cn } from '@/lib/utils';

type Level = {
    key: string;
    title: string;
    /** Fits in one segment of the picker. */
    short: string;
    help: string;
    categories: FollowCategories;
};

/**
 * How much a follower hears about a result, from the quietest to everything:
 * each level is a combination of the kinds of notification.
 */
export const LEVELS: Level[] = [
    {
        key: 'final',
        title: 'Só o resultado final',
        short: 'Só o final',
        help: 'Um único aviso, quando a apuração terminar.',
        categories: {
            notify_updates: false,
            notify_decided: false,
            notify_final: true,
        },
    },
    {
        key: 'decisive',
        title: 'Momentos decisivos',
        short: 'Decisivos',
        help: 'Quando já houver eleito ou 2º turno garantido, e no fim da apuração.',
        categories: {
            notify_updates: false,
            notify_decided: true,
            notify_final: true,
        },
    },
    {
        key: 'all',
        title: 'Tudo, em tempo real',
        short: 'Tudo',
        help: 'Também cada nova parcial do TSE, no máximo a cada 10 minutos.',
        categories: {
            notify_updates: true,
            notify_decided: true,
            notify_final: true,
        },
    },
];

export const DEFAULT_LEVEL = LEVELS[2];

const CATEGORY_NAMES: Record<keyof FollowCategories, string> = {
    notify_updates: 'parciais do TSE',
    notify_decided: 'resultado definido',
    notify_final: 'fim da apuração',
};

/**
 * The level matching these notifications, or null for a combination chosen
 * before the levels existed.
 */
export function levelOf(categories: FollowCategories): Level | null {
    return (
        LEVELS.find((level) =>
            (Object.keys(level.categories) as (keyof FollowCategories)[]).every(
                (key) => level.categories[key] === categories[key],
            ),
        ) ?? null
    );
}

/**
 * What a combination outside the levels sends: "Parciais do TSE, fim da
 * apuração".
 */
function describe(categories: FollowCategories): string {
    return (Object.keys(CATEGORY_NAMES) as (keyof FollowCategories)[])
        .filter((key) => categories[key])
        .map((key) => CATEGORY_NAMES[key])
        .join(', ')
        .replace(/^./, (letter) => letter.toUpperCase());
}

type PickerProps = {
    value: FollowCategories;
    onChange: (categories: FollowCategories) => void;
    disabled?: boolean;
};

/**
 * The level of a result already followed: segments from the quietest to
 * everything, with what the chosen one sends.
 */
export function NotificationLevelPicker({
    value,
    onChange,
    disabled = false,
}: PickerProps) {
    const current = levelOf(value);
    const name = useId();

    return (
        <fieldset disabled={disabled} className="mt-3 text-sm">
            <legend className="mb-1.5 text-muted">Avisar</legend>
            <div className="grid w-full grid-cols-3 gap-1 rounded-xl bg-subtle p-1 sm:inline-grid sm:w-auto">
                {LEVELS.map((level) => (
                    <label
                        key={level.key}
                        className={cn(
                            'flex min-h-10 cursor-pointer items-center justify-center rounded-lg px-3 text-center font-semibold transition-colors has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus',
                            current?.key === level.key
                                ? 'bg-primary text-on-primary shadow-sm'
                                : 'text-muted hover:bg-surface hover:text-ink',
                            disabled && 'cursor-wait opacity-60',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={level.key}
                            checked={current?.key === level.key}
                            onChange={() => onChange(level.categories)}
                            className="sr-only"
                        />
                        {level.short}
                    </label>
                ))}
            </div>
            <p className="mt-1.5 text-muted" aria-live="polite">
                {current ? (
                    <>
                        <strong className="text-ink">{current.title}:</strong>{' '}
                        {current.help}
                    </>
                ) : (
                    <>
                        <strong className="text-ink">Personalizado:</strong>{' '}
                        {describe(value)}. Escolha um nível para trocar.
                    </>
                )}
            </p>
        </fieldset>
    );
}

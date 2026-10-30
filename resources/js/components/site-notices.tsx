import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { SiteNotice } from '@/types';

const CLOSED_KEY = 'avisos.fechados';

const LEVELS: Record<
    SiteNotice['level'],
    { icon: string; label: string; className: string }
> = {
    informacao: {
        icon: 'ℹ️',
        label: 'Informação',
        className: 'border-primary/30 bg-primary-soft text-ink',
    },
    atencao: {
        icon: '⚠️',
        label: 'Atenção',
        className: 'border-warning/40 bg-warning-soft text-warning',
    },
};

/** "12:1730300000": a notice as closed, until it is edited. */
const closedKey = (notice: SiteNotice) => `${notice.id}:${notice.version}`;

function readClosed(): string[] {
    try {
        return JSON.parse(localStorage.getItem(CLOSED_KEY) ?? '[]');
    } catch {
        return [];
    }
}

/**
 * The notices of the site, at the top of a page, each with a way to close
 * it in this browser.
 */
export function SiteNoticeBanner({ notices }: { notices: SiteNotice[] }) {
    // Only known in the browser: read after mounting.
    const [closed, setClosed] = useState<string[]>([]);

    useEffect(() => setClosed(readClosed()), []);

    function close(notice: SiteNotice) {
        // Only keeps the notices still shown, so the list does not grow.
        const next = [
            ...closed.filter((key) =>
                notices.some((item) => closedKey(item) === key),
            ),
            closedKey(notice),
        ];

        setClosed(next);

        try {
            localStorage.setItem(CLOSED_KEY, JSON.stringify(next));
        } catch {
            // Storage may be blocked: it is closed until the next page.
        }
    }

    const shown = notices.filter(
        (notice) => !closed.includes(closedKey(notice)),
    );

    if (shown.length === 0) {
        return null;
    }

    return (
        <div>
            {shown.map((notice) => (
                <NoticeStrip
                    key={notice.id}
                    level={notice.level}
                    message={notice.message}
                    onClose={() => close(notice)}
                />
            ))}
        </div>
    );
}

/**
 * One notice as shown on the site; also the preview in the admin.
 */
export function NoticeStrip({
    level,
    message,
    onClose,
}: {
    level: SiteNotice['level'];
    message: string;
    onClose?: () => void;
}) {
    const style = LEVELS[level];

    return (
        <section
            aria-label={`Aviso: ${style.label}`}
            className={cn('border-b', style.className)}
        >
            <div className="mx-auto flex max-w-7xl items-start gap-3 px-4 py-2">
                <span aria-hidden="true" className="pt-2">
                    {style.icon}
                </span>
                <p className="min-w-0 flex-1 py-2 font-medium wrap-anywhere">
                    <span className="sr-only">{style.label}: </span>
                    {message}
                </p>
                {onClose && (
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Fechar aviso"
                        className="flex size-10 shrink-0 items-center justify-center rounded-lg hover:bg-surface/60"
                    >
                        <span aria-hidden="true">✕</span>
                    </button>
                )}
            </div>
        </section>
    );
}

/**
 * Default layout of every page: the notices of the site above it.
 */
export default function SiteLayout({ children }: { children: ReactNode }) {
    const { notices } = usePage().props;

    return (
        <>
            <SiteNoticeBanner notices={notices} />
            {children}
        </>
    );
}

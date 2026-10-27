import { useEffect, useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** What is being chosen, always visible: "Eleição", "Estado". */
    label: string;
    /** Current choice, or what to do when there is none yet. */
    value: string;
    /** Highlights a step that still has to be chosen. */
    pending?: boolean;
    /** Wider panel, for long lists. */
    wide?: boolean;
    /** The options, or a function of what closes the menu. */
    children: ReactNode | ((close: () => void) => ReactNode);
};

/**
 * One step of the guided selection: a button with the current choice that
 * opens the options, built on a native disclosure so it works with the
 * keyboard and screen readers. It closes with Escape, a click outside or
 * once an option is chosen.
 */
export default function PickerMenu({
    label,
    value,
    pending = false,
    wide = false,
    children,
}: Props) {
    const detailsRef = useRef<HTMLDetailsElement>(null);
    const panelId = useId();

    useEffect(() => {
        const details = detailsRef.current;

        if (!details) {
            return;
        }

        const close = () => {
            details.open = false;
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && details.open) {
                close();
                details.querySelector('summary')?.focus();
            }
        };
        const onClick = (event: MouseEvent) => {
            if (!details.contains(event.target as Node)) {
                close();
            }
        };

        document.addEventListener('keydown', onKey);
        document.addEventListener('click', onClick);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('click', onClick);
        };
    }, []);

    return (
        <details ref={detailsRef} className="group relative">
            <summary
                aria-controls={panelId}
                className={cn(
                    'flex min-h-12 cursor-pointer list-none flex-col justify-center rounded-xl border-2 bg-surface px-4 py-1.5 transition-colors hover:border-primary [&::-webkit-details-marker]:hidden',
                    pending ? 'border-primary' : 'border-line-strong',
                )}
            >
                <span className="text-sm text-muted">{label}</span>
                <span className="flex items-center gap-2 font-semibold">
                    {value}
                    <span
                        aria-hidden="true"
                        className="text-sm transition-transform duration-300 group-open:rotate-180"
                    >
                        ▾
                    </span>
                </span>
            </summary>
            <div
                id={panelId}
                onClick={(event) => {
                    if ((event.target as HTMLElement).closest('a')) {
                        detailsRef.current!.open = false;
                    }
                }}
                className={cn(
                    'z-30 max-h-[70vh] origin-top animate-menu-in overflow-y-auto rounded-xl border border-line bg-surface p-3 shadow-xl',
                    // Small screens: the whole width, wherever the button is.
                    'max-sm:fixed max-sm:inset-x-4 max-sm:top-24',
                    'sm:absolute sm:top-full sm:left-0 sm:mt-2',
                    wide ? 'sm:w-[40rem]' : 'sm:w-72',
                )}
            >
                {typeof children === 'function'
                    ? children(() => {
                          detailsRef.current!.open = false;
                      })
                    : children}
            </div>
        </details>
    );
}

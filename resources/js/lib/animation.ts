import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** Fast at first, gently settling on the final value. */
export function easeOutCubic(t: number): number {
    return 1 - (1 - t) ** 3;
}

/**
 * A number that glides to each new value instead of jumping, starting from
 * zero when first shown.
 */
export function useAnimatedNumber(value: number, duration = 700): number {
    const [shown, setShown] = useState(() =>
        prefersReducedMotion() ? value : 0,
    );
    const from = useRef(shown);

    useEffect(() => {
        if (prefersReducedMotion()) {
            from.current = value;
            setShown(value);

            return;
        }

        const start = performance.now();
        const initial = from.current;
        let frame = requestAnimationFrame(function step(now) {
            const t = Math.min((now - start) / duration, 1);
            const current = initial + (value - initial) * easeOutCubic(t);

            from.current = current;
            setShown(current);

            if (t < 1) {
                frame = requestAnimationFrame(step);
            }
        });

        return () => cancelAnimationFrame(frame);
    }, [value, duration]);

    return shown;
}

/**
 * Slides the children of a list to their new places when they are reordered
 * (FLIP): each child marked with data-flip-key starts where it was and moves
 * to where it is now.
 */
export function useFlipList<T extends HTMLElement>(order: string) {
    const listRef = useRef<T>(null);
    const positions = useRef(new Map<string, [number, number]>());

    useLayoutEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        const items = [
            ...list.querySelectorAll<HTMLElement>('[data-flip-key]'),
        ];
        const previous = positions.current;
        const animate = previous.size > 0 && !prefersReducedMotion();

        positions.current = new Map(
            items.map((item) => [
                item.dataset.flipKey!,
                [item.offsetLeft, item.offsetTop],
            ]),
        );

        if (!animate) {
            return;
        }

        for (const item of items) {
            const before = previous.get(item.dataset.flipKey!);
            const now = positions.current.get(item.dataset.flipKey!)!;

            if (before && (before[0] !== now[0] || before[1] !== now[1])) {
                item.animate(
                    [
                        {
                            transform: `translate(${before[0] - now[0]}px, ${before[1] - now[1]}px)`,
                        },
                        { transform: 'translate(0, 0)' },
                    ],
                    { duration: 500, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' },
                );
            }
        }
    }, [order]);

    return listRef;
}

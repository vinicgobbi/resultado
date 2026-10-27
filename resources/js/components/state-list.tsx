import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Place } from '@/types';

type Props = {
    places: Place[];
    /** Page of a state. */
    href: (code: string) => InertiaLinkProps['href'];
    current?: string | null;
    className?: string;
};

/**
 * Every state where the office is disputed, as links.
 */
export default function StateList({ places, href, current, className }: Props) {
    return (
        <ul
            className={cn(
                'grid grid-cols-1 gap-2 min-[400px]:grid-cols-2',
                className,
            )}
        >
            {places.map((place) => (
                <li key={place.code}>
                    <Link
                        href={href(place.code)}
                        aria-current={
                            place.code === current ? 'page' : undefined
                        }
                        className={cn(
                            'flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 font-medium hover:border-primary hover:bg-primary-soft',
                            place.code === current
                                ? 'border-primary bg-primary-soft'
                                : 'border-line',
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className="w-7 text-sm font-bold text-muted"
                        >
                            {place.code}
                        </span>
                        {place.name}
                    </Link>
                </li>
            ))}
        </ul>
    );
}

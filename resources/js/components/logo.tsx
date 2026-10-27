import { cn } from '@/lib/utils';

/**
 * The site logo: a count progress ring, closed by a check. Decorative next
 * to the site name, so screen readers skip it.
 */
export default function Logo({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 512 512"
            aria-hidden="true"
            className={cn('size-10 shrink-0', className)}
        >
            <rect width="512" height="512" rx="112" fill="#1e4fbf" />
            <circle
                cx="256"
                cy="256"
                r="146"
                fill="none"
                stroke="#fff"
                strokeOpacity=".3"
                strokeWidth="76"
            />
            <circle
                cx="256"
                cy="256"
                r="146"
                fill="none"
                stroke="#ffd25e"
                strokeWidth="76"
                strokeLinecap="round"
                strokeDasharray="560 918"
                transform="rotate(-90 256 256)"
            />
            <path
                d="M200 260l38 38 74-82"
                fill="none"
                stroke="#fff"
                strokeWidth="38"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

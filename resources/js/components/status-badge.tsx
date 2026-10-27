import { STATUS } from '@/lib/election';
import { cn } from '@/lib/utils';
import type { ElectionStatus } from '@/types';

export default function StatusBadge({
    status,
    className,
}: {
    status: ElectionStatus;
    className?: string;
}) {
    const { label, icon, className: colors } = STATUS[status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-sm font-semibold',
                colors,
                className,
            )}
        >
            <span aria-hidden="true" className="text-xs">
                {icon}
            </span>
            {label}
        </span>
    );
}

import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import type { Candidate } from '@/types';

type Props = {
    candidate: Candidate;
    alt?: string;
    className?: string;
    style?: CSSProperties;
    /** Shown while the TSE has no photo of the candidate. */
    fallback: ReactNode;
};

export default function CandidatePhoto({
    candidate,
    alt = '',
    className,
    style,
    fallback,
}: Props) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);

    if (failedUrl === candidate.photo_url) {
        return fallback;
    }

    return (
        <img
            src={candidate.photo_url}
            alt={alt}
            loading="lazy"
            className={className}
            style={style}
            onError={() => setFailedUrl(candidate.photo_url)}
        />
    );
}

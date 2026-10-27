import { useEffect } from 'react';
import CandidatePhoto from '@/components/candidate-photo';
import { contrastText } from '@/lib/format';
import type { Candidate, Runoff } from '@/types';

type Props = {
    runoff: Runoff;
    candidates: Map<number, Candidate>;
    onClose: () => void;
};

/** The toast leaves by itself after this long. */
const VISIBLE_MS = 9000;

/**
 * A quiet celebration for the candidates sure of a place in the runoff: a
 * toast in a corner with their faces, that does not cover the results and
 * goes away by itself.
 */
export default function RunoffToast({ runoff, candidates, onClose }: Props) {
    const qualified = runoff.candidate_ids
        .map((id) => candidates.get(id))
        .filter((candidate): candidate is Candidate => candidate !== undefined);
    const confirmed = runoff.status === 'confirmed';

    useEffect(() => {
        const timer = window.setTimeout(onClose, VISIBLE_MS);

        return () => clearTimeout(timer);
    }, [onClose]);

    if (qualified.length === 0) {
        return null;
    }

    const names = qualified.map((candidate) => candidate.name).join(' e ');

    return (
        <div
            role="status"
            className="fixed inset-x-4 bottom-4 z-40 animate-fade-up rounded-2xl border border-line bg-surface p-4 shadow-2xl sm:right-4 sm:left-auto sm:w-96"
        >
            <div className="flex items-center gap-4">
                <div className="flex shrink-0 -space-x-3">
                    {qualified.map((candidate, index) => (
                        <div
                            key={candidate.id}
                            className="animate-badge-in"
                            style={{ animationDelay: `${index * 120}ms` }}
                        >
                            <div
                                className="size-14 rounded-full p-0.5 motion-safe:animate-[glow_2s_ease-in-out_2]"
                                style={{
                                    backgroundColor: candidate.color,
                                    // Used by the glow keyframes.
                                    ['--glow' as string]: candidate.color,
                                }}
                            >
                                <CandidatePhoto
                                    key={candidate.photo_url}
                                    candidate={candidate}
                                    className="size-full rounded-full border-2 border-surface object-cover object-top"
                                    fallback={
                                        <span
                                            className="flex size-full items-center justify-center rounded-full border-2 border-surface font-bold"
                                            style={{
                                                backgroundColor:
                                                    candidate.color,
                                                color: contrastText(
                                                    candidate.color,
                                                ),
                                            }}
                                        >
                                            {candidate.number}
                                        </span>
                                    }
                                />
                            </div>
                        </div>
                    ))}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="font-bold text-primary">
                        <span aria-hidden="true">🗳️ </span>
                        {confirmed
                            ? 'Vão ao 2º turno'
                            : 'Garantido no 2º turno'}
                    </p>
                    <p className="font-semibold">{names}</p>
                    {!confirmed && qualified.length === 1 && (
                        <p className="text-sm text-muted">
                            A outra vaga ainda está em disputa.
                        </p>
                    )}
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Fechar aviso"
                    className="flex size-11 shrink-0 items-center justify-center self-start rounded-full text-muted hover:bg-subtle hover:text-ink"
                >
                    ✕
                </button>
            </div>
        </div>
    );
}

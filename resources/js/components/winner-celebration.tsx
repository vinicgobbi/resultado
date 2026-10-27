import { useEffect, useRef, useState } from 'react';
import CandidatePhoto from '@/components/candidate-photo';
import { createConfetti, prefersReducedMotion } from '@/lib/confetti';
import { formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Candidate, CandidateResult, Winner } from '@/types';

export type CelebratedWinner = {
    winner: Winner;
    candidate: Candidate;
    result: CandidateResult | undefined;
};

type Props = {
    winners: CelebratedWinner[];
    /** E.g. "Eleito governador de São Paulo". */
    title: string;
    round: number;
    onClose: () => void;
};

/** Above this, the winners (deputies) are shown as a mosaic of faces. */
const MOSAIC_FROM = 5;

const BRAZIL = ['#009739', '#FEDD00', '#012169', '#ffffff'];

export default function WinnerCelebration({
    winners,
    title,
    round,
    onClose,
}: Props) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const photoRef = useRef<HTMLDivElement>(null);
    const closeRef = useRef<HTMLButtonElement>(null);
    // The confetti waits for the card to finish popping in, so the photo is
    // measured where it ends up.
    const [entered, setEntered] = useState(false);
    const elected = winners.every(({ winner }) => winner.status === 'elected');
    const [{ candidate, result }] = winners;
    const mosaic = winners.length >= MOSAIC_FROM;
    const colors = [
        ...new Set(winners.map(({ candidate }) => candidate.color)),
    ].slice(0, 6);
    const colorKey = colors.join();

    useEffect(() => {
        const fallback = window.setTimeout(() => setEntered(true), 900);

        return () => clearTimeout(fallback);
    }, []);

    useEffect(() => {
        closeRef.current?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    useEffect(() => {
        if (!entered || !canvasRef.current || prefersReducedMotion()) {
            return;
        }

        const confetti = createConfetti(canvasRef.current);
        const palette = [...colorKey.split(','), ...BRAZIL, '#f59e0b'];
        const timers: number[] = [];
        const later = (ms: number, fn: () => void) =>
            timers.push(window.setTimeout(fn, ms));

        const fromPhoto = (count: number, speed: number) => {
            const rect = photoRef.current?.getBoundingClientRect();
            const x = rect ? rect.left + rect.width / 2 : window.innerWidth / 2;
            const y = rect
                ? rect.top + rect.height / 2
                : window.innerHeight / 3;

            confetti.burst(x, y, { count, speed, colors: palette });
        };

        // The photo explodes in waves.
        later(0, () => fromPhoto(180, 18));
        later(300, () => fromPhoto(120, 14));
        later(650, () => fromPhoto(90, 11));

        // Cannons from the bottom corners for a few seconds.
        for (let i = 0; i < 6; i++) {
            later(100 + i * 450, () => {
                const height = window.innerHeight;
                confetti.burst(0, height, {
                    count: 45,
                    angle: -Math.PI / 3,
                    spread: 0.6,
                    speed: 22,
                    colors: palette,
                });
                confetti.burst(window.innerWidth, height, {
                    count: 45,
                    angle: (-2 * Math.PI) / 3,
                    spread: 0.6,
                    speed: 22,
                    colors: palette,
                });
            });
        }

        return () => {
            timers.forEach(clearTimeout);
            confetti.destroy();
        };
    }, [entered, colorKey]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/70 p-4 backdrop-blur-sm motion-safe:animate-[fade-in_300ms_ease-out]"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="winner-title"
        >
            <div
                className={cn(
                    'relative w-full rounded-3xl bg-surface p-8 text-center shadow-2xl motion-safe:animate-[pop-in_500ms_cubic-bezier(0.34,1.56,0.64,1)]',
                    mosaic ? 'max-w-2xl' : 'max-w-md',
                )}
                onClick={(event) => event.stopPropagation()}
                onAnimationEnd={(event) =>
                    event.animationName === 'pop-in' && setEntered(true)
                }
            >
                {winners.length === 1 ? (
                    <div
                        ref={photoRef}
                        className="relative mx-auto -mt-24 size-44 rounded-full p-1.5 shadow-xl motion-safe:animate-[glow_2s_ease-in-out_infinite]"
                        style={{
                            backgroundColor: candidate.color,
                            // Used by the glow keyframes.
                            ['--glow' as string]: candidate.color,
                        }}
                    >
                        <CandidatePhoto
                            key={candidate.photo_url}
                            candidate={candidate}
                            alt={candidate.full_name}
                            className="size-full rounded-full border-4 border-surface object-cover object-top"
                            fallback={
                                <span className="flex size-full items-center justify-center rounded-full border-4 border-surface bg-subtle text-5xl font-black text-ink">
                                    {candidate.number}
                                </span>
                            }
                        />
                        <span className="absolute -right-1 bottom-2 rounded-full bg-surface px-2.5 py-1 text-lg font-black text-ink shadow">
                            {candidate.number}
                        </span>
                    </div>
                ) : (
                    <div
                        ref={photoRef}
                        className={cn(
                            mosaic
                                ? '-mt-4 grid max-h-72 grid-cols-[repeat(auto-fill,minmax(3.5rem,1fr))] gap-2 overflow-y-auto p-1'
                                : '-mt-20 flex justify-center gap-4',
                        )}
                    >
                        {winners.map(({ candidate: winnerCandidate }) => (
                            <figure
                                key={winnerCandidate.id}
                                className="min-w-0"
                                title={`${winnerCandidate.name} (${winnerCandidate.party})`}
                            >
                                <div
                                    className={cn(
                                        'mx-auto rounded-full shadow-lg motion-safe:animate-[glow_2s_ease-in-out_infinite]',
                                        mosaic
                                            ? 'size-14 p-0.5'
                                            : 'size-32 p-1',
                                    )}
                                    style={{
                                        backgroundColor: winnerCandidate.color,
                                        ['--glow' as string]:
                                            winnerCandidate.color,
                                    }}
                                >
                                    <CandidatePhoto
                                        key={winnerCandidate.photo_url}
                                        candidate={winnerCandidate}
                                        alt={winnerCandidate.full_name}
                                        className="size-full rounded-full border-2 border-surface object-cover object-top"
                                        fallback={
                                            <span
                                                className={cn(
                                                    'flex size-full items-center justify-center rounded-full border-2 border-surface bg-subtle font-black text-ink',
                                                    mosaic
                                                        ? 'text-[10px]'
                                                        : 'text-3xl',
                                                )}
                                            >
                                                {winnerCandidate.number}
                                            </span>
                                        }
                                    />
                                </div>
                                {!mosaic && (
                                    <figcaption className="mt-2 truncate text-sm font-bold text-ink">
                                        {winnerCandidate.name}
                                    </figcaption>
                                )}
                            </figure>
                        ))}
                    </div>
                )}

                <p className="mt-6 text-xs font-bold tracking-[0.2em] text-success uppercase">
                    {title}
                </p>
                {winners.length === 1 ? (
                    <>
                        <h2
                            id="winner-title"
                            className="mt-1 text-3xl font-black text-ink"
                        >
                            {candidate.name}
                        </h2>
                        <p className="text-sm text-muted">
                            {candidate.party} · {round}º turno
                        </p>

                        {result && (
                            <p className="mt-4 text-4xl font-bold text-ink tabular-nums">
                                {formatPercent(result.percent)}
                                <span className="block text-sm font-normal text-muted">
                                    {formatNumber(result.votes)} votos
                                </span>
                            </p>
                        )}
                    </>
                ) : (
                    <h2
                        id="winner-title"
                        className="mt-1 text-2xl font-black text-ink"
                    >
                        {mosaic
                            ? `${winners.length} eleitos`
                            : winners
                                  .map(({ candidate }) => candidate.name)
                                  .join(' e ')}
                    </h2>
                )}

                {!elected && (
                    <p className="mt-4 text-sm text-muted">
                        Mesmo que todos os eleitores ainda não apurados votassem
                        contra, a vitória não muda mais.
                    </p>
                )}

                <button
                    ref={closeRef}
                    type="button"
                    onClick={onClose}
                    className="mt-6 w-full rounded-xl bg-success px-4 py-3 font-semibold text-on-success hover:bg-success-hover"
                >
                    Ver os resultados
                </button>
            </div>

            <canvas
                ref={canvasRef}
                className="pointer-events-none fixed inset-0 size-full"
                aria-hidden="true"
            />
        </div>
    );
}

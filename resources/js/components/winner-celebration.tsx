import { useEffect, useRef, useState } from 'react';
import { createConfetti, prefersReducedMotion } from '@/lib/confetti';
import { formatNumber, formatPercent } from '@/lib/format';
import type { Candidate, CandidateResult, Winner } from '@/types';

type Props = {
    winner: Winner;
    candidate: Candidate;
    result: CandidateResult | undefined;
    round: number;
    onClose: () => void;
};

const BRAZIL = ['#009739', '#FEDD00', '#012169', '#ffffff'];

export default function WinnerCelebration({
    winner,
    candidate,
    result,
    round,
    onClose,
}: Props) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const photoRef = useRef<HTMLDivElement>(null);
    const closeRef = useRef<HTMLButtonElement>(null);
    // The confetti waits for the card to finish popping in, so the photo is
    // measured where it ends up.
    const [entered, setEntered] = useState(false);
    const elected = winner.status === 'elected';

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
        const colors = [candidate.color, ...BRAZIL, '#f59e0b'];
        const timers: number[] = [];
        const later = (ms: number, fn: () => void) =>
            timers.push(window.setTimeout(fn, ms));

        const fromPhoto = (count: number, speed: number) => {
            const rect = photoRef.current?.getBoundingClientRect();
            const x = rect ? rect.left + rect.width / 2 : window.innerWidth / 2;
            const y = rect
                ? rect.top + rect.height / 2
                : window.innerHeight / 3;

            confetti.burst(x, y, { count, speed, colors });
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
                    colors,
                });
                confetti.burst(window.innerWidth, height, {
                    count: 45,
                    angle: (-2 * Math.PI) / 3,
                    spread: 0.6,
                    speed: 22,
                    colors,
                });
            });
        }

        return () => {
            timers.forEach(clearTimeout);
            confetti.destroy();
        };
    }, [entered, candidate.color]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/70 p-4 backdrop-blur-sm motion-safe:animate-[fade-in_300ms_ease-out]"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="winner-title"
        >
            <div
                className="relative w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-2xl motion-safe:animate-[pop-in_500ms_cubic-bezier(0.34,1.56,0.64,1)] dark:bg-zinc-900"
                onClick={(event) => event.stopPropagation()}
                onAnimationEnd={(event) =>
                    event.animationName === 'pop-in' && setEntered(true)
                }
            >
                <div
                    ref={photoRef}
                    className="relative mx-auto -mt-24 size-44 rounded-full p-1.5 shadow-xl motion-safe:animate-[glow_2s_ease-in-out_infinite]"
                    style={{
                        backgroundColor: candidate.color,
                        // Used by the glow keyframes.
                        ['--glow' as string]: candidate.color,
                    }}
                >
                    {candidate.photo_url ? (
                        <img
                            src={candidate.photo_url}
                            alt={candidate.full_name}
                            className="size-full rounded-full border-4 border-white object-cover object-top dark:border-zinc-900"
                        />
                    ) : (
                        <span className="flex size-full items-center justify-center rounded-full border-4 border-white bg-zinc-100 text-5xl font-black text-zinc-700 dark:border-zinc-900 dark:bg-zinc-800 dark:text-zinc-200">
                            {candidate.number}
                        </span>
                    )}
                    <span className="absolute -right-1 bottom-2 rounded-full bg-white px-2.5 py-1 text-lg font-black shadow dark:bg-zinc-800">
                        {candidate.number}
                    </span>
                </div>

                <p className="mt-6 text-xs font-bold tracking-[0.2em] text-emerald-600 uppercase">
                    {elected
                        ? 'Eleito presidente do Brasil'
                        : 'Matematicamente eleito'}
                </p>
                <h2
                    id="winner-title"
                    className="mt-1 text-3xl font-black text-zinc-900 dark:text-zinc-50"
                >
                    {candidate.name}
                </h2>
                <p className="text-sm text-zinc-500">
                    {candidate.party} · {round}º turno
                </p>

                {result && (
                    <p className="mt-4 text-4xl font-bold text-zinc-900 tabular-nums dark:text-zinc-50">
                        {formatPercent(result.percent)}
                        <span className="block text-sm font-normal text-zinc-500">
                            {formatNumber(result.votes)} votos
                        </span>
                    </p>
                )}

                {!elected && (
                    <p className="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Mesmo que todos os eleitores ainda não apurados votassem
                        contra, a vitória não muda mais.
                    </p>
                )}

                <button
                    ref={closeRef}
                    type="button"
                    onClick={onClose}
                    className="mt-6 w-full rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-700"
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

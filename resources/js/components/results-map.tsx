import { useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import { geometry as geometryRoute } from '@/routes/map';
import type { Candidate, MapGeometry, MapResults } from '@/types';

type ViewBox = { x: number; y: number; w: number; h: number };

type Hover = { ibge: string; x: number; y: number };

type Props = {
    results: MapResults | undefined;
    candidates: Map<number, Candidate>;
    selectedState: string;
    onSelect: (ibge: string) => void;
};

const MIN_ZOOM_WIDTH = 8;

/**
 * Leader share (40% → 80%) mapped to fill opacity, so landslides look darker.
 */
function shareOpacity(percent: number): number {
    const t = Math.min(Math.max((percent - 40) / 40, 0), 1);

    return 0.3 + t * 0.7;
}

export default function ResultsMap({
    results,
    candidates,
    selectedState,
    onSelect,
}: Props) {
    const [geometry, setGeometry] = useState<MapGeometry | null>(null);
    const [failed, setFailed] = useState(false);
    const [viewBox, setViewBox] = useState<ViewBox | null>(null);
    const [hover, setHover] = useState<Hover | null>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const pointers = useRef(new Map<number, { x: number; y: number }>());
    const gesture = useRef<{
        moved: number;
        pinchDistance: number | null;
    }>({ moved: 0, pinchDistance: null });

    useEffect(() => {
        let cancelled = false;

        fetch(geometryRoute.url())
            .then((response) => {
                if (!response.ok) {
                    throw new Error('geometry');
                }

                return response.json() as Promise<MapGeometry>;
            })
            .then((data) => {
                if (!cancelled) {
                    setGeometry(data);
                }
            })
            .catch(() => !cancelled && setFailed(true));

        return () => {
            cancelled = true;
        };
    }, []);

    const fullView: ViewBox | null = geometry
        ? { x: 0, y: 0, w: geometry.width, h: geometry.height }
        : null;

    useEffect(() => {
        if (!geometry) {
            return;
        }

        const state = selectedState ? geometry.states[selectedState] : null;

        if (!state) {
            setViewBox({ x: 0, y: 0, w: geometry.width, h: geometry.height });

            return;
        }

        const [x, y, w, h] = state.bbox;
        const padding = Math.max(w, h) * 0.08;

        setViewBox({
            x: x - padding,
            y: y - padding,
            w: w + padding * 2,
            h: h + padding * 2,
        });
    }, [geometry, selectedState]);

    // Wheel zoom needs a non-passive listener to prevent page scrolling.
    useEffect(() => {
        const svg = svgRef.current;

        if (!svg) {
            return;
        }

        const onWheel = (event: WheelEvent) => {
            event.preventDefault();
            zoomAt(
                event.clientX,
                event.clientY,
                event.deltaY > 0 ? 1.2 : 1 / 1.2,
            );
        };

        svg.addEventListener('wheel', onWheel, { passive: false });

        return () => svg.removeEventListener('wheel', onWheel);
    });

    function toSvgPoint(clientX: number, clientY: number, box: ViewBox) {
        const rect = svgRef.current!.getBoundingClientRect();
        const scale = Math.max(box.w / rect.width, box.h / rect.height);
        const offsetX = (rect.width * scale - box.w) / 2;
        const offsetY = (rect.height * scale - box.h) / 2;

        return {
            x: box.x - offsetX + (clientX - rect.left) * scale,
            y: box.y - offsetY + (clientY - rect.top) * scale,
            scale,
        };
    }

    function zoomAt(clientX: number, clientY: number, factor: number) {
        setViewBox((box) => {
            if (!box || !fullView) {
                return box;
            }

            const w = Math.min(
                Math.max(box.w * factor, MIN_ZOOM_WIDTH),
                fullView.w * 1.5,
            );
            const ratio = w / box.w;
            const point = toSvgPoint(clientX, clientY, box);

            return {
                x: point.x - (point.x - box.x) * ratio,
                y: point.y - (point.y - box.y) * ratio,
                w,
                h: box.h * ratio,
            };
        });
    }

    function zoomCenter(factor: number) {
        const rect = svgRef.current?.getBoundingClientRect();

        if (rect) {
            zoomAt(
                rect.left + rect.width / 2,
                rect.top + rect.height / 2,
                factor,
            );
        }
    }

    function onPointerDown(event: ReactPointerEvent<SVGSVGElement>) {
        event.currentTarget.setPointerCapture(event.pointerId);
        pointers.current.set(event.pointerId, {
            x: event.clientX,
            y: event.clientY,
        });
        gesture.current = { moved: 0, pinchDistance: null };
    }

    function onPointerMove(event: ReactPointerEvent<SVGSVGElement>) {
        const previous = pointers.current.get(event.pointerId);

        if (!previous) {
            if (event.pointerType === 'mouse') {
                updateHover(event);
            }

            return;
        }

        pointers.current.set(event.pointerId, {
            x: event.clientX,
            y: event.clientY,
        });

        if (pointers.current.size === 2) {
            const [a, b] = [...pointers.current.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);

            if (gesture.current.pinchDistance !== null) {
                zoomAt(
                    (a.x + b.x) / 2,
                    (a.y + b.y) / 2,
                    gesture.current.pinchDistance / distance,
                );
            }

            gesture.current.pinchDistance = distance;
            gesture.current.moved += 10;

            return;
        }

        const dx = event.clientX - previous.x;
        const dy = event.clientY - previous.y;
        gesture.current.moved += Math.abs(dx) + Math.abs(dy);

        if (gesture.current.moved > 4) {
            setHover(null);
            setViewBox((box) => {
                if (!box) {
                    return box;
                }

                const { scale } = toSvgPoint(0, 0, box);

                return { ...box, x: box.x - dx * scale, y: box.y - dy * scale };
            });
        }
    }

    function onPointerUp(event: ReactPointerEvent<SVGSVGElement>) {
        pointers.current.delete(event.pointerId);

        if (pointers.current.size > 0) {
            return;
        }

        if (gesture.current.moved <= 4) {
            const target = document.elementFromPoint(
                event.clientX,
                event.clientY,
            );
            const ibge = target?.getAttribute('data-ibge');

            if (ibge) {
                onSelect(ibge);
            }
        }
    }

    function updateHover(event: ReactPointerEvent<SVGSVGElement>) {
        const ibge = (event.target as Element).getAttribute('data-ibge');
        const rect = svgRef.current!.getBoundingClientRect();

        setHover(
            ibge
                ? {
                      ibge,
                      x: event.clientX - rect.left,
                      y: event.clientY - rect.top,
                  }
                : null,
        );
    }

    if (failed) {
        return (
            <div className="flex aspect-square items-center justify-center rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">
                Mapa indisponível. Gere a geometria com{' '}
                <code className="mx-1 rounded bg-zinc-100 px-1 dark:bg-zinc-800">
                    php artisan mapa:baixar
                </code>
            </div>
        );
    }

    if (!geometry || !viewBox) {
        return (
            <div className="aspect-square animate-pulse rounded-xl bg-zinc-200/70 dark:bg-zinc-800/70" />
        );
    }

    const hovered = hover ? results?.[hover.ibge] : undefined;
    const hoveredName = hover ? geometry.names[hover.ibge] : undefined;
    const hoveredLeader =
        hovered?.[0] != null ? candidates.get(hovered[0]) : undefined;

    return (
        <div className="relative">
            <svg
                ref={svgRef}
                viewBox={`${viewBox.x} ${viewBox.y} ${viewBox.w} ${viewBox.h}`}
                className="aspect-square w-full cursor-grab touch-none select-none active:cursor-grabbing"
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onPointerLeave={() => setHover(null)}
                role="img"
                aria-label="Mapa do Brasil com o candidato mais votado em cada município"
            >
                <g>
                    {Object.entries(geometry.municipalities).map(
                        ([ibge, d]) => {
                            const result = results?.[ibge];
                            const leader =
                                result?.[0] != null
                                    ? candidates.get(result[0])
                                    : undefined;

                            return (
                                <path
                                    key={ibge}
                                    d={d}
                                    data-ibge={ibge}
                                    fill={leader?.color}
                                    fillOpacity={
                                        leader ? shareOpacity(result![1]) : 1
                                    }
                                    className={cn(
                                        'stroke-white/60 dark:stroke-zinc-950/60',
                                        !leader &&
                                            'fill-zinc-200 dark:fill-zinc-800',
                                        hover?.ibge === ibge &&
                                            'stroke-zinc-950 dark:stroke-white',
                                    )}
                                    strokeWidth={
                                        hover?.ibge === ibge ? 1.5 : 0.3
                                    }
                                    vectorEffect="non-scaling-stroke"
                                />
                            );
                        },
                    )}
                </g>
                <g className="pointer-events-none">
                    {Object.entries(geometry.states).map(([code, state]) => (
                        <path
                            key={code}
                            d={state.d}
                            fill="none"
                            className={cn(
                                'stroke-zinc-700 dark:stroke-zinc-300',
                                selectedState === code &&
                                    'stroke-zinc-950 dark:stroke-white',
                            )}
                            strokeWidth={selectedState === code ? 2 : 0.8}
                            vectorEffect="non-scaling-stroke"
                        />
                    ))}
                </g>
            </svg>

            <div className="absolute top-3 right-3 flex flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white/90 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90">
                {[
                    {
                        label: '+',
                        title: 'Aproximar',
                        action: () => zoomCenter(1 / 1.5),
                    },
                    {
                        label: '−',
                        title: 'Afastar',
                        action: () => zoomCenter(1.5),
                    },
                    {
                        label: '⟲',
                        title: 'Ver o Brasil inteiro',
                        action: () => fullView && setViewBox(fullView),
                    },
                ].map((button) => (
                    <button
                        key={button.title}
                        type="button"
                        title={button.title}
                        aria-label={button.title}
                        onClick={button.action}
                        className="size-9 text-lg leading-none text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        {button.label}
                    </button>
                ))}
            </div>

            {hover && hoveredName && (
                <div
                    className="pointer-events-none absolute z-10 w-56 rounded-lg border border-zinc-200 bg-white/95 p-3 text-xs shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95"
                    style={{
                        left: Math.min(
                            hover.x + 14,
                            (svgRef.current?.clientWidth ?? 0) - 230,
                        ),
                        top: hover.y + 14,
                    }}
                >
                    <p className="font-semibold text-zinc-900 dark:text-zinc-50">
                        {hoveredName[0]} – {hoveredName[1]}
                    </p>
                    {hoveredLeader && hovered ? (
                        <>
                            <p className="mt-1 flex items-center gap-1.5">
                                <span
                                    className="size-2.5 rounded-full"
                                    style={{
                                        backgroundColor: hoveredLeader.color,
                                    }}
                                />
                                <span className="font-medium">
                                    {hoveredLeader.name}
                                </span>
                                <span className="ml-auto tabular-nums">
                                    {formatPercent(hovered[1])}
                                </span>
                            </p>
                            <p className="mt-1 text-zinc-500">
                                {formatPercent(hovered[2])} das seções apuradas
                            </p>
                        </>
                    ) : (
                        <p className="mt-1 text-zinc-500">
                            Sem votos apurados ainda
                        </p>
                    )}
                    <p className="mt-1 text-zinc-400">Clique para detalhes</p>
                </div>
            )}
        </div>
    );
}

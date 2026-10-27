import {
    memo,
    useEffect,
    useEffectEvent,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { formatPercent } from '@/lib/format';
import { geometry as geometryRoute } from '@/routes/map';
import type { Candidate, MapGeometry, MapResults } from '@/types';

type ViewBox = { x: number; y: number; w: number; h: number };

type Hover = { ibge: string; x: number; y: number };

type Props = {
    results: MapResults | undefined;
    candidates: Map<number, Candidate>;
    selectedState: string;
    /** State offices: only the municipalities of the selected state. */
    restrictToState?: boolean;
    onSelect: (ibge: string) => void;
};

const MIN_ZOOM_WIDTH = 8;

/** Wheel zoom is drawn again this long after the last wheel step. */
const WHEEL_SETTLE_MS = 150;

const TOOLTIP_WIDTH = 224;

/**
 * The map is drawn this many visible widths beyond each edge (the SVG is
 * 200% of the frame), so moving it shows content that is already drawn.
 */
const MARGIN = 0.5;

/** Zoomed in beyond this, the scaled drawing gets blurry: draw it again. */
const MAX_PREVIEW_SCALE = 2.5;

function padded([x, y, w, h]: [number, number, number, number]): ViewBox {
    const padding = Math.max(w, h) * 0.08;

    return {
        x: x - padding,
        y: y - padding,
        w: w + padding * 2,
        h: h + padding * 2,
    };
}

/**
 * Scale and offset, in pixels, of a viewBox drawn centered inside an element
 * of the given size (the SVG default "xMidYMid meet").
 */
function fit(box: ViewBox, width: number, height: number) {
    const scale = Math.min(width / box.w, height / box.h);

    return {
        scale,
        x: (width - box.w * scale) / 2,
        y: (height - box.h * scale) / 2,
    };
}

/**
 * Leader share (40% → 80%) mapped to fill opacity, so landslides look darker.
 */
function shareOpacity(percent: number): number {
    const t = Math.min(Math.max((percent - 40) / 40, 0), 1);

    return 0.3 + t * 0.7;
}

type MunicipalityPathsProps = {
    geometry: MapGeometry;
    results: MapResults | undefined;
    candidates: Map<number, Candidate>;
    selectedState: string;
    restrictToState: boolean;
};

/**
 * The thousands of municipality shapes. Only rendered again when the results
 * change, never while the map is moved or hovered.
 */
const MunicipalityPaths = memo(function MunicipalityPaths({
    geometry,
    results,
    candidates,
    selectedState,
    restrictToState,
}: MunicipalityPathsProps) {
    return Object.entries(geometry.municipalities)
        .filter(
            ([ibge]) =>
                !restrictToState || geometry.names[ibge]?.[1] === selectedState,
        )
        .map(([ibge, d]) => {
            const result = results?.[ibge];
            const leader =
                result?.[0] != null ? candidates.get(result[0]) : undefined;

            return (
                <path
                    key={ibge}
                    d={d}
                    data-ibge={ibge}
                    fill={leader?.color}
                    fillOpacity={leader ? shareOpacity(result![1]) : undefined}
                />
            );
        });
});

export default function ResultsMap({
    results,
    candidates,
    selectedState,
    restrictToState = false,
    onSelect,
}: Props) {
    const [geometry, setGeometry] = useState<MapGeometry | null>(null);
    const [failed, setFailed] = useState(false);
    const [viewBox, setViewBox] = useState<ViewBox | null>(null);
    const [hover, setHover] = useState<Hover | null>(null);
    const [width, setWidth] = useState(0);
    const frameRef = useRef<HTMLDivElement>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    // While a gesture runs, the drawn map is only moved and scaled by the
    // GPU (CSS transform); the viewBox is updated once it ends.
    const drawn = useRef<ViewBox | null>(null);
    const live = useRef<ViewBox | null>(null);
    const animationFrame = useRef(0);
    const wheelTimer = useRef(0);
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

    const selectedBox = geometry?.states[selectedState]?.bbox;
    const fullView: ViewBox | null = !geometry
        ? null
        : restrictToState && selectedBox
          ? padded(selectedBox)
          : { x: 0, y: 0, w: geometry.width, h: geometry.height };

    useEffect(() => {
        if (!geometry) {
            return;
        }

        const state = selectedState ? geometry.states[selectedState] : null;

        live.current = null;
        setViewBox(
            state
                ? padded(state.bbox)
                : { x: 0, y: 0, w: geometry.width, h: geometry.height },
        );
    }, [geometry, selectedState]);

    // A new viewBox is drawn: drop the transform that previewed it.
    useLayoutEffect(() => {
        drawn.current = viewBox;

        if (live.current === viewBox) {
            live.current = null;
        }

        applyTransform();
    }, [viewBox]);

    // The frame only exists once the map is ready.
    const ready = geometry !== null && viewBox !== null;

    useEffect(() => {
        const frame = frameRef.current;

        if (!frame) {
            return;
        }

        const observer = new ResizeObserver(() => setWidth(frame.clientWidth));
        observer.observe(frame);

        return () => observer.disconnect();
    }, [ready]);

    const onWheel = useEffectEvent((event: WheelEvent) => {
        event.preventDefault();
        setHover(null);
        preview(
            zoomAt(
                event.clientX,
                event.clientY,
                event.deltaY > 0 ? 1.2 : 1 / 1.2,
            ),
        );
        window.clearTimeout(wheelTimer.current);
        wheelTimer.current = window.setTimeout(commit, WHEEL_SETTLE_MS);
    });

    // Wheel zoom needs a non-passive listener to prevent page scrolling.
    useEffect(() => {
        const frame = frameRef.current;

        if (!frame) {
            return;
        }

        const listener = (event: WheelEvent) => onWheel(event);
        frame.addEventListener('wheel', listener, { passive: false });

        return () => {
            frame.removeEventListener('wheel', listener);
            window.clearTimeout(wheelTimer.current);
            cancelAnimationFrame(animationFrame.current);
        };
    }, [ready]);

    /**
     * Move and scale the drawn map so it looks like the live viewBox.
     */
    function applyTransform() {
        const svg = svgRef.current;
        const frame = frameRef.current;
        const from = drawn.current;
        const to = live.current;

        if (!svg || !frame) {
            return;
        }

        if (!from || !to) {
            svg.style.transform = '';

            return;
        }

        const a = fit(from, frame.clientWidth, frame.clientHeight);
        const b = fit(to, frame.clientWidth, frame.clientHeight);
        const scale = b.scale / a.scale;
        const x = b.x - to.x * b.scale - scale * (a.x - from.x * a.scale);
        const y = b.y - to.y * b.scale - scale * (a.y - from.y * a.scale);

        // The drawn area, moved and scaled, must still cover the frame.
        const width = frame.clientWidth;
        const height = frame.clientHeight;
        const covered =
            x - scale * MARGIN * width <= 0 &&
            y - scale * MARGIN * height <= 0 &&
            x + scale * (1 + MARGIN) * width >= width &&
            y + scale * (1 + MARGIN) * height >= height;

        if (!covered || scale > MAX_PREVIEW_SCALE) {
            commit();
        }

        svg.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    }

    function preview(box: ViewBox | null) {
        if (!box) {
            return;
        }

        live.current = box;

        if (animationFrame.current === 0) {
            animationFrame.current = requestAnimationFrame(() => {
                animationFrame.current = 0;
                applyTransform();
            });
        }
    }

    /**
     * Draw the map again at the live viewBox.
     */
    function commit() {
        if (live.current) {
            setViewBox(live.current);
        }
    }

    function currentBox(): ViewBox | null {
        return live.current ?? drawn.current;
    }

    function toSvgPoint(clientX: number, clientY: number, box: ViewBox) {
        const rect = frameRef.current!.getBoundingClientRect();
        const layout = fit(box, rect.width, rect.height);

        return {
            x: box.x + (clientX - rect.left - layout.x) / layout.scale,
            y: box.y + (clientY - rect.top - layout.y) / layout.scale,
            scale: 1 / layout.scale,
        };
    }

    function zoomAt(
        clientX: number,
        clientY: number,
        factor: number,
    ): ViewBox | null {
        const box = currentBox();

        if (!box || !fullView) {
            return null;
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
    }

    function zoomCenter(factor: number) {
        const rect = frameRef.current?.getBoundingClientRect();
        const box = rect
            ? zoomAt(
                  rect.left + rect.width / 2,
                  rect.top + rect.height / 2,
                  factor,
              )
            : null;

        if (box) {
            live.current = box;
            setViewBox(box);
        }
    }

    function onPointerDown(event: ReactPointerEvent<HTMLDivElement>) {
        event.currentTarget.setPointerCapture(event.pointerId);
        pointers.current.set(event.pointerId, {
            x: event.clientX,
            y: event.clientY,
        });
        gesture.current = { moved: 0, pinchDistance: null };
    }

    function onPointerMove(event: ReactPointerEvent<HTMLDivElement>) {
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
                preview(
                    zoomAt(
                        (a.x + b.x) / 2,
                        (a.y + b.y) / 2,
                        gesture.current.pinchDistance / distance,
                    ),
                );
            }

            gesture.current.pinchDistance = distance;
            gesture.current.moved += 10;

            return;
        }

        const dx = event.clientX - previous.x;
        const dy = event.clientY - previous.y;
        const wasMoving = gesture.current.moved > 4;
        gesture.current.moved += Math.abs(dx) + Math.abs(dy);

        if (gesture.current.moved > 4) {
            if (!wasMoving) {
                setHover(null);
            }

            const box = currentBox();

            if (box) {
                const { scale } = toSvgPoint(0, 0, box);

                preview({
                    ...box,
                    x: box.x - dx * scale,
                    y: box.y - dy * scale,
                });
            }
        }
    }

    function onPointerUp(event: ReactPointerEvent<HTMLDivElement>) {
        if (!pointers.current.delete(event.pointerId)) {
            return;
        }

        if (pointers.current.size > 0) {
            // The remaining finger pans from where it is now.
            gesture.current.pinchDistance = null;

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

        commit();
    }

    function updateHover(event: ReactPointerEvent<HTMLDivElement>) {
        const ibge = (event.target as Element).getAttribute('data-ibge');
        const rect = event.currentTarget.getBoundingClientRect();

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
            <div className="flex aspect-square items-center justify-center rounded-xl border border-dashed border-line-strong p-6 text-center text-sm text-muted">
                O mapa ainda não está disponível.
            </div>
        );
    }

    if (!geometry || !viewBox) {
        return (
            <div className="aspect-square animate-pulse rounded-xl bg-track" />
        );
    }

    // Strokes keep the same width on screen at any zoom: one map unit is
    // this many pixels.
    const unit = Math.max(viewBox.w, viewBox.h) / (width || 600);
    const drawnBox = {
        x: viewBox.x - viewBox.w * MARGIN,
        y: viewBox.y - viewBox.h * MARGIN,
        w: viewBox.w * (1 + 2 * MARGIN),
        h: viewBox.h * (1 + 2 * MARGIN),
    };

    const hovered = hover ? results?.[hover.ibge] : undefined;
    const hoveredName = hover ? geometry.names[hover.ibge] : undefined;
    const hoveredShape = hover ? geometry.municipalities[hover.ibge] : null;
    const hoveredLeader =
        hovered?.[0] != null ? candidates.get(hovered[0]) : undefined;

    return (
        <div className="relative">
            <div
                ref={frameRef}
                className="relative aspect-square w-full cursor-grab touch-none overflow-hidden select-none active:cursor-grabbing"
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onPointerLeave={() => setHover(null)}
            >
                <svg
                    ref={svgRef}
                    viewBox={`${drawnBox.x} ${drawnBox.y} ${drawnBox.w} ${drawnBox.h}`}
                    // 200% of the frame, centered on it (MARGIN); transforms
                    // are relative to the frame's corner.
                    className="absolute -top-1/2 -left-1/2 block size-[200%] origin-[25%_25%] will-change-transform"
                    role="img"
                    aria-label={
                        restrictToState
                            ? 'Mapa do estado com o candidato mais votado em cada município'
                            : 'Mapa do Brasil com o candidato mais votado em cada município'
                    }
                >
                    <g
                        className="fill-track stroke-surface/60"
                        strokeWidth={0.3 * unit}
                    >
                        <MunicipalityPaths
                            geometry={geometry}
                            results={results}
                            candidates={candidates}
                            selectedState={selectedState}
                            restrictToState={restrictToState}
                        />
                    </g>
                    {hoveredShape && (
                        <path
                            d={hoveredShape}
                            fill="none"
                            className="pointer-events-none stroke-ink"
                            strokeWidth={1.5 * unit}
                        />
                    )}
                    <g className="pointer-events-none" fill="none">
                        {Object.entries(geometry.states)
                            .filter(
                                ([code]) =>
                                    !restrictToState || code === selectedState,
                            )
                            .map(([code, state]) => (
                                <path
                                    key={code}
                                    d={state.d}
                                    className={
                                        selectedState === code
                                            ? 'stroke-ink'
                                            : 'stroke-muted'
                                    }
                                    strokeWidth={
                                        (selectedState === code ? 2 : 0.8) *
                                        unit
                                    }
                                />
                            ))}
                    </g>
                </svg>
            </div>

            <div className="absolute top-3 right-3 flex flex-col overflow-hidden rounded-lg border border-line bg-surface/95 shadow-sm">
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
                        title: restrictToState
                            ? 'Ver o estado inteiro'
                            : 'Ver o Brasil inteiro',
                        action: () => {
                            if (fullView) {
                                live.current = fullView;
                                setViewBox(fullView);
                            }
                        },
                    },
                ].map((button) => (
                    <button
                        key={button.title}
                        type="button"
                        title={button.title}
                        aria-label={button.title}
                        onClick={button.action}
                        className="size-9 text-lg leading-none text-ink hover:bg-subtle"
                    >
                        {button.label}
                    </button>
                ))}
            </div>

            {hover && hoveredName && (
                <div
                    className="pointer-events-none absolute z-10 w-56 rounded-lg border border-line bg-surface p-3 text-xs shadow-lg"
                    style={{
                        left: Math.min(hover.x + 14, width - TOOLTIP_WIDTH - 6),
                        top: hover.y + 14,
                    }}
                >
                    <p className="font-semibold text-ink">
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
                            <p className="mt-1 text-muted">
                                {formatPercent(hovered[2])} das seções apuradas
                            </p>
                        </>
                    ) : (
                        <p className="mt-1 text-muted">
                            Sem votos apurados ainda
                        </p>
                    )}
                    <p className="mt-1 text-muted">Clique para detalhes</p>
                </div>
            )}
        </div>
    );
}

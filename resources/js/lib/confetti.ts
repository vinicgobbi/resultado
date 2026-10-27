type Particle = {
    x: number;
    y: number;
    vx: number;
    vy: number;
    size: number;
    color: string;
    rotation: number;
    spin: number;
    wobble: number;
    wobbleSpeed: number;
    shape: 'rect' | 'circle' | 'strip';
    life: number;
    decay: number;
};

type BurstOptions = {
    count?: number;
    /** Direction in radians (0 = right, -PI/2 = up). Omit for all directions. */
    angle?: number;
    /** Spread around the angle, in radians. */
    spread?: number;
    speed?: number;
    colors: string[];
};

const GRAVITY = 0.22;
const DRAG = 0.985;

/**
 * Small dependency-free confetti engine drawing on a full-screen canvas.
 */
export function createConfetti(canvas: HTMLCanvasElement) {
    const context = canvas.getContext('2d')!;
    let particles: Particle[] = [];
    let frame = 0;

    function resize() {
        const ratio = window.devicePixelRatio || 1;
        canvas.width = window.innerWidth * ratio;
        canvas.height = window.innerHeight * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function tick() {
        context.clearRect(0, 0, window.innerWidth, window.innerHeight);

        particles = particles.filter(
            (p) => p.life > 0 && p.y < window.innerHeight + 40,
        );

        for (const p of particles) {
            p.vx *= DRAG;
            p.vy = p.vy * DRAG + GRAVITY;
            p.x += p.vx + Math.sin(p.wobble) * 0.6;
            p.y += p.vy;
            p.rotation += p.spin;
            p.wobble += p.wobbleSpeed;
            p.life -= p.decay;

            context.save();
            context.globalAlpha = Math.min(1, p.life * 2);
            context.translate(p.x, p.y);
            context.rotate(p.rotation);
            // Flipping on one axis makes the paper look like it is turning.
            context.scale(1, Math.cos(p.wobble * 2));
            context.fillStyle = p.color;

            if (p.shape === 'circle') {
                context.beginPath();
                context.arc(0, 0, p.size / 2, 0, Math.PI * 2);
                context.fill();
            } else if (p.shape === 'strip') {
                context.fillRect(-p.size / 6, -p.size, p.size / 3, p.size * 2);
            } else {
                context.fillRect(
                    -p.size / 2,
                    -p.size / 3,
                    p.size,
                    p.size / 1.5,
                );
            }

            context.restore();
        }

        frame = particles.length > 0 ? requestAnimationFrame(tick) : 0;
    }

    function burst(x: number, y: number, options: BurstOptions) {
        const {
            count = 120,
            angle,
            spread = Math.PI * 2,
            speed = 14,
            colors,
        } = options;
        const shapes: Particle['shape'][] = ['rect', 'rect', 'circle', 'strip'];

        for (let i = 0; i < count; i++) {
            const direction =
                angle === undefined
                    ? Math.random() * Math.PI * 2
                    : angle + (Math.random() - 0.5) * spread;
            const velocity = speed * (0.35 + Math.random() * 0.75);

            particles.push({
                x,
                y,
                vx: Math.cos(direction) * velocity,
                vy: Math.sin(direction) * velocity,
                size: 6 + Math.random() * 7,
                color: colors[Math.floor(Math.random() * colors.length)],
                rotation: Math.random() * Math.PI,
                spin: (Math.random() - 0.5) * 0.3,
                wobble: Math.random() * Math.PI * 2,
                wobbleSpeed: 0.05 + Math.random() * 0.1,
                shape: shapes[Math.floor(Math.random() * shapes.length)],
                life: 1,
                decay: 0.004 + Math.random() * 0.004,
            });
        }

        if (frame === 0) {
            frame = requestAnimationFrame(tick);
        }
    }

    resize();
    window.addEventListener('resize', resize);

    return {
        burst,
        destroy() {
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', resize);
            particles = [];
        },
    };
}

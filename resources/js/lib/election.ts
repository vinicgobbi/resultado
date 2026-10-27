import type { ElectionDay, ElectionStatus } from '@/types';

/**
 * "2026 · 2º turno": how the public names an election day.
 */
export function dayLabel(day: Pick<ElectionDay, 'year' | 'round'>): string {
    return `${day.year} · ${day.round}º turno`;
}

/**
 * Status of an election in plain words. The icon repeats the meaning of
 * the color, so it does not depend on telling colors apart.
 */
export const STATUS: Record<
    ElectionStatus,
    { label: string; icon: string; className: string }
> = {
    agendada: {
        label: 'Ainda vai acontecer',
        icon: '🗓️',
        className: 'bg-primary-soft text-primary',
    },
    apuracao: {
        label: 'Apuração em andamento',
        icon: '🔴',
        className: 'bg-live-soft text-live',
    },
    concluida: {
        label: 'Apuração encerrada',
        icon: '✔️',
        className: 'bg-success-soft text-success',
    },
    pendente: {
        label: 'Carregando resultados',
        icon: '⏳',
        className: 'bg-warning-soft text-warning',
    },
};

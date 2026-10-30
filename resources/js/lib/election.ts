import type { ElectionStatus } from '@/types';

/**
 * "2026 · 2º turno": how the public names a round of an election.
 */
export function roundLabel(election: { year: number; round: number }): string {
    return `${election.year} · ${election.round}º turno`;
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

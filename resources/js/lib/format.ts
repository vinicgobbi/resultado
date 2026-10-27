const numberFormatter = new Intl.NumberFormat('pt-BR');

const percentFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const timeFormatter = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: 'America/Sao_Paulo',
});

export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

export function formatPercent(value: number): string {
    return `${percentFormatter.format(value)}%`;
}

export function formatDateTime(value: string | null): string {
    return value ? timeFormatter.format(new Date(value)) : '—';
}

/**
 * Readable text color (black or white) for a given background color.
 */
export function contrastText(hex: string): string {
    const value = parseInt(hex.replace('#', ''), 16);
    const r = (value >> 16) & 255;
    const g = (value >> 8) & 255;
    const b = value & 255;

    return r * 0.299 + g * 0.587 + b * 0.114 > 160 ? '#18181b' : '#ffffff';
}

/**
 * "2026-10-04" → "04/10/2026", without time zone shifts.
 */
export function formatDate(value: string): string {
    const [year, month, day] = value.split('-');

    return `${day}/${month}/${year}`;
}

const longDateFormatter = new Intl.DateTimeFormat('pt-BR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/**
 * "2026-10-04" → "4 de outubro de 2026".
 */
export function formatLongDate(value: string): string {
    return longDateFormatter.format(new Date(`${value}T00:00:00Z`));
}

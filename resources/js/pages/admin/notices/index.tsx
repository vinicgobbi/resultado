import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { NoticeStrip } from '@/components/site-notices';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/admin/site-notices';
import type { SiteNotice } from '@/types';

type NoticeStatus = 'ativo' | 'agendado' | 'encerrado';

type Notice = {
    id: number;
    message: string;
    level: SiteNotice['level'];
    /** "2026-10-30T17:00", as typed in the form. */
    starts_at: string | null;
    ends_at: string | null;
    status: NoticeStatus;
};

type Props = {
    notices: Notice[];
    levels: { value: SiteNotice['level']; label: string }[];
};

const MAX_LENGTH = 300;

const STATUS: Record<NoticeStatus, { label: string; className: string }> = {
    ativo: {
        label: 'No ar agora',
        className: 'bg-success-soft text-success',
    },
    agendado: { label: 'Agendado', className: 'bg-primary-soft text-primary' },
    encerrado: { label: 'Encerrado', className: 'bg-subtle text-muted' },
};

const inputClass =
    'min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm';

const EMPTY = {
    message: '',
    level: 'informacao' as SiteNotice['level'],
    starts_at: '',
    ends_at: '',
};

/**
 * "De 30/10 17:00 até 31/10 08:00": when a notice is shown.
 */
function period(notice: Notice): string {
    const from = notice.starts_at
        ? `De ${formatDateTime(notice.starts_at)}`
        : 'Desde a publicação';
    const until = notice.ends_at
        ? `até ${formatDateTime(notice.ends_at)}`
        : 'até ser removido';

    return `${from} ${until}`;
}

export default function NoticesIndex({ notices, levels }: Props) {
    const form = useForm<{
        id: number | null;
        message: string;
        level: SiteNotice['level'];
        starts_at: string;
        ends_at: string;
    }>({ id: null, ...EMPTY });
    const editing = form.data.id !== null;

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => form.setData({ id: null, ...EMPTY }),
        };

        form.transform(({ id: _id, ...data }) => ({
            ...data,
            starts_at: data.starts_at || null,
            ends_at: data.ends_at || null,
        }));

        if (form.data.id === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url(form.data.id), options);
        }
    }

    function edit(notice: Notice) {
        form.clearErrors();
        form.setData({
            id: notice.id,
            message: notice.message,
            level: notice.level,
            starts_at: notice.starts_at ?? '',
            ends_at: notice.ends_at ?? '',
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    return (
        <AdminLayout title="Aviso no site">
            <Head title="Avisos" />

            <p className="text-sm text-muted">
                Uma faixa no topo de todas as páginas, para avisos como "A
                apuração começa às 17h" ou "Instabilidade no site do TSE". Quem
                visita pode fechá-la; ela volta se o aviso for editado.
            </p>

            <form
                onSubmit={submit}
                className="space-y-4 rounded-2xl border border-line bg-surface p-4"
            >
                <h2 className="font-semibold">
                    {editing ? 'Editar aviso' : 'Novo aviso'}
                </h2>

                <label className="block text-sm">
                    <span className="mb-1 block font-medium">Mensagem</span>
                    <textarea
                        value={form.data.message}
                        onChange={(event) =>
                            form.setData('message', event.target.value)
                        }
                        required
                        maxLength={MAX_LENGTH}
                        rows={2}
                        className={inputClass}
                    />
                    <span className="mt-1 flex justify-between gap-3 text-xs text-muted">
                        <span className="text-live">{form.errors.message}</span>
                        <span className="tabular-nums">
                            {form.data.message.length}/{MAX_LENGTH}
                        </span>
                    </span>
                </label>

                <fieldset className="text-sm">
                    <legend className="mb-1 font-medium">Destaque</legend>
                    <div className="grid grid-cols-2 gap-1 rounded-xl bg-subtle p-1 sm:inline-grid">
                        {levels.map((level) => (
                            <label
                                key={level.value}
                                className={cn(
                                    'flex min-h-10 cursor-pointer items-center justify-center rounded-lg px-4 font-semibold transition-colors has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus',
                                    form.data.level === level.value
                                        ? 'bg-primary text-on-primary'
                                        : 'text-muted hover:bg-surface hover:text-ink',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="level"
                                    value={level.value}
                                    checked={form.data.level === level.value}
                                    onChange={() =>
                                        form.setData('level', level.value)
                                    }
                                    className="sr-only"
                                />
                                {level.label}
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">
                            Aparece a partir de
                        </span>
                        <input
                            type="datetime-local"
                            value={form.data.starts_at}
                            onChange={(event) =>
                                form.setData('starts_at', event.target.value)
                            }
                            className={inputClass}
                        />
                        <span className="mt-1 block text-xs text-muted">
                            Vazio: aparece assim que for salvo.
                        </span>
                        {form.errors.starts_at && (
                            <span className="mt-1 block text-xs text-live">
                                {form.errors.starts_at}
                            </span>
                        )}
                    </label>
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">Some em</span>
                        <input
                            type="datetime-local"
                            value={form.data.ends_at}
                            onChange={(event) =>
                                form.setData('ends_at', event.target.value)
                            }
                            className={inputClass}
                        />
                        <span className="mt-1 block text-xs text-muted">
                            Vazio: fica até ser removido.
                        </span>
                        {form.errors.ends_at && (
                            <span className="mt-1 block text-xs text-live">
                                {form.errors.ends_at}
                            </span>
                        )}
                    </label>
                </div>

                <div className="space-y-1">
                    <p className="text-sm font-medium">Prévia</p>
                    <div className="overflow-hidden rounded-xl border border-line">
                        <NoticeStrip
                            level={form.data.level}
                            message={
                                form.data.message ||
                                'O texto do aviso aparece aqui.'
                            }
                        />
                    </div>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="min-h-11 rounded-xl bg-primary px-5 font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50"
                    >
                        {editing ? 'Salvar aviso' : 'Publicar aviso'}
                    </button>
                    {editing && (
                        <button
                            type="button"
                            onClick={() => {
                                form.clearErrors();
                                form.setData({ id: null, ...EMPTY });
                            }}
                            className="min-h-11 rounded-xl border-2 border-line-strong px-5 font-semibold hover:bg-subtle"
                        >
                            Cancelar edição
                        </button>
                    )}
                </div>
            </form>

            <section aria-labelledby="avisos" className="space-y-3">
                <h2 id="avisos" className="text-lg font-bold">
                    Avisos
                </h2>
                {notices.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-line-strong p-6 text-center text-muted">
                        Nenhum aviso ainda.
                    </p>
                ) : (
                    <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
                        {notices.map((notice) => (
                            <li
                                key={notice.id}
                                className={cn(
                                    'flex flex-col gap-3 p-4 sm:flex-row sm:items-start',
                                    notice.id === form.data.id &&
                                        'bg-primary-soft',
                                )}
                            >
                                <div className="min-w-0 flex-1 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2 text-xs">
                                        <span
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 font-bold',
                                                STATUS[notice.status].className,
                                            )}
                                        >
                                            {STATUS[notice.status].label}
                                        </span>
                                        <span className="text-muted">
                                            {
                                                levels.find(
                                                    (level) =>
                                                        level.value ===
                                                        notice.level,
                                                )?.label
                                            }
                                        </span>
                                    </p>
                                    <p className="font-medium wrap-anywhere">
                                        {notice.message}
                                    </p>
                                    <p className="text-sm text-muted">
                                        {period(notice)}
                                    </p>
                                </div>
                                <div className="grid grid-cols-2 gap-2 sm:flex">
                                    <button
                                        type="button"
                                        onClick={() => edit(notice)}
                                        className="min-h-10 rounded-lg border-2 border-line-strong px-3 text-sm font-semibold hover:border-primary hover:bg-primary-soft"
                                    >
                                        Editar
                                    </button>
                                    <Link
                                        href={destroy(notice.id)}
                                        as="button"
                                        preserveScroll
                                        onBefore={() =>
                                            confirm('Remover este aviso?')
                                        }
                                        className="min-h-10 rounded-lg border-2 border-live px-3 text-sm font-semibold text-live hover:bg-live-soft"
                                    >
                                        Remover
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AdminLayout>
    );
}

import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    currentSubscription,
    isPushSupported,
    subscribe,
    unsubscribe,
} from '@/lib/push';

export default function NotificationToggle() {
    const { push } = usePage().props;
    const [subscription, setSubscription] = useState<PushSubscription | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const supported = isPushSupported() && Boolean(push.publicKey);

    useEffect(() => {
        if (supported) {
            void currentSubscription().then(setSubscription);
        }
    }, [supported]);

    if (!supported) {
        return null;
    }

    async function toggle() {
        setBusy(true);
        setError(null);

        try {
            if (subscription) {
                await unsubscribe(subscription);
                setSubscription(null);
            } else {
                setSubscription(await subscribe(push.publicKey!));
            }
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Não foi possível ativar as notificações.',
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex items-center gap-2">
            <button
                type="button"
                onClick={toggle}
                disabled={busy}
                className={
                    subscription
                        ? 'rounded-full border border-emerald-600 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-950'
                        : 'rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50'
                }
            >
                {subscription
                    ? '🔔 Notificações ativas'
                    : '🔔 Receber notificações'}
            </button>
            {error && <span className="text-xs text-red-600">{error}</span>}
        </div>
    );
}

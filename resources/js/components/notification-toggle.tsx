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
    // Only known in the browser: checked after mounting, so the page
    // rendered on the server matches the first render.
    const [supported, setSupported] = useState(false);

    useEffect(() => {
        if (isPushSupported() && push.publicKey) {
            setSupported(true);
            void currentSubscription().then(setSubscription);
        }
    }, [push.publicKey]);

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
                aria-pressed={subscription !== null}
                className={
                    subscription
                        ? 'rounded-full border border-success px-3 py-1.5 text-xs font-medium text-success hover:bg-success-soft disabled:opacity-50'
                        : 'rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-on-primary hover:bg-primary-hover disabled:opacity-50'
                }
            >
                {subscription
                    ? '🔔 Notificações ativas'
                    : '🔔 Receber notificações'}
            </button>
            {error && (
                <span role="alert" className="text-xs text-live">
                    {error}
                </span>
            )}
        </div>
    );
}

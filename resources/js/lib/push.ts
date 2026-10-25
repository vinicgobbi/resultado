import { destroy, store } from '@/routes/push-subscriptions';

export function isPushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function registerServiceWorker(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js').catch(() => {
            // Service workers need HTTPS (or localhost); ignore otherwise.
        });
    });
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    if (!isPushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

export async function subscribe(publicKey: string): Promise<PushSubscription> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error('Permissão de notificação negada.');
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToBytes(publicKey),
        }));

    await send(store.url(), 'POST', subscription.toJSON());

    return subscription;
}

export async function unsubscribe(
    subscription: PushSubscription,
): Promise<void> {
    await send(destroy.url(), 'DELETE', {
        endpoint: subscription.endpoint,
    });
    await subscription.unsubscribe();
}

async function send(url: string, method: string, body: unknown): Promise<void> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('Não foi possível salvar a inscrição.');
    }
}

function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

import { follow, following, unfollow } from '@/routes/notifications';
import { destroy, store } from '@/routes/push-subscriptions';

/** A result this browser follows, and the notifications it wants of it. */
export type Follow = {
    office: string;
    scope_code: string;
    label: string;
    url: string | null;
    notify_updates: boolean;
    notify_decided: boolean;
    notify_final: boolean;
};

export type FollowCategories = Pick<
    Follow,
    'notify_updates' | 'notify_decided' | 'notify_final'
>;

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

/**
 * Results this browser follows (none without a push subscription).
 */
export async function listFollows(): Promise<Follow[]> {
    const subscription = await currentSubscription();

    if (!subscription) {
        return [];
    }

    const response = await send(following.url(), 'POST', {
        endpoint: subscription.endpoint,
    });

    return (await response.json()) as Follow[];
}

/**
 * Follow a result with the chosen notifications, subscribing this browser
 * first if needed. Without any notification, it stops following it.
 */
export async function saveFollow(
    publicKey: string,
    office: string,
    scopeCode: string,
    categories: FollowCategories,
): Promise<void> {
    const subscription =
        (await currentSubscription()) ?? (await subscribe(publicKey));

    await send(follow.url(), 'PUT', {
        endpoint: subscription.endpoint,
        office,
        scope_code: scopeCode,
        ...categories,
    });
}

export async function removeFollow(
    office: string,
    scopeCode: string,
): Promise<void> {
    const subscription = await currentSubscription();

    if (subscription) {
        await send(unfollow.url(), 'DELETE', {
            endpoint: subscription.endpoint,
            office,
            scope_code: scopeCode,
        });
    }
}

async function send(
    url: string,
    method: string,
    body: unknown,
): Promise<Response> {
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
        throw new Error('Não foi possível salvar. Tente de novo.');
    }

    return response;
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

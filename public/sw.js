/* Service worker: PWA install, offline-friendly assets and push notifications. */

const STATIC_CACHE = 'eleicoes-static-v3';
const MAP_CACHE = 'eleicoes-mapa-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) => ![STATIC_CACHE, MAP_CACHE].includes(key),
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Hashed build assets and icons never change: cache first.
    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/app-icons/')
    ) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);

                if (cached) {
                    return cached;
                }

                const response = await fetch(request);

                if (response.ok) {
                    cache.put(request, response.clone());
                }

                return response;
            }),
        );

        return;
    }

    // Map geometry is large: serve from cache and refresh in background.
    if (url.pathname === '/mapa/geometria.json') {
        event.respondWith(
            caches.open(MAP_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok) {
                            cache.put(request, response.clone());
                        }

                        return response;
                    })
                    .catch(() => cached);

                return cached ?? network;
            }),
        );
    }
});

/**
 * Messages waiting for this browser, looked up by the SHA-256 of its push
 * endpoint (pushes carry no content).
 */
async function pendingMessages() {
    const subscription = await self.registration.pushManager.getSubscription();

    if (!subscription) {
        return [];
    }

    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(subscription.endpoint),
    );
    const hash = [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
    const response = await fetch(`/notificacoes/pendentes?inscricao=${hash}`, {
        cache: 'no-store',
    });

    return response.ok ? response.json() : [];
}

/**
 * The national summary, for a push without waiting messages.
 */
function showSummary() {
    return fetch('/resumo.json', { cache: 'no-store' })
        .then((response) => response.json())
        .then((summary) => {
            const percent = (value) =>
                `${Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;

            const leaders = summary.candidates
                .slice(0, 2)
                .map(
                    (candidate) =>
                        `${candidate.name} ${percent(candidate.percent)}`,
                )
                .join(' × ');

            const counted = `${percent(summary.counted_percent)} das urnas apuradas`;
            const runoff = summary.runoff;
            const names = runoff?.names.filter(Boolean) ?? [];

            // A winner first, then a runoff that is sure to happen, then
            // the progress of the count.
            const title = summary.winner
                ? `🎉 ${summary.winner.name} ${summary.winner.status === 'elected' ? 'está eleito!' : 'está matematicamente eleito!'}`
                : runoff
                  ? runoff.status === 'confirmed' && names.length === 2
                      ? `🗳️ ${names[0]} e ${names[1]} vão ao 2º turno`
                      : names.length === 2
                        ? `🗳️ Vai ter 2º turno: ${names[0]} × ${names[1]}`
                        : '🗳️ Vai ter 2º turno'
                  : summary.is_final
                    ? `Apuração encerrada (${summary.round}º turno)`
                    : counted;

            const body =
                runoff && runoff.status !== 'confirmed' && names.length < 2
                    ? `${names.length === 1 ? `${names[0]} já garantiu vaga; a outra está em disputa.` : 'As duas vagas ainda estão em disputa.'} ${counted}.`
                    : runoff || summary.winner
                      ? `${leaders} · ${counted}`
                      : leaders;

            return self.registration.showNotification(title, {
                body: body || 'Novos resultados disponíveis.',
                icon: '/app-icons/icon-192.png',
                badge: '/app-icons/badge-96.png',
                tag: 'apuracao-brasil',
                renotify: true,
                data: { url: '/' },
            });
        })
        .catch(() =>
            self.registration.showNotification('Resultados das Eleições', {
                body: 'O TSE publicou novos resultados.',
                icon: '/app-icons/icon-192.png',
                badge: '/app-icons/badge-96.png',
                tag: 'apuracao-brasil',
                data: { url: '/' },
            }),
        );
}

self.addEventListener('push', (event) => {
    event.waitUntil(
        pendingMessages()
            .catch(() => [])
            .then((messages) =>
                messages.length === 0
                    ? showSummary()
                    : Promise.all(
                          messages.map((message) =>
                              self.registration.showNotification(
                                  message.title,
                                  {
                                      body: message.body,
                                      icon: '/app-icons/icon-192.png',
                                      badge: '/app-icons/badge-96.png',
                                      tag: message.tag,
                                      renotify: true,
                                      data: { url: message.url },
                                  },
                              ),
                          ),
                      ),
            ),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                const existing = windows.find(
                    (client) =>
                        new URL(client.url).origin === self.location.origin,
                );

                const url = event.notification.data?.url ?? '/';

                if (existing) {
                    // Only windows this worker controls can be navigated.
                    return existing
                        .focus()
                        .then((client) => client.navigate(url))
                        .catch(() => self.clients.openWindow(url));
                }

                return self.clients.openWindow(url);
            }),
    );
});

import { createInertiaApp } from '@inertiajs/react';
import { prefersReducedMotion } from '@/lib/animation';
import { registerServiceWorker } from '@/lib/push';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#1e4fbf',
    },
    defaults: {
        // Moving to another view animates it; partial reloads (polling,
        // deferred props) only update numbers in place.
        visitOptions: (href, options) =>
            options.only?.length ||
            options.except?.length ||
            prefersReducedMotion()
                ? {}
                : { viewTransition: true },
    },
});

registerServiceWorker();

import { createInertiaApp } from '@inertiajs/react';
import { registerServiceWorker } from '@/lib/push';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#1e4fbf',
    },
});

registerServiceWorker();

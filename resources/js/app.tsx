import { createInertiaApp } from '@inertiajs/react';
import SiteLayout from '@/components/site-notices';
import { registerServiceWorker } from '@/lib/push';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Every page shows the notices of the site above it.
    layout: () => SiteLayout,
    progress: {
        color: '#1e4fbf',
    },
});

registerServiceWorker();

import type { Auth } from '@/types/auth';
import type { SiteNotice } from '@/types/site';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            flash: {
                success: string | null;
                error: string | null;
            };
            push: {
                publicKey: string | null;
            };
            /** Notices shown now at the top of every page. */
            notices: SiteNotice[];
            [key: string]: unknown;
        };
    }
}

/** A notice at the top of every page, written in the admin. */
export type SiteNotice = {
    id: number;
    message: string;
    level: 'informacao' | 'atencao';
    /** Changes when the notice is edited. */
    version: number;
};

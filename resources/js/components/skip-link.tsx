/**
 * First focusable element of the page: lets keyboard and screen reader users
 * jump over the header straight to the content.
 */
export default function SkipLink() {
    return (
        <a
            href="#conteudo"
            className="sr-only z-50 rounded-lg bg-primary px-4 py-2 font-semibold text-on-primary focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >
            Pular para o conteúdo
        </a>
    );
}

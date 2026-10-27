type Props = {
    /** Seats split by party: deputies and councillors. */
    proportional: boolean;
    /** The page has a map of municipalities. */
    hasMap: boolean;
};

/**
 * Plain explanation of the terms used on the results page.
 */
export default function HowToRead({ proportional, hasMap }: Props) {
    const terms: [string, string][] = [
        [
            'Votos válidos',
            'São os votos dados a um candidato. Votos em branco e nulos não entram na conta.',
        ],
        [
            'Percentual',
            'É a parte dos votos válidos que cada candidato recebeu.',
        ],
        [
            'Matematicamente eleito',
            'Mesmo que todos os votos que ainda faltam apurar fossem para os adversários, o resultado não mudaria mais. O TSE ainda vai confirmar.',
        ],
        ['Eleito', 'O TSE já confirmou o resultado.'],
        [
            'Garantido no 2º turno',
            'Já é certo que haverá 2º turno e que o candidato vai disputá-lo: mesmo com os votos que faltam apurar, ninguém passa da metade dos votos válidos e os adversários não conseguem mais tirá-lo dos dois primeiros lugares.',
        ],
        proportional
            ? [
                  'Como as vagas são divididas',
                  'Primeiro, as vagas são repartidas entre os partidos de acordo com o total de votos de cada um. Depois, elas vão para os candidatos mais votados de cada partido. Por isso, nem sempre os mais votados de todos são eleitos.',
              ]
            : [
                  'Segundo turno',
                  'Presidente, governador e prefeito de cidades com mais de 200 mil eleitores precisam de mais da metade dos votos válidos para vencer no 1º turno. Se ninguém conseguir, os dois mais votados disputam o 2º turno.',
              ],
    ];

    if (hasMap) {
        terms.push([
            'Mapa',
            'A cor de cada município mostra quem está na frente nele. Quanto mais forte a cor, maior a vantagem.',
        ]);
    }

    return (
        <details className="group rounded-2xl border border-line bg-surface">
            <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-lg font-semibold [&::-webkit-details-marker]:hidden">
                Como entender este resultado
                <span
                    aria-hidden="true"
                    className="transition-transform duration-300 group-open:rotate-180"
                >
                    ▾
                </span>
            </summary>
            <dl className="animate-fade-in space-y-4 border-t border-line px-5 py-4">
                {terms.map(([term, meaning]) => (
                    <div key={term}>
                        <dt className="font-semibold">{term}</dt>
                        <dd className="text-muted">{meaning}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

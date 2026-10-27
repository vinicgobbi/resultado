import type { Candidate, Runoff } from '@/types';

type Props = {
    runoff: Runoff;
    candidates: Map<number, Candidate>;
};

/**
 * Tells that there will be a runoff and who is already in it.
 */
export default function RunoffNotice({ runoff, candidates }: Props) {
    const names = runoff.candidate_ids
        .map((id) => candidates.get(id)?.name)
        .filter((name): name is string => name !== undefined);
    const confirmed = runoff.status === 'confirmed';

    return (
        <div
            role="status"
            className="animate-fade-up rounded-xl border-2 border-primary bg-primary-soft p-4"
        >
            <p className="text-lg font-bold">
                <span aria-hidden="true">🗳️ </span>
                Vai ter 2º turno
            </p>
            <p className="mt-1">
                {confirmed
                    ? 'Ninguém teve mais da metade dos votos válidos.'
                    : 'Já é matemático: mesmo com os votos que faltam, ninguém consegue mais passar da metade dos votos válidos.'}{' '}
                {names.length === 2
                    ? `${names[0]} e ${names[1]} ${confirmed ? 'disputam' : 'já garantiram vaga para disputar'} o 2º turno.`
                    : names.length === 1
                      ? `${names[0]} já garantiu vaga. A outra vaga ainda está em disputa.`
                      : 'As duas vagas ainda estão em disputa.'}
            </p>
        </div>
    );
}

export type Candidate = {
    id: number;
    number: number;
    name: string;
    full_name: string;
    party: string;
    color: string;
    /** Downloaded from the TSE on first use; may not exist. */
    photo_url: string;
};

export type OfficeOption = {
    value: string;
    label: string;
    /** Election holding the office, among the elections of the same day. */
    election?: number;
};

export type ElectionStatus = 'agendada' | 'apuracao' | 'concluida' | 'pendente';

/** The elections of the same day and round, shown as one. */
export type ElectionDay = {
    /** Election the day opens on (the federal one first). */
    code: number;
    year: number;
    round: number;
    date: string;
    status: ElectionStatus;
    offices: string[];
};

export type Election = {
    code: number;
    name: string;
    date: string;
    year: number;
    round: number;
    type: 'federal' | 'estadual' | 'municipal';
    status: ElectionStatus;
};

/** Where an office is elected. */
export type ElectionLevel = 'country' | 'state' | 'municipality';

/** A municipality with results of a municipal office. */
export type MunicipalityOption = {
    code: string;
    name: string;
    counted_percent: number;
    is_final: boolean;
};

/** A state with results of a state office. */
export type Place = {
    code: string;
    name: string;
    counted_percent: number;
    is_final: boolean;
};

export type Winner = {
    candidate_id: number;
    /** elected: declared by the TSE; mathematical: can no longer be caught. */
    status: 'elected' | 'mathematical';
};

export type CandidateResult = {
    candidate_id: number;
    votes: number;
    percent: number;
};

export type ResultSummary = {
    counted_percent: number;
    sections_total: number;
    sections_counted: number;
    electorate: number;
    turnout: number;
    abstentions: number;
    valid_votes: number;
    blank_votes: number;
    null_votes: number;
    seats: number;
    is_final: boolean;
    leader_id: number | null;
    updated_at: string | null;
    results: CandidateResult[];
    /** Only in the summary of the place where the office is elected. */
    winners?: Winner[];
};

export type StateResult = ResultSummary & {
    code: string;
    name: string;
};

/** IBGE code => [leader id, leader %, counted %] */
export type MapResults = Record<string, [number | null, number, number]>;

export type MapGeometry = {
    width: number;
    height: number;
    municipalities: Record<string, string>;
    states: Record<
        string,
        { d: string; bbox: [number, number, number, number] }
    >;
    names: Record<string, [string, string]>;
};

export type MunicipalityDetail = {
    name: string;
    state: string;
    is_capital: boolean;
    result: ResultSummary | null;
    candidates: Candidate[];
};

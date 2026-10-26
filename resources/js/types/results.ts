export type Candidate = {
    id: number;
    number: number;
    name: string;
    full_name: string;
    party: string;
    color: string;
    photo_url: string | null;
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
    is_final: boolean;
    leader_id: number | null;
    updated_at: string | null;
    results: CandidateResult[];
    /** Only in the national summary. */
    winner?: Winner | null;
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

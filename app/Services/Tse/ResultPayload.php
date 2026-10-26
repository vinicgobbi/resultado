<?php

namespace App\Services\Tse;

use Illuminate\Support\Carbon;

/**
 * Normalized content of a TSE "-u.json" result file.
 */
final readonly class ResultPayload
{
    /**
     * @param  list<array{tse_sequence: string, number: int, ballot_name: string, full_name: string, party: string, votes: int, elected: bool}>  $candidates
     */
    public function __construct(
        public array $candidates,
        public int $sectionsTotal,
        public int $sectionsCounted,
        public float $countedPercent,
        public int $electorate,
        public int $electorateCounted,
        public int $turnout,
        public int $abstentions,
        public int $validVotes,
        public int $blankVotes,
        public int $nullVotes,
        public bool $isFinal,
        public ?Carbon $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromTse(array $json): self
    {
        $candidates = [];

        foreach ($json['carg'] ?? [] as $office) {
            foreach ($office['agr'] ?? [] as $grouping) {
                foreach ($grouping['par'] ?? [] as $party) {
                    foreach ($party['cand'] ?? [] as $candidate) {
                        $candidates[] = [
                            'tse_sequence' => (string) $candidate['sqcand'],
                            'number' => (int) $candidate['n'],
                            'ballot_name' => (string) ($candidate['nmu'] ?? $candidate['nm']),
                            'full_name' => (string) $candidate['nm'],
                            'party' => (string) ($party['sg'] ?? ''),
                            'votes' => (int) ($candidate['vap'] ?? 0),
                            // "e" is also "s" for who goes to the runoff;
                            // "st" is "Eleito" only for the winner.
                            'elected' => ($candidate['st'] ?? '') === 'Eleito',
                        ];
                    }
                }
            }
        }

        $sections = $json['s'] ?? [];
        $electorate = $json['e'] ?? [];
        $votes = $json['v'] ?? [];

        return new self(
            candidates: $candidates,
            sectionsTotal: (int) ($sections['ts'] ?? 0),
            sectionsCounted: (int) ($sections['st'] ?? 0),
            countedPercent: self::decimal($sections['pstn'] ?? $sections['pst'] ?? '0'),
            electorate: (int) ($electorate['te'] ?? 0),
            electorateCounted: (int) ($electorate['est'] ?? 0),
            turnout: (int) ($electorate['c'] ?? 0),
            abstentions: (int) ($electorate['a'] ?? 0),
            validVotes: (int) ($votes['vv'] ?? 0),
            blankVotes: (int) ($votes['vb'] ?? 0),
            nullVotes: (int) ($votes['tvn'] ?? 0),
            isFinal: ($json['and'] ?? null) === 'f',
            updatedAt: self::dateTime($json['dt'] ?? null, $json['ht'] ?? null),
        );
    }

    /**
     * Parse the "dd/mm/yyyy" + "hh:mm:ss" pair used by the TSE (Brasília time).
     */
    public static function dateTime(?string $date, ?string $time): ?Carbon
    {
        if (blank($date) || blank($time)) {
            return null;
        }

        return Carbon::createFromFormat('d/m/Y H:i:s', "{$date} {$time}", 'America/Sao_Paulo') ?: null;
    }

    /**
     * Parse a TSE decimal string such as "38,521536391".
     */
    public static function decimal(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}

<?php

namespace App\Services\Tse;

use App\Enums\ElectionType;
use App\Enums\Office;
use App\Models\Election;
use Illuminate\Support\Carbon;

/**
 * Registers the regular elections listed in the TSE elections index
 * ("ele-c.json"); supplementary elections and popular consultations are left
 * out. The TSE only keeps the current cycles there, so what is registered
 * (and imported) here is what remains once a cycle is gone: elections that
 * leave the index are never removed.
 */
class ElectionCatalog
{
    public function __construct(private readonly TseClient $client) {}

    /**
     * @return int number of elections registered or updated
     *
     * @throws RateLimitedException
     */
    public function discover(): int
    {
        $index = $this->client->fetch($this->client->electionsIndexUrl());
        $count = 0;

        foreach ($index['pl'] ?? [] as $pleito) {
            $date = Carbon::createFromFormat('d/m/Y', (string) $pleito['dt']);

            foreach ($pleito['e'] ?? [] as $entry) {
                $type = ElectionType::fromTseCode((string) ($entry['tp'] ?? ''));
                $offices = $this->offices($entry['abr'] ?? []);

                if ($type === null || $offices === [] || $date === null) {
                    continue;
                }

                $election = Election::query()->firstOrNew(['tse_code' => (int) $entry['cd']]);

                $election->fill([
                    'cycle' => (string) $pleito['c'],
                    'date' => $date->toDateString(),
                    'round' => (int) ($entry['t'] ?? 1),
                    'type' => $type,
                    'name' => trim((string) $entry['nm']),
                    'second_round_code' => filled($entry['cdt2'] ?? null) ? (int) $entry['cdt2'] : null,
                    'places' => $this->places($entry['abr'] ?? []),
                    // Offices listed before are kept, even if the index stops
                    // listing them.
                    'offices' => array_values(array_filter(
                        Office::cases(),
                        fn (Office $office): bool => in_array($office, $offices, true) || ($election->exists && $election->offices->contains($office)),
                    )),
                    'available_until' => filled($pleito['dtlim'] ?? null)
                        ? Carbon::createFromFormat('d/m/Y', (string) $pleito['dtlim'])?->toDateString()
                        : null,
                ])->save();

                $count++;
            }
        }

        return $count;
    }

    /**
     * Offices disputed in an election, in display order.
     *
     * @param  list<array<string, mixed>>  $places
     * @return list<Office>
     */
    private function offices(array $places): array
    {
        $offices = [];

        foreach ($places as $place) {
            foreach ($place['cp'] ?? [] as $office) {
                $offices[] = Office::fromTseCode((int) $office['cd']);
            }
        }

        return array_values(array_filter(Office::cases(), fn (Office $office): bool => in_array($office, $offices, true)));
    }

    /**
     * Where the election takes place: null for the whole country, otherwise
     * the states and, when listed, the municipalities (TSE code => state).
     *
     * @param  list<array<string, mixed>>  $places
     * @return array{states: list<string>, municipalities: array<string, string>}|null
     */
    private function places(array $places): ?array
    {
        $states = [];
        $municipalities = [];

        foreach ($places as $place) {
            $state = (string) $place['cd'];

            if ($state === 'br') {
                return null;
            }

            $states[] = $state;

            foreach ($place['mu'] ?? [] as $municipality) {
                $municipalities[(string) $municipality['cd']] = $state;
            }
        }

        return ['states' => array_values(array_unique($states)), 'municipalities' => $municipalities];
    }
}

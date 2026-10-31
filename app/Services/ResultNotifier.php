<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Jobs\SendPushBatch;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\PushFollow;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Services\Tse\SyncEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns what happened to the followed results into a message for each
 * follower who wants that kind of notification, then wakes their browsers
 * (in batches, on the queue).
 */
class ResultNotifier
{
    /** Names listed in a message before "e mais N". */
    private const int LISTED_NAMES = 3;

    /** Messages written at a time, within the bound parameters of a query. */
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private readonly RaceOutcome $outcome,
        private readonly WebPush $webPush,
    ) {}

    /**
     * @param  list<SyncEvent>  $events
     * @return int browsers queued to be woken up
     */
    public function notify(array $events): int
    {
        $subscriptionIds = [];

        foreach ($events as $event) {
            $election = Election::query()->where('tse_code', $event->electionCode)->first();
            $result = Result::query()
                ->forElection($event->electionCode, $event->office, $event->office->electedScope())
                ->where('scope_code', $event->scopeCode)
                ->first();

            if ($election === null || $result === null) {
                continue;
            }

            $updateKey = $event->category === NotificationCategory::Updates ? $this->updateKey($result) : null;
            $follows = $this->followers($event, $updateKey);

            if ($follows === []) {
                continue;
            }

            $message = $this->message($event, $election, $result);
            $now = now();

            foreach (array_chunk($follows, self::INSERT_CHUNK) as $chunk) {
                PushMessage::query()->insert(array_map(fn (PushFollow $follow): array => [
                    'push_subscription_id' => $follow->push_subscription_id,
                    ...$message,
                    'created_at' => $now,
                ], $chunk));

                if ($event->category === NotificationCategory::Updates) {
                    PushFollow::query()
                        ->whereKey(array_map(fn (PushFollow $follow): int => $follow->id, $chunk))
                        ->update(['last_update_notified_at' => $now, 'last_update_key' => $updateKey]);
                }
            }

            foreach ($follows as $follow) {
                $subscriptionIds[$follow->push_subscription_id] = true;
            }
        }

        return SendPushBatch::dispatchFor(array_keys($subscriptionIds));
    }

    /**
     * Tell a browser it now follows a result and what it will receive.
     */
    public function confirmFollow(PushFollow $follow): int
    {
        $place = new ResultPlace($follow->office, $follow->scope_code);
        $election = $place->latestElection();
        $kinds = array_keys(array_filter([
            'as parciais do TSE quando a apuração avançar ou o líder mudar' => $follow->notify_updates,
            'quando estiver matematicamente definido' => $follow->notify_decided,
            'o resultado final' => $follow->notify_final,
        ]));
        $last = array_pop($kinds);

        $follow->subscription->messages()->create([
            'title' => "🔔 Você está seguindo {$place->label()}",
            'body' => 'Vai receber '.($kinds === [] ? $last : implode(', ', $kinds)." e {$last}").'.',
            'url' => $election === null ? route('home', absolute: false) : $place->url($election),
            'tag' => "{$follow->office->value}-{$follow->scope_code}-seguindo",
        ]);

        return $this->webPush->send(collect([$follow->subscription]));
    }

    /**
     * Send a message written by an admin to the followers of a result, or to
     * every subscribed browser.
     *
     * @return int browsers queued to be woken up
     */
    public function announce(string $title, string $body, ?ResultPlace $place = null): int
    {
        $election = $place?->latestElection();
        $message = [
            'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 500),
            'url' => $place === null || $election === null ? route('home', absolute: false) : $place->url($election),
            // Every notice is shown, not replaced by the next one.
            'tag' => 'aviso-'.now()->getTimestamp(),
            'created_at' => now(),
        ];
        $queued = 0;

        PushSubscription::query()
            ->when($place !== null, fn (Builder $query) => $query->whereHas('follows', fn (Builder $query) => $query
                ->where('office', $place?->office)
                ->where('scope_code', $place?->scopeCode)))
            ->select('id')
            ->chunkById(self::INSERT_CHUNK, function (Collection $subscriptions) use ($message, &$queued): void {
                $ids = $subscriptions->modelKeys();

                PushMessage::query()->insert(array_map(fn (int $subscriptionId): array => [
                    'push_subscription_id' => $subscriptionId,
                    ...$message,
                ], $ids));

                $queued += SendPushBatch::dispatchFor($ids);
            });

        return $queued;
    }

    /**
     * Follows of the result that want this kind of notification; new results
     * only when they tell something new (see updateKey()), and at most once
     * every few minutes.
     *
     * @return array<int, PushFollow>
     */
    private function followers(SyncEvent $event, ?string $updateKey): array
    {
        return PushFollow::query()
            ->where('office', $event->office)
            ->where('scope_code', $event->scopeCode)
            ->where($event->category->column(), true)
            ->when($updateKey !== null, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNull('last_update_key')
                    ->orWhere('last_update_key', '!=', $updateKey))
                ->where(fn (Builder $query) => $query
                    ->whereNull('last_update_notified_at')
                    ->orWhere('last_update_notified_at', '<=', now()->subMinutes(PushFollow::UPDATE_EVERY_MINUTES))))
            ->get()
            ->all();
    }

    /**
     * What a "new results" notification tells: the election, the step of the
     * count reached and the leader. A follower hears again only when it
     * changes, so a milestone held back by the interval is sent on the next
     * sync after it.
     */
    private function updateKey(Result $result): string
    {
        $milestone = collect(PushFollow::UPDATE_MILESTONES)
            ->filter(fn (int $milestone): bool => $result->counted_percent >= $milestone)
            ->last() ?? 0;

        return "{$result->election_code}|{$milestone}|{$result->leading_candidate_id}";
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function message(SyncEvent $event, Election $election, Result $result): array
    {
        $place = new ResultPlace($event->office, $event->scopeCode);
        $heading = $place->label();
        $counted = "{$this->percent($result->counted_percent)} apurado";
        $winners = array_column($this->outcome->winners($result, $event->office), 'candidate_id');
        $runoff = $election->round === 1 ? $this->outcome->runoff($result, $event->office) : null;
        $names = Candidate::query()
            ->whereKey(array_keys($result->votes))
            ->get(['id', 'ballot_name', 'custom_ballot_name'])
            ->mapWithKeys(fn (Candidate $candidate): array => [$candidate->id => $candidate->displayName()])
            ->all();
        $leaders = $this->leaders($result, $names);

        [$title, $body] = match ($event->category) {
            NotificationCategory::Updates => ["{$heading}: {$counted}", $leaders],
            NotificationCategory::Decided => $winners !== []
                ? ["🎉 {$heading}: {$this->list($winners, $names)} ".(count($winners) > 1 ? 'eleitos' : 'eleito'), "Matematicamente, com {$counted}."]
                : ["🗳️ {$heading}: vai ter 2º turno", "{$this->runoffPlaces($runoff['candidate_ids'] ?? [], $names)} {$counted}."],
            NotificationCategory::Final => ["✔️ {$heading}: apuração encerrada", match (true) {
                $winners !== [] => (count($winners) > 1 ? 'Eleitos: ' : 'Eleito: ').$this->list($winners, $names).'.',
                $runoff !== null && count($runoff['candidate_ids']) === 2 => "{$this->list($runoff['candidate_ids'], $names)} vão ao 2º turno.",
                default => $leaders,
            }],
        };

        return [
            'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 500),
            'url' => $place->url($election),
            // A newer message of the same kind replaces the older one.
            'tag' => "{$event->office->value}-{$event->scopeCode}-{$event->category->value}",
        ];
    }

    /**
     * "TARCÍSIO 52,10% × HADDAD 40,20%": the first two.
     *
     * @param  array<int, string>  $names
     */
    private function leaders(Result $result, array $names): string
    {
        $validVotes = max($result->valid_votes, array_sum($result->votes));

        return collect($result->sortedVotes())
            ->take(2)
            ->map(fn (int $votes, int $candidateId): string => ($names[$candidateId] ?? '?').' '.$this->percent($validVotes > 0 ? $votes / $validVotes * 100 : 0))
            ->implode(' × ');
    }

    /**
     * @param  list<int>  $qualified
     * @param  array<int, string>  $names
     */
    private function runoffPlaces(array $qualified, array $names): string
    {
        return match (count($qualified)) {
            2 => "{$this->list($qualified, $names)} vão disputá-lo.",
            1 => "{$this->list($qualified, $names)} já garantiu vaga; a outra está em disputa.",
            default => 'As duas vagas ainda estão em disputa.',
        };
    }

    /**
     * "A, B e C", or "A, B, C e mais 4".
     *
     * @param  list<int>  $candidateIds
     * @param  array<int, string>  $names
     */
    private function list(array $candidateIds, array $names): string
    {
        $listed = array_map(fn (int $candidateId): string => $names[$candidateId] ?? '?', array_slice($candidateIds, 0, self::LISTED_NAMES));
        $more = count($candidateIds) - count($listed);

        if ($more > 0) {
            return implode(', ', $listed)." e mais {$more}";
        }

        $last = array_pop($listed);

        return $listed === [] ? (string) $last : implode(', ', $listed)." e {$last}";
    }

    private function percent(float $value): string
    {
        return number_format($value, 2, ',', '.').'%';
    }
}

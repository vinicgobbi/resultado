<?php

namespace App\Models;

use App\Enums\ElectionType;
use App\Enums\Office;
use App\Enums\State;
use Database\Factories\ElectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An election published by the TSE, discovered from its elections index.
 *
 * @property int $id
 * @property int $tse_code
 * @property string $cycle
 * @property Carbon $date
 * @property int $round
 * @property ElectionType $type
 * @property string $name
 * @property int|null $second_round_code
 * @property array{states: list<string>, municipalities: array<string, string>}|null $places
 * @property \Illuminate\Support\Collection<int, Office> $offices
 * @property Carbon|null $available_until
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'tse_code', 'cycle', 'date', 'round', 'type', 'name', 'second_round_code', 'places', 'offices',
    'available_until', 'last_synced_at', 'completed_at',
])]
class Election extends Model
{
    /** @use HasFactory<ElectionFactory> */
    use HasFactory;

    /**
     * Results start to be published after the polls close at 17h (Brasília).
     */
    public const string SYNC_STARTS_AT = '16:30';

    /**
     * Days after the election day the count is still followed live.
     */
    public const int LIVE_DAYS = 2;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => ElectionType::class,
            'places' => 'array',
            'offices' => AsEnumCollection::of(Office::class),
            'available_until' => 'date',
            'last_synced_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'tse_code';
    }

    /**
     * Elections whose count is followed live: on the election day after the
     * polls close, and the following days until every result is final.
     *
     * @param  Builder<Election>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->recent()->whereNull('completed_at');
    }

    /**
     * Elections from the election day (after the polls close) to the last
     * day followed live, complete or not.
     *
     * @param  Builder<Election>  $query
     */
    public function scopeRecent(Builder $query): void
    {
        $query->whereDate('date', '>=', today()->subDays(self::LIVE_DAYS))
            ->whereDate('date', '<=', now()->format('H:i') >= self::SYNC_STARTS_AT ? today() : today()->subDay());
    }

    /**
     * The election shown when none is chosen: one being counted, otherwise
     * the latest that already happened (the federal one of an election day
     * first), otherwise the next one.
     */
    public static function current(?Office $office = null): ?self
    {
        $elections = self::query()
            ->when($office !== null, fn (Builder $query) => $query->whereJsonContains('offices', $office?->value))
            ->get()
            ->sort(fn (self $a, self $b): int => [$b->date->getTimestamp(), $a->type->order()] <=> [$a->date->getTimestamp(), $b->type->order()])
            ->values();

        return $elections->first(fn (self $election): bool => $election->isLive())
            ?? $elections->first(fn (self $election): bool => $election->date->lte(today()))
            ?? $elections->last();
    }

    /**
     * "agendada" before the polls close, "apuracao" while it is followed
     * live, "concluida" once every result is final, and "pendente" for a
     * past election not fully imported yet.
     */
    public function status(): string
    {
        $startsAt = $this->date->copy()->setTimeFromTimeString(self::SYNC_STARTS_AT);

        return match (true) {
            $this->completed_at !== null => 'concluida',
            now()->lt($startsAt) => 'agendada',
            $this->date->gte(today()->subDays(self::LIVE_DAYS)) => 'apuracao',
            default => 'pendente',
        };
    }

    public function isLive(): bool
    {
        return $this->status() === 'apuracao';
    }

    /**
     * Data shown on the public pages.
     *
     * @return array{code: int, name: string, date: string, year: int, round: int, type: string, status: string}
     */
    public function toPublicArray(): array
    {
        return [
            'code' => $this->tse_code,
            'name' => $this->name,
            'date' => $this->date->toDateString(),
            'year' => $this->date->year,
            'round' => $this->round,
            'type' => $this->type->value,
            'status' => $this->status(),
        ];
    }

    /**
     * Elections shown together with this one: those of the same day and
     * round (federal, state and municipal ones).
     *
     * @return Collection<int, static>
     */
    public function siblings(): Collection
    {
        return static::query()
            ->whereDate('date', $this->date)
            ->where('round', $this->round)
            ->get()
            ->sortBy(fn (Election $election): int => $election->type->order())
            ->values();
    }

    /**
     * The first round of this election (itself in the first round).
     */
    public function firstRound(): ?self
    {
        return $this->round === 1 ? $this : self::query()->where('second_round_code', $this->tse_code)->first();
    }

    public function secondRound(): ?self
    {
        return $this->second_round_code === null ? null : self::query()->where('tse_code', $this->second_round_code)->first();
    }

    /**
     * States where an office is disputed in this election.
     *
     * @return list<State>
     */
    public function statesFor(Office $office): array
    {
        $states = $this->places['states'] ?? null;

        return $states === null
            ? $office->states()
            : array_values(array_filter($office->states(), fn (State $state): bool => in_array($state->value, $states, true)));
    }

    /**
     * Municipalities (TSE code => state) where the election takes place, when
     * the TSE lists them (municipal runoffs); null when it
     * takes place in every municipality.
     *
     * @return array<string, State>|null
     */
    public function municipalities(): ?array
    {
        $municipalities = $this->places['municipalities'] ?? [];

        if ($municipalities === []) {
            return null;
        }

        $states = [];

        foreach ($municipalities as $code => $state) {
            $states[(string) $code] = State::from($state);
        }

        return $states;
    }
}

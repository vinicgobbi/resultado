<?php

namespace App\Models;

use App\Enums\NoticeLevel;
use Database\Factories\SiteNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A notice shown at the top of every page, written in the admin. Without a
 * start it shows right away; without an end, until it is removed.
 *
 * @property int $id
 * @property string $message
 * @property NoticeLevel $level
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message', 'level', 'starts_at', 'ends_at'])]
class SiteNotice extends Model
{
    /** @use HasFactory<SiteNoticeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => NoticeLevel::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Notices shown now: already started and not over yet.
     *
     * @param  Builder<SiteNotice>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * "ativo" while shown, "agendado" before its start and "encerrado" after
     * its end.
     */
    public function status(): string
    {
        return match (true) {
            $this->starts_at?->isFuture() ?? false => 'agendado',
            $this->ends_at !== null && ! $this->ends_at->isFuture() => 'encerrado',
            default => 'ativo',
        };
    }
}

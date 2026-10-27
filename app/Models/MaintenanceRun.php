<?php

namespace App\Models;

use App\Enums\MaintenanceTask;
use Database\Factories\MaintenanceRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A task started from the admin dashboard, with what its command printed.
 *
 * @property int $id
 * @property int|null $user_id
 * @property MaintenanceTask $task
 * @property int|null $election_code
 * @property string $status
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'task', 'election_code', 'status', 'output', 'started_at', 'finished_at'])]
class MaintenanceRun extends Model
{
    /** @use HasFactory<MaintenanceRunFactory> */
    use HasFactory;

    public const string QUEUED = 'na-fila';

    public const string RUNNING = 'executando';

    public const string DONE = 'concluida';

    public const string FAILED = 'falhou';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => MaintenanceTask::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Runs not finished yet.
     *
     * @param  Builder<MaintenanceRun>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING]);
    }
}

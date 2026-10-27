<?php

namespace App\Models;

use App\Enums\Office;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A result a browser follows: an office where it is elected (the country,
 * a state or a municipality), across the rounds of every election, with the
 * kinds of notification it wants.
 *
 * @property int $id
 * @property int $push_subscription_id
 * @property Office $office
 * @property string $scope_code
 * @property bool $notify_updates
 * @property bool $notify_decided
 * @property bool $notify_final
 * @property Carbon|null $last_update_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['push_subscription_id', 'office', 'scope_code', 'notify_updates', 'notify_decided', 'notify_final', 'last_update_notified_at'])]
class PushFollow extends Model
{
    /**
     * At most one "new results" notification of a follow this often.
     */
    public const int UPDATE_EVERY_MINUTES = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'office' => Office::class,
            'notify_updates' => 'boolean',
            'notify_decided' => 'boolean',
            'notify_final' => 'boolean',
            'last_update_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PushSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'push_subscription_id');
    }
}

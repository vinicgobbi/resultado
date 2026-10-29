<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How one sending of pushes went: browsers that accepted it, that refused
 * it, and that no longer exist.
 *
 * @property int $id
 * @property int $delivered
 * @property int $failed
 * @property int $expired
 * @property Carbon|null $created_at
 */
#[Fillable(['delivered', 'failed', 'expired'])]
class PushDelivery extends Model
{
    use MassPrunable;

    public const null UPDATED_AT = null;

    /**
     * Only the recent periods are shown on the dashboard.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }

    /**
     * Totals since the given moment.
     *
     * @return array{delivered: int, failed: int, expired: int}
     */
    public static function totalsSince(CarbonInterface $since): array
    {
        $totals = self::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('sum(delivered) as delivered, sum(failed) as failed, sum(expired) as expired')
            ->first();

        return [
            'delivered' => (int) $totals?->delivered,
            'failed' => (int) $totals?->failed,
            'expired' => (int) $totals?->expired,
        ];
    }
}

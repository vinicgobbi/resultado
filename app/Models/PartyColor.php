<?php

namespace App\Models;

use Database\Factories\PartyColorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The color of a party chosen in the admin, over the default of
 * config/partidos.php.
 *
 * @property int $id
 * @property string $party
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['party', 'color'])]
class PartyColor extends Model
{
    /** @use HasFactory<PartyColorFactory> */
    use HasFactory;

    private const string CACHE_KEY = 'partidos.cores';

    /**
     * Every chosen color by party abbreviation, read once: a page colors
     * hundreds of candidates.
     *
     * @return array<string, string>
     */
    public static function chosen(): array
    {
        return Cache::memo()->rememberForever(self::CACHE_KEY, fn (): array => self::query()->pluck('color', 'party')->all());
    }

    protected static function booted(): void
    {
        $forget = fn () => Cache::memo()->forget(self::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }
}

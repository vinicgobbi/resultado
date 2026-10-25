<?php

namespace App\Models;

use App\Enums\State;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tse_code
 * @property string|null $ibge_code
 * @property State $state
 * @property string $name
 * @property bool $is_capital
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tse_code', 'ibge_code', 'state', 'name', 'is_capital'])]
class Municipality extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => State::class,
            'is_capital' => 'boolean',
        ];
    }
}

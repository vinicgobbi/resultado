<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string|null $public_key
 * @property string|null $auth_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['endpoint', 'endpoint_hash', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /**
     * @return HasMany<PushFollow, $this>
     */
    public function follows(): HasMany
    {
        return $this->hasMany(PushFollow::class);
    }

    /**
     * @return HasMany<PushMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(PushMessage::class);
    }

    /**
     * Hash used to look up a subscription by its (long) endpoint URL.
     */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}

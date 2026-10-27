<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A notification waiting for its browser: pushes carry no content, so the
 * service worker fetches its messages when a push arrives.
 *
 * @property int $id
 * @property int $push_subscription_id
 * @property string $title
 * @property string $body
 * @property string $url
 * @property string $tag
 * @property Carbon|null $created_at
 */
#[Fillable(['push_subscription_id', 'title', 'body', 'url', 'tag'])]
class PushMessage extends Model
{
    public const null UPDATED_AT = null;
}

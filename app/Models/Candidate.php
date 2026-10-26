<?php

namespace App\Models;

use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $tse_sequence
 * @property int $number
 * @property string $ballot_name
 * @property string $full_name
 * @property string $party
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tse_sequence', 'number', 'ballot_name', 'full_name', 'party', 'color'])]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    /**
     * Fallback palette used while an admin has not chosen a color.
     *
     * @var list<string>
     */
    public const array FALLBACK_COLORS = [
        '#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2',
        '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#a16207',
    ];

    /**
     * Storage path of the official photo downloaded from the TSE.
     */
    public static function photoPath(string $sequence): string
    {
        return "candidatos/{$sequence}.jpeg";
    }

    public function hasPhoto(): bool
    {
        return Storage::exists(self::photoPath($this->tse_sequence));
    }

    /**
     * The color shown on charts and on the map.
     */
    public function displayColor(): string
    {
        return $this->color ?? self::FALLBACK_COLORS[$this->id % count(self::FALLBACK_COLORS)];
    }

    /**
     * @return array{id: int, number: int, name: string, full_name: string, party: string, color: string, photo_url: string|null}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->ballot_name,
            'full_name' => $this->full_name,
            'party' => $this->party,
            'color' => $this->displayColor(),
            'photo_url' => $this->hasPhoto() ? route('candidates.photo', $this) : null,
        ];
    }
}

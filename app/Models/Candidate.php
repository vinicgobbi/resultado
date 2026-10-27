<?php

namespace App\Models;

use App\Enums\Office;
use App\Enums\State;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $tse_sequence
 * @property Office $office
 * @property State|null $state
 * @property int $number
 * @property string $ballot_name
 * @property string $full_name
 * @property string $party
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tse_sequence', 'office', 'state', 'number', 'ballot_name', 'full_name', 'party', 'color'])]
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
     * Disk of the photos, served directly by the web server.
     */
    public const string PHOTO_DISK = 'photos';

    /**
     * Storage path of the official photo downloaded from the TSE.
     */
    public static function photoPath(string $sequence): string
    {
        return "candidatos/{$sequence}.jpeg";
    }

    /**
     * TSE election and directory that hold the official photo: every
     * candidate runs in the first round, so its files always have it.
     *
     * @return array{0: int|null, 1: string}
     */
    public function photoSource(): array
    {
        return [$this->office->electionCode(1), $this->state->value ?? 'br'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'office' => Office::class,
            'state' => State::class,
        ];
    }

    /**
     * The color shown on charts and on the map: the one chosen in the admin,
     * otherwise an approximation of the party color, otherwise the palette.
     */
    public function displayColor(): string
    {
        return $this->color ?? $this->partyColor() ?? self::FALLBACK_COLORS[$this->id % count(self::FALLBACK_COLORS)];
    }

    /**
     * Approximate color of the party, from config/partidos.php.
     */
    public function partyColor(): ?string
    {
        $color = config('partidos.cores.'.mb_strtoupper(trim($this->party)));

        return is_string($color) ? strtolower($color) : null;
    }

    /**
     * @return array{id: int, number: int, name: string, full_name: string, party: string, color: string, photo_url: string}
     */
    public function toPublicArray(?string $color = null): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->ballot_name,
            'full_name' => $this->full_name,
            'party' => $this->party,
            'color' => $color ?? $this->displayColor(),
            'photo_url' => $this->photoUrl(),
        ];
    }

    /**
     * The static file once the photo was downloaded; until then, the route
     * that downloads it from the TSE.
     */
    public function photoUrl(): string
    {
        $disk = Storage::disk(self::PHOTO_DISK);
        $path = self::photoPath($this->tse_sequence);

        return $disk->exists($path) ? $disk->url($path) : route('candidates.photo', $this);
    }
}

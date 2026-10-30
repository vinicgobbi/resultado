<?php

namespace App\Models;

use App\Enums\Office;
use App\Enums\State;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $tse_sequence
 * @property int|null $election_code
 * @property Office $office
 * @property State|null $state
 * @property string|null $municipality
 * @property int $number
 * @property string $ballot_name
 * @property string|null $custom_ballot_name
 * @property string $full_name
 * @property string $party
 * @property string|null $color
 * @property string|null $custom_photo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tse_sequence', 'election_code', 'office', 'state', 'municipality', 'number', 'ballot_name', 'custom_ballot_name', 'full_name', 'party', 'color', 'custom_photo'])]
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
     * Folder of the photos sent in the admin, apart from the TSE ones (which
     * can all be deleted and downloaded again).
     */
    public const string CUSTOM_PHOTO_DIRECTORY = 'manuais';

    /**
     * Storage path of the official photo downloaded from the TSE.
     */
    public static function photoPath(string $sequence): string
    {
        return "candidatos/{$sequence}.jpeg";
    }

    /**
     * The first round election the candidate ran in.
     *
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_code', 'tse_code');
    }

    /**
     * TSE election and directory that hold the official photo: every
     * candidate runs in the first round, so its files always have it.
     *
     * @return array{0: Election|null, 1: string}
     */
    public function photoSource(): array
    {
        return [$this->election, $this->state->value ?? 'br'];
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
     * Color of the party: the one chosen in the admin, otherwise the
     * approximation of config/partidos.php.
     */
    public function partyColor(): ?string
    {
        $party = mb_strtoupper(trim($this->party));

        return PartyColor::chosen()[$party] ?? self::defaultPartyColor($party);
    }

    /**
     * Approximate color of a party, from config/partidos.php.
     */
    public static function defaultPartyColor(string $party): ?string
    {
        $color = config('partidos.cores.'.mb_strtoupper(trim($party)));

        return is_string($color) ? strtolower($color) : null;
    }

    /**
     * Name shown on the site: the one corrected in the admin, otherwise the
     * ballot name published by the TSE.
     */
    public function displayName(): string
    {
        return $this->custom_ballot_name ?? $this->ballot_name;
    }

    /**
     * @return array{id: int, number: int, name: string, full_name: string, party: string, color: string, photo_url: string}
     */
    public function toPublicArray(?string $color = null): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->displayName(),
            'full_name' => $this->full_name,
            'party' => $this->party,
            'color' => $color ?? $this->displayColor(),
            'photo_url' => $this->photoUrl(),
        ];
    }

    /**
     * The static file once the photo was sent in the admin or downloaded,
     * and the public/storage link exists ("php artisan storage:link");
     * otherwise the route that serves it (downloading the TSE one).
     */
    public function photoUrl(): string
    {
        $path = $this->custom_photo ?? self::photoPath($this->tse_sequence);

        return is_file(public_path("storage/fotos/{$path}"))
            ? Storage::disk(self::PHOTO_DISK)->url($path)
            : route('candidates.photo', $this);
    }
}

<?php

namespace App\Services;

use App\Models\Candidate;

/**
 * Colors of the candidates of one race, kept apart from each other: party
 * colors repeat (many parties are red or blue), so a candidate whose color
 * looks like the color of someone with more votes gets a lighter or darker
 * shade of it. Colors chosen in the admin are never changed.
 */
class CandidateColors
{
    /**
     * Below this distance two colors are hard to tell apart on the map.
     */
    private const float MIN_DISTANCE = 90.0;

    /**
     * Shades tried in order: mixed with white (positive) or black (negative).
     *
     * @var list<float>
     */
    private const array SHADES = [0.45, -0.4, 0.7, -0.65, 0.25, -0.2];

    /**
     * @param  iterable<Candidate>  $candidates  ordered from most to least voted
     * @return array<int, string> color keyed by candidate id
     */
    public function assign(iterable $candidates): array
    {
        $colors = [];

        foreach ($candidates as $candidate) {
            $color = $candidate->color ?? $candidate->partyColor() ?? $this->paletteColor($candidate, $colors);

            if ($candidate->color === null && $this->closestDistance($color, $colors) < self::MIN_DISTANCE) {
                $color = $this->distinctShade($color, $colors);
            }

            $colors[$candidate->id] = $color;
        }

        return $colors;
    }

    /**
     * For a party without a known color: the first palette color unlike the
     * used ones, or the candidate's usual palette color.
     *
     * @param  array<int, string>  $used
     */
    private function paletteColor(Candidate $candidate, array $used): string
    {
        foreach (Candidate::FALLBACK_COLORS as $color) {
            if ($this->closestDistance($color, $used) >= self::MIN_DISTANCE) {
                return $color;
            }
        }

        return $candidate->displayColor();
    }

    /**
     * The first shade far enough from the used colors, or the farthest one.
     *
     * @param  array<int, string>  $used
     */
    private function distinctShade(string $color, array $used): string
    {
        $best = $color;
        $bestDistance = -1.0;

        foreach (self::SHADES as $amount) {
            $shade = $this->mix($color, $amount);
            $distance = $this->closestDistance($shade, $used);

            if ($distance >= self::MIN_DISTANCE) {
                return $shade;
            }

            if ($distance > $bestDistance) {
                [$best, $bestDistance] = [$shade, $distance];
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $used
     */
    private function closestDistance(string $color, array $used): float
    {
        return array_reduce($used, fn (float $closest, string $other): float => min($closest, $this->distance($color, $other)), PHP_FLOAT_MAX);
    }

    /**
     * "Redmean" distance, a cheap approximation of how different two colors look.
     */
    private function distance(string $first, string $second): float
    {
        [$r1, $g1, $b1] = $this->rgb($first);
        [$r2, $g2, $b2] = $this->rgb($second);
        $redMean = ($r1 + $r2) / 2;

        return sqrt((2 + $redMean / 256) * ($r1 - $r2) ** 2 + 4 * ($g1 - $g2) ** 2 + (2 + (255 - $redMean) / 256) * ($b1 - $b2) ** 2);
    }

    /**
     * Mix with white (positive amount) or black (negative amount).
     */
    private function mix(string $color, float $amount): string
    {
        $target = $amount > 0 ? 255 : 0;

        return '#'.implode('', array_map(
            fn (int $channel): string => str_pad(dechex((int) round($channel + ($target - $channel) * abs($amount))), 2, '0', STR_PAD_LEFT),
            $this->rgb($color),
        ));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $color): array
    {
        $value = hexdec(ltrim($color, '#'));

        return [($value >> 16) & 255, ($value >> 8) & 255, $value & 255];
    }
}

<?php

namespace App\Services;

use App\Enums\State;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Converts the IBGE municipality and state meshes into compact SVG paths.
 *
 * The browser renders the map as a plain SVG, so the projection is done here
 * once (equirectangular corrected by the cosine of the central latitude).
 */
class MapGeometryBuilder
{
    public const string PATH = 'mapa/brasil.json';

    private const string MESH_URL = 'https://servicodados.ibge.gov.br/api/v3/malhas/paises/BR';

    private const int WIDTH = 1000;

    /**
     * Decimal places of the coordinates: the map zooms up to 125 times.
     */
    private const int PRECISION = 2;

    private float $minLongitude;

    private float $maxLatitude;

    private float $scale;

    private float $longitudeFactor;

    /**
     * Download both meshes and store the projected geometry.
     *
     * @return array{municipalities: int, states: int}
     */
    public function build(): array
    {
        $municipalities = $this->download('municipio');
        $states = $this->download('UF');

        $this->computeProjection($states['features']);

        $geometry = [
            'width' => self::WIDTH,
            'height' => 0,
            'municipalities' => [],
            'states' => [],
        ];

        foreach ($municipalities['features'] as $feature) {
            $geometry['municipalities'][(string) $feature['properties']['codarea']] = $this->path($feature['geometry'])['d'];
        }

        $height = 0.0;

        foreach ($states['features'] as $feature) {
            $state = collect(State::cases())->first(fn (State $state): bool => $state->ibgePrefix() === (string) $feature['properties']['codarea']);

            if ($state === null) {
                continue;
            }

            $path = $this->path($feature['geometry']);
            $height = max($height, $path['bbox'][1] + $path['bbox'][3]);
            $geometry['states'][$state->abbreviation()] = $path;
        }

        $geometry['height'] = (int) ceil($height);

        Storage::put(self::PATH, json_encode($geometry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'municipalities' => count($geometry['municipalities']),
            'states' => count($geometry['states']),
        ];
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function download(string $level): array
    {
        return Http::connectTimeout(10)
            ->timeout(180)
            ->retry(3, 2000)
            ->get(self::MESH_URL, [
                'formato' => 'application/vnd.geo+json',
                'qualidade' => 'minima',
                'intrarregiao' => $level,
            ])
            ->throw()
            ->json();
    }

    /**
     * @param  list<array<string, mixed>>  $features
     */
    private function computeProjection(array $features): void
    {
        $longitudes = [];
        $latitudes = [];

        foreach ($features as $feature) {
            foreach ($this->rings($feature['geometry']) as $ring) {
                foreach ($ring as [$longitude, $latitude]) {
                    $longitudes[] = $longitude;
                    $latitudes[] = $latitude;
                }
            }
        }

        if ($longitudes === [] || $latitudes === []) {
            throw new RuntimeException('Malha do IBGE vazia.');
        }

        $this->minLongitude = min($longitudes);
        $this->maxLatitude = max($latitudes);
        $this->longitudeFactor = cos(deg2rad((max($latitudes) + min($latitudes)) / 2));
        $this->scale = self::WIDTH / ((max($longitudes) - $this->minLongitude) * $this->longitudeFactor);
    }

    /**
     * SVG path of a geometry: each ring starts at an absolute point and
     * continues with relative moves, which are short and keep the precision
     * the map needs when zoomed in.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{d: string, bbox: array{0: float, 1: float, 2: float, 3: float}}
     */
    private function path(array $geometry): array
    {
        $commands = '';
        $minX = $minY = PHP_FLOAT_MAX;
        $maxX = $maxY = -PHP_FLOAT_MAX;

        foreach ($this->rings($geometry) as $ring) {
            $points = [];

            foreach ($ring as [$longitude, $latitude]) {
                $point = [
                    round(($longitude - $this->minLongitude) * $this->longitudeFactor * $this->scale, self::PRECISION),
                    round(($this->maxLatitude - $latitude) * $this->scale, self::PRECISION),
                ];

                if (end($points) !== $point) {
                    $points[] = $point;
                }

                $minX = min($minX, $point[0]);
                $minY = min($minY, $point[1]);
                $maxX = max($maxX, $point[0]);
                $maxY = max($maxY, $point[1]);
            }

            if (count($points) < 3) {
                continue;
            }

            [$x, $y] = $points[0];
            $commands .= 'M'.$this->coordinates($x, $y).'l';

            foreach (array_slice($points, 1) as $index => [$nextX, $nextY]) {
                $move = $this->coordinates(round($nextX - $x, self::PRECISION), round($nextY - $y, self::PRECISION));
                $commands .= ($index > 0 && ! str_starts_with($move, '-') ? ' ' : '').$move;
                [$x, $y] = [$nextX, $nextY];
            }

            $commands .= 'z';
        }

        return [
            'd' => $commands,
            'bbox' => [$minX, $minY, round($maxX - $minX, 1), round($maxY - $minY, 1)],
        ];
    }

    /**
     * A pair of coordinates in the shortest form SVG accepts: "1.5-0.25".
     */
    private function coordinates(float $x, float $y): string
    {
        $y = $this->number($y);

        return $this->number($x).(str_starts_with($y, '-') ? '' : ' ').$y;
    }

    private function number(float $value): string
    {
        return $value == 0 ? '0' : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return list<list<array{0: float, 1: float}>>
     */
    private function rings(array $geometry): array
    {
        return match ($geometry['type']) {
            'Polygon' => $geometry['coordinates'],
            'MultiPolygon' => array_merge(...$geometry['coordinates']),
            default => [],
        };
    }
}

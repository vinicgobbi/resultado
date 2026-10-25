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
                $x = round(($longitude - $this->minLongitude) * $this->longitudeFactor * $this->scale, 1);
                $y = round(($this->maxLatitude - $latitude) * $this->scale, 1);
                $point = "{$x} {$y}";

                if (end($points) !== $point) {
                    $points[] = $point;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }

            if (count($points) >= 3) {
                $commands .= 'M'.implode('L', $points).'Z';
            }
        }

        return [
            'd' => $commands,
            'bbox' => [$minX, $minY, round($maxX - $minX, 1), round($maxY - $minY, 1)],
        ];
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

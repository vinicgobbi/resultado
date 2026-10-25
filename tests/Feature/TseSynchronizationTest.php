<?php

use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Services\WebPush;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TSE_BASE = 'https://resultados.tse.jus.br/oficial/ele2026/6258';

/**
 * Minimal TSE "-u.json" result file.
 *
 * @param  array<string, int>  $votes  keyed by candidate sequence number
 * @return array<string, mixed>
 */
function tseResult(array $votes, string $time = '20:00:00', string $counted = '50'): array
{
    $candidates = [
        '280002551544' => ['n' => '22', 'nmu' => 'FLAVIO BOLSONARO', 'nm' => 'FLAVIO NANTES BOLSONARO', 'sg' => 'PL'],
        '280002542548' => ['n' => '13', 'nmu' => 'LULA', 'nm' => 'LUIZ INÁCIO LULA DA SILVA', 'sg' => 'PT'],
    ];

    return [
        'dt' => '25/10/2026',
        'ht' => $time,
        'and' => 'p',
        'carg' => [[
            'agr' => collect($candidates)->map(fn (array $candidate, string $sequence): array => [
                'par' => [[
                    'sg' => $candidate['sg'],
                    'cand' => [[
                        'sqcand' => $sequence,
                        'n' => $candidate['n'],
                        'nmu' => $candidate['nmu'],
                        'nm' => $candidate['nm'],
                        'vap' => (string) ($votes[$sequence] ?? 0),
                    ]],
                ]],
            ])->values()->all(),
        ]],
        's' => ['ts' => '100', 'st' => $counted, 'pstn' => "{$counted},000000000"],
        'e' => ['te' => '1000', 'c' => '800', 'a' => '200'],
        'v' => ['vv' => (string) array_sum($votes), 'vb' => '10', 'tvn' => '20'],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeTse(string $countryTime = '20:00:00', string $municipalityTime = '19:59:00', array $overrides = []): void
{
    Http::fake([
        ...$overrides,
        TSE_BASE.'/config/mun-e006258-cm.json' => Http::response(['abr' => [
            ['cd' => 'sp', 'mu' => [
                ['cd' => '71072', 'cdi' => '3550308', 'nm' => 'SÃO PAULO', 'c' => 's'],
                ['cd' => '61000', 'cdi' => '3500105', 'nm' => 'ADAMANTINA', 'c' => 'n'],
            ]],
            ['cd' => 'zz', 'mu' => [['cd' => '29254', 'cdi' => '', 'nm' => 'ABIDJÃ', 'c' => 'n']]],
        ]]),
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 600, '280002542548' => 400], $countryTime)),
        TSE_BASE.'/dados/sp/sp-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 300, '280002542548' => 200])),
        TSE_BASE.'/dados/sp/sp-e006258-ab.json' => Http::response(['abr' => [
            ['tpabr' => 'mun', 'cdabr' => '71072', 'dt' => '25/10/2026', 'ht' => $municipalityTime, 's' => ['st' => '10']],
            ['tpabr' => 'mun', 'cdabr' => '61000', 'dt' => '25/10/2026', 'ht' => '18:00:00', 's' => ['st' => '0']],
        ]]),
        TSE_BASE.'/dados/sp/sp71072-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 100, '280002542548' => 300], $municipalityTime, '10')),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);
}

function municipalityFileRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'sp71072-'))->count();
}

test('sync stores candidates and results for every scope', function () {
    Http::preventStrayRequests();
    fakeTse();

    $this->artisan('tse:sincronizar', ['--turno' => 2])->assertSuccessful();

    expect(Municipality::query()->pluck('ibge_code', 'tse_code')->all())
        ->toBe(['71072' => '3550308', '61000' => '3500105', '29254' => null]);

    $flavio = Candidate::query()->where('number', 22)->sole();
    $lula = Candidate::query()->where('number', 13)->sole();

    $country = Result::query()->forElection(6258, Result::SCOPE_COUNTRY)->sole();
    expect($country->leading_candidate_id)->toBe($flavio->id)
        ->and($country->votes)->toBe([$flavio->id => 600, $lula->id => 400])
        ->and($country->counted_percent)->toBe(50.0)
        ->and($country->tse_updated_at?->format('Y-m-d H:i:s'))->toBe('2026-10-25 20:00:00');

    expect(Result::query()->forElection(6258, Result::SCOPE_STATE)->pluck('scope_code')->all())->toBe(['sp']);

    $saoPaulo = Result::query()->forElection(6258, Result::SCOPE_MUNICIPALITY)->sole();
    expect($saoPaulo->scope_code)->toBe('71072')
        ->and($saoPaulo->leading_candidate_id)->toBe($lula->id);
});

test('sync only downloads municipalities updated since the last run', function () {
    Http::preventStrayRequests();
    fakeTse(municipalityTime: '19:59:00');
    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect(municipalityFileRequests())->toBe(1);

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect(municipalityFileRequests())->toBe(1);

    Result::query()->forElection(6258, Result::SCOPE_MUNICIPALITY)->update(['tse_updated_at' => '2026-10-25 19:00:00']);

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect(municipalityFileRequests())->toBe(2);
});

test('sync keeps admin colors when candidates are refreshed', function () {
    Http::preventStrayRequests();
    Candidate::factory()->create(['tse_sequence' => '280002542548', 'number' => 13, 'color' => '#ff0000']);
    fakeTse();

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(Candidate::query()->where('tse_sequence', '280002542548')->sole())
        ->color->toBe('#ff0000')
        ->ballot_name->toBe('LULA');
});

test('a push notification is sent only when the national result changes', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    PushSubscription::create(['endpoint' => 'https://push.example.com/abc', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.com/abc')]);
    $pushes = fn (): int => Http::recorded(fn (Request $request): bool => $request->url() === 'https://push.example.com/abc')->count();

    fakeTse(countryTime: '20:00:00', overrides: ['push.example.com/*' => Http::response('', 201)]);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect($pushes())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://push.example.com/abc'
        && str_starts_with($request->header('Authorization')[0], 'vapid t=')
        && $request->header('TTL')[0] === '600');

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect($pushes())->toBe(1);
});

test('expired push subscriptions are removed', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    PushSubscription::create(['endpoint' => 'https://push.example.com/gone', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.com/gone')]);

    fakeTse(overrides: ['push.example.com/*' => Http::response('', 410)]);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushSubscription::query()->count())->toBe(0);
});

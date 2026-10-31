<?php

use App\Console\Commands\DownloadCandidatePhotos;
use App\Enums\ElectionType;
use App\Enums\Office;
use App\Enums\State;
use App\Jobs\SendResultsNotification;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Municipality;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Services\Tse\CandidatePhotos;
use App\Services\Tse\ResultSynchronizer;
use App\Services\Tse\TseClient;
use App\Services\WebPush;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

const TSE_BASE = 'https://resultados.tse.jus.br/oficial/ele2026/6258';

beforeEach(fn () => elections2026());

/**
 * Minimal TSE "-u.json" result file.
 *
 * @param  array<string, int>  $votes  keyed by candidate sequence number
 * @return array<string, mixed>
 */
function tseResult(array $votes, string $time = '20:00:00', string $counted = '50', ?string $elected = null): array
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
                        'st' => $sequence === $elected ? 'Eleito' : '',
                    ]],
                ]],
            ])->values()->all(),
        ]],
        's' => ['ts' => '100', 'st' => $counted, 'pstn' => "{$counted},000000000"],
        'e' => ['te' => '1000', 'est' => '900', 'c' => '800', 'a' => '200'],
        'v' => ['vv' => (string) array_sum($votes), 'vb' => '10', 'tvn' => '20'],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeTse(string $countryTime = '20:00:00', string $municipalityTime = '19:59:00', array $overrides = []): void
{
    $stubs = [
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
    ];

    // Overrides replace a default with the same URL; anything else on the
    // TSE host is a 404, matched last.
    Http::fake([...$stubs, ...$overrides, 'resultados.tse.jus.br/*' => Http::response('', 404)]);
}

function municipalityFileRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'sp71072-'))->count();
}

test('sync stores candidates and results for every scope', function () {
    Http::preventStrayRequests();
    fakeTse();

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(Municipality::query()->pluck('ibge_code', 'tse_code')->all())
        ->toBe(['71072' => '3550308', '61000' => '3500105', '29254' => null]);

    $flavio = Candidate::query()->where('number', 22)->sole();
    $lula = Candidate::query()->where('number', 13)->sole();

    $country = Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->sole();
    expect($country->leading_candidate_id)->toBe($flavio->id)
        ->and($country->votes)->toBe([$flavio->id => 600, $lula->id => 400])
        ->and($country->counted_percent)->toBe(50.0)
        ->and($country->tse_updated_at?->format('Y-m-d H:i:s'))->toBe('2026-10-25 20:00:00');

    expect(Result::query()->forElection(6258, Office::President, Result::SCOPE_STATE)->pluck('scope_code')->all())->toBe(['sp']);

    $saoPaulo = Result::query()->forElection(6258, Office::President, Result::SCOPE_MUNICIPALITY)->sole();
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

    Result::query()->forElection(6258, Office::President, Result::SCOPE_MUNICIPALITY)->update(['progress_updated_at' => '2026-10-25 19:00:00']);

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect(municipalityFileRequests())->toBe(2);
});

test('sync completes a partial municipality import', function () {
    Http::preventStrayRequests();
    // As left by an import interrupted after its first chunk.
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    fakeTse();

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(Municipality::query()->pluck('tse_code')->sort()->values()->all())->toBe(['29254', '61000', '71072']);
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

/**
 * A browser subscribed to push notifications, following a result.
 *
 * @param  array<string, mixed>  $follow
 */
function followingBrowser(string $endpoint, array $follow = []): PushSubscription
{
    $subscription = PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);
    $subscription->follows()->create(['office' => Office::President, 'scope_code' => 'br', ...$follow]);

    return $subscription;
}

test('a push notification is sent only when a followed result changes', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    $pushes = fn (): int => Http::recorded(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/abc')->count();

    fakeTse(countryTime: '20:00:00', overrides: ['fcm.googleapis.com/*' => Http::response('', 201)]);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect($pushes())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/abc'
        && str_starts_with($request->header('Authorization')[0], 'vapid t=')
        && $request->header('TTL')[0] === '600');

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect($pushes())->toBe(1);
});

test('a followed result gets a message about its new results', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    // Still open: 2 × 520 is not more than 1000 valid votes + 100 voters left.
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 520, '280002542548' => 480])),
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushMessage::query()->sole()->only(['title', 'body', 'url', 'tag']))->toBe([
        'title' => 'Presidente · Brasil: 50,00% apurado',
        'body' => 'FLAVIO BOLSONARO 52,00% × LULA 48,00%',
        'url' => '/eleicoes/6258?cargo=presidente',
        'tag' => 'presidente-br-atualizacao',
    ]);
});

test('a result that becomes mathematically decided notifies it once', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc', ['notify_updates' => false]);
    // 900 valid votes and 100 voters left: 2 × 700 > 900 + 100.
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 700, '280002542548' => 200])),
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushMessage::query()->sole()->only(['title', 'body']))->toBe([
        'title' => '🎉 Presidente · Brasil: FLAVIO BOLSONARO eleito',
        'body' => 'Matematicamente, com 50,00% apurado.',
    ]);

    Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->update(['tse_updated_at' => '2026-10-25 19:00:00']);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushMessage::query()->count())->toBe(1);
});

test('the end of the count tells who was elected', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response([
            ...tseResult(['280002551544' => 400, '280002542548' => 600], counted: '100', elected: '280002542548'),
            'and' => 'f',
        ]),
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushMessage::query()->sole()->only(['title', 'body']))->toBe([
        'title' => '✔️ Presidente · Brasil: apuração encerrada',
        'body' => 'Eleito: LULA.',
    ]);
});

/**
 * Syncs the national result with these votes and percent counted, and
 * returns how many "new results" messages it wrote.
 *
 * @param  array<string, int>  $votes
 */
function syncCountry(array &$country, array $votes, string $counted): int
{
    $country = ['votes' => $votes, 'counted' => $counted];
    PushMessage::query()->delete();
    Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->update(['tse_updated_at' => '2026-10-25 19:00:00']);
    test()->artisan('tse:sincronizar')->assertSuccessful();

    return PushMessage::query()->where('tag', 'presidente-br-atualizacao')->count();
}

test('new results of a followed result are notified at a new step of the count or a new leader', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    $country = [];
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => function () use (&$country) {
            return Http::response(tseResult($country['votes'], counted: $country['counted']));
        },
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);
    $flavioLeads = ['280002551544' => 520, '280002542548' => 480];
    $lulaLeads = ['280002551544' => 480, '280002542548' => 520];

    expect(syncCountry($country, $flavioLeads, '50'))->toBe(1);

    $this->travel(11)->minutes();
    expect(syncCountry($country, $flavioLeads, '60'))->toBe(0)
        ->and(syncCountry($country, $flavioLeads, '75'))->toBe(1);

    $this->travel(11)->minutes();
    expect(syncCountry($country, $lulaLeads, '76'))->toBe(1);
});

test('new results are notified at most every ten minutes, and a step held back is sent after it', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    $country = [];
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => function () use (&$country) {
            return Http::response(tseResult($country['votes'], counted: $country['counted']));
        },
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);
    $votes = ['280002551544' => 520, '280002542548' => 480];

    expect(syncCountry($country, $votes, '50'))->toBe(1);

    $this->travel(5)->minutes();
    expect(syncCountry($country, $votes, '75'))->toBe(0);

    $this->travel(6)->minutes();
    expect(syncCountry($country, $votes, '76'))->toBe(1);
});

test('nothing is sent for kinds of notification the follower turned off', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc', ['notify_updates' => false]);
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response(tseResult(['280002551544' => 520, '280002542548' => 480])),
        'fcm.googleapis.com/*' => Http::response('', 201),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushMessage::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com'));
});

test('a sync without followers queues no notification', function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    fakeTse();

    $this->artisan('tse:sincronizar')->assertSuccessful();

    Queue::assertNotPushed(SendResultsNotification::class);
});

test('the push notification is queued instead of holding up the sync', function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/abc');
    fakeTse();

    $this->artisan('tse:sincronizar')->assertSuccessful();

    Queue::assertPushed(SendResultsNotification::class);
});

test('a sync caches the results under a new version only when something changed', function () {
    Http::preventStrayRequests();
    fakeTse();
    $this->artisan('tse:sincronizar')->assertSuccessful();

    $version = ResultSynchronizer::version(6258);
    expect($version)->toBe(1)
        ->and(Cache::has(ResultSynchronizer::cacheKey(6258, 'presidente.resumo.br')))->toBeTrue()
        ->and(Cache::has(ResultSynchronizer::cacheKey(6258, 'presidente.mapa.br')))->toBeTrue();

    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect(ResultSynchronizer::version(6258))->toBe($version);

    Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->update(['tse_updated_at' => '2026-10-25 19:00:00']);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(ResultSynchronizer::version(6258))->toBe($version + 1)
        ->and(Cache::has(ResultSynchronizer::cacheKey(6258, 'presidente.resumo.br')))->toBeTrue()
        ->and(Cache::has(ResultSynchronizer::cacheKey(6258, 'presidente.resumo.br', $version)))->toBeFalse()
        ->and(Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->sole()->tse_updated_at?->format('H:i:s'))
        ->toBe('20:00:00');
});

test('expired push subscriptions are removed', function () {
    Http::preventStrayRequests();
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    followingBrowser('https://fcm.googleapis.com/gone');

    fakeTse(overrides: ['fcm.googleapis.com/*' => Http::response('', 410)]);
    $this->artisan('tse:sincronizar')->assertSuccessful();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('sync stores the officially elected candidate and the counted electorate', function () {
    Http::preventStrayRequests();
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response(
            tseResult(['280002551544' => 400, '280002542548' => 600], elected: '280002542548'),
        ),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    $country = Result::query()->forElection(6258, Office::President, Result::SCOPE_COUNTRY)->sole();
    expect($country->elected_candidate_ids)->toBe([Candidate::query()->where('number', 13)->value('id')])
        ->and($country->electorate_counted)->toBe(900);
});

test('a candidate photo is downloaded from the TSE once, on the first request', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $jpeg = "\xFF\xD8\xFF\xE0fake-jpeg";
    Http::fake(['resultados.tse.jus.br/oficial/ele2026/6259/fotos/sp/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
    $candidate = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();

    expect($candidate->toPublicArray()['photo_url'])->toBe(route('candidates.photo', $candidate));

    $this->get(route('candidates.photo', $candidate))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('candidates.photo', $candidate))->assertOk();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($candidate->tse_sequence));
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://resultados.tse.jus.br/oficial/ele2026/6259/fotos/sp/{$candidate->tse_sequence}.jpeg");

    // Without the public/storage link, the route keeps serving the photo.
    expect($candidate->toPublicArray()['photo_url'])->toBe(route('candidates.photo', $candidate));
});

test('while an election is counted, only its photos are downloaded on demand', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    // 2026-10-26: the federal runoff is being counted.
    $this->travelTo('2026-10-26 12:00:00');
    Election::factory()->municipal2024()->completed()->create();
    $councillor = Candidate::factory()->running(Office::Councillor, State::SaoPaulo, '71072')->create();
    $president = Candidate::factory()->create();
    Http::fake(['resultados.tse.jus.br/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);

    $this->get(route('candidates.photo', $councillor))->assertServiceUnavailable()->assertHeader('Retry-After', '1800');
    $this->get(route('candidates.photo', $president))->assertOk();

    Http::assertSentCount(1);
});

test('a visitor cannot trigger more than a page of photo downloads a minute', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Http::fake(['resultados.tse.jus.br/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);
    $candidate = Candidate::factory()->create();
    RateLimiter::increment('tse-fotos-visitante:127.0.0.1', 60, 60);

    $this->get(route('candidates.photo', $candidate))->assertServiceUnavailable()->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get(route('candidates.photo', $candidate))->assertOk();

    Http::assertSentCount(1);
});

test('presidential photos come from the first round of the federal election', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Http::fake(['resultados.tse.jus.br/oficial/ele2026/6257/fotos/br/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);
    $candidate = Candidate::factory()->create();

    $this->get(route('candidates.photo', $candidate))->assertOk();
});

test('a missing photo is not requested again for a while', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Http::fake(['resultados.tse.jus.br/*' => Http::response('', 404)]);
    $candidate = Candidate::factory()->create();

    $this->get(route('candidates.photo', $candidate))->assertNotFound();
    $this->get(route('candidates.photo', $candidate))->assertNotFound();

    Http::assertSentCount(1);
});

/**
 * TSE result file of a state office.
 *
 * @param  array<string, array{0: string, 1: int, 2?: string}>  $candidates  number, votes and status keyed by sequence
 * @return array<string, mixed>
 */
function tseOfficeResult(array $candidates, int $seats = 1, string $time = '22:00:00'): array
{
    return [
        'dt' => '04/10/2026',
        'ht' => $time,
        'and' => 'f',
        'carg' => [[
            'nv' => (string) $seats,
            'agr' => [[
                'par' => [[
                    'sg' => 'PARTIDO',
                    'cand' => collect($candidates)->map(fn (array $candidate, string $sequence): array => [
                        'sqcand' => $sequence,
                        'n' => $candidate[0],
                        'nm' => "CANDIDATO {$candidate[0]}",
                        'vap' => (string) $candidate[1],
                        'st' => $candidate[2] ?? 'Não eleito',
                    ])->values()->all(),
                ]],
            ]],
        ]],
        's' => ['ts' => '100', 'st' => '100', 'pstn' => '100'],
        'e' => ['te' => '1000', 'est' => '1000', 'c' => '800', 'a' => '200'],
        'v' => ['vv' => (string) collect($candidates)->sum(fn (array $candidate): int => $candidate[1]), 'vb' => '10', 'tvn' => '20'],
    ];
}

test('sync stores the governor, senator and deputy results of the state election', function () {
    Http::preventStrayRequests();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    $base = 'https://resultados.tse.jus.br/oficial/ele2026';
    Http::fake([
        "{$base}/6257/dados/br/br-c0001-e006257-u.json" => Http::response(tseResult(['280002542548' => 10])),
        "{$base}/6259/dados/sp/sp-c0003-e006259-u.json" => Http::response(tseOfficeResult(['250000000001' => ['10', 600, 'Eleito'], '250000000002' => ['13', 400]])),
        "{$base}/6259/dados/sp/sp-c0005-e006259-u.json" => Http::response(tseOfficeResult([
            '250000000011' => ['101', 500, 'Eleito'], '250000000012' => ['131', 400, 'Eleito'], '250000000013' => ['555', 100],
        ], seats: 2)),
        "{$base}/6259/dados/sp/sp-c0006-e006259-u.json" => Http::response(tseOfficeResult([
            '250000000021' => ['1010', 90, 'Eleito por QP'], '250000000022' => ['1311', 80, 'Eleito por média'], '250000000023' => ['1313', 85, 'Suplente'],
        ], seats: 70)),
        "{$base}/6259/dados/df/df-c0008-e006259-u.json" => Http::response(tseOfficeResult(['250000000031' => ['10123', 50, 'Eleito por QP']], seats: 24)),
        "{$base}/6259/dados/sp/sp-e006259-ab.json" => Http::response(['abr' => [
            ['tpabr' => 'mun', 'cdabr' => '71072', 'dt' => '04/10/2026', 'ht' => '21:00:00', 's' => ['st' => '10']],
        ]]),
        "{$base}/6259/dados/sp/sp71072-c0003-e006259-u.json" => Http::response(tseOfficeResult(['250000000001' => ['10', 40], '250000000002' => ['13', 60]], time: '21:00:00')),
        "{$base}/6259/dados/sp/sp71072-c0005-e006259-u.json" => Http::response(tseOfficeResult(['250000000011' => ['101', 5], '250000000013' => ['555', 9]], seats: 2, time: '21:00:00')),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:sincronizar', ['--eleicao' => [6257, 6259], '--sem-notificacao' => true])->assertSuccessful();

    $ids = Candidate::query()->pluck('id', 'tse_sequence');
    expect(Candidate::query()->where('tse_sequence', '250000000001')->sole())
        ->office->toBe(Office::Governor)
        ->state->toBe(State::SaoPaulo);
    expect(Candidate::query()->where('tse_sequence', '250000000031')->sole())
        ->office->toBe(Office::StateDeputy)
        ->state->toBe(State::DistritoFederal);

    $governor = Result::query()->forElection(6259, Office::Governor, Result::SCOPE_STATE)->sole();
    expect($governor->elected_candidate_ids)->toBe([$ids['250000000001']])
        ->and($governor->seats)->toBe(1);

    $senate = Result::query()->forElection(6259, Office::Senator, Result::SCOPE_STATE)->sole();
    expect($senate->elected_candidate_ids)->toBe([$ids['250000000011'], $ids['250000000012']])
        ->and($senate->seats)->toBe(2);

    $deputies = Result::query()->forElection(6259, Office::FederalDeputy, Result::SCOPE_STATE)->sole();
    expect($deputies->elected_candidate_ids)->toBe([$ids['250000000021'], $ids['250000000022']])
        ->and($deputies->seats)->toBe(70);

    expect(Result::query()->forElection(6259, Office::Governor, Result::SCOPE_MUNICIPALITY)->sole()->leading_candidate_id)->toBe($ids['250000000002'])
        ->and(Result::query()->forElection(6259, Office::Senator, Result::SCOPE_MUNICIPALITY)->sole()->leading_candidate_id)->toBe($ids['250000000013'])
        ->and(Result::query()->forElection(6259, Office::FederalDeputy, Result::SCOPE_MUNICIPALITY)->exists())->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sp71072-c0006'));
});

test('a runoff is only requested where the TSE lists it', function () {
    Http::preventStrayRequests();
    fakeTse();

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertSuccessful();

    $runoffRequests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/6260/'))
        ->map(fn (array $pair): string => basename($pair[0]->url()))
        ->sort()
        ->values()
        ->all();
    expect($runoffRequests)->toBe(['df-c0003-e006260-u.json', 'df-e006260-ab.json']);
});

test('a 429 pauses every request to the TSE instead of insisting', function () {
    Http::preventStrayRequests();
    fakeTse(overrides: [TSE_BASE.'/dados/sp/sp-c0001-e006258-u.json' => Http::response('', 429)]);

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])
        ->expectsOutputToContain('O TSE limitou as requisições (HTTP 429)')
        ->assertFailed();
    $requests = Http::recorded()->count();

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertFailed();
    expect(Http::recorded())->toHaveCount($requests);

    // Blocked again right after the pause: the next pause is twice as long.
    $this->travel(301)->seconds();
    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertFailed();
    expect(Http::recorded()->count())->toBeGreaterThan($requests);
    expect(app(TseClient::class)->pausedUntil()?->getTimestamp())->toBe(now()->addSeconds(600)->getTimestamp());
});

test('an election whose results are all final is not downloaded again', function () {
    Http::preventStrayRequests();
    $final = fn (array $result): array => [...$result, 'and' => 'f'];
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response($final(tseResult(['280002551544' => 600, '280002542548' => 400]))),
        TSE_BASE.'/dados/sp/sp-c0001-e006258-u.json' => Http::response($final(tseResult(['280002551544' => 300, '280002542548' => 200]))),
        TSE_BASE.'/dados/sp/sp-e006258-ab.json' => Http::response(['abr' => [
            ['tpabr' => 'mun', 'cdabr' => '71072', 'and' => 'f', 'dt' => '25/10/2026', 'ht' => '19:59:00', 's' => ['st' => '10']],
        ]]),
        TSE_BASE.'/dados/sp/sp71072-c0001-e006258-u.json' => Http::response($final(tseResult(['280002551544' => 100, '280002542548' => 300], '19:59:00', '10'))),
    ]);

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertSuccessful();
    $requests = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/6258/'))->count();
    $afterFirstSync = $requests();

    expect(Election::query()->where('tse_code', 6258)->sole()->completed_at)->not->toBeNull();

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertSuccessful();
    expect($requests())->toBe($afterFirstSync);

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true, '--completo' => true])->assertSuccessful();
    expect($requests())->toBeGreaterThan($afterFirstSync);
});

test('an election with failed downloads is not complete', function () {
    Http::preventStrayRequests();
    fakeTse(overrides: [
        TSE_BASE.'/dados/br/br-c0001-e006258-u.json' => Http::response([...tseResult(['280002551544' => 600]), 'and' => 'f']),
        TSE_BASE.'/dados/sp/sp-c0001-e006258-u.json' => Http::response('', 500),
    ]);

    $this->artisan('tse:sincronizar', ['--sem-notificacao' => true])->assertSuccessful();

    expect(Election::query()->where('tse_code', 6258)->sole()->completed_at)->toBeNull();
});

test('a photo missing from an election that is over is not requested again for a month', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Http::fake(['resultados.tse.jus.br/*' => Http::response('', 404)]);
    $this->travelTo('2026-11-10 12:00:00');
    Election::factory()->municipal2024()->completed()->create();
    $councillor = Candidate::factory()->running(Office::Councillor, State::SaoPaulo, '71072')->create();
    $photos = app(CandidatePhotos::class);

    $photos->download([$councillor]);
    $this->travel(29)->days();

    expect($photos->isMissing($councillor))->toBeTrue();

    $this->travel(2)->days();

    expect($photos->isMissing($councillor))->toBeFalse();
});

test('a photo blocked by a 429 is retried later instead of marked as missing', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Http::fakeSequence('resultados.tse.jus.br/*')
        ->push('', 429)
        ->push("\xFF\xD8\xFF\xE0fake-jpeg");
    $candidate = Candidate::factory()->create();

    $this->get(route('candidates.photo', $candidate))->assertServiceUnavailable()->assertHeader('Retry-After');
    $this->get(route('candidates.photo', $candidate))->assertServiceUnavailable();
    Http::assertSentCount(1);

    $this->travel(301)->seconds();
    $this->get(route('candidates.photo', $candidate))->assertOk();
});

test('the photos command downloads every missing photo and stops on a 429', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    [$first, $second] = Candidate::factory()->count(2)->create();
    $deputy = Candidate::factory()->running(Office::FederalDeputy, State::SaoPaulo)->create();
    Storage::disk(Candidate::PHOTO_DISK)->put(Candidate::photoPath($first->tse_sequence), 'stored');
    Http::fake([
        "*/6257/fotos/br/{$second->tse_sequence}.jpeg" => Http::response("\xFF\xD8\xFF\xE0fake-jpeg"),
        "*/6259/fotos/sp/{$deputy->tse_sequence}.jpeg" => Http::response('', 404),
    ]);

    $this->artisan('tse:fotos')
        ->expectsOutputToContain('1 fotos baixadas, 1 sem foto no TSE, 0 falharam')
        ->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($second->tse_sequence));
    Http::assertSentCount(2);

    $this->artisan('tse:fotos')->expectsOutputToContain('Todas as fotos já foram baixadas.')->assertSuccessful();

    $governor = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    Http::fake(["*/6259/fotos/sp/{$governor->tse_sequence}.jpeg" => Http::response('', 429)]);

    $this->artisan('tse:fotos', ['--cargo' => ['governador'], '--sem-espera' => true])
        ->expectsOutputToContain('O TSE limitou as requisições (HTTP 429)')
        ->assertFailed();
});

test('the photos of past elections are downloaded, majority offices first, but never during a count', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Election::factory()->municipal2024()->completed()->create();
    $councillor = Candidate::factory()->running(Office::Councillor, State::SaoPaulo, '71072')->create();
    $mayor = Candidate::factory()->running(Office::Mayor, State::SaoPaulo, '71072')->create();
    Http::fake(['*/619/fotos/sp/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);

    // 2026-10-26: the federal runoff is being counted.
    $this->artisan('tse:fotos', ['--encerradas' => true])
        ->expectsOutputToContain('Há eleição em apuração')
        ->assertSuccessful();
    Http::assertNothingSent();

    $this->travelTo('2026-11-10 12:00:00');
    $this->artisan('tse:fotos', ['--encerradas' => true, '--cargo' => ['prefeito']])->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($mayor->tse_sequence));
    Storage::disk(Candidate::PHOTO_DISK)->assertMissing(Candidate::photoPath($councillor->tse_sequence));

    $this->artisan('tse:fotos', ['--encerradas' => true])->assertSuccessful();
    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($councillor->tse_sequence));
});

test('the photos command downloads both rounds of the current election', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    // Only in the first round: district councillors have no runoff.
    Election::factory()->create([
        'tse_code' => 6261, 'cycle' => 'ele2026', 'date' => '2026-10-04', 'round' => 1,
        'type' => ElectionType::Municipal, 'offices' => [Office::DistrictCouncillor],
    ]);
    $councillor = Candidate::factory()->running(Office::DistrictCouncillor, State::Pernambuco, '30015')->create(['election_code' => 6261]);
    Http::fake(["*/6261/fotos/pe/{$councillor->tse_sequence}.jpeg" => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);

    // 2026-10-25: the runoff is on screen, the councillors are in the first round.
    $this->artisan('tse:fotos')->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($councillor->tse_sequence));
});

test('the scheduled photos of the current election wait for the count to end', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $candidate = Candidate::factory()->create();
    Http::fake(['*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);

    $this->artisan('tse:fotos', ['--fora-da-apuracao' => true])
        ->expectsOutputToContain('Há eleição em apuração')
        ->assertSuccessful();
    Http::assertNothingSent();

    $this->travelTo('2026-11-10 12:00:00');
    $this->artisan('tse:fotos', ['--fora-da-apuracao' => true])->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($candidate->tse_sequence));
});

test('every photo can be deleted from the server, after a confirmation', function () {
    Storage::fake(Candidate::PHOTO_DISK);
    [$first, $second] = Candidate::factory()->count(2)->create();

    $this->artisan('fotos:apagar')->expectsOutputToContain('Não há fotos no servidor.')->assertSuccessful();

    foreach ([$first, $second] as $candidate) {
        Storage::disk(Candidate::PHOTO_DISK)->put(Candidate::photoPath($candidate->tse_sequence), str_repeat('x', 1024));
    }

    $this->artisan('fotos:apagar')
        ->expectsConfirmation('Apagar 2 fotos (2 KB)? Elas são baixadas de novo quando aparecerem ou pelas tarefas de fotos.', 'no')
        ->expectsOutputToContain('Nada foi apagado.')
        ->assertSuccessful();
    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($first->tse_sequence));

    $version = ResultSynchronizer::version(6258);

    $this->artisan('fotos:apagar', ['--force' => true])
        ->expectsOutputToContain('2 fotos (2 KB) apagadas.')
        ->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertMissing(Candidate::photoPath($first->tse_sequence));
    Storage::disk(Candidate::PHOTO_DISK)->assertMissing(Candidate::photoPath($second->tse_sequence));
    // The cached results linked the photos to their files.
    expect(ResultSynchronizer::version(6258))->toBe($version + 1);
});

test('the photos command waits for the pause after a 429 and continues', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Sleep::fake(syncWithCarbon: true);
    $candidate = Candidate::factory()->create();
    Http::fakeSequence('resultados.tse.jus.br/*')
        ->push('', 429)
        ->push("\xFF\xD8\xFF\xE0fake-jpeg");

    $startedAt = now();

    $this->artisan('tse:fotos')
        ->expectsOutputToContain('Aguardando para continuar')
        ->expectsOutputToContain('Aguardando o TSE até')
        ->expectsOutputToContain('1 fotos baixadas')
        ->assertSuccessful();

    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($candidate->tse_sequence));
    // In steps of a minute at most, printing a line between them.
    expect($startedAt->diffInSeconds(now()))->toBeGreaterThanOrEqual(300);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds <= 60, 6);
});

test('the photos command continues from where the last run stopped', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $president = Candidate::factory()->create();
    $governor = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    $blocked = true;
    Http::fake(function (Request $request) use (&$blocked, $governor) {
        return $blocked && str_contains($request->url(), $governor->tse_sequence)
            ? Http::response('', 429)
            : Http::response("\xFF\xD8\xFF\xE0fake-jpeg");
    });

    // Presidents come first: they are kept as checked before the 429.
    $this->artisan('tse:fotos', ['--sem-espera' => true])->assertFailed();

    $this->travel(1)->hour();
    Storage::disk(Candidate::PHOTO_DISK)->delete(Candidate::photoPath($president->tse_sequence));
    $blocked = false;

    $this->artisan('tse:fotos')
        ->expectsOutputToContain('Continuando de onde a última execução parou: 1 de 2')
        ->expectsOutputToContain('1 fotos baixadas')
        ->assertSuccessful();

    // The president was not checked again; a complete pass starts over.
    Storage::disk(Candidate::PHOTO_DISK)->assertMissing(Candidate::photoPath($president->tse_sequence));
    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($governor->tse_sequence));

    $this->artisan('tse:fotos')
        ->doesntExpectOutputToContain('Continuando')
        ->expectsOutputToContain('1 fotos baixadas')
        ->assertSuccessful();
    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($president->tse_sequence));
});

test('a photo that could not be saved counts as failed, not downloaded', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $candidate = Candidate::factory()->create();
    Http::fake(['*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg")]);
    $disk = Storage::disk(Candidate::PHOTO_DISK);
    $directory = $disk->path(dirname(Candidate::photoPath($candidate->tse_sequence)));
    // Like a folder of another system user: nothing can be written.
    mkdir($directory, 0555, true);

    try {
        $this->artisan('tse:fotos')
            ->expectsOutputToContain('0 fotos baixadas, 0 sem foto no TSE, 1 falharam')
            ->assertFailed();
    } finally {
        chmod($directory, 0755);
    }

    $disk->assertMissing(Candidate::photoPath($candidate->tse_sequence));
});

test('deleting the photos says so when the files could not be deleted', function () {
    Storage::fake(Candidate::PHOTO_DISK);
    $candidate = Candidate::factory()->create();
    $disk = Storage::disk(Candidate::PHOTO_DISK);
    $disk->put(Candidate::photoPath($candidate->tse_sequence), 'stored');
    $directory = $disk->path(dirname(Candidate::photoPath($candidate->tse_sequence)));
    // Like photos written by another system user: nothing can be removed.
    chmod($directory, 0555);
    $version = ResultSynchronizer::version(6258);

    try {
        $this->artisan('fotos:apagar', ['--force' => true])
            ->expectsOutputToContain('1 fotos não puderam ser apagadas (0 apagadas, 0 B liberados)')
            ->doesntExpectOutputToContain('apagadas.')
            ->assertFailed();
    } finally {
        chmod($directory, 0755);
    }

    $disk->assertExists(Candidate::photoPath($candidate->tse_sequence));
    expect(ResultSynchronizer::version(6258))->toBe($version);
});

test('after deleting the photos, the photos command checks every candidate again', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    $president = Candidate::factory()->create();
    $governor = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    $blocked = true;
    Http::fake(function (Request $request) use (&$blocked, $governor) {
        return $blocked && str_contains($request->url(), $governor->tse_sequence)
            ? Http::response('', 429)
            : Http::response("\xFF\xD8\xFF\xE0fake-jpeg");
    });

    // Stops on the governor, with the president kept as checked.
    $this->artisan('tse:fotos', ['--sem-espera' => true])->assertFailed();

    $this->travel(1)->hour();
    $blocked = false;
    $this->artisan('fotos:apagar', ['--force' => true])->assertSuccessful();

    $this->artisan('tse:fotos')
        ->doesntExpectOutputToContain('Continuando')
        ->expectsOutputToContain('2 fotos baixadas')
        ->assertSuccessful();
    Storage::disk(Candidate::PHOTO_DISK)->assertExists(Candidate::photoPath($president->tse_sequence));
});

test('the photos command stops at the time limit instead of waiting past it', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Sleep::fake(syncWithCarbon: true);
    Candidate::factory()->create();
    Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    Http::fake([
        '*/fotos/br/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg"),
        '*/fotos/sp/*' => Http::response('', 429),
    ]);

    $this->artisan('tse:fotos', ['--minutos' => 2])
        ->expectsOutputToContain('Parou depois de 2 minutos')
        ->assertExitCode(DownloadCandidatePhotos::INCOMPLETE);

    Sleep::assertNeverSlept();
});

test('the photos command fails instead of continuing when the TSE blocked the whole run', function () {
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Sleep::fake(syncWithCarbon: true);
    Candidate::factory()->create();
    Http::fake(['*' => Http::response('', 429)]);

    $this->artisan('tse:fotos', ['--minutos' => 2])
        ->expectsOutputToContain('Nenhum candidato verificado em 2 minutos')
        ->assertFailed();
});

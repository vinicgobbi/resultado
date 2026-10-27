<?php

use App\Enums\ElectionType;
use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Municipality;
use App\Models\Result;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Minimal TSE result file of a municipal office.
 *
 * @param  array<string, array{0: string, 1: int, 2?: string}>  $candidates  number, votes and status keyed by sequence
 * @return array<string, mixed>
 */
function municipalResult(array $candidates, int $seats = 1, int $electorate = 1000, string $time = '22:00:00'): array
{
    return [
        'dt' => '06/10/2024',
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
        'e' => ['te' => (string) $electorate, 'est' => (string) $electorate, 'c' => '800', 'a' => '200'],
        'v' => ['vv' => (string) collect($candidates)->sum(fn (array $candidate): int => $candidate[1]), 'vb' => '10', 'tvn' => '20'],
    ];
}

test('regular elections are discovered from the TSE index, without supplementary elections nor consultations', function () {
    Http::preventStrayRequests();
    Http::fake(['resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response(['pl' => [
        ['cd' => '3220', 'c' => 'ele2026', 'dt' => '04/10/2026', 'dtlim' => '04/10/2034', 'e' => [
            ['cd' => '6259', 'cdt2' => '6260', 'nm' => 'Eleição Ordinária Estadual - 2026 1º Turno', 't' => '1', 'tp' => '1', 'abr' => [
                ['cd' => 'br', 'cp' => [['cd' => '3'], ['cd' => '5'], ['cd' => '6'], ['cd' => '7'], ['cd' => '8']]],
            ]],
        ]],
        ['cd' => '3221', 'c' => 'ele2026', 'dt' => '25/10/2026', 'dtlim' => '25/10/2034', 'e' => [
            ['cd' => '6260', 'cdt2' => '', 'nm' => 'Eleição Ordinária Estadual - 2026 2º Turno', 't' => '2', 'tp' => '1', 'abr' => [
                ['cd' => 'am', 'cp' => [['cd' => '3']]], ['cd' => 'df', 'cp' => [['cd' => '3']]],
            ]],
            ['cd' => '6285', 'cdt2' => '', 'nm' => 'Eleição Suplementar - Itaguaí', 't' => '1', 'tp' => '4', 'abr' => [
                ['cd' => 'rj', 'mu' => [['cd' => '58394', 'cdi' => '3302007']], 'cp' => [['cd' => '11']]],
            ]],
            ['cd' => '6287', 'cdt2' => '', 'nm' => 'Consulta Popular - Paraná', 't' => '1', 'tp' => '6', 'abr' => [
                ['cd' => 'pr', 'cp' => [['cd' => '25', 'ds' => 'Você é a favor?']]],
            ]],
        ]],
    ]])]);

    $this->artisan('tse:descobrir')->expectsOutputToContain('2 eleições')->assertSuccessful();

    expect(Election::query()->pluck('tse_code')->sort()->values()->all())->toBe([6259, 6260]);

    $state = Election::query()->where('tse_code', 6259)->sole();
    expect($state->type)->toBe(ElectionType::State)
        ->and($state->second_round_code)->toBe(6260)
        ->and($state->places)->toBeNull()
        ->and($state->offices->all())->toBe([Office::Governor, Office::Senator, Office::FederalDeputy, Office::StateDeputy])
        ->and($state->available_until?->toDateString())->toBe('2034-10-04');

    $runoff = Election::query()->where('tse_code', 6260)->sole();
    expect($runoff->round)->toBe(2)
        ->and($runoff->firstRound()?->tse_code)->toBe(6259)
        ->and($runoff->statesFor(Office::Governor))->toBe([State::Amazonas, State::DistritoFederal]);
});

test('elections are only synchronized live from the polls closing until they are complete', function () {
    Http::preventStrayRequests();
    Http::fake(['resultados.tse.jus.br/*' => Http::response('', 404)]);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    $election = Election::factory()->municipal2024()->create();
    $sent = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/619/'))->count();

    $this->travelTo('2024-10-06 16:00:00');
    $this->artisan('tse:sincronizar')->expectsOutputToContain('Nenhuma eleição em apuração')->assertSuccessful();
    expect($election->fresh()->status())->toBe('agendada');

    $this->travelTo('2024-10-06 17:00:00');
    $this->artisan('tse:sincronizar')->assertSuccessful();
    expect($sent())->toBeGreaterThan(0)
        ->and($election->fresh()->status())->toBe('apuracao');

    $this->travelTo('2024-10-09 17:00:00');
    $before = $sent();
    $this->artisan('tse:sincronizar')->expectsOutputToContain('Nenhuma eleição em apuração')->assertSuccessful();
    expect($sent())->toBe($before)
        ->and($election->fresh()->status())->toBe('pendente');
});

test('a past municipal election is imported with every mayor and councillor', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-03-01 10:00:00');
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    $election = Election::factory()->municipal2024()->create();
    $base = 'https://resultados.tse.jus.br/oficial/ele2024/619/dados/sp';
    Http::fake([
        "{$base}/sp-e000619-ab.json" => Http::response(['abr' => [
            ['tpabr' => 'mun', 'cdabr' => '71072', 'and' => 'f', 'dt' => '06/10/2024', 'ht' => '22:00:00', 's' => ['st' => '100']],
        ]]),
        "{$base}/sp71072-c0011-e000619-u.json" => Http::response(municipalResult(['250000000001' => ['15', 600], '250000000002' => ['45', 300]])),
        "{$base}/sp71072-c0013-e000619-u.json" => Http::response(municipalResult([
            '250000000011' => ['15111', 90, 'Eleito por QP'], '250000000012' => ['45222', 50, 'Suplente'],
        ], seats: 55)),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:importar')->expectsOutputToContain('concluída')->assertSuccessful();

    $councillor = Candidate::query()->where('tse_sequence', '250000000011')->sole();
    expect($councillor)
        ->office->toBe(Office::Councillor)
        ->state->toBe(State::SaoPaulo)
        ->municipality->toBe('71072')
        ->election_code->toBe(619);

    $mayor = Result::query()->forElection(619, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole();
    expect($mayor->scope_code)->toBe('71072')
        ->and($mayor->leading_candidate_id)->toBe(Candidate::query()->where('tse_sequence', '250000000001')->value('id'));
    expect(Result::query()->forElection(619, Office::Councillor, Result::SCOPE_MUNICIPALITY)->sole()->elected_candidate_ids)
        ->toBe([$councillor->id]);
    expect($election->fresh()->completed_at)->not->toBeNull();

    $this->artisan('tse:importar')->expectsOutputToContain('Nenhuma eleição para importar')->assertSuccessful();
});

test('the import waits while an election is being counted', function () {
    Http::preventStrayRequests();
    Election::factory()->municipal2024()->create();
    Election::factory()->federal2026()->create();
    $this->travelTo('2026-10-04 20:00:00');

    $this->artisan('tse:importar')->expectsOutputToContain('Há eleição em apuração')->assertSuccessful();
    Http::assertNothingSent();
});

test('a municipal runoff downloads only the municipalities the TSE lists, without progress files', function () {
    Http::preventStrayRequests();
    $this->travelTo('2024-10-27 20:00:00');
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    Election::factory()->municipal2024()->completed()->create();
    Election::factory()->municipal2024(2)->in(['sp'], ['71072' => 'sp'])->create();
    Http::fake([
        'resultados.tse.jus.br/oficial/ele2024/620/dados/sp/sp71072-c0011-e000620-u.json' => Http::response(municipalResult(['250000000001' => ['15', 700], '250000000002' => ['45', 300]])),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:sincronizar')->assertSuccessful();

    $result = Result::query()->forElection(620, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole();
    expect($result->scope_code)->toBe('71072')
        ->and(Candidate::query()->where('tse_sequence', '250000000001')->value('election_code'))->toBe(619);
    Http::assertSentCount(1);
});

test('the archive download discovers and downloads every past election, waiting out a 429', function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo('2026-03-01 10:00:00');
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    $base = 'https://resultados.tse.jus.br/oficial/ele2024/619/dados/sp';
    Http::fake([
        'resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response(['pl' => [
            ['cd' => '452', 'c' => 'ele2024', 'dt' => '06/10/2024', 'dtlim' => '06/10/2028', 'e' => [
                ['cd' => '619', 'cdt2' => '620', 'nm' => 'Eleição Ordinária Municipal - 2024 1º Turno', 't' => '1', 'tp' => '3', 'abr' => [
                    ['cd' => 'br', 'cp' => [['cd' => '11']]],
                ]],
            ]],
        ]]),
        "{$base}/sp-e000619-ab.json" => Http::sequence()
            ->push('', 429)
            ->push(['abr' => [['tpabr' => 'mun', 'cdabr' => '71072', 'and' => 'f', 'dt' => '06/10/2024', 'ht' => '22:00:00', 's' => ['st' => '100']]]]),
        "{$base}/sp71072-c0011-e000619-u.json" => Http::response(municipalResult(['250000000001' => ['15', 600], '250000000002' => ['45', 300]])),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:baixar-base')
        ->expectsOutputToContain('Aguardando para continuar')
        ->expectsOutputToContain('1 de 1 eleição(ões) concluída(s)')
        ->assertSuccessful();

    expect(Result::query()->forElection(619, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole()->scope_code)->toBe('71072');

    $this->artisan('tse:baixar-base')->expectsOutputToContain('a base já está completa')->assertSuccessful();
});

test('a stored result is never replaced by an older or partial one', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-03-01 10:00:00');
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    Election::factory()->municipal2024()->create();
    $base = 'https://resultados.tse.jus.br/oficial/ele2024/619/dados/sp';
    $progress = ['abr' => [['tpabr' => 'mun', 'cdabr' => '71072', 'and' => 'f', 'dt' => '06/10/2024', 'ht' => '23:00:00', 's' => ['st' => '100']]]];
    Http::fake([
        "{$base}/sp-e000619-ab.json" => Http::response($progress),
        "{$base}/sp71072-c0011-e000619-u.json" => Http::sequence()
            ->push(municipalResult(['250000000001' => ['15', 600]], time: '23:00:00'))
            ->push([...municipalResult(['250000000001' => ['15', 10]], time: '21:00:00'), 'and' => 'p']),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:importar')->assertSuccessful();
    $this->artisan('tse:importar', ['--eleicao' => [619]])->assertSuccessful();

    $result = Result::query()->forElection(619, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole();
    expect($result->is_final)->toBeTrue()
        ->and(array_values($result->votes))->toBe([600]);
});

test('offices listed before are kept when the index stops listing them', function () {
    Http::preventStrayRequests();
    Election::factory()->municipal2024()->create();
    Http::fake(['resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response(['pl' => [
        ['cd' => '452', 'c' => 'ele2024', 'dt' => '06/10/2024', 'dtlim' => '06/10/2028', 'e' => [
            ['cd' => '619', 'cdt2' => '620', 'nm' => 'Eleição Ordinária Municipal - 2024 1º Turno', 't' => '1', 'tp' => '3', 'abr' => [
                ['cd' => 'br', 'cp' => [['cd' => '11']]],
            ]],
        ]],
    ]])]);

    $this->artisan('tse:descobrir')->assertSuccessful();

    expect(Election::query()->where('tse_code', 619)->sole()->offices->all())->toBe([Office::Mayor, Office::Councillor]);
});

test('every office fits in the office columns', function () {
    // MySQL rejects longer values; SQLite, used by the tests, does not.
    expect(max(array_map(fn (Office $office): int => strlen($office->value), Office::cases())))->toBeLessThanOrEqual(30);
});

test('candidates of results that did not change are not written again', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-03-01 10:00:00');
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    // Listed municipalities are downloaded on every run.
    Election::factory()->municipal2024(2)->in(['sp'], ['71072' => 'sp'])->create();
    Http::fake([
        'resultados.tse.jus.br/oficial/ele2024/620/dados/sp/sp71072-c0011-e000620-u.json' => Http::response(
            [...municipalResult(['250000000001' => ['15', 600]]), 'and' => 'p'],
        ),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:importar')->assertSuccessful();
    $written = Candidate::query()->where('tse_sequence', '250000000001')->value('updated_at');

    $this->travel(1)->hours();
    $this->artisan('tse:importar', ['--eleicao' => [620]])->assertSuccessful();

    Http::assertSentCount(2);
    expect(Candidate::query()->where('tse_sequence', '250000000001')->value('updated_at'))->toEqual($written);
});

test('a past election is complete even with results the TSE never finalized, and is reviewed later', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-03-01 10:00:00');
    Municipality::create(['tse_code' => '31054', 'ibge_code' => '2800308', 'state' => 'se', 'name' => 'ARACAJU']);
    $election = Election::factory()->municipal2024()->create(['offices' => [Office::Mayor]]);
    $base = 'https://resultados.tse.jus.br/oficial/ele2024/619/dados/se';
    $pending = [...municipalResult(['250000000001' => ['15', 600]]), 'and' => 'p'];
    Http::fake([
        "{$base}/se-e000619-ab.json" => Http::sequence()
            ->push(['abr' => [['tpabr' => 'mun', 'cdabr' => '31054', 'and' => 'p', 'dt' => '06/10/2024', 'ht' => '22:00:00', 's' => ['st' => '100']]]])
            ->push(['abr' => [['tpabr' => 'mun', 'cdabr' => '31054', 'and' => 'f', 'dt' => '07/05/2026', 'ht' => '13:00:00', 's' => ['st' => '100']]]]),
        "{$base}/se31054-c0011-e000619-u.json" => Http::sequence()
            ->push($pending)
            ->push([...municipalResult(['250000000001' => ['15', 650]]), 'dt' => '07/05/2026', 'ht' => '13:00:00']),
        'resultados.tse.jus.br/*' => Http::response('', 404),
    ]);

    $this->artisan('tse:importar')->assertSuccessful();

    expect($election->fresh()->status())->toBe('concluida')
        ->and(Result::query()->forElection(619, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole()->is_final)->toBeFalse();

    // The TSE retotalized it.
    $this->travelTo('2026-05-08 04:30:00');
    $this->artisan('tse:importar')->expectsOutputToContain('Nenhuma eleição para importar')->assertSuccessful();
    $this->artisan('tse:importar', ['--revisar' => true])->assertSuccessful();

    $result = Result::query()->forElection(619, Office::Mayor, Result::SCOPE_MUNICIPALITY)->sole();
    expect($result->is_final)->toBeTrue()
        ->and(array_values($result->votes))->toBe([650]);
});

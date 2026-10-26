<?php

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<int, int>  $votes
 */
function storeResult(string $scope, string $code, array $votes, int $election = 6258): Result
{
    arsort($votes);

    return Result::create([
        'election_code' => $election,
        'scope' => $scope,
        'scope_code' => $code,
        'sections_total' => 10,
        'sections_counted' => 5,
        'counted_percent' => 50,
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        'tse_updated_at' => '2026-10-25 20:00:00',
    ]);
}

test('home page shows the active round with national, state and map results', function () {
    [$first, $second] = Candidate::factory()->count(2)->create();
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeResult(Result::SCOPE_COUNTRY, 'br', [$first->id => 300, $second->id => 100]);
    storeResult(Result::SCOPE_STATE, 'sp', [$first->id => 10, $second->id => 30]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$first->id => 1, $second->id => 3]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('results/index')
            ->where('round', 2)
            ->where('summary.results.0', ['candidate_id' => $first->id, 'votes' => 300, 'percent' => 75])
            ->where('states.0.code', 'SP')
            ->where('states.0.leader_id', $second->id)
            ->has('candidates', 2)
            ->missing('map')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('map.3550308', [$second->id, 75, 50])));
});

test('home page renders without synchronized results', function () {
    $this->get(route('home', ['turno' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('round', 1)
            ->where('summary', null)
            ->where('candidates', []));
});

test('an unknown round is rejected', function () {
    $this->get(route('home', ['turno' => 9]))->assertSessionHasErrors('turno');
});

test('municipality endpoint returns its detailed result', function () {
    $candidate = Candidate::factory()->create(['color' => '#123456']);
    $municipality = Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO', 'is_capital' => true]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$candidate->id => 50]);

    $this->getJson(route('municipalities.show', $municipality))
        ->assertOk()
        ->assertJsonPath('state', 'SP')
        ->assertJsonPath('result.results.0.percent', 100)
        ->assertJsonPath('candidates.0.color', '#123456');

    $this->getJson(route('municipalities.show', '9999999'))->assertNotFound();
});

test('summary endpoint lists the leading candidates for notifications', function () {
    [$first, $second] = Candidate::factory()->count(2)->create();
    storeResult(Result::SCOPE_COUNTRY, 'br', [$first->id => 300, $second->id => 100]);

    $this->getJson(route('results.summary'))
        ->assertOk()
        ->assertJsonPath('counted_percent', 50)
        ->assertJsonPath('candidates.0.name', $first->ballot_name)
        ->assertJsonPath('candidates.1.percent', 25);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function storeCountry(array $votes, array $attributes = []): void
{
    arsort($votes);

    Result::create([
        'election_code' => 6258,
        'scope' => Result::SCOPE_COUNTRY,
        'scope_code' => 'br',
        'sections_total' => 100,
        'sections_counted' => 99,
        'counted_percent' => 99,
        'electorate' => 1200,
        'electorate_counted' => 1100,
        'valid_votes' => array_sum($votes),
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        ...$attributes,
    ]);
}

test('a leader that cannot be caught is mathematically elected', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // 1000 valid votes and 100 voters left: 700 > (1000 + 100) / 2.
    storeCountry([$leader->id => 700, $other->id => 300]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'mathematical']]));
});

test('a leader that can still be caught is not elected', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // Lead of 40 votes with 100 voters left.
    storeCountry([$leader->id => 520, $other->id => 480]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('summary.winners', []));
});

test('the candidate elected by the TSE is the winner', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    storeCountry([$leader->id => 520, $other->id => 480], ['elected_candidate_ids' => [$leader->id]]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'elected']]));

    $this->getJson(route('results.summary'))->assertJsonPath('winner', ['name' => $leader->ballot_name, 'status' => 'elected']);
});

test('the voters left fall back to turnout and abstentions', function () {
    [$leader, $other] = Candidate::factory()->count(2)->create();
    // Synced before electorate_counted existed: 1100 voters counted (1000 + 100).
    storeCountry([$leader->id => 700, $other->id => 300], ['electorate_counted' => 0, 'turnout' => 1000, 'abstentions' => 100]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.winners', [['candidate_id' => $leader->id, 'status' => 'mathematical']]));
});

/**
 * @param  array<int, int>  $votes
 * @param  array<string, mixed>  $attributes
 */
function storeStateResult(Office $office, string $state, array $votes, array $attributes = []): Result
{
    arsort($votes);

    return Result::create([
        'election_code' => 6259,
        'office' => $office,
        'scope' => Result::SCOPE_STATE,
        'scope_code' => $state,
        'sections_total' => 100,
        'sections_counted' => 99,
        'counted_percent' => 99,
        'electorate' => 1200,
        'electorate_counted' => 1100,
        'valid_votes' => array_sum($votes),
        'votes' => $votes,
        'leading_candidate_id' => array_key_first($votes),
        ...$attributes,
    ]);
}

test('a state office shows the result of the chosen state and its municipalities', function () {
    [$first, $second] = Candidate::factory()->count(2)->running(Office::Governor, State::RioDeJaneiro)->create();
    $other = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    Municipality::create(['tse_code' => '60011', 'ibge_code' => '3304557', 'state' => 'rj', 'name' => 'RIO DE JANEIRO']);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeStateResult(Office::Governor, 'rj', [$first->id => 300, $second->id => 700]);
    storeStateResult(Office::Governor, 'sp', [$other->id => 100]);
    storeResult(Result::SCOPE_MUNICIPALITY, '60011', [$first->id => 3, $second->id => 1], 6259)->update(['office' => Office::Governor]);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$other->id => 1], 6259)->update(['office' => Office::Governor]);

    $this->get(route('home', ['turno' => 1, 'cargo' => 'governador', 'uf' => 'rj']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'governador')
            ->where('state', 'RJ')
            ->where('places.0.code', 'RJ')
            ->where('summary.results.0', ['candidate_id' => $second->id, 'votes' => 700, 'percent' => 70])
            ->where('summary.winners', [['candidate_id' => $second->id, 'status' => 'mathematical']])
            ->has('candidates', 2)
            ->where('states', [])
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('map', ['3304557' => [$first->id, 75, 50]])));

    $municipality = Municipality::query()->where('tse_code', '60011')->sole();
    $this->getJson(route('municipalities.show', ['municipality' => $municipality, 'turno' => 1, 'cargo' => 'governador']))
        ->assertOk()
        ->assertJsonPath('result.results.0.candidate_id', $first->id);
});

test('a state office shows nothing until the visitor picks a state', function () {
    $candidate = Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    storeStateResult(Office::Governor, 'sp', [$candidate->id => 100], ['elected_candidate_ids' => [$candidate->id]]);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);
    storeResult(Result::SCOPE_MUNICIPALITY, '71072', [$candidate->id => 1], 6259)->update(['office' => Office::Governor]);

    foreach ([[], ['uf' => 'rj'], ['uf' => 'xx']] as $query) {
        $this->get(route('home', ['turno' => 1, 'cargo' => 'governador', ...$query]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', null)
                ->where('summary', null)
                ->where('candidates', [])
                ->where('places.0.code', 'SP')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('map', [])));
    }
});

test('an office that is not disputed in the round falls back to president', function () {
    $this->get(route('home', ['turno' => 2, 'cargo' => 'senador']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'presidente')
            ->where('offices', [
                ['value' => 'presidente', 'label' => 'Presidente'],
                ['value' => 'governador', 'label' => 'Governador'],
            ]));
});

test('senators that cannot be caught are mathematically elected', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->running(Office::Senator, State::SaoPaulo)->create();
    // 100 voters left: the second leads the third by 150, the first by 250.
    storeStateResult(Office::Senator, 'sp', [$first->id => 500, $second->id => 400, $third->id => 250], ['seats' => 2]);

    $this->get(route('home', ['turno' => 1, 'cargo' => 'senador', 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.winners', [
            ['candidate_id' => $first->id, 'status' => 'mathematical'],
            ['candidate_id' => $second->id, 'status' => 'mathematical'],
        ]));
});

test('a senator still within reach is not elected', function () {
    [$first, $second, $third] = Candidate::factory()->count(3)->running(Office::Senator, State::SaoPaulo)->create();
    storeStateResult(Office::Senator, 'sp', [$first->id => 500, $second->id => 400, $third->id => 350], ['seats' => 2]);

    $this->get(route('home', ['turno' => 1, 'cargo' => 'senador', 'uf' => 'sp']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.winners', [
            ['candidate_id' => $first->id, 'status' => 'mathematical'],
        ]));
});

test('deputies are only elected by the TSE and have no map', function () {
    [$elected, $leader] = Candidate::factory()->count(2)->running(Office::StateDeputy, State::DistritoFederal)->create();
    storeStateResult(Office::StateDeputy, 'df', [$leader->id => 900, $elected->id => 100], ['seats' => 24, 'valid_votes' => 2000]);

    $this->get(route('home', ['turno' => 1, 'cargo' => 'deputado-estadual', 'uf' => 'df']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.winners', [])
            ->where('summary.results.0.percent', 45)
            ->where('offices.4.label', 'Deputado Distrital')
            ->where('mapAvailable', false));

    Result::query()->update(['elected_candidate_ids' => [$elected->id]]);
    Cache::flush();

    $this->get(route('home', ['turno' => 1, 'cargo' => 'deputado-estadual', 'uf' => 'df']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.winners', [['candidate_id' => $elected->id, 'status' => 'elected']]));
});

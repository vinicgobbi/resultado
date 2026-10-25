<?php

use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
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

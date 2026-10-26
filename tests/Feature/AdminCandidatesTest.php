<?php

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $candidate = Candidate::factory()->create();

    $this->get(route('admin.candidates.index'))->assertRedirect(route('login'));
    $this->patch(route('admin.candidates.update', $candidate), ['color' => '#000000'])->assertRedirect(route('login'));
});

test('admins can change a candidate color', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => '#AA00FF'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($candidate->fresh()->color)->toBe('#aa00ff');
});

test('clearing the color restores the automatic color', function () {
    $candidate = Candidate::factory()->create(['color' => '#aa00ff']);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => ''])
        ->assertSessionHasNoErrors();

    expect($candidate->fresh()->color)->toBeNull();
});

test('colors must be hexadecimal', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.candidates.update', $candidate), ['color' => 'red'])
        ->assertSessionHasErrors(['color' => 'Informe uma cor no formato #RRGGBB.']);
});

test('admins see the candidates of the chosen office and state', function () {
    $president = Candidate::factory()->create();
    $governor = Candidate::factory()->running(Office::Governor, State::RioDeJaneiro)->create();
    Candidate::factory()->running(Office::Governor, State::SaoPaulo)->create();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.candidates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('office', 'presidente')
            ->has('candidates', 1)
            ->where('candidates.0.id', $president->id));

    $this->actingAs($admin)
        ->get(route('admin.candidates.index', ['cargo' => 'governador', 'uf' => 'rj']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'rj')
            ->has('candidates', 1)
            ->where('candidates.0.id', $governor->id));
});

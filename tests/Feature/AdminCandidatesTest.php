<?php

use App\Models\Candidate;
use App\Models\User;

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

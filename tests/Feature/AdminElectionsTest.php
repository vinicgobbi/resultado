<?php

use App\Models\Election;
use App\Models\User;
use App\Services\Tse\ResultSynchronizer;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 2026-10-25: the 2026 runoff is being counted.
    elections2026();
    Election::factory()->municipal2024()->completed()->create();
    Election::factory()->municipal2024(2)->completed()->create();
});

test('guests cannot manage the elections', function () {
    $this->get(route('admin.elections.index'))->assertRedirect(route('login'));
    $this->patch(route('admin.elections.update', 'ele2024'), ['is_hidden' => true])->assertRedirect(route('login'));
    $this->post(route('admin.elections.feature'), ['cycle' => 'ele2024'])->assertRedirect(route('login'));
    $this->post(route('admin.elections.reopen', 619))->assertRedirect(route('login'));

    expect(Election::query()->where('is_hidden', true)->orWhere('is_featured', true)->count())->toBe(0);
});

test('the admin lists each year with its elections', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.elections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/elections/index')
            ->has('cycles', 2)
            ->where('cycles.0.cycle', 'ele2026')
            ->where('cycles.0.name', 'Eleições 2026')
            ->where('cycles.0.elections', fn ($elections): bool => collect($elections)->pluck('code')->all() === [6257, 6259, 6258, 6260])
            ->where('cycles.1.elections.0.completed_at', fn (?string $value): bool => $value !== null));
});

test('a year gets a name on the site', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('admin.elections.update', 'ele2026'), ['display_name' => 'Eleições Gerais 2026', 'is_hidden' => false])
        ->assertSessionHasNoErrors();

    $this->get(route('elections.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cycles.0.name', 'Eleições Gerais 2026'));
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('election.cycle_name', 'Eleições Gerais 2026'));
});

test('a hidden year leaves the archive and the home page, but its links still open', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)->post(route('admin.elections.feature'), ['cycle' => 'ele2024']);

    $this->actingAs($admin)
        ->patch(route('admin.elections.update', 'ele2024'), ['display_name' => '', 'is_hidden' => true])
        ->assertSessionHasNoErrors();

    expect(Election::query()->where('cycle', 'ele2024')->pluck('is_featured')->unique()->all())->toBe([false]);
    $this->get(route('elections.index'))
        ->assertInertia(fn (Assert $page) => $page->has('cycles', 1)->where('cycles.0.cycle', 'ele2026'));
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('election.cycle', 'ele2026'));
    $this->get(route('elections.show', 619))->assertOk();
});

test('the home page shows the year chosen in the admin, or chooses by itself again', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post(route('admin.elections.feature'), ['cycle' => 'ele2024'])->assertSessionHasNoErrors();

    // Its latest round, even with 2026 being counted.
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('election.code', 620));

    $this->actingAs($admin)->post(route('admin.elections.feature'), ['cycle' => null])->assertSessionHasNoErrors();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('election.code', 6258));
});

test('a year that does not exist cannot be featured', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.elections.feature'), ['cycle' => 'ele1998'])
        ->assertSessionHasErrors('cycle');
});

test('a completed election is synced again once reopened', function () {
    $version = ResultSynchronizer::version(619);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.elections.reopen', 619))
        ->assertSessionHas('success');

    $election = Election::query()->where('tse_code', 619)->sole();
    expect($election->completed_at)->toBeNull()
        ->and($election->status())->not->toBe('concluida')
        ->and(ResultSynchronizer::version(619))->toBe($version + 1);
});

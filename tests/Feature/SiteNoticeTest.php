<?php

use App\Enums\NoticeLevel;
use App\Models\SiteNotice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-30 12:00:00');
});

test('guests cannot manage the notices', function () {
    $notice = SiteNotice::factory()->create();

    $this->get(route('admin.site-notices.index'))->assertRedirect(route('login'));
    $this->post(route('admin.site-notices.store'), ['message' => 'Oi', 'level' => 'informacao'])->assertRedirect(route('login'));
    $this->put(route('admin.site-notices.update', $notice), ['message' => 'Oi', 'level' => 'informacao'])->assertRedirect(route('login'));
    $this->delete(route('admin.site-notices.destroy', $notice))->assertRedirect(route('login'));

    expect(SiteNotice::query()->sole()->message)->toBe($notice->message);
});

test('an admin publishes, edits and removes a notice', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('admin.site-notices.store'), [
        'message' => 'A apuração começa às 17h.',
        'level' => 'informacao',
        'starts_at' => '2026-10-30T13:00',
        'ends_at' => '2026-10-30T17:00',
    ])->assertRedirect()->assertSessionHas('success');

    $notice = SiteNotice::query()->sole();
    expect($notice->level)->toBe(NoticeLevel::Info)
        ->and($notice->starts_at?->format('Y-m-d H:i'))->toBe('2026-10-30 13:00')
        ->and($notice->ends_at?->format('Y-m-d H:i'))->toBe('2026-10-30 17:00');

    $this->put(route('admin.site-notices.update', $notice), [
        'message' => 'Instabilidade no site do TSE.',
        'level' => 'atencao',
        'starts_at' => null,
        'ends_at' => null,
    ])->assertSessionHas('success');

    expect($notice->refresh()->only(['message', 'level', 'starts_at', 'ends_at']))->toBe([
        'message' => 'Instabilidade no site do TSE.',
        'level' => NoticeLevel::Warning,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $this->delete(route('admin.site-notices.destroy', $notice))->assertSessionHas('success');

    expect(SiteNotice::query()->count())->toBe(0);
});

test('a notice needs a message, a level and an end after its start and after now', function (array $data, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.site-notices.store'), [...['message' => 'Aviso', 'level' => 'informacao'], ...$data])
        ->assertSessionHasErrors($field);

    expect(SiteNotice::query()->count())->toBe(0);
})->with([
    'no message' => [['message' => ''], 'message'],
    'too long' => [['message' => str_repeat('a', 301)], 'message'],
    'unknown level' => [['level' => 'urgente'], 'level'],
    'end before start' => [['starts_at' => '2026-10-30T15:00', 'ends_at' => '2026-10-30T14:00'], 'ends_at'],
    'end in the past' => [['ends_at' => '2026-10-30T11:00'], 'ends_at'],
]);

test('every page shows the notices of the moment, and polling leaves them out', function () {
    $shown = SiteNotice::factory()->warning()->create(['message' => 'Instabilidade no TSE.']);
    SiteNotice::factory()->create(['message' => 'Começou.', 'starts_at' => '2026-10-30 11:00', 'ends_at' => '2026-10-30 13:00']);
    SiteNotice::factory()->create(['message' => 'Ainda não.', 'starts_at' => '2026-10-30 13:00']);
    SiteNotice::factory()->create(['message' => 'Já passou.', 'ends_at' => '2026-10-30 12:00']);

    $this->get(route('elections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notices', fn ($notices): bool => collect($notices)->pluck('message')->all() === ['Começou.', 'Instabilidade no TSE.'])
            ->where('notices.1', [
                'id' => $shown->id,
                'message' => 'Instabilidade no TSE.',
                'level' => 'atencao',
                'version' => $shown->updated_at?->getTimestamp(),
            ])
            ->reloadOnly('cycles', fn (Assert $reload) => $reload->missing('notices')));
});

test('the admin lists the notices with when they are shown', function () {
    SiteNotice::factory()->create(['starts_at' => '2026-10-30 13:00']);
    SiteNotice::factory()->create(['ends_at' => '2026-10-30 12:00']);
    SiteNotice::factory()->create(['starts_at' => '2026-10-30 11:00', 'ends_at' => '2026-10-30 18:30']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.site-notices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/notices/index')
            ->where('notices', fn ($notices): bool => collect($notices)->pluck('status')->all() === ['ativo', 'encerrado', 'agendado'])
            ->where('notices.0.starts_at', '2026-10-30T11:00')
            ->where('notices.0.ends_at', '2026-10-30T18:30')
            ->has('levels', 2));
});

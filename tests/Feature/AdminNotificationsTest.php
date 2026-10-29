<?php

use App\Enums\Office;
use App\Jobs\SendAnnouncement;
use App\Models\PushDelivery;
use App\Models\PushFollow;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    elections2026('2026-10-26 12:00:00');
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    $this->admin = User::factory()->create();
});

function subscribedBrowser(string $endpoint): PushSubscription
{
    return PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);
}

function followResult(PushSubscription $subscription, Office $office, string $scopeCode): PushFollow
{
    return $subscription->follows()->create(['office' => $office, 'scope_code' => $scopeCode, 'notify_updates' => true, 'notify_decided' => true, 'notify_final' => true]);
}

test('guests cannot send notifications', function () {
    $this->post(route('admin.notifications.announce'), ['title' => 'Aviso', 'body' => 'Texto'])->assertRedirect(route('login'));
    $this->post(route('admin.notifications.test'), ['endpoint' => 'https://push.example/a'])->assertRedirect(route('login'));
});

test('the dashboard lists the most followed results and the pushes sent by period', function () {
    $first = subscribedBrowser('https://push.example/a');
    $second = subscribedBrowser('https://push.example/b');
    followResult($first, Office::President, 'br');
    followResult($second, Office::President, 'br');
    followResult($first, Office::Governor, 'sp');
    $this->travel(-2)->days();
    PushDelivery::create(['delivered' => 10, 'failed' => 0, 'expired' => 0]);
    $this->travel(2)->days();
    PushDelivery::create(['delivered' => 5, 'failed' => 1, 'expired' => 2]);

    $this->actingAs($this->admin)
        ->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('followed.0', ['office' => 'presidente', 'scope_code' => 'br', 'label' => 'Presidente · Brasil', 'followers' => 2])
            ->where('followed.1.label', 'Governador · São Paulo')
            ->where('deliveries.0', ['label' => 'Última hora', 'delivered' => 5, 'failed' => 1, 'expired' => 2])
            ->where('deliveries.2.delivered', 15));
});

test('a push records what was delivered, refused and expired', function () {
    Http::fake([
        'https://push.example/ok' => Http::response('', 201),
        'https://push.example/gone' => Http::response('', 410),
        'https://push.example/down' => Http::response('', 500),
    ]);
    $browsers = collect(['ok', 'gone', 'down'])->map(fn (string $name) => subscribedBrowser("https://push.example/{$name}"));

    expect(app(WebPush::class)->send($browsers))->toBe(1)
        ->and(PushDelivery::query()->sole()->only(['delivered', 'failed', 'expired']))->toBe(['delivered' => 1, 'failed' => 1, 'expired' => 1])
        ->and(PushSubscription::query()->count())->toBe(2);
});

test('admins send a notice to the followers of a result', function () {
    Http::fake(['*' => Http::response('', 201)]);
    $follower = subscribedBrowser('https://push.example/a');
    $other = subscribedBrowser('https://push.example/b');
    followResult($follower, Office::Governor, 'sp');
    followResult($other, Office::President, 'br');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.announce'), [
            'title' => 'Atraso no TSE',
            'body' => 'O TSE está atrasando a divulgação.',
            'office' => 'governador',
            'scope_code' => 'sp',
        ])
        ->assertSessionHas('success', 'Aviso na fila para 1 navegador.');

    expect(PushMessage::query()->sole())
        ->push_subscription_id->toBe($follower->id)
        ->title->toBe('Atraso no TSE')
        ->url->toBe('/eleicoes/6259?cargo=governador&uf=sp');
    Http::assertSentCount(1);
});

test('admins send a notice to every subscribed browser', function () {
    Queue::fake();
    subscribedBrowser('https://push.example/a');
    subscribedBrowser('https://push.example/b');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.announce'), ['title' => 'Começa às 17h', 'body' => 'A apuração começa às 17h.'])
        ->assertSessionHas('success', 'Aviso na fila para 2 navegadores.');

    Queue::assertPushed(SendAnnouncement::class, fn (SendAnnouncement $job): bool => $job->office === null);
});

test('a notice nobody would receive is not sent', function () {
    Queue::fake();
    subscribedBrowser('https://push.example/a');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.announce'), ['title' => 'Aviso', 'body' => 'Texto', 'office' => 'governador', 'scope_code' => 'sp'])
        ->assertSessionHas('error');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.announce'), ['title' => '', 'body' => 'Texto', 'office' => 'governador'])
        ->assertSessionHasErrors(['title', 'scope_code']);

    Queue::assertNothingPushed();
});

test('admins send a test notification to their own browser', function () {
    Http::fake(['*' => Http::response('', 201)]);
    $browser = subscribedBrowser('https://push.example/a');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.test'), ['endpoint' => 'https://push.example/a'])
        ->assertSessionHas('success');

    expect(PushMessage::query()->sole())
        ->push_subscription_id->toBe($browser->id)
        ->tag->toBe('teste');

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.test'), ['endpoint' => 'https://push.example/desconhecido'])
        ->assertSessionHas('error');
});

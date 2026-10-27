<?php

use App\Enums\Office;
use App\Models\Municipality;
use App\Models\PushFollow;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Services\WebPush;
use Illuminate\Support\Facades\Http;

const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

beforeEach(function () {
    elections2026('2026-10-26 12:00:00');
    $this->subscription = PushSubscription::create(['endpoint' => ENDPOINT, 'endpoint_hash' => PushSubscription::hashEndpoint(ENDPOINT)]);
});

test('a browser follows a result and lists what it follows', function () {
    $this->putJson(route('notifications.follow'), [
        'endpoint' => ENDPOINT,
        'office' => 'governador',
        'scope_code' => 'sp',
        'notify_updates' => false,
    ])->assertOk()->assertJsonPath('label', 'Governador · São Paulo');

    $this->postJson(route('notifications.following'), ['endpoint' => ENDPOINT])
        ->assertOk()
        ->assertExactJson([[
            'office' => 'governador',
            'scope_code' => 'sp',
            'label' => 'Governador · São Paulo',
            'url' => '/eleicoes/6259?cargo=governador&uf=sp',
            'notify_updates' => false,
            'notify_decided' => true,
            'notify_final' => true,
        ]]);
});

test('a browser that starts following a result gets a confirmation', function () {
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    Http::fake([ENDPOINT => Http::response('', 201)]);
    $follow = fn (array $categories) => $this->putJson(route('notifications.follow'), [
        'endpoint' => ENDPOINT,
        'office' => 'governador',
        'scope_code' => 'sp',
        ...$categories,
    ])->assertOk();

    $follow(['notify_updates' => false]);

    expect(PushMessage::query()->sole()->only(['title', 'body', 'url']))->toBe([
        'title' => '🔔 Você está seguindo Governador · São Paulo',
        'body' => 'Vai receber quando estiver matematicamente definido e o resultado final.',
        'url' => '/eleicoes/6259?cargo=governador&uf=sp',
    ]);
    Http::assertSentCount(1);

    $follow(['notify_updates' => true]);

    expect(PushMessage::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('following a result again changes its notifications, and none stops following it', function () {
    $follow = fn (array $categories) => $this->putJson(route('notifications.follow'), [
        'endpoint' => ENDPOINT,
        'office' => 'presidente',
        'scope_code' => 'br',
        ...$categories,
    ]);

    $follow(['notify_final' => false])->assertOk();
    expect(PushFollow::query()->sole()->notify_final)->toBeFalse();

    $follow(['notify_updates' => false, 'notify_decided' => false, 'notify_final' => false])->assertNoContent();
    expect(PushFollow::query()->count())->toBe(0);
});

test('a browser stops following a result', function () {
    $this->subscription->follows()->create(['office' => Office::Mayor, 'scope_code' => '71072']);
    Municipality::create(['tse_code' => '71072', 'ibge_code' => '3550308', 'state' => 'sp', 'name' => 'SÃO PAULO']);

    $this->deleteJson(route('notifications.unfollow'), ['endpoint' => ENDPOINT, 'office' => 'prefeito', 'scope_code' => '71072'])
        ->assertNoContent();

    expect(PushFollow::query()->count())->toBe(0);
});

test('only places where the office is elected can be followed', function () {
    $this->putJson(route('notifications.follow'), ['endpoint' => ENDPOINT, 'office' => 'governador', 'scope_code' => 'br'])
        ->assertJsonValidationErrors('scope_code');

    $this->putJson(route('notifications.follow'), ['endpoint' => ENDPOINT, 'office' => 'prefeito', 'scope_code' => '99999'])
        ->assertJsonValidationErrors('scope_code');
});

test('a browser without push notifications cannot follow', function () {
    $this->putJson(route('notifications.follow'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/unknown',
        'office' => 'presidente',
        'scope_code' => 'br',
    ])->assertNotFound();
});

test('the service worker gets its waiting messages once, without old ones', function () {
    $message = fn (string $title) => $this->subscription->messages()->create(['title' => $title, 'body' => 'b', 'url' => '/', 'tag' => 't']);
    $message('antiga');
    $this->travel(61)->minutes();
    $message('nova');
    $pending = fn () => $this->getJson(route('notifications.pending', ['inscricao' => PushSubscription::hashEndpoint(ENDPOINT)]));

    $pending()->assertOk()->assertExactJson([['title' => 'nova', 'body' => 'b', 'url' => '/', 'tag' => 't']]);
    $pending()->assertOk()->assertExactJson([]);
});

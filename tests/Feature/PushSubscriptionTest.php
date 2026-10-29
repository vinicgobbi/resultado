<?php

use App\Models\PushSubscription;
use App\Services\WebPush;
use Illuminate\Support\Facades\Http;

test('a browser can subscribe to push notifications', function () {
    $this->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        'keys' => ['p256dh' => 'key', 'auth' => 'secret'],
    ])->assertNoContent();

    $this->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
    ])->assertNoContent();

    expect(PushSubscription::query()->sole())
        ->endpoint->toBe('https://fcm.googleapis.com/fcm/send/abc')
        ->public_key->toBeNull();
});

test('push endpoints must use https', function () {
    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://example.com/push'])
        ->assertJsonValidationErrors('endpoint');
});

test('push endpoints must belong to the push service of a browser', function () {
    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://example.com/push'])
        ->assertJsonValidationErrors('endpoint');
    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com.example.com/push'])
        ->assertJsonValidationErrors('endpoint');

    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abc'])
        ->assertNoContent();
    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://web.push.apple.com/abc'])
        ->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(2);
});

test('pushes are never sent to an endpoint outside the push services', function () {
    config(['services.webpush' => [...WebPush::generateKeys(), 'subject' => 'mailto:test@example.com']]);
    Http::fake();
    $endpoint = 'https://internal.example/admin';
    $subscription = PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);

    expect(app(WebPush::class)->send(collect([$subscription])))->toBe(0);
    Http::assertNothingSent();
});

test('a browser can unsubscribe from push notifications', function () {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';
    PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);

    $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(0);
});

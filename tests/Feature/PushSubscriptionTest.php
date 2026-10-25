<?php

use App\Models\PushSubscription;

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

test('a browser can unsubscribe from push notifications', function () {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';
    PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);

    $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(0);
});

<?php

use App\Models\MaintenanceRun;
use App\Models\PushDelivery;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('old push messages, deliveries and finished task runs are pruned', function () {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';
    $subscription = PushSubscription::create(['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)]);
    $message = fn () => $subscription->messages()->create(['title' => 'Título', 'body' => 'Texto', 'url' => '/', 'tag' => 'aviso']);

    $this->travel(-91)->days();
    PushDelivery::create(['delivered' => 1]);
    $oldRun = MaintenanceRun::factory()->create(['status' => MaintenanceRun::DONE]);
    $stuckRun = MaintenanceRun::factory()->running()->create();
    $this->travelBack();

    $this->travel(-2)->hours();
    $oldMessage = $message();
    $this->travelBack();
    $newMessage = $message();
    PushDelivery::create(['delivered' => 2]);
    MaintenanceRun::factory()->create(['status' => MaintenanceRun::DONE]);

    $this->artisan('model:prune')->assertSuccessful();

    expect(PushMessage::query()->pluck('id')->all())->toBe([$newMessage->id])
        ->and(PushMessage::query()->find($oldMessage->id))->toBeNull()
        ->and(PushDelivery::query()->pluck('delivered')->all())->toBe([2])
        ->and(MaintenanceRun::query()->find($oldRun->id))->toBeNull()
        ->and(MaintenanceRun::query()->find($stuckRun->id))->not->toBeNull()
        ->and(MaintenanceRun::query()->count())->toBe(2);
});

test('expired keys of the database cache are deleted', function () {
    config(['cache.default' => 'database']);
    Cache::put('expira', true, now()->addMinute());
    Cache::put('fica', true, now()->addHour());
    Cache::forever('sempre', true);
    $this->travel(2)->minutes();

    $this->artisan('cache:limpar-expirados')
        ->expectsOutputToContain('1 chaves expiradas apagadas')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(2)
        ->and(Cache::get('fica'))->toBeTrue();
});

test('the expired keys cleanup does nothing when the cache is not in the database', function () {
    config(['cache.default' => 'array']);

    $this->artisan('cache:limpar-expirados')
        ->expectsOutputToContain('nada a limpar')
        ->assertSuccessful();
});

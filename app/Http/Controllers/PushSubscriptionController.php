<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    /**
     * Register (or refresh) a browser push subscription.
     */
    public function store(StorePushSubscriptionRequest $request): Response
    {
        $endpoint = $request->string('endpoint')->toString();

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'endpoint' => $endpoint,
                'public_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
            ],
        );

        return response()->noContent();
    }

    /**
     * Remove a browser push subscription.
     */
    public function destroy(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($request->string('endpoint')->toString()))
            ->delete();

        return response()->noContent();
    }
}

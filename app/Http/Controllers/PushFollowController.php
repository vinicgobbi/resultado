<?php

namespace App\Http\Controllers;

use App\Enums\Office;
use App\Enums\State;
use App\Http\Requests\FollowResultRequest;
use App\Jobs\SendFollowConfirmation;
use App\Models\Municipality;
use App\Models\PushFollow;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Services\ResultPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PushFollowController extends Controller
{
    /**
     * Page to choose what this browser follows where the visitor votes, and
     * to manage it (follows are loaded by the page itself: the browser is
     * only known by its push subscription).
     */
    public function index(Request $request): InertiaResponse
    {
        $state = State::tryFrom($request->string('uf')->toString());
        $state = $state === State::Exterior ? null : $state;

        return Inertia::render('notifications/index', [
            'states' => collect(State::cases())
                ->reject(fn (State $state): bool => $state === State::Exterior)
                ->map(fn (State $state): array => ['code' => $state->value, 'name' => $state->label()])
                ->values()
                ->all(),
            'state' => $state?->value,
            'offices' => fn (): array => $this->offices($state),
            'municipalities' => fn (): array => $state === null ? [] : Municipality::query()
                ->where('state', $state)
                ->orderBy('name')
                ->get(['tse_code', 'name'])
                ->map(fn (Municipality $municipality): array => ['code' => $municipality->tse_code, 'name' => $municipality->name])
                ->all(),
        ]);
    }

    /**
     * Results the browser follows.
     */
    public function following(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $subscription = $this->subscription($request->string('endpoint')->toString());

        return response()->json($subscription === null ? [] : $subscription->follows()
            ->orderBy('id')
            ->get()
            ->map(fn (PushFollow $follow): array => $this->present($follow))
            ->all());
    }

    /**
     * Follow a result, or change which notifications of it the browser wants;
     * without any, it stops following it.
     */
    public function update(FollowResultRequest $request): JsonResponse|Response
    {
        $subscription = $this->subscription($request->string('endpoint')->toString());
        abort_if($subscription === null, 404, 'Ative as notificações neste navegador primeiro.');

        $place = $request->place();
        $categories = [
            'notify_updates' => $request->boolean('notify_updates', true),
            'notify_decided' => $request->boolean('notify_decided', true),
            'notify_final' => $request->boolean('notify_final', true),
        ];
        $key = ['office' => $place->office, 'scope_code' => $place->scopeCode];

        if (! in_array(true, $categories, true)) {
            $subscription->follows()->where($key)->delete();

            return response()->noContent();
        }

        $follow = $subscription->follows()->updateOrCreate($key, $categories);

        if ($follow->wasRecentlyCreated) {
            SendFollowConfirmation::dispatch($follow);
        }

        return response()->json($this->present($follow));
    }

    /**
     * Stop following a result.
     */
    public function destroy(FollowResultRequest $request): Response
    {
        $place = $request->place();

        $this->subscription($request->string('endpoint')->toString())
            ?->follows()
            ->where(['office' => $place->office, 'scope_code' => $place->scopeCode])
            ->delete();

        return response()->noContent();
    }

    /**
     * Messages waiting for a browser, fetched by its service worker when a
     * push arrives; they are delivered once.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate(['inscricao' => ['required', 'string', 'size:64']]);

        $subscription = PushSubscription::query()->where('endpoint_hash', $request->string('inscricao')->toString())->first();

        if ($subscription === null) {
            return response()->json([]);
        }

        $messages = $subscription->messages()->orderBy('id')->get();
        PushMessage::query()->whereKey($messages->modelKeys())->delete();

        return response()->json($messages
            ->filter(fn (PushMessage $message): bool => $message->created_at?->gte(now()->subMinutes(PushMessage::EXPIRES_MINUTES)) ?? false)
            ->map(fn (PushMessage $message): array => $message->only(['title', 'body', 'url', 'tag']))
            ->values()
            ->all())->header('Cache-Control', 'no-store');
    }

    /**
     * Offices that can be followed by whoever votes in the state, the
     * country's first; without a state, only those of the country.
     *
     * @return list<array{office: string, scope: string, label: string, description: string, municipalities: list<string>|null}>
     */
    private function offices(?State $state): array
    {
        return array_values(array_map(fn (Office $office): array => [
            'office' => $office->value,
            'scope' => $office->electedScope(),
            'label' => $office->label($state),
            'description' => $office->description($state),
            // Only disputed in a few municipalities: those with results.
            'municipalities' => $office === Office::DistrictCouncillor
                ? array_values(Result::query()
                    ->where('office', $office)
                    ->where('scope', Result::SCOPE_MUNICIPALITY)
                    ->distinct()
                    ->get(['scope_code'])
                    ->map(fn (Result $result): string => $result->scope_code)
                    ->all())
                : null,
        ], array_filter(Office::cases(), fn (Office $office): bool => $office->isNationwide()
            || ($state !== null && in_array($state, $office->states(), true)))));
    }

    private function subscription(string $endpoint): ?PushSubscription
    {
        return PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->first();
    }

    /**
     * @return array{office: string, scope_code: string, label: string, url: string|null, notify_updates: bool, notify_decided: bool, notify_final: bool}
     */
    private function present(PushFollow $follow): array
    {
        $place = new ResultPlace($follow->office, $follow->scope_code);
        $election = $place->latestElection();

        return [
            'office' => $follow->office->value,
            'scope_code' => $follow->scope_code,
            'label' => $place->label(),
            'url' => $election === null ? null : $place->url($election),
            'notify_updates' => $follow->notify_updates,
            'notify_decided' => $follow->notify_decided,
            'notify_final' => $follow->notify_final,
        ];
    }
}

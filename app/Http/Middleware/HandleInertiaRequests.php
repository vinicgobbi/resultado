<?php

namespace App\Http\Middleware;

use App\Models\SiteNotice;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email']),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'push' => [
                'publicKey' => config('services.webpush.public_key'),
            ],
            // Only on full visits: polling reloads leave it out.
            'notices' => fn (): array => SiteNotice::query()
                ->active()
                ->latest('id')
                ->get()
                ->map(fn (SiteNotice $notice): array => [
                    'id' => $notice->id,
                    'message' => $notice->message,
                    'level' => $notice->level->value,
                    // Changes when edited, so a closed notice shows again.
                    'version' => $notice->updated_at?->getTimestamp() ?? 0,
                ])
                ->all(),
        ];
    }
}

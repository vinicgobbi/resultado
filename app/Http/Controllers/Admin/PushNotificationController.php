<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Jobs\SendAnnouncement;
use App\Models\PushFollow;
use App\Models\PushSubscription;
use App\Services\WebPush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    /**
     * Queue a notice for the followers of a result, or for everyone.
     */
    public function announce(StoreAnnouncementRequest $request): RedirectResponse
    {
        $place = $request->place();
        $recipients = $place === null
            ? PushSubscription::query()->count()
            : PushFollow::query()
                ->where('office', $place->office)
                ->where('scope_code', $place->scopeCode)
                ->distinct()
                ->count('push_subscription_id');

        if ($recipients === 0) {
            return back()->with('error', 'Nenhum navegador receberia este aviso.');
        }

        SendAnnouncement::dispatch($request->string('title')->toString(), $request->string('body')->toString(), $place?->office, $place?->scopeCode);

        return back()->with('success', $recipients === 1
            ? 'Aviso na fila para 1 navegador.'
            : "Aviso na fila para {$recipients} navegadores.");
    }

    /**
     * Send a notification to the browser of the admin, to check that push
     * notifications reach it.
     */
    public function test(Request $request, WebPush $webPush): RedirectResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $subscription = PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($request->string('endpoint')->toString()))
            ->first();

        if ($subscription === null) {
            return back()->with('error', 'Este navegador não está inscrito: ative as notificações no site primeiro.');
        }

        $subscription->messages()->create([
            'title' => '🔔 Notificação de teste',
            'body' => 'Se você está vendo isto, as notificações chegam a este aparelho.',
            'url' => route('admin.index', absolute: false),
            'tag' => 'teste',
        ]);

        if ($webPush->send(collect([$subscription])) === 0) {
            return back()->with('error', 'O serviço de push não aceitou a notificação. Ative as notificações no site de novo.');
        }

        return back()->with('success', 'Notificação de teste enviada; deve aparecer em alguns segundos.');
    }
}

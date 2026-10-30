<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NoticeLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSiteNoticeRequest;
use App\Models\SiteNotice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteNoticeController extends Controller
{
    /**
     * Notices of the site: those shown now, scheduled and over.
     */
    public function index(): Response
    {
        return Inertia::render('admin/notices/index', [
            'notices' => SiteNotice::query()
                ->latest('id')
                ->get()
                ->map(fn (SiteNotice $notice): array => [
                    'id' => $notice->id,
                    'message' => $notice->message,
                    'level' => $notice->level->value,
                    // As typed in a datetime-local field, in the app's time zone.
                    'starts_at' => $notice->starts_at?->format('Y-m-d\TH:i'),
                    'ends_at' => $notice->ends_at?->format('Y-m-d\TH:i'),
                    'status' => $notice->status(),
                ])
                ->all(),
            'levels' => array_map(
                fn (NoticeLevel $level): array => ['value' => $level->value, 'label' => $level->label()],
                NoticeLevel::cases(),
            ),
        ]);
    }

    public function store(SaveSiteNoticeRequest $request): RedirectResponse
    {
        SiteNotice::create($request->validated());

        return back()->with('success', 'Aviso criado.');
    }

    public function update(SaveSiteNoticeRequest $request, SiteNotice $siteNotice): RedirectResponse
    {
        $siteNotice->update($request->validated());

        return back()->with('success', 'Aviso salvo.');
    }

    public function destroy(SiteNotice $siteNotice): RedirectResponse
    {
        $siteNotice->delete();

        return back()->with('success', 'Aviso removido.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MaintenanceTask;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\MaintenanceRun;
use App\Models\PushDelivery;
use App\Models\PushFollow;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Services\MapGeometryBuilder;
use App\Services\ResultPlace;
use App\Services\Tse\TseClient;
use App\Services\WebPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Most recent elections shown.
     */
    private const int ELECTIONS = 12;

    /**
     * Most recent task runs shown.
     */
    private const int RUNS = 10;

    /**
     * Only the end of a run's output is sent to the page.
     */
    private const int OUTPUT_TAIL = 4000;

    /**
     * Most followed results shown.
     */
    private const int FOLLOWED = 20;

    /**
     * How the synchronization is going, what may need attention, and the
     * tasks that can be started from here.
     */
    public function index(): Response
    {
        $elections = Election::query()->orderByDesc('date')->orderBy('round')->limit(self::ELECTIONS)->get();
        $counts = Result::query()
            ->whereIn('election_code', $elections->pluck('tse_code'))
            ->groupBy('election_code')
            ->selectRaw('election_code, count(*) as total, sum(case when is_final then 1 else 0 end) as final')
            ->get()
            ->keyBy('election_code');
        $failedJob = DB::table('failed_jobs')->latest('failed_at')->first(['failed_at', 'exception']);

        return Inertia::render('admin/dashboard', [
            'elections' => $elections->map(fn (Election $election): array => [
                'code' => $election->tse_code,
                'name' => $election->name,
                'date' => $election->date->toDateString(),
                'status' => $election->status(),
                'last_synced_at' => $election->last_synced_at?->toIso8601String(),
                'completed_at' => $election->completed_at?->toIso8601String(),
                'results' => (int) ($counts[$election->tse_code]['total'] ?? 0),
                'final_results' => (int) ($counts[$election->tse_code]['final'] ?? 0),
            ]),
            'health' => [
                'tse_paused_until' => TseClient::fromConfig()->pausedUntil()?->toIso8601String(),
                'queued_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'last_failure' => $failedJob === null ? null : [
                    'at' => (string) $failedJob->failed_at,
                    'message' => Str::limit(strtok((string) $failedJob->exception, "\n") ?: '', 300),
                ],
                'map_ready' => Storage::exists(MapGeometryBuilder::PATH),
                'push_ready' => WebPush::isConfigured(),
                'subscriptions' => PushSubscription::query()->count(),
                'follows' => PushFollow::query()->count(),
            ],
            'tasks' => array_map(fn (MaintenanceTask $task): array => [
                'value' => $task->value,
                'label' => $task->label(),
                'button_label' => $task->buttonLabel(),
                'description' => $task->description(),
                'needs_election' => $task->needsElection(),
            ], MaintenanceTask::cases()),
            'runs' => MaintenanceRun::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(self::RUNS)
                ->get()
                ->map(fn (MaintenanceRun $run): array => [
                    'id' => $run->id,
                    'task' => $run->task->value,
                    'task_label' => $run->task->label(),
                    'election_code' => $run->election_code,
                    'status' => $run->status,
                    'user' => $run->user?->name,
                    'created_at' => $run->created_at?->toIso8601String(),
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'output' => Str::substr($run->output ?? '', -self::OUTPUT_TAIL),
                ]),
            'running' => MaintenanceRun::query()->unfinished()->exists(),
            'deliveries' => [
                ['label' => 'Última hora', ...PushDelivery::totalsSince(now()->subHour())],
                ['label' => 'Últimas 24 horas', ...PushDelivery::totalsSince(now()->subDay())],
                ['label' => 'Últimos 7 dias', ...PushDelivery::totalsSince(now()->subWeek())],
            ],
            'followed' => $this->mostFollowed(),
        ]);
    }

    /**
     * Results followed by the most browsers.
     *
     * @return list<array{office: string, scope_code: string, label: string, followers: int}>
     */
    private function mostFollowed(): array
    {
        return PushFollow::query()
            ->groupBy('office', 'scope_code')
            ->selectRaw('office, scope_code, count(*) as followers')
            ->orderByDesc('followers')
            ->orderBy('office')
            ->orderBy('scope_code')
            ->limit(self::FOLLOWED)
            ->get()
            ->map(fn (PushFollow $follow): array => [
                'office' => $follow->office->value,
                'scope_code' => $follow->scope_code,
                'label' => (new ResultPlace($follow->office, $follow->scope_code))->label(),
                'followers' => (int) $follow->getAttribute('followers'),
            ])
            ->all();
    }
}

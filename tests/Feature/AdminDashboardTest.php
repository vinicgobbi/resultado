<?php

use App\Enums\MaintenanceTask;
use App\Jobs\RunMaintenanceTask;
use App\Models\Election;
use App\Models\MaintenanceRun;
use App\Models\Result;
use App\Models\User;
use App\Services\Tse\ResultSynchronizer;
use App\Services\Tse\TseClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => elections2026('2026-10-26 12:00:00'));

test('guests are sent to the login page', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
    $this->post(route('admin.maintenance-runs.store'), ['task' => 'sincronizar'])->assertRedirect(route('login'));
});

test('the dashboard shows how each election is going and what needs attention', function () {
    Result::create([
        'election_code' => 6258, 'scope' => Result::SCOPE_COUNTRY, 'scope_code' => 'br', 'votes' => [],
        'sections_total' => 10, 'sections_counted' => 10, 'counted_percent' => 100, 'is_final' => true,
    ]);
    Result::create([
        'election_code' => 6258, 'scope' => Result::SCOPE_STATE, 'scope_code' => 'sp', 'votes' => [],
        'sections_total' => 10, 'sections_counted' => 5, 'counted_percent' => 50,
    ]);
    Cache::put(TseClient::PAUSE_KEY, now()->addMinutes(5)->getTimestamp());

    $this->actingAs(User::factory()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('elections.0.code', 6260)
            ->where('elections.1.code', 6258)
            ->where('elections.1.status', 'apuracao')
            ->where('elections.1.results', 2)
            ->where('elections.1.final_results', 1)
            ->where('health.tse_paused_until', TseClient::fromConfig()->pausedUntil()?->toIso8601String())
            ->where('health.failed_jobs', 0)
            ->has('tasks', count(MaintenanceTask::cases()))
            ->where('running', false));
});

test('admins queue a task on its own queue', function () {
    Queue::fake();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.maintenance-runs.store'), ['task' => 'ressincronizar', 'election' => 6258])
        ->assertSessionHasNoErrors();

    $run = MaintenanceRun::query()->sole();
    expect($run->task)->toBe(MaintenanceTask::FullSync)
        ->and($run->election_code)->toBe(6258)
        ->and($run->user_id)->toBe($admin->id)
        ->and($run->status)->toBe(MaintenanceRun::QUEUED);
    Queue::assertPushedOn('admin', RunMaintenanceTask::class);
});

test('a long task is never taken again by another worker while it runs', function () {
    $job = new RunMaintenanceTask(MaintenanceRun::factory()->create());

    // A database queue hands a job out again after retry_after: it must
    // outlast the job, or a long task fails as "attempted too many times".
    expect(config("queue.connections.{$job->connection}.retry_after"))->toBeGreaterThan($job->timeout)
        ->and($job->queue)->toBe('admin');
});

test('a task cannot be queued twice, nor a full sync without an election', function () {
    Queue::fake();
    MaintenanceRun::factory()->running()->create(['task' => MaintenanceTask::Photos]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.maintenance-runs.store'), ['task' => 'fotos'])
        ->assertSessionHasErrors('task');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.maintenance-runs.store'), ['task' => 'ressincronizar'])
        ->assertSessionHasErrors('election');

    Queue::assertNothingPushed();
});

test('a task runs its command and keeps what it printed', function () {
    $run = MaintenanceRun::factory()->create(['task' => MaintenanceTask::ClearCache]);

    RunMaintenanceTask::dispatchSync($run);

    expect($run->refresh())
        ->status->toBe(MaintenanceRun::DONE)
        ->started_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->output->toContain('Cache de 4 eleições limpo')
        ->and(ResultSynchronizer::version(6258))->toBe(1);
});

test('a scheduled and a manual sync never run at the same time', function () {
    Cache::lock('tse:sincronizar', 60)->get();

    $this->artisan('tse:sincronizar')
        ->expectsOutputToContain('Outra sincronização já está em andamento')
        ->assertSuccessful();

    expect(Election::query()->whereNotNull('last_synced_at')->exists())->toBeFalse();
});

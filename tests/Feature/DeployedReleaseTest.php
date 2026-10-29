<?php

use App\Console\Commands\DownloadCandidatePhotos;
use App\Models\Candidate;
use App\Services\DeployedRelease;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('a process knows when a newer release went live', function () {
    $app = storage_path('framework/testing/deploy-'.uniqid());
    File::ensureDirectoryExists("{$app}/releases/antiga");
    File::ensureDirectoryExists("{$app}/releases/nova");
    symlink("{$app}/releases/antiga", "{$app}/current");

    expect((new DeployedRelease("{$app}/releases/antiga"))->isOutdated())->toBeFalse();

    unlink("{$app}/current");
    symlink("{$app}/releases/nova", "{$app}/current");

    expect((new DeployedRelease("{$app}/releases/antiga"))->isOutdated())->toBeTrue()
        ->and((new DeployedRelease("{$app}/releases/nova"))->isOutdated())->toBeFalse()
        // Outside a deploy by releases, as in development.
        ->and((new DeployedRelease($app))->isOutdated())->toBeFalse()
        ->and((new DeployedRelease)->isOutdated())->toBeFalse();

    File::deleteDirectory($app);
});

test('the photos command stops when a newer release went live, to continue in it', function () {
    elections2026('2026-10-26 12:00:00');
    Http::preventStrayRequests();
    Storage::fake(Candidate::PHOTO_DISK);
    Candidate::factory()->create();
    $this->mock(DeployedRelease::class)->shouldReceive('isOutdated')->andReturnTrue();

    $this->artisan('tse:fotos')
        ->expectsOutputToContain('Uma nova versão do site foi publicada')
        ->assertExitCode(DownloadCandidatePhotos::INCOMPLETE);

    Http::assertNothingSent();
});

test('the import stops when a newer release went live, to continue on the next run', function () {
    elections2026('2026-11-20 12:00:00');
    Http::preventStrayRequests();
    $this->mock(DeployedRelease::class)->shouldReceive('isOutdated')->andReturnTrue();

    $this->artisan('tse:importar')
        ->expectsOutputToContain('Uma nova versão do site foi publicada')
        ->assertSuccessful();

    Http::assertNothingSent();
});

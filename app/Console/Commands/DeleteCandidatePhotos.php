<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

#[Signature('fotos:apagar
    {--force : Apaga sem pedir confirmação}')]
#[Description('Apaga do servidor todas as fotos dos candidatos baixadas do TSE')]
class DeleteCandidatePhotos extends Command
{
    /**
     * Execute the console command. The pages then link the photos to the
     * route that downloads them again when first shown, and the photos
     * command checks every candidate again.
     */
    public function handle(): int
    {
        $disk = Storage::disk(Candidate::PHOTO_DISK);
        $directory = dirname(Candidate::photoPath('0'));
        $files = $disk->allFiles($directory);

        if ($files === []) {
            $this->components->info('Não há fotos no servidor.');

            return self::SUCCESS;
        }

        $bytes = array_sum(array_map(fn (string $file): int => $disk->size($file), $files));
        $summary = sprintf('%s fotos (%s)', number_format(count($files), 0, ',', '.'), $this->readableSize($bytes));

        if (! $this->option('force') && ! $this->confirm("Apagar {$summary}? Elas são baixadas de novo quando aparecerem ou pelas tarefas de fotos.")) {
            $this->components->info('Nada foi apagado.');

            return self::SUCCESS;
        }

        // One by one: the disk does not throw, so only the result of each
        // file tells what was really deleted (a file of another user is not).
        $deleted = 0;
        $freed = 0;

        foreach ($files as $file) {
            $size = $disk->size($file);

            if ($disk->delete($file)) {
                $deleted++;
                $freed += $size;
            }
        }

        if ($deleted > 0) {
            Cache::forever(DownloadCandidatePhotos::GENERATION_KEY, (int) Cache::get(DownloadCandidatePhotos::GENERATION_KEY, 0) + 1);
            // The cached results link each photo to its file while it existed.
            $this->call('resultados:limpar-cache');
        }

        $failed = count($files) - $deleted;

        if ($failed > 0) {
            $this->components->error(sprintf(
                '%s fotos não puderam ser apagadas (%s apagadas, %s liberados). Confira se o usuário que roda este comando (%s) pode escrever em %s e nos arquivos dela.',
                number_format($failed, 0, ',', '.'),
                number_format($deleted, 0, ',', '.'),
                $this->readableSize($freed),
                $this->processUser(),
                $disk->path($directory),
            ));

            return self::FAILURE;
        }

        $disk->deleteDirectory($directory);
        $this->components->info("{$summary} apagadas.");

        return self::SUCCESS;
    }

    /**
     * The system user running the command, which may not be the one that
     * wrote the photos (the web server, when it downloads one on request).
     */
    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user)) {
                return $user['name'];
            }
        }

        return (string) get_current_user();
    }

    /**
     * "3,4 GB", "820 MB".
     */
    private function readableSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return number_format($bytes / (1024 ** $power), $power > 1 ? 1 : 0, ',', '.').' '.$units[$power];
    }
}

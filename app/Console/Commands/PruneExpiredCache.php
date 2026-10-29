<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('cache:limpar-expirados')]
#[Description('Apaga do banco as chaves de cache já expiradas, que o cache em banco só remove quando alguém as lê')]
class PruneExpiredCache extends Command
{
    /**
     * Rows deleted at a time, so a large cleanup does not lock the table.
     */
    private const int CHUNK_SIZE = 5000;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $store = config('cache.default');

        if (config("cache.stores.{$store}.driver") !== 'database') {
            $this->components->info('O cache não fica no banco; nada a limpar.');

            return self::SUCCESS;
        }

        $table = DB::connection(config("cache.stores.{$store}.connection"))->table((string) config("cache.stores.{$store}.table"));
        $deleted = 0;

        do {
            $keys = (clone $table)->where('expiration', '<=', now()->getTimestamp())->limit(self::CHUNK_SIZE)->pluck('key');
            $deleted += (clone $table)->whereIn('key', $keys)->delete();
        } while ($keys->count() === self::CHUNK_SIZE);

        $this->components->info("{$deleted} chaves expiradas apagadas.");

        return self::SUCCESS;
    }
}

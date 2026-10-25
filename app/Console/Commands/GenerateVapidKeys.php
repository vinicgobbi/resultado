<?php

namespace App\Console\Commands;

use App\Services\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('push:gerar-chaves')]
#[Description('Gera o par de chaves VAPID usado nas notificações push')]
class GenerateVapidKeys extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $keys = WebPush::generateKeys();

        $this->components->info('Adicione as linhas abaixo ao seu arquivo .env:');
        $this->line("VAPID_PUBLIC_KEY={$keys['public_key']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['private_key']}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\MapGeometryBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mapa:baixar')]
#[Description('Baixa a malha de municípios e estados do IBGE e gera a geometria do mapa')]
class DownloadMunicipalityMap extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MapGeometryBuilder $builder): int
    {
        $this->components->info('Baixando malhas do IBGE (pode levar alguns segundos)...');

        $totals = $builder->build();

        $this->components->info("Mapa gerado com {$totals['municipalities']} municípios e {$totals['states']} estados.");

        return self::SUCCESS;
    }
}

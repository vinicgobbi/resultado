<?php

namespace App\Enums;

use App\Models\Election;

/**
 * Operations an admin can start from the dashboard: the console commands
 * that otherwise need a terminal on the server.
 */
enum MaintenanceTask: string
{
    case Sync = 'sincronizar';
    case FullSync = 'ressincronizar';
    case Discover = 'descobrir';
    case Import = 'importar';
    case Photos = 'fotos';
    case Map = 'mapa';
    case ClearCache = 'limpar-cache';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Sincronizar agora',
            self::FullSync => 'Ressincronizar por completo',
            self::Discover => 'Descobrir eleições novas',
            self::Import => 'Importar eleições encerradas',
            self::Photos => 'Baixar fotos que faltam',
            self::Map => 'Gerar o mapa',
            self::ClearCache => 'Limpar o cache dos resultados',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sync => 'Baixa agora os resultados das eleições em apuração, sem esperar o agendamento.',
            self::FullSync => 'Baixa de novo todos os resultados de uma eleição, mesmo os que não mudaram.',
            self::Discover => 'Procura no índice do TSE eleições que ainda não estão cadastradas.',
            self::Import => 'Importa as eleições encerradas que ainda não foram baixadas.',
            self::Photos => 'Baixa do TSE as fotos de candidatos que ainda não têm, sem provocar bloqueio.',
            self::Map => 'Baixa a malha do IBGE e gera de novo a geometria do mapa.',
            self::ClearCache => 'Faz as páginas de resultados serem calculadas de novo na próxima visita.',
        };
    }

    /**
     * Whether the task runs on one election.
     */
    public function needsElection(): bool
    {
        return $this === self::FullSync;
    }

    /**
     * Console command and its arguments.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function command(?Election $election = null): array
    {
        return match ($this) {
            self::Sync => ['tse:sincronizar', []],
            self::FullSync => ['tse:sincronizar', ['--eleicao' => [(string) $election?->tse_code], '--completo' => true]],
            self::Discover => ['tse:descobrir', []],
            self::Import => ['tse:importar', []],
            self::Photos => ['tse:fotos', []],
            self::Map => ['mapa:baixar', []],
            self::ClearCache => ['resultados:limpar-cache', []],
        };
    }
}

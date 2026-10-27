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
    case ElectionPhotos = 'fotos-eleicao';
    case PastPhotos = 'fotos-encerradas';
    case Map = 'mapa';
    case ClearCache = 'limpar-cache';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Sincronizar agora',
            self::FullSync => 'Ressincronizar por completo',
            self::Discover => 'Descobrir eleições novas',
            self::Import => 'Importar eleições encerradas',
            self::Photos => 'Baixar fotos da eleição atual',
            self::ElectionPhotos => 'Baixar fotos da eleição',
            self::PastPhotos => 'Baixar fotos das eleições encerradas',
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
            self::Photos => 'Baixa do TSE as fotos que faltam dos candidatos da eleição atual, sem provocar bloqueio.',
            self::ElectionPhotos => 'Baixa do TSE as fotos que faltam dos candidatos de uma eleição.',
            self::PastPhotos => 'Baixa as fotos que faltam de todas as eleições encerradas, da mais recente para a mais antiga. As municipais têm centenas de milhares de candidatos: pode levar horas e ocupar alguns GB.',
            self::Map => 'Baixa a malha do IBGE e gera de novo a geometria do mapa.',
            self::ClearCache => 'Faz as páginas de resultados serem calculadas de novo na próxima visita.',
        };
    }

    /**
     * Whether the task runs on one election.
     */
    public function needsElection(): bool
    {
        return in_array($this, [self::FullSync, self::ElectionPhotos], true);
    }

    /**
     * Short label of the button in the row of an election.
     */
    public function buttonLabel(): string
    {
        return match ($this) {
            self::FullSync => 'Ressincronizar',
            self::ElectionPhotos => 'Baixar fotos',
            default => $this->label(),
        };
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
            self::ElectionPhotos => ['tse:fotos', ['--eleicao' => [(string) $election?->tse_code]]],
            self::PastPhotos => ['tse:fotos', ['--encerradas' => true]],
            self::Map => ['mapa:baixar', []],
            self::ClearCache => ['resultados:limpar-cache', []],
        };
    }
}

<?php

namespace App\Enums;

/**
 * How much a notice on the site stands out.
 */
enum NoticeLevel: string
{
    /** Something worth knowing: "A apuração começa às 17h". */
    case Info = 'informacao';

    /** Something that affects what the visitor sees: "Instabilidade no site do TSE". */
    case Warning = 'atencao';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informação',
            self::Warning => 'Atenção',
        };
    }
}

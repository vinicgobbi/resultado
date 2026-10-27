<?php

namespace App\Enums;

enum ElectionType: string
{
    case Federal = 'federal';
    case State = 'estadual';
    case Municipal = 'municipal';

    /**
     * Type of an election from the "tp" code of the TSE elections index.
     * Only regular elections are followed: supplementary elections (2 and 4)
     * and popular consultations (6 and 7) are not.
     */
    public static function fromTseCode(string $code): ?self
    {
        return match ($code) {
            '8' => self::Federal,
            '1' => self::State,
            '3' => self::Municipal,
            default => null,
        };
    }

    /**
     * Order of the elections of the same day: the federal one first.
     */
    public function order(): int
    {
        return match ($this) {
            self::Federal => 0,
            self::State => 1,
            self::Municipal => 2,
        };
    }
}

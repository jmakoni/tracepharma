<?php

namespace App\Enums;

enum ExceptionDisposition: string
{
    case Cleared = 'cleared';
    case Illegitimate = 'illegitimate';

    public function label(): string
    {
        return match ($this) {
            self::Cleared => 'Cleared for distribution',
            self::Illegitimate => 'Illegitimate',
        };
    }
}

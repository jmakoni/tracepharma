<?php

namespace App\Enums;

enum ExceptionActivityVisibility: string
{
    case Internal = 'internal';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Partner => 'Partner-visible',
        };
    }
}

<?php

namespace App\Enums;

enum TracingRequestorType: string
{
    case Internal = 'internal';
    case Regulator = 'regulator';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Regulator => 'Regulator',
            self::Supplier => 'Supplier',
        };
    }
}

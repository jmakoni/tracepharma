<?php

namespace App\Enums;

enum SsccPrintDeliveryMode: string
{
    case Queue = 'queue';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Queue => 'Server queue',
            self::Client => 'Browser client',
        };
    }

    public static function isClient(self|string|null $mode): bool
    {
        if ($mode instanceof self) {
            return $mode === self::Client;
        }

        return $mode === self::Client->value;
    }
}

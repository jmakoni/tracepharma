<?php

namespace App\Enums;

enum PackingSessionKind: string
{
    case Pack = 'pack';
    case Unpack = 'unpack';
    case BreakPack = 'break_pack';
    case Repack = 'repack';

    public function label(): string
    {
        return match ($this) {
            self::Pack => 'Pack',
            self::Unpack => 'Unpack',
            self::BreakPack => 'Break & pack',
            self::Repack => 'Repack',
        };
    }
}

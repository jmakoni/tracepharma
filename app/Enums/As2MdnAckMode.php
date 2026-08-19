<?php

namespace App\Enums;

enum As2MdnAckMode: string
{
    case Sync = 'sync';
    case Async = 'async';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Synchronous MDN',
            self::Async => 'Asynchronous MDN',
            self::None => 'No MDN',
        };
    }

    public function mdnStatus(): string
    {
        return match ($this) {
            self::Sync => 'received',
            self::Async => 'pending',
            self::None => 'none',
        };
    }
}

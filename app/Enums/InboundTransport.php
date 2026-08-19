<?php

namespace App\Enums;

enum InboundTransport: string
{
    case Https = 'https';
    case Sftp = 'sftp';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Https => 'HTTPS',
            self::Sftp => 'SFTP',
            self::Manual => 'Manual',
        };
    }
}

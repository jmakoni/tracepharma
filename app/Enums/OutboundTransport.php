<?php

namespace App\Enums;

enum OutboundTransport: string
{
    case Https = 'https';
    case Sftp = 'sftp';
    case As2 = 'as2';

    public function label(): string
    {
        return match ($this) {
            self::Https => 'HTTPS',
            self::Sftp => 'SFTP',
            self::As2 => 'AS2',
        };
    }
}

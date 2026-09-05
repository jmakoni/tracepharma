<?php

namespace App\Enums;

enum OutboundTransport: string
{
    case Https = 'https';
    case Sftp = 'sftp';
    case As2 = 'as2';
    case Email = 'email';
    case Portal = 'portal';

    public function label(): string
    {
        return match ($this) {
            self::Https => 'HTTPS',
            self::Sftp => 'SFTP',
            self::As2 => 'AS2',
            self::Email => 'Email (EPCIS attachment)',
            self::Portal => 'Client portal',
        };
    }
}

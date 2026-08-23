<?php

namespace App\Enums;

enum InboundTransport: string
{
    case Https = 'https';
    case Sftp = 'sftp';
    case Manual = 'manual';
    case As2 = 'as2';

    public function label(): string
    {
        return match ($this) {
            self::Https => 'HTTPS',
            self::Sftp => 'SFTP',
            self::Manual => 'Manual',
            self::As2 => 'AS2',
        };
    }

    /**
     * Transports the Inbound Connections form can create. AS2 inbound is
     * configured via tests / a later dedicated page — not that resource.
     *
     * @return list<self>
     */
    public static function operatorSelectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $transport): bool => $transport !== self::As2,
        ));
    }
}

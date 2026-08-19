<?php

namespace App\Enums;

enum SerializationProvider: string
{
    case Systech = 'systech';
    case CustomHttps = 'custom_https';
    case SapIch = 'sap_ich';
    case TraceLink = 'tracelink';
    case Lspedia = 'lspedia';
    case Advasur = 'advasur';
    case CustomSftp = 'custom_sftp';
    case Axway = 'axway';
    case Rfxcel = 'rfxcel';
    case UniTrace = 'unitrace';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Systech => 'Systech',
            self::CustomHttps => 'Custom (HTTPS)',
            self::SapIch => 'SAP ICH',
            self::TraceLink => 'TraceLink',
            self::Lspedia => 'LSPediA',
            self::Advasur => 'Advasur',
            self::CustomSftp => 'Custom (SFTP)',
            self::Axway => 'Axway',
            self::Rfxcel => 'rfXcel',
            self::UniTrace => 'UniTrace',
            self::Other => 'Other',
        };
    }

    public function defaultTransport(): InboundTransport
    {
        return match ($this) {
            self::Advasur, self::CustomSftp, self::Rfxcel, self::TraceLink => InboundTransport::Sftp,
            default => InboundTransport::Https,
        };
    }

    public function defaultOutboundTransport(): OutboundTransport
    {
        return OutboundTransport::Https;
    }

    public function supportsHubRouting(): bool
    {
        return in_array($this, [self::Systech, self::UniTrace], true);
    }

    public function hubProviderSlug(): string
    {
        return match ($this) {
            self::Systech => 'systech',
            self::UniTrace => 'unitrace',
            default => throw new \InvalidArgumentException("Provider [{$this->value}] does not support hub routing."),
        };
    }
}

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
    case TracePharma = 'tracepharma';
    case CustomAs2 = 'custom_as2';
    case GatewayChecker = 'gateway_checker';
    case Jennason = 'jennason';
    case InfiniTrak = 'infinitrak';
    case TheSystemsHouse = 'the_systems_house';
    case TrackTraceRx = 'tracktracerx';
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
            self::TracePharma => 'TracePharma',
            self::CustomAs2 => 'Custom (AS2)',
            self::GatewayChecker => 'Gateway Checker',
            self::Jennason => 'Jennason',
            self::InfiniTrak => 'InfiniTrak',
            self::TheSystemsHouse => 'The Systems House',
            self::TrackTraceRx => 'TrackTraceRx',
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
        return match ($this) {
            self::CustomAs2, self::TraceLink, self::Lspedia, self::Axway, self::Rfxcel => OutboundTransport::As2,
            default => OutboundTransport::Https,
        };
    }

    public function supportsHubRouting(): bool
    {
        return in_array($this, [self::Systech, self::UniTrace, self::TracePharma], true);
    }

    public function hubProviderSlug(): string
    {
        return match ($this) {
            self::Systech => 'systech',
            self::UniTrace => 'unitrace',
            self::TracePharma => 'tracepharma',
            default => throw new \InvalidArgumentException("Provider [{$this->value}] does not support hub routing."),
        };
    }

    /**
     * Slugs that have Admin outbound network profiles (not Custom SFTP / Other).
     *
     * @return list<self>
     */
    public static function networkProfileProviders(): array
    {
        return [
            self::TracePharma,
            self::Systech,
            self::UniTrace,
            self::CustomAs2,
            self::CustomHttps,
            self::SapIch,
            self::TraceLink,
            self::Lspedia,
            self::Advasur,
            self::Axway,
            self::Rfxcel,
            self::GatewayChecker,
            self::Jennason,
            self::InfiniTrak,
            self::TheSystemsHouse,
            self::TrackTraceRx,
        ];
    }

    public function isNetworkProfileProvider(): bool
    {
        return in_array($this, self::networkProfileProviders(), true);
    }
}

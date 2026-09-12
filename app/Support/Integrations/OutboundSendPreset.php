<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use DomainException;

/**
 * Operator-facing presets for outbound send channels.
 * One connection per network; partners are assigned to the connection.
 */
final class OutboundSendPreset
{
    public const KEY_LSPEDIA = 'lspedia';

    public const KEY_UNITRACE = 'unitrace';

    public const KEY_SYSTECH = 'systech';

    public const KEY_TRACELINK = 'tracelink';

    public const KEY_OTHER_NETWORK = 'other_network';

    public const KEY_DIRECT = 'direct';

    public const KEY_PORTAL = 'portal';

    public const KEY_EMAIL = 'email';

    /**
     * Primary network cards shown in the create wizard (not the full enum dump).
     *
     * @return array<string, array{
     *     label: string,
     *     kind: OutboundConnectionKind,
     *     provider: SerializationProvider|null,
     *     help: string,
     *     allowed_transports: list<OutboundTransport>,
     *     default_transport: OutboundTransport,
     *     default_outbound_path: ?string
     * }>
     */
    public static function networkCards(): array
    {
        return [
            self::KEY_LSPEDIA => [
                'label' => 'LSPediA',
                'kind' => OutboundConnectionKind::ProviderHub,
                'provider' => SerializationProvider::Lspedia,
                'help' => 'One connection into the LSPediA network — every customer of yours on LSPediA receives through it. No per-customer AS2 endpoint needed.',
                'allowed_transports' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'default_transport' => OutboundTransport::Https,
                'default_outbound_path' => '/outbound/epcis/lspedia',
            ],
            self::KEY_UNITRACE => [
                'label' => 'UniTrace',
                'kind' => OutboundConnectionKind::ProviderHub,
                'provider' => SerializationProvider::UniTrace,
                'help' => 'One connection into the UniTrace network — partners on UniTrace receive through it.',
                'allowed_transports' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'default_transport' => OutboundTransport::Https,
                'default_outbound_path' => '/outbound/epcis/unitrace',
            ],
            self::KEY_SYSTECH => [
                'label' => 'Systech',
                'kind' => OutboundConnectionKind::ProviderHub,
                'provider' => SerializationProvider::Systech,
                'help' => 'One connection into the Systech network. Prefer HTTPS unless the network issued you AS2 credentials.',
                'allowed_transports' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'default_transport' => OutboundTransport::Https,
                'default_outbound_path' => '/outbound/epcis/systech',
            ],
            self::KEY_TRACELINK => [
                'label' => 'TraceLink',
                'kind' => OutboundConnectionKind::ProviderHub,
                'provider' => SerializationProvider::TraceLink,
                'help' => 'One gateway into the TraceLink network — partners already on TraceLink receive through it.',
                'allowed_transports' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'default_transport' => OutboundTransport::Https,
                'default_outbound_path' => '/outbound/epcis/tracelink',
            ],
            self::KEY_OTHER_NETWORK => [
                'label' => 'Another network',
                'kind' => OutboundConnectionKind::ProviderHub,
                'provider' => null,
                'help' => 'SAP ICH, Axway, rfXcel, Advasur, or another network. Pick the network on the next step.',
                'allowed_transports' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'default_transport' => OutboundTransport::Https,
                'default_outbound_path' => '/outbound/epcis',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function networkCardOptions(): array
    {
        return collect(self::networkCards())
            ->mapWithKeys(fn (array $card, string $key): array => [$key => $card['label']])
            ->all();
    }

    /**
     * @return list<OutboundTransport>
     */
    public static function allowedTransportsFor(
        OutboundConnectionKind $kind,
        ?SerializationProvider $provider = null,
    ): array {
        if ($kind === OutboundConnectionKind::LocalDelivery) {
            return [
                OutboundTransport::Email,
                OutboundTransport::Portal,
            ];
        }

        if ($provider !== null && $provider->isNetworkProfileProvider()) {
            return self::networkAllowedTransports($provider);
        }

        return match ($kind) {
            OutboundConnectionKind::LocalDelivery => [
                OutboundTransport::Email,
                OutboundTransport::Portal,
            ],
            OutboundConnectionKind::DirectPartner,
            OutboundConnectionKind::ProviderHub => [
                OutboundTransport::Https,
                OutboundTransport::Sftp,
                OutboundTransport::As2,
            ],
        };
    }

    /**
     * @return list<OutboundTransport>
     */
    private static function networkAllowedTransports(SerializationProvider $provider): array
    {
        return match ($provider) {
            SerializationProvider::TracePharma => [OutboundTransport::Https],
            SerializationProvider::Systech,
            SerializationProvider::UniTrace => [OutboundTransport::Https, OutboundTransport::As2],
            SerializationProvider::CustomAs2 => [OutboundTransport::As2],
            SerializationProvider::CustomHttps => [OutboundTransport::Https],
            SerializationProvider::SapIch => [OutboundTransport::Https],
            SerializationProvider::TraceLink => [OutboundTransport::As2, OutboundTransport::Https],
            SerializationProvider::Lspedia => [OutboundTransport::As2, OutboundTransport::Sftp, OutboundTransport::Https],
            SerializationProvider::Advasur => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
            SerializationProvider::Axway,
            SerializationProvider::Rfxcel => [OutboundTransport::As2, OutboundTransport::Sftp, OutboundTransport::Https],
            SerializationProvider::GatewayChecker,
            SerializationProvider::Jennason,
            SerializationProvider::InfiniTrak,
            SerializationProvider::TheSystemsHouse,
            SerializationProvider::TrackTraceRx => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
            default => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
        };
    }

    public static function defaultTransportFor(
        OutboundConnectionKind $kind,
        ?SerializationProvider $provider = null,
    ): OutboundTransport {
        if ($kind === OutboundConnectionKind::LocalDelivery) {
            return OutboundTransport::Portal;
        }

        if ($provider !== null && $provider->isNetworkProfileProvider()) {
            return $provider->defaultOutboundTransport();
        }

        return OutboundTransport::Https;
    }

    public static function isNetworkProvider(SerializationProvider $provider): bool
    {
        return $provider->isNetworkProfileProvider()
            && ! in_array($provider, [
                SerializationProvider::CustomHttps,
                SerializationProvider::CustomAs2,
            ], true);
    }

    public static function assertTransportAllowed(
        OutboundConnectionKind $kind,
        SerializationProvider $provider,
        OutboundTransport $transport,
    ): void {
        $allowed = self::allowedTransportsFor($kind, $provider);

        if (! in_array($transport, $allowed, true)) {
            throw new DomainException(
                sprintf(
                    'Transport %s is not allowed for %s (%s). Allowed: %s.',
                    $transport->label(),
                    $kind->shortLabel(),
                    $provider->label(),
                    implode(', ', array_map(fn (OutboundTransport $t): string => $t->label(), $allowed)),
                ),
            );
        }
    }

    /**
     * Infer kind from existing row shape (backfill / display).
     */
    public static function inferKind(
        SerializationProvider $provider,
        OutboundTransport $transport,
        int $partnerCount,
        bool $isSystem = false,
    ): OutboundConnectionKind {
        if ($isSystem || in_array($transport, [OutboundTransport::Email, OutboundTransport::Portal], true)) {
            return OutboundConnectionKind::LocalDelivery;
        }

        if (self::isNetworkProvider($provider) && $partnerCount >= 1) {
            return OutboundConnectionKind::ProviderHub;
        }

        if ($partnerCount === 1 && ! self::isNetworkProvider($provider)) {
            return OutboundConnectionKind::DirectPartner;
        }

        if (self::isNetworkProvider($provider)) {
            return OutboundConnectionKind::ProviderHub;
        }

        return OutboundConnectionKind::DirectPartner;
    }

    public static function profileKeyForProvider(?SerializationProvider $provider): ?string
    {
        return match ($provider) {
            SerializationProvider::Lspedia => self::KEY_LSPEDIA,
            SerializationProvider::UniTrace => self::KEY_UNITRACE,
            SerializationProvider::Systech => self::KEY_SYSTECH,
            SerializationProvider::TraceLink => self::KEY_TRACELINK,
            SerializationProvider::SapIch,
            SerializationProvider::Axway,
            SerializationProvider::Rfxcel,
            SerializationProvider::Advasur => self::KEY_OTHER_NETWORK,
            default => null,
        };
    }
}

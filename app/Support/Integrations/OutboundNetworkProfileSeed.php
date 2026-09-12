<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\OutboundTransport;

/**
 * Seed definitions for Admin outbound network profiles.
 * Values from the Xttrium UniTrace prod export (2026-09-05) that do not change per partner.
 * Tenant enrollment secrets, AS2-From, SFTP hosts, PEMs, and Xttrium-only hop URLs are never seeded.
 */
final class OutboundNetworkProfileSeed
{
    public const SYSTECH_HUB_PROD = 'https://hub-prod.systechcloud.com/jobs/api/inboundjob/?message-type=epcis&format=xml';

    public const SAP_ICH_PROD = 'https://ich4ls.net.sap/cxf/ICH_DataExchange_SOAP_ASYNC_v4';

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $rows = [];

        foreach (self::networks() as $slug => $network) {
            foreach (self::environmentsFor($slug) as $environment) {
                $rows[] = self::definition($slug, $environment, $network);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array{label: string, default_transport: OutboundTransport, allowed: list<OutboundTransport>, notes: ?string}>
     */
    private static function networks(): array
    {
        $customAs2Notes = implode("\n", [
            'AS2-To presets (set on the tenant connection or profile): Cardinal CARDINALEPCIS, rfXcel Denmat rfxchangeprod, Dentsply dentsply, Colgate ATTP via ICC ICCNET_AS2. Subject EPCIS.',
            'AS2-From is tenant-owned (Xttrium used UniTraceXttriumProd).',
            'SFTP filename patterns seen in UniTrace export: Bloodworth Xttrium_{{timestamp|%Y%m%d-%H%M%S}}.xml; Medicom TransferShipmentWorkflow_{{timestamp|%Y%m%d%H%M%S%f}}.xml; Denmat EPCIS_{{timestamp|%Y%m%d%H%M%S%f}}.xml.',
        ]);

        return [
            'tracepharma' => [
                'label' => 'TracePharma',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https],
                'notes' => 'Platform hub edge for this environment. Tenants POST EPCIS to the shared TracePharma hub.',
            ],
            'systech' => [
                'label' => 'Systech',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2],
                'notes' => 'Systech Hub HTTPS endpoint is shared by UniTrace channels (DSCSA Hub Output, Sky Output, SAP ICH DSCSA Output). Query string is part of the URL.',
            ],
            'unitrace' => [
                'label' => 'UniTrace',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2],
                'notes' => 'UniTrace collaboration edge — same Systech Hub URL as Systech. One profile, many partners.',
            ],
            'custom_as2' => [
                'label' => 'Custom (AS2)',
                'default_transport' => OutboundTransport::As2,
                'allowed' => [OutboundTransport::As2],
                'notes' => $customAs2Notes,
            ],
            'custom_https' => [
                'label' => 'Custom (HTTPS)',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https],
                'notes' => 'Tenant-owned HTTPS endpoint. Use my own endpoint for Xttrium-specific hops (AHP, Henry Schein, TraceLink SN list).',
            ],
            'sap_ich' => [
                'label' => 'SAP ICH',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https],
                'notes' => 'SAP Information Collaboration Hub. SOAP wrapping is not applied by TracePharma in this release.',
            ],
            'tracelink' => [
                'label' => 'TraceLink',
                'default_transport' => OutboundTransport::As2,
                'allowed' => [OutboundTransport::As2, OutboundTransport::Https],
                'notes' => 'One B2B gateway into the TraceLink network. AS2-To TRACELINKPROD; subject presets PT_SOMINT_SALES_SHIPMENT_IB, SNX_DISPOSITION_ASSIGNED, SOM_SALES_SHIPMENT.',
            ],
            'lspedia' => [
                'label' => 'LSPediA',
                'default_transport' => OutboundTransport::As2,
                'allowed' => [OutboundTransport::As2, OutboundTransport::Sftp, OutboundTransport::Https],
                'notes' => 'LSPediA OneScan Exchange. Cardinal and Cencora are partners on this network — not separate connections.',
            ],
            'advasur' => [
                'label' => 'Advasur',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2],
                'notes' => null,
            ],
            'axway' => [
                'label' => 'Axway',
                'default_transport' => OutboundTransport::As2,
                'allowed' => [OutboundTransport::As2, OutboundTransport::Sftp, OutboundTransport::Https],
                'notes' => null,
            ],
            'rfxcel' => [
                'label' => 'rfXcel',
                'default_transport' => OutboundTransport::As2,
                'allowed' => [OutboundTransport::As2, OutboundTransport::Sftp, OutboundTransport::Https],
                'notes' => null,
            ],
            'gateway_checker' => [
                'label' => 'Gateway Checker',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
                'notes' => null,
            ],
            'jennason' => [
                'label' => 'Jennason',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
                'notes' => null,
            ],
            'infinitrak' => [
                'label' => 'InfiniTrak',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
                'notes' => null,
            ],
            'the_systems_house' => [
                'label' => 'The Systems House',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
                'notes' => null,
            ],
            'tracktracerx' => [
                'label' => 'TrackTraceRx',
                'default_transport' => OutboundTransport::Https,
                'allowed' => [OutboundTransport::Https, OutboundTransport::As2, OutboundTransport::Sftp],
                'notes' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function environmentsFor(string $slug): array
    {
        if ($slug === 'tracepharma') {
            return ['demo', 'stage', 'prod'];
        }

        return ['test', 'prod'];
    }

    /**
     * @param  array{label: string, default_transport: OutboundTransport, allowed: list<OutboundTransport>, notes: ?string}  $network
     * @return array<string, mixed>
     */
    private static function definition(string $slug, string $environment, array $network): array
    {
        $row = [
            'network_slug' => $slug,
            'environment' => $environment,
            'label' => $network['label'],
            'default_transport' => $network['default_transport']->value,
            'allowed_transports' => array_map(
                static fn (OutboundTransport $t): string => $t->value,
                $network['allowed'],
            ),
            'endpoint_url' => null,
            'as2_url' => null,
            'as2_to' => null,
            'as2_subject' => null,
            'notes' => $network['notes'],
            'is_locked' => true,
        ];

        if ($environment === 'prod') {
            if (in_array($slug, ['systech', 'unitrace'], true)) {
                $row['endpoint_url'] = self::SYSTECH_HUB_PROD;
            } elseif ($slug === 'sap_ich') {
                $row['endpoint_url'] = self::SAP_ICH_PROD;
            } elseif ($slug === 'tracelink') {
                $row['as2_to'] = 'TRACELINKPROD';
                $row['as2_subject'] = 'PT_SOMINT_SALES_SHIPMENT_IB';
            }
        }

        if ($slug === 'tracepharma') {
            $host = match ($environment) {
                'demo' => 'admin2.internal.vatengi.com',
                'stage' => 'stage.tracepharma.io',
                default => 'prod.tracepharma.io',
            };
            $row['endpoint_url'] = 'https://'.$host.'/api/webhooks/epcis/hub/tracepharma';
        }

        return $row;
    }
}

<?php

namespace App\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;

final class OutboundTransportAvailability
{
    public static function isSelectable(OutboundTransport $transport): bool
    {
        return true;
    }

    /**
     * Previously marked outbound SFTP as legacy/unavailable. SFTP is a first-class
     * outbound transport; retained for Integration Health API compatibility.
     */
    public static function isLegacyUnavailable(OutboundTransport $transport): bool
    {
        return false;
    }

    public static function legacyBadgeLabel(): string
    {
        return 'Legacy/unavailable';
    }

    public static function sftpSaveMessage(): string
    {
        return 'SFTP outbound requires host, username, and outbound path.';
    }

    public static function sftpTransmitMessage(): string
    {
        return 'SFTP outbound transmit failed. Check host, credentials, and outbound path.';
    }

    public static function assertSavable(OutboundConnection $connection): void
    {
        // All outbound transports including SFTP are savable.
    }

    public static function assertTransmittable(OutboundConnection $connection): void
    {
        // All outbound transports including SFTP are transmittable when configured.
    }

    /**
     * Deactivate all active SFTP outbound connections in the current tenant (ops cleanup).
     */
    public static function deactivateActiveLegacySftpConnections(): int
    {
        $connections = OutboundConnection::query()
            ->where('transport', OutboundTransport::Sftp)
            ->where('is_active', true)
            ->get();

        $deactivated = 0;

        foreach ($connections as $connection) {
            $connection->is_active = false;
            $connection->save();
            $deactivated++;
        }

        return $deactivated;
    }
}

<?php

namespace App\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use DomainException;
use Illuminate\Validation\ValidationException;

final class OutboundTransportAvailability
{
    public static function isSelectable(OutboundTransport $transport): bool
    {
        return $transport !== OutboundTransport::Sftp;
    }

    public static function isLegacyUnavailable(OutboundTransport $transport): bool
    {
        return $transport === OutboundTransport::Sftp;
    }

    public static function legacyBadgeLabel(): string
    {
        return 'Legacy/unavailable';
    }

    public static function sftpSaveMessage(): string
    {
        return 'SFTP outbound is not available in this release. Use HTTPS or AS2 instead.';
    }

    public static function sftpTransmitMessage(): string
    {
        return 'SFTP outbound is not available in this release. Edit the outbound connection to use HTTPS or AS2, or deactivate this legacy SFTP connection.';
    }

    /**
     * Fail closed when UI/API attempts to create or persist SFTP outbound transport.
     *
     * Legacy rows may remain in the database for audit, but cannot be activated and
     * cannot be switched to SFTP from another transport.
     */
    public static function assertSavable(OutboundConnection $connection): void
    {
        if ($connection->transport !== OutboundTransport::Sftp) {
            return;
        }

        if (! $connection->exists) {
            throw ValidationException::withMessages([
                'transport' => [self::sftpSaveMessage()],
            ]);
        }

        if ($connection->isDirty('transport')) {
            throw ValidationException::withMessages([
                'transport' => [self::sftpSaveMessage()],
            ]);
        }

        if ($connection->isDirty('is_active') && $connection->is_active) {
            throw ValidationException::withMessages([
                'is_active' => [self::sftpSaveMessage()],
            ]);
        }
    }

    public static function assertTransmittable(OutboundConnection $connection): void
    {
        if ($connection->transport === OutboundTransport::Sftp) {
            throw new DomainException(self::sftpTransmitMessage());
        }
    }

    /**
     * Deactivate all active legacy SFTP outbound connections in the current tenant.
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

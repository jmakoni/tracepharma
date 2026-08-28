<?php

use App\Models\OutboundConnection;
use Illuminate\Database\Migrations\Migration;

/**
 * Pin existing outbound connections without an explicit EPCIS version to 1.2
 * before the platform default flips to 2.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(OutboundConnection::class)) {
            return;
        }

        OutboundConnection::query()->orderBy('id')->chunkById(100, function ($connections): void {
            foreach ($connections as $connection) {
                $settings = is_array($connection->settings) ? $connection->settings : [];
                $version = $settings['epcis_document_version'] ?? null;
                if (is_string($version) && $version !== '') {
                    continue;
                }

                $settings['epcis_document_version'] = '1.2';
                $connection->forceFill(['settings' => $settings])->save();
            }
        });
    }

    public function down(): void
    {
        // Irreversible pin — leave stored 1.2 values in place.
    }
};

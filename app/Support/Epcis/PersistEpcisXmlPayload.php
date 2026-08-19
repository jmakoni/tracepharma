<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Persist authored EPCIS XML to the preferred disk, falling back to local.
 *
 * Treats a falsey put() (common when local.throw is false) as failure so we
 * never record payload_path/file_sha256 without a readable object on disk.
 *
 * Authored payloads should use the local disk (see authored_payload_disk).
 * Inbound S3 uploads use hub keys inbound/{uuid}.xml via EpcisStoragePath.
 */
final class PersistEpcisXmlPayload
{
    public function handle(
        EpcisDocument $document,
        string $xml,
        string $payloadPath,
        string $preferredDisk,
        string $context = 'EPCIS',
    ): void {
        $sha256 = hash('sha256', $xml);
        $disks = array_values(array_unique(array_filter([$preferredDisk, 'local'])));
        $lastError = null;

        foreach ($disks as $disk) {
            $path = EpcisStoragePath::onDisk($disk, $payloadPath);

            try {
                $stored = Storage::disk($disk)->put($path, $xml);
                if ($stored !== true || ! Storage::disk($disk)->exists($path)) {
                    throw new RuntimeException(
                        "Disk [{$disk}] did not store payload at [{$path}].",
                    );
                }

                $document->forceFill([
                    'payload_disk' => $disk,
                    'payload_path' => $path,
                    'file_sha256' => $sha256,
                ])->save();

                return;
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning("{$context} payload storage failed; trying next disk.", [
                    'document_id' => $document->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException(
            "Unable to persist {$context} payload after disk failures.",
            previous: $lastError,
        );
    }
}

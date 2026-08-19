<?php

namespace App\Actions\Epcis;

use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisStoragePath;
use DomainException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Replace the stored XML payload on an errored EPCIS document, then reprocess in place.
 */
final class ReplaceEpcisDocumentPayload
{
    public function __construct(
        private readonly ReprocessEpcisDocument $reprocess,
    ) {}

    /**
     * @param  array{original_filename?: string|null, notes?: string|null, sync?: bool, force?: bool}  $meta
     */
    public function handle(EpcisDocument $document, string $absolutePath, array $meta = []): EpcisDocument
    {
        if (! JobRoleAccess::allows(Permissions::NavExceptions)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }

        if ($document->status !== 'error') {
            throw new DomainException(
                "EPCIS document {$document->getKey()} can only accept a corrected file when status is [error].",
            );
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new \InvalidArgumentException("EPCIS XML file is missing or unreadable: {$absolutePath}");
        }

        $force = (bool) ($meta['force'] ?? false);
        if (! $force && Schema::hasTable('receiving_sessions')) {
            $activeReceiving = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->whereIn('status', ['open', 'in_progress'])
                ->exists();

            if ($activeReceiving) {
                throw new DomainException(
                    "EPCIS document {$document->getKey()} has an open or in-progress receiving session.",
                );
            }
        }

        $originalFilename = $meta['original_filename'] ?? basename($absolutePath);
        $extension = strtolower(pathinfo((string) $originalFilename, PATHINFO_EXTENSION));
        if ($extension !== 'xml') {
            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        }
        if ($extension !== 'xml') {
            throw new \InvalidArgumentException('Corrected EPCIS upload must be an .xml file.');
        }

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new \RuntimeException("Unable to hash EPCIS XML: {$absolutePath}");
        }

        $existing = EpcisDocument::query()
            ->where('file_sha256', $sha256)
            ->where('id', '!=', $document->getKey())
            ->whereNotIn('status', ['error', 'voided'])
            ->first();

        if ($existing !== null) {
            throw new DuplicateEpcisUploadException($existing);
        }

        $disk = (string) ($document->payload_disk ?: config('tracepharma.epcis.payload_disk', 'local'));
        $direction = (string) ($document->direction ?: 'inbound');
        $newPath = EpcisStoragePath::onDisk($disk, "epcis/{$direction}/".(string) Str::uuid().'.xml');
        $oldPath = $document->payload_path;

        $this->storePayloadStream($disk, $newPath, $absolutePath);

        $notes = filled($meta['notes'] ?? null)
            ? (string) $meta['notes']
            : $document->notes;

        $document->forceFill([
            'original_filename' => $originalFilename,
            'file_sha256' => $sha256,
            'payload_disk' => $disk,
            'payload_path' => $newPath,
            'error_message' => null,
            'notes' => $notes,
        ])->save();

        if (filled($oldPath) && $oldPath !== $newPath) {
            try {
                Storage::disk($disk)->delete($oldPath);
            } catch (\Throwable) {
                // Best-effort cleanup of superseded payload.
            }
        }

        if (function_exists('activity')) {
            activity()
                ->performedOn($document)
                ->withProperties([
                    'original_filename' => $originalFilename,
                    'file_sha256' => $sha256,
                ])
                ->log('epcis_document_payload_replaced');
        }

        $sync = (bool) ($meta['sync'] ?? false) || Queue::getDefaultDriver() === 'sync';

        return $this->reprocess->handle($document->refresh(), sync: $sync, force: $force, authorizeExceptionsRole: false);
    }

    private function storePayloadStream(string $disk, string $payloadPath, string $absolutePath): void
    {
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Unable to read EPCIS XML: {$absolutePath}");
        }

        $isS3 = config("filesystems.disks.{$disk}.driver") === 's3';
        // Do not set S3 object ACL/visibility — ACL-disabled buckets reject it.
        $options = array_filter([
            'ContentType' => $isS3 ? 'application/xml' : null,
        ]);

        try {
            $filesystem = Storage::disk($disk);
            if (method_exists($filesystem, 'writeStream')) {
                $written = $filesystem->writeStream($payloadPath, $stream, $options);
                if ($written === false) {
                    throw new \RuntimeException("Unable to store EPCIS XML payload at {$payloadPath}");
                }

                return;
            }

            $filesystem->put($payloadPath, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

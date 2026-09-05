<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\OutboundEpcisFilename;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\SbdhInstanceIdentifier;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Mint a new SBDH InstanceIdentifier + outbound filename for non-shipping
 * retransmit, rewriting the header in the existing payload body.
 */
final class RemintOutboundEpcisIdentityForRetransmit
{
    public function __construct(
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
    ) {}

    /**
     * @return array{document: EpcisDocument, old_uuid: string, new_uuid: string, old_filename: ?string, new_filename: string}
     */
    public function handle(EpcisDocument $document): array
    {
        if ($document->direction !== 'outbound') {
            throw new DomainException('Only outbound EPCIS documents can be reminted for retransmit.');
        }

        if (blank($document->payload_path)) {
            throw new DomainException('Cannot remint: outbound payload path is empty.');
        }

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new DomainException('Tenant context required to remint outbound identity.');
        }

        $oldUuid = (string) ($document->document_uuid ?? '');
        $oldFilename = filled($document->original_filename) ? (string) $document->original_filename : null;
        $oldPath = (string) $document->payload_path;
        $oldDisk = $document->payloadFilesystemDisk();

        $absolute = $document->materializePayloadPath();
        $shouldUnlink = str_contains($absolute, DIRECTORY_SEPARATOR.'epcis_payload_');
        try {
            $bytes = (string) file_get_contents($absolute);
        } finally {
            if ($shouldUnlink && is_file($absolute)) {
                @unlink($absolute);
            }
        }

        if ($bytes === '') {
            throw new DomainException('Cannot remint: outbound payload is missing or unreadable.');
        }

        $newUuid = SbdhInstanceIdentifier::uuid();
        $extension = strtolower((string) ($document->format ?? 'xml')) === 'json' ? 'json' : 'xml';
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $allocated = OutboundEpcisFilename::allocateUnique($tenant, Carbon::now('UTC'), $extension, $disk);
        $filename = $allocated['filename'];
        $path = $allocated['path'];

        $rewritten = $extension === 'json'
            ? $this->rewriteJsonInstanceId($bytes, $newUuid, $oldUuid)
            : $this->rewriteXmlInstanceId($bytes, $newUuid, $oldUuid);

        $document->forceFill([
            'document_uuid' => $newUuid,
            'original_filename' => $filename,
            'payload_path' => $path,
        ])->save();

        $this->persistEpcisXmlPayload->handle(
            $document,
            $rewritten,
            $path,
            $disk,
            'Remint outbound EPCIS identity',
        );

        if ($oldPath !== '' && ($oldPath !== $path || $oldDisk !== $document->fresh()->payloadFilesystemDisk())) {
            try {
                Storage::disk($oldDisk)->delete($oldPath);
            } catch (\Throwable) {
                // Best-effort: new payload is already durable.
            }
        }

        $document->refresh();

        return [
            'document' => $document,
            'old_uuid' => $oldUuid,
            'new_uuid' => $newUuid,
            'old_filename' => $oldFilename,
            'new_filename' => $filename,
        ];
    }

    private function rewriteXmlInstanceId(string $xml, string $newUuid, string $oldUuid): string
    {
        $replaced = 0;
        $out = preg_replace(
            '/(<sbdh:InstanceIdentifier>)([^<]*)(<\/sbdh:InstanceIdentifier>)/i',
            '${1}'.htmlspecialchars($newUuid, ENT_XML1 | ENT_QUOTES).'${3}',
            $xml,
            1,
            $replaced,
        );

        if (! is_string($out) || $replaced < 1) {
            $out = preg_replace(
                '/(<InstanceIdentifier>)([^<]*)(<\/InstanceIdentifier>)/i',
                '${1}'.htmlspecialchars($newUuid, ENT_XML1 | ENT_QUOTES).'${3}',
                $xml,
                1,
                $replaced,
            );
        }

        if (! is_string($out) || $replaced < 1) {
            if ($oldUuid !== '' && str_contains($xml, $oldUuid)) {
                return str_replace($oldUuid, $newUuid, $xml);
            }

            throw new DomainException(
                'Cannot remint: SBDH InstanceIdentifier not found in outbound XML.',
            );
        }

        return $out;
    }

    private function rewriteJsonInstanceId(string $json, string $newUuid, string $oldUuid): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new DomainException('Cannot remint: outbound JSON payload is invalid.');
        }

        $rewrote = false;
        if (isset($decoded['id']) && is_string($decoded['id'])) {
            $decoded['id'] = $newUuid;
            $rewrote = true;
        }
        if (isset($decoded['instanceIdentifier']) && is_string($decoded['instanceIdentifier'])) {
            $decoded['instanceIdentifier'] = $newUuid;
            $rewrote = true;
        }

        if (! $rewrote && $oldUuid !== '') {
            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && str_contains($encoded, $oldUuid)) {
                return str_replace($oldUuid, $newUuid, $encoded);
            }
        }

        if (! $rewrote) {
            throw new DomainException('Cannot remint: instance id field not found in outbound JSON.');
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || $encoded === '') {
            throw new DomainException('Cannot remint: failed to encode reminted JSON payload.');
        }

        return $encoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Enums\EpcisAuthoredKind;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Jobs\Labeling\ForwardCommissioningToL3;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\Epcis\EpcisDocument;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Epcis\EpcisStoragePath;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persist authored SSCC EPCIS XML as an outbound EpcisDocument and queue ingest.
 *
 * Mirrors ReceiveEpcisUpload (status=received + ProcessEpcisDocumentJob) while
 * keeping deterministic outbound paths like GenerateReceivingEpcisEvents.
 */
final class PersistAuthoredSsccEpcis
{
    public function __construct(
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
    ) {}

    /**
     * @param  array{
     *     trading_partner_id?: int|null,
     *     ship_from_site_id?: int|null,
     *     notes?: string|null,
     *     original_filename?: string|null,
     *     authored_kind: EpcisAuthoredKind,
     *     sync?: bool,
     *     dispatch?: bool,
     *     force_outbound?: bool,
     * }  $meta
     */
    public function handle(string $xml, string $payloadPath, array $meta = []): EpcisDocument
    {
        if (! isset($meta['authored_kind']) || ! $meta['authored_kind'] instanceof EpcisAuthoredKind) {
            throw new \InvalidArgumentException('PersistAuthoredSsccEpcis requires meta.authored_kind (EpcisAuthoredKind).');
        }

        $preferredDisk = (string) config('tracepharma.epcis.authored_payload_disk', config('tracepharma.epcis.payload_disk', 'local'));
        $sha256 = hash('sha256', $xml);

        // Serialize the duplicate check + insert per hash: without this lock, two
        // concurrent authoring calls for the same payload can both pass the "no
        // existing document" check and each persist their own EpcisDocument row.
        $document = Cache::lock($this->epcisUploadHashLockKey($sha256), 60)->block(10, function () use (
            $xml,
            $payloadPath,
            $preferredDisk,
            $sha256,
            $meta,
        ): EpcisDocument {
            $existing = EpcisDocument::query()
                ->where('file_sha256', $sha256)
                ->whereNotIn('status', ['error', 'voided'])
                ->first();

            if ($existing !== null) {
                throw new DuplicateEpcisUploadException($existing);
            }

            [$disk, $storedPath] = $this->persistPayload($xml, $payloadPath, $preferredDisk);

            return EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => $meta['authored_kind'],
                'trading_partner_id' => isset($meta['trading_partner_id']) ? (int) $meta['trading_partner_id'] : null,
                'ship_from_site_id' => isset($meta['ship_from_site_id']) ? (int) $meta['ship_from_site_id'] : null,
                'format' => 'xml',
                'original_filename' => $meta['original_filename'] ?? basename($payloadPath),
                'file_sha256' => $sha256,
                'payload_disk' => $disk,
                'payload_path' => $storedPath,
                'dscsa_affirm' => false,
                'status' => 'received',
                'notes' => $meta['notes'] ?? null,
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
        });

        $dispatch = (bool) ($meta['dispatch'] ?? true);
        $sync = (bool) ($meta['sync'] ?? false);

        if ($dispatch || $sync) {
            $this->dispatchProcess($document, $sync || Queue::getDefaultDriver() === 'sync');
        }

        if ($this->shouldTransmitOutbound($document, $meta)) {
            $this->scheduleOutboundTransmission->afterPersist($document, true);
        }

        if ($this->shouldForwardToL3($meta)) {
            $this->dispatchL3Forward($document);
        }

        return $document->refresh();
    }

    /**
     * Internal authoring (pack/commission with no counterparty) must not fall through to
     * the first active outbound connection — only partner-addressed documents transmit.
     *
     * @param  array<string, mixed>  $meta
     */
    private function shouldTransmitOutbound(EpcisDocument $document, array $meta): bool
    {
        if ((bool) ($meta['force_outbound'] ?? false)) {
            return true;
        }

        return $document->trading_partner_id !== null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function shouldForwardToL3(array $meta): bool
    {
        $kind = $meta['authored_kind'] ?? null;
        if (! $kind instanceof EpcisAuthoredKind) {
            return false;
        }

        if (! in_array($kind, [EpcisAuthoredKind::SsccCommissioning, EpcisAuthoredKind::Commissioning], true)) {
            return false;
        }

        $settings = TenantSettings::forTenant(tenant());

        return $settings->l3Enabled() && filled($settings->l3EndpointUrl());
    }

    private function dispatchL3Forward(EpcisDocument $document): void
    {
        $tenant = tenant();
        if ($tenant === null) {
            throw new \RuntimeException('PersistAuthoredSsccEpcis requires an initialized tenant.');
        }

        ForwardCommissioningToL3::dispatch((string) $tenant->getKey(), (int) $document->getKey())->afterCommit();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function persistPayload(string $xml, string $payloadPath, string $preferredDisk): array
    {
        $disks = array_values(array_unique(array_filter([$preferredDisk, 'local'])));
        $lastError = null;

        foreach ($disks as $disk) {
            $path = EpcisStoragePath::onDisk($disk, $payloadPath);

            try {
                $stored = Storage::disk($disk)->put($path, $xml);
                if ($stored !== true || ! Storage::disk($disk)->exists($path)) {
                    throw new \RuntimeException("Disk [{$disk}] did not store payload at [{$path}].");
                }

                return [$disk, $path];
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('Authored SSCC EPCIS payload storage failed; trying next disk.', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            'Unable to persist authored SSCC EPCIS payload after disk failures.',
            previous: $lastError,
        );
    }

    private function dispatchProcess(EpcisDocument $document, bool $sync): void
    {
        $tenant = tenant();
        if ($tenant === null) {
            throw new \RuntimeException('PersistAuthoredSsccEpcis requires an initialized tenant.');
        }

        $job = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());

        if ($sync) {
            // Calling handle() directly skips the job's WithoutOverlapping queue
            // middleware, so an equivalent lock is taken here to keep a concurrent
            // reprocess of the same document from racing this synchronous run.
            Cache::lock($this->epcisProcessLockKey($document), 600)->block(30, function () use ($job): void {
                $job->handle(app(EpcisIngestionService::class));
            });

            return;
        }

        ProcessEpcisDocumentJob::dispatch($tenant, (int) $document->getKey())->afterCommit();
    }

    private function epcisUploadHashLockKey(string $sha256): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-upload-hash:'.$tenantId.':'.$sha256;
    }

    private function epcisProcessLockKey(EpcisDocument $document): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-process:'.$tenantId.':'.$document->getKey();
    }
}

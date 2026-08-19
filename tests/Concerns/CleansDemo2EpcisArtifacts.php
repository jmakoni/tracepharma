<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tracks EPCIS documents / inbound / outbound connections created by a test
 * that runs against the shared demo2 tenant, so they can be purged again in
 * a `finally` block.
 *
 * demo2 is a shared, persistent tenant database reused across CI/local test
 * runs (not reset per test). Any Feature test that ingests EPCIS into demo2
 * (webhooks, hub routing, SFTP polling, etc.) MUST track every document and
 * connection it creates via {@see trackEpcisDocumentId()} /
 * {@see trackInboundConnectionId()} / {@see trackOutboundConnectionId()},
 * and MUST call {@see cleanupTrackedEpcisArtifacts()} from a `finally` block
 * (before `tenancy()->end()`) so demo2 never accumulates leftover rows or
 * payload files across runs — even when an assertion fails.
 *
 * Intentionally minimal: only wires the known demo2 EPCIS leakers. Not every
 * demo2 test needs to be refactored onto this trait.
 */
trait CleansDemo2EpcisArtifacts
{
    /** @var list<int> */
    private array $trackedEpcisDocumentIds = [];

    /** @var list<int> */
    private array $trackedInboundConnectionIds = [];

    /** @var list<int> */
    private array $trackedOutboundConnectionIds = [];

    private function trackEpcisDocumentId(int $id): void
    {
        $this->trackedEpcisDocumentIds[] = $id;
    }

    private function trackInboundConnectionId(int $id): void
    {
        $this->trackedInboundConnectionIds[] = $id;
    }

    private function trackOutboundConnectionId(int $id): void
    {
        $this->trackedOutboundConnectionIds[] = $id;
    }

    /**
     * Best-effort delete of everything tracked so far. Safe to call even if
     * some ids no longer exist, and safe to call multiple times. Must run
     * while tenancy is still initialized (call before `tenancy()->end()`).
     */
    private function cleanupTrackedEpcisArtifacts(): void
    {
        if (! tenancy()->initialized) {
            $this->trackedEpcisDocumentIds = [];
            $this->trackedInboundConnectionIds = [];
            $this->trackedOutboundConnectionIds = [];

            return;
        }

        if ($this->trackedEpcisDocumentIds !== []) {
            $documents = EpcisDocument::query()
                ->whereIn('id', $this->trackedEpcisDocumentIds)
                ->get(['id', 'payload_disk', 'payload_path']);

            foreach ($documents as $document) {
                $this->deletePayloadFile($document->payload_disk, $document->payload_path);
            }

            EpcisDocument::query()->whereIn('id', $this->trackedEpcisDocumentIds)->delete();
        }

        if ($this->trackedInboundConnectionIds !== []) {
            InboundConnection::query()->whereIn('id', $this->trackedInboundConnectionIds)->delete();
        }

        if ($this->trackedOutboundConnectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->trackedOutboundConnectionIds)->delete();
        }

        $this->trackedEpcisDocumentIds = [];
        $this->trackedInboundConnectionIds = [];
        $this->trackedOutboundConnectionIds = [];
    }

    private function deletePayloadFile(?string $disk, ?string $path): void
    {
        if (! filled($disk) || ! filled($path)) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Best-effort only — DB cleanup is what matters for demo2 hygiene.
        }
    }
}

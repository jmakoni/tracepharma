<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Support\Epcis\TestEpcisArtifactMatcher;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Hard-delete EPCIS documents (and their now-orphaned inbound connections) that
 * were created by automated tests and leaked into a tenant database. Matching
 * is delegated to {@see TestEpcisArtifactMatcher} so only unambiguous
 * test-generated artifacts are touched — real partner traffic is never matched.
 */
final class PurgeTestEpcisDocuments
{
    public function __construct(
        private readonly TestEpcisArtifactMatcher $matcher,
        private readonly DeleteEpcisDocument $deleteDocument,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     documents_deleted: int,
     *     connections_deleted: int,
     *     dry_run_documents: list<array{id: int, filename: string, direction: string, status: string}>,
     *     dry_run_connections: list<array{id: int, name: string}>,
     * }
     */
    public function handle(bool $dryRun = false, ?string $reason = null): array
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            throw new RuntimeException('PurgeTestEpcisDocuments requires an initialized tenant.');
        }

        $note = filled($reason) ? $reason : 'Purged automated test EPCIS artifact.';

        $matchedDocuments = $this->matchedDocuments();

        $dryRunDocuments = $matchedDocuments
            ->map(fn (EpcisDocument $document): array => [
                'id' => (int) $document->getKey(),
                'filename' => (string) $document->original_filename,
                'direction' => (string) $document->direction,
                'status' => (string) $document->status,
            ])
            ->values()
            ->all();

        $matchedConnectionIds = $matchedDocuments
            ->pluck('inbound_connection_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $matchedDocumentIds = $matchedDocuments->pluck('id')->map(fn (mixed $id): int => (int) $id);

        $connectionCandidates = $this->connectionCandidates($matchedConnectionIds);

        $dryRunConnections = $connectionCandidates
            ->filter(fn (InboundConnection $connection): bool => $this->connectionIsOrphanedAfterPurge(
                $connection,
                $matchedDocumentIds,
            ))
            ->map(fn (InboundConnection $connection): array => [
                'id' => (int) $connection->getKey(),
                'name' => (string) $connection->name,
            ])
            ->values()
            ->all();

        if ($dryRun) {
            return [
                'dry_run' => true,
                'documents_deleted' => count($dryRunDocuments),
                'connections_deleted' => count($dryRunConnections),
                'dry_run_documents' => $dryRunDocuments,
                'dry_run_connections' => $dryRunConnections,
            ];
        }

        $documentsDeleted = 0;
        foreach ($matchedDocuments as $document) {
            $this->purgeDocument($document, $note);
            $documentsDeleted++;
        }

        $connectionsDeleted = 0;
        foreach ($connectionCandidates as $connection) {
            if (! $this->connectionIsOrphanedAfterPurge($connection, $matchedDocumentIds)) {
                continue;
            }

            $connection->delete();
            $connectionsDeleted++;
        }

        return [
            'dry_run' => false,
            'documents_deleted' => $documentsDeleted,
            'connections_deleted' => $connectionsDeleted,
            'dry_run_documents' => [],
            'dry_run_connections' => [],
        ];
    }

    /**
     * @return Collection<int, EpcisDocument>
     */
    private function matchedDocuments(): Collection
    {
        return EpcisDocument::query()
            ->select(['id', 'original_filename', 'direction', 'status', 'payload_disk', 'payload_path', 'inbound_connection_id'])
            ->get()
            ->filter(fn (EpcisDocument $document): bool => $this->matcher->isTestDocumentFilename(
                (string) $document->original_filename,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $matchedConnectionIds
     * @return Collection<int, InboundConnection>
     */
    private function connectionCandidates(Collection $matchedConnectionIds): Collection
    {
        return InboundConnection::query()
            ->select(['id', 'name'])
            ->get()
            ->filter(fn (InboundConnection $connection): bool => $matchedConnectionIds->contains((int) $connection->getKey())
                || $this->matcher->isTestInboundConnectionName((string) $connection->name))
            ->values();
    }

    /**
     * A test-named connection is only deleted once every document referencing it
     * is either already gone or part of this purge's matched set — real
     * documents left pointing at a test-named connection block the delete.
     *
     * @param  Collection<int, int>  $matchedDocumentIds
     */
    private function connectionIsOrphanedAfterPurge(InboundConnection $connection, Collection $matchedDocumentIds): bool
    {
        $remaining = EpcisDocument::query()
            ->where('inbound_connection_id', $connection->getKey())
            ->whereNotIn('id', $matchedDocumentIds->all())
            ->exists();

        return ! $remaining;
    }

    private function purgeDocument(EpcisDocument $document, string $reason): void
    {
        $document->forceFill(['status' => 'voided'])->save();

        $this->deleteDocument->handle($document->fresh(), $reason, force: true);
    }
}

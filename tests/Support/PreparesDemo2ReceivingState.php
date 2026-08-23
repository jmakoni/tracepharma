<?php

namespace Tests\Support;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shared demo2 receiving isolation: leftover open sessions and fixture
 * EPC scan lines from prior classes must not leak into the next suite.
 */
trait PreparesDemo2ReceivingState
{
    /**
     * @param  list<string>  $fixtureEpcUris
     */
    protected function prepareDemo2ReceivingState(array $fixtureEpcUris = []): void
    {
        $tenant = tenant();
        if ($tenant !== null) {
            TenantSettings::forTenant($tenant)->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
            $tenant->save();
        }

        $openIds = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($openIds as $sessionId) {
            $this->deleteReceivingSessionForIsolation($sessionId);
        }

        if ($fixtureEpcUris === []) {
            return;
        }

        $epcIds = Epc::query()
            ->whereIn('epc_uri', $fixtureEpcUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return;
        }

        foreach ($epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
        }

        $sessionIds = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($sessionIds as $sessionId) {
            $this->deleteReceivingSessionForIsolation($sessionId);
        }
    }

    /**
     * Drop fixture EPCs only when no event_epcs remain (live ingest rows stay).
     *
     * @param  list<string>  $fixtureEpcUris
     */
    protected function deleteOrphanFixtureEpcs(array $fixtureEpcUris): void
    {
        foreach ($fixtureEpcUris as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc === null) {
                continue;
            }

            QuarantineHold::query()->where('epc_id', $epc->id)->delete();

            if (! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }
    }

    private function deleteReceivingSessionForIsolation(int $sessionId): void
    {
        $session = ReceivingSession::query()->find($sessionId);
        if ($session === null) {
            return;
        }

        if ($session->receiving_epcis_document_id !== null) {
            $receivingDocument = EpcisDocument::query()->find($session->receiving_epcis_document_id);
            if ($receivingDocument !== null && filled($receivingDocument->payload_path)) {
                Storage::disk((string) $receivingDocument->payload_disk)->delete((string) $receivingDocument->payload_path);
            }
            EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
        }

        ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
        $session->delete();
    }
}

<?php

namespace Tests\Support;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Support\Receiving\EligibleReceiveSites;
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
            $this->ensureDemo2OrgPrefixMatchesReceiveSites();
            $settings = TenantSettings::forTenant($tenant);
            $settings->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
            // Shared demo2 can retain this from OrganizationSettings / destination-GLN
            // suites; default is warning-only so unrelated receive tests can open ASN.
            if ($settings->blockReceiveOnDestinationGlnMismatch()) {
                $settings->setBlockReceiveOnDestinationGlnMismatch(false);
            }
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

    /**
     * tracepharma_test demo2 can retain a polluted org GLN/prefix that does not
     * cover the site ResolveReceivingSite would pick — receiving EPCIS then cannot
     * build an SGLN. Align identity to that same eligible fallback site.
     */
    protected function ensureDemo2OrgPrefixMatchesReceiveSites(): void
    {
        $tenant = tenant();
        if ($tenant === null) {
            return;
        }

        // Always work on the tenancy instance so later $tenant->save() calls cannot
        // overwrite identity with a stale in-memory company_prefix/gln.
        $settings = TenantSettings::forTenant($tenant);
        $receiveSiteId = $settings->defaultReceiveSiteId();
        $siteGln = '';

        if ($receiveSiteId !== null) {
            $default = Site::query()->whereKey($receiveSiteId)->first();
            if (
                $default !== null
                && EligibleReceiveSites::isEligible($default)
            ) {
                $siteGln = preg_replace('/\D+/', '', (string) ($default->gln ?? '')) ?? '';
            }
        }

        if (strlen($siteGln) !== 13) {
            $fallback = EligibleReceiveSites::forOrganization()
                ->reorder()
                ->orderByDesc('is_headquarters')
                ->orderBy('id')
                ->first();
            $siteGln = preg_replace('/\D+/', '', (string) ($fallback?->gln ?? '')) ?? '';
        }

        if (strlen($siteGln) !== 13) {
            return;
        }

        $orgGln = preg_replace('/\D+/', '', (string) ($settings->gln() ?? '')) ?? '';
        $prefix = $settings->companyPrefix();

        if ($prefix !== null && str_starts_with($siteGln, $prefix) && $orgGln !== '' && str_starts_with($orgGln, $prefix)) {
            return;
        }

        $aligned = substr($siteGln, 0, 6);
        $settings->setCompanyPrefix($aligned);
        if ($orgGln === '' || ! str_starts_with($orgGln, $aligned)) {
            $settings->setGln($siteGln);
        }
        $tenant->saveQuietly();
    }
}

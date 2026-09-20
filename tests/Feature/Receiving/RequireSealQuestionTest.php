<?php

declare(strict_types=1);

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class RequireSealQuestionTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?string $ssccUri = null;

    private ?bool $priorRequireSeal = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function sealed_auto_confirm_without_seal_ack_is_rejected_when_setting_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setRequireSealQuestion(true);
            $tenant->save();

            $document = $this->ingestUniqueMinimalFixture();
            $session = app(OpenReceivingSessionFromDocument::class)->handle(
                $document,
                $this->resolveEligibleReceiveSiteId(),
            );
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $rejected = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
                unpack: false,
                sealAcknowledged: false,
            );

            $this->assertFalse($rejected['ok']);
            $this->assertSame('seal_ack_required', $rejected['effect']);
            $this->assertStringContainsString('seal intact', strtolower((string) $rejected['message']));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_auto_confirm_succeeds_when_seal_acknowledged(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setRequireSealQuestion(true);
            $tenant->save();

            $document = $this->ingestUniqueMinimalFixture();
            $session = app(OpenReceivingSessionFromDocument::class)->handle(
                $document,
                $this->resolveEligibleReceiveSiteId(),
            );
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $ok = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
                unpack: false,
                sealAcknowledged: true,
            );

            $this->assertTrue($ok['ok'], $ok['message'] ?? 'confirm with seal ack failed');
            $this->assertSame('parent_confirmed', $ok['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function ingestUniqueMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());

        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_seal_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentId = (int) $document->getKey();
        $this->ssccUri = $ssccUri;
        $this->assertSame('validated', $document->status);

        return $document->fresh();
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);
        if ($tenant->profile !== TenantProfile::Pharmacy) {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        }

        tenancy()->initialize($tenant);
        $this->prepareDemo2ReceivingState();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireSeal = $settings->requireSealQuestion();
        $this->priorEdgeMode = $settings->receivingEdgeMode();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session?->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }
            ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
            DB::table('event_epcs')->whereIn(
                'event_id',
                DB::table('epcis_events')->where('document_id', $this->documentId)->select('id'),
            )->delete();
            DB::table('epcis_events')->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        $this->prepareDemo2ReceivingState();

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorRequireSeal !== null) {
            $settings->setRequireSealQuestion($this->priorRequireSeal);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireSeal = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;
        $this->ssccUri = null;

        tenancy()->end();
    }
}

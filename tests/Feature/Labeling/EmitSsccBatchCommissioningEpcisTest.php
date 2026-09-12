<?php

declare(strict_types=1);

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\EmitSsccBatchCommissioningEpcis;
use App\Actions\Labeling\StampSsccBatchCommissionedFromDocument;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmitSsccBatchCommissioningEpcisTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    #[Test]
    public function failed_ingest_leaves_commissioned_at_null(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$batch, $label] = $this->createUncommissionedBatch($site);

            // Persist only — no sync/async ingest — so the document never becomes validated.
            app(EmitSsccBatchCommissioningEpcis::class)->execute($batch->fresh(['labels']), [
                'site_id' => (int) $site->id,
                'sync' => false,
                'dispatch' => false,
            ]);

            $this->trackCommissioningArtifacts($batch);

            $batch->refresh();
            $label->refresh();

            $this->assertNull($batch->commissioned_at);
            $this->assertNull($label->commissioned_at);
            $this->assertNotNull($batch->commissioning_epcis_file_path);

            $document = EpcisDocument::query()
                ->where('notes', 'like', '%sscc_label_batch_id='.$batch->id.'%')
                ->latest('id')
                ->first();
            $this->assertNotNull($document);
            $this->assertSame('received', $document->status);

            // Simulated failed ingest: document lands in error; stamp must remain null.
            $document->forceFill([
                'status' => 'error',
                'error_message' => 'Forced commissioning ingest failure.',
            ])->save();

            app(StampSsccBatchCommissionedFromDocument::class)
                ->handle($document->refresh());

            $this->assertNull($batch->fresh()->commissioned_at);
            $this->assertNull($label->fresh()->commissioned_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function successful_validated_ingest_sets_commissioned_at(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$batch, $label] = $this->createUncommissionedBatch($site);

            app(EmitSsccBatchCommissioningEpcis::class)->execute($batch->fresh(['labels']), [
                'site_id' => (int) $site->id,
                'sync' => true,
            ]);

            $this->trackCommissioningArtifacts($batch);

            $batch->refresh();
            $label->refresh();

            $this->assertNotNull($batch->commissioned_at);
            $this->assertNotNull($label->commissioned_at);
            $this->assertNotNull($batch->commissioning_epcis_file_path);

            $document = EpcisDocument::query()
                ->where('notes', 'like', '%sscc_label_batch_id='.$batch->id.'%')
                ->latest('id')
                ->first();
            $this->assertNotNull($document);
            $this->assertSame('validated', $document->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: SsccLabelBatch, 1: SsccLabel}
     */
    private function createUncommissionedBatch(Site $site): array
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'send_to_printer' => false,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $site->id,
            'commissioned_at' => null,
        ]);
        $this->batchIds[] = (int) $batch->id;

        $serialInt = random_int(9400000, 9499999);
        $serialRef = str_pad((string) $serialInt, 9, '0', STR_PAD_LEFT);
        $ssccBody = '00399991'.$serialRef;
        $sscc18 = $ssccBody.Gtin::checkDigit($ssccBody);

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->id,
            'sscc_18' => $sscc18,
            'sscc_urn' => 'urn:epc:id:sscc:0399991.0'.$serialRef,
            'extension_digit' => '0',
            'company_prefix' => '0399991',
            'serial_reference' => $serialRef,
            'serial_reference_int' => $serialInt,
            'element_string' => '00'.$sscc18,
            'hrt' => '00'.$sscc18,
            'label_disk' => 'local',
            'label_path' => 'sscc/commission-stamp-test.pdf',
            'commissioned_at' => null,
        ]);
        $this->labelIds[] = (int) $label->id;

        return [$batch, $label];
    }

    private function createCommissionSite(Tenant $tenant, ?string $gln = null): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        $site = Site::query()->create([
            'name' => 'SSCC Commission Stamp '.Str::random(6),
            'gln' => $gln ?? $this->uniqueOrgGln('0399991'),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->id;

        $settings->setDefaultShipFromSiteId((int) $site->id);
        $tenant->save();

        return $site;
    }

    private function uniqueOrgGln(string $companyPrefix): string
    {
        $prefixLen = strlen($companyPrefix);
        $locationLen = 12 - $prefixLen;
        $locationRef = str_pad((string) random_int(0, (10 ** $locationLen) - 1), $locationLen, '0', STR_PAD_LEFT);
        $body = $companyPrefix.$locationRef;

        return $body.Gtin::checkDigit($body);
    }

    private function trackCommissioningArtifacts(SsccLabelBatch $batch): void
    {
        $documents = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where('notes', 'like', '%sscc_label_batch_id='.$batch->id.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->documentIds = array_values(array_unique(array_merge($this->documentIds, $documents)));

        $ssccs = $batch->labels()->pluck('sscc_18')->filter()->all();
        if ($ssccs !== []) {
            $epcIds = Epc::query()
                ->whereIn('sscc18', $ssccs)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->epcIds = array_values(array_unique(array_merge($this->epcIds, $epcIds)));
        }
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $this->priorProfile ??= $tenant->profile;
        $tenant->forceFill(['profile' => $profile])->save();
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

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->documentIds !== []) {
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
                }

                if (Schema::hasTable('epcis_events')) {
                    $eventIds = DB::table('epcis_events')
                        ->whereIn('document_id', $this->documentIds)
                        ->pluck('id')
                        ->all();

                    if ($eventIds !== [] && Schema::hasTable('event_locations')) {
                        DB::table('event_locations')->whereIn('event_id', $eventIds)->delete();
                    }

                    if ($eventIds !== [] && Schema::hasTable('event_epcs')) {
                        DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                    }

                    if ($eventIds !== [] && Schema::hasTable('epcis_exceptions')) {
                        DB::table('epcis_exceptions')->whereIn('document_id', $this->documentIds)->delete();
                    }

                    DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
                }

                if (Schema::hasTable('epcis_exceptions')) {
                    DB::table('epcis_exceptions')->whereIn('document_id', $this->documentIds)->delete();
                }

                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->epcIds !== []) {
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                }

                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->labelIds !== []) {
                SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
                $this->labelIds = [];
            }

            if ($this->batchIds !== []) {
                SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
                $this->batchIds = [];
            }

            if ($this->priorDefaultShipFromSiteId !== null || $this->siteIds !== []) {
                TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $tenant->save();
                $this->priorDefaultShipFromSiteId = null;
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->priorProfile !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile])->save();
                $this->priorProfile = null;
            }

            tenancy()->end();
        }
    }
}

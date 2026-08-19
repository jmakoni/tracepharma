<?php

namespace Tests\Feature\Labeling;

use App\Enums\ClientPrintBridge;
use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Enums\TenantProfile;
use App\Models\LabelPrinter;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientLabelPrintControllerSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $printJobIds = [];

    /** @var list<int> */
    private array $printerIds = [];

    #[Test]
    public function site_a_user_cannot_fetch_zpl_for_site_b_label(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedEligibleSites();
            [$labelB] = $this->createLabelArtifactsAtSite((int) $siteB->id);

            $user = $this->createUserWithSites([(int) $siteA->id]);

            $this->actingAs($user);

            $this->call(
                'GET',
                'http://'.self::DEMO2_DOMAIN.'/label-print/labels/'.$labelB->id.'/zpl',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                ],
            )->assertNotFound();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_a_user_cannot_start_print_job_for_site_b_batch(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedEligibleSites();
            [, $printJobB] = $this->createPrintJobArtifactsAtSite((int) $siteB->id);

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);

            $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/label-print/jobs/'.$printJobB->id.'/start',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                ],
                '{}',
            )->assertNotFound();
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedEligibleSites(): array
    {
        $siteA = Site::query()->create([
            'name' => 'Print Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $siteB = Site::query()->create([
            'name' => 'Print Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    /**
     * @return array{0: SsccLabel}
     */
    private function createLabelArtifactsAtSite(int $siteId): array
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399987',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $siteId,
            'commissioned_at' => now(),
        ]);
        $this->batchIds[] = (int) $batch->id;

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->id,
            'sscc_18' => '0039998700002101683',
            'sscc_urn' => 'urn:epc:id:sscc:0399987.00000210168',
            'extension_digit' => '0',
            'company_prefix' => '0399987',
            'serial_reference' => '0000210168',
            'serial_reference_int' => 210168,
            'element_string' => '00039998700002101683',
            'hrt' => '00039998700002101683',
            'label_disk' => 'local',
            'label_path' => 'sscc/site-access-print.pdf',
        ]);
        $this->labelIds[] = (int) $label->id;

        return [$label];
    }

    /**
     * @return array{0: SsccLabel, 1: SsccPrintJob}
     */
    private function createPrintJobArtifactsAtSite(int $siteId): array
    {
        [$label] = $this->createLabelArtifactsAtSite($siteId);

        $printer = LabelPrinter::query()->create([
            'name' => 'Site Access Printer',
            'protocol' => LabelPrinterProtocol::QzTray,
            'settings' => ['client_printer_name' => 'ZDesigner'],
            'enabled' => true,
        ]);
        $this->printerIds[] = (int) $printer->id;

        $printJob = SsccPrintJob::query()->create([
            'sscc_label_batch_id' => $label->batch_id,
            'sscc_label_id' => $label->id,
            'label_printer_id' => $printer->id,
            'copies' => 1,
            'status' => SsccPrintJobStatus::Queued,
            'delivery_mode' => SsccPrintDeliveryMode::Client,
            'queued_at' => now(),
        ]);
        $this->printJobIds[] = (int) $printJob->id;

        return [$label, $printJob];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->id;

        return $user;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
        TenantSettings::forTenant($tenant)->setClientPrintBridge(ClientPrintBridge::QzTray);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->printJobIds !== []) {
            SsccPrintJob::query()->whereIn('id', $this->printJobIds)->delete();
        }
        if ($this->labelIds !== []) {
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }
        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }
        if ($this->printerIds !== []) {
            LabelPrinter::query()->whereIn('id', $this->printerIds)->delete();
        }
        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
        }
        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        $this->printJobIds = [];
        $this->labelIds = [];
        $this->batchIds = [];
        $this->printerIds = [];
        $this->userIds = [];
        $this->siteIds = [];

        tenancy()->end();
    }
}

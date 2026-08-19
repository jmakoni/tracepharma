<?php

namespace Tests\Feature\Labeling;

use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\SsccLabels\Pages\ViewSsccLabelBatch;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ViewSsccLabelBatchSiteAccessTest extends TestCase
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

    #[Test]
    public function site_a_user_cannot_mount_or_list_site_b_batch(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedEligibleSites();
            $batchB = $this->createBatchAtSite((int) $siteB->id, commissioned: true);
            $labelB = $this->createLabelForBatch($batchB);

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);

            $visibleLabelIds = SsccLabelResource::getEloquentQuery()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $this->assertNotContains((int) $labelB->id, $visibleLabelIds);

            try {
                Livewire::test(ViewSsccLabelBatch::class, ['record' => $batchB->id]);
                $this->fail('Site-A user should not mount a site-B SSCC batch.');
            } catch (ModelNotFoundException|AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException) {
                    $this->assertContains($exception->getStatusCode(), [403, 404]);
                }
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_a_user_cannot_commission_now_at_site_b(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedEligibleSites();
            $batchA = $this->createBatchAtSite((int) $siteA->id, commissioned: false);
            $this->createLabelForBatch($batchA);

            $user = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($user);

            $component = Livewire::test(ViewSsccLabelBatch::class, ['record' => $batchA->id]);

            try {
                $component->call('commissionNow', ['site_id' => $siteB->id]);
                $component->assertStatus(403);
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException) {
                    $this->assertContains($exception->getStatusCode(), [403, 404]);
                }
            }

            $this->assertNull($batchA->fresh()->commissioned_at);
            $this->assertNotSame((int) $siteB->id, (int) ($batchA->fresh()->commission_site_id ?? 0));
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
            'name' => 'SSCC Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $siteB = Site::query()->create([
            'name' => 'SSCC Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    private function createBatchAtSite(int $siteId, bool $commissioned): SsccLabelBatch
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'send_to_printer' => false,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $siteId,
            'commissioned_at' => $commissioned ? now() : null,
        ]);
        $this->batchIds[] = (int) $batch->id;

        return $batch;
    }

    private function createLabelForBatch(SsccLabelBatch $batch): SsccLabel
    {
        $serialInt = random_int(9300000, 9399999);
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
            'label_path' => 'sscc/site-access-test.pdf',
            'commissioned_at' => $batch->commissioned_at,
        ]);
        $this->labelIds[] = (int) $label->id;

        return $label;
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->labelIds !== []) {
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }
        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }
        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
        }
        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        $this->labelIds = [];
        $this->batchIds = [];
        $this->userIds = [];
        $this->siteIds = [];

        tenancy()->end();
    }
}

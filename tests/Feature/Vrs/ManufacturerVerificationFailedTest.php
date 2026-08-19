<?php

namespace Tests\Feature\Vrs;

use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Actions\Vrs\RunProductVerification;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Models\Verification;
use App\Notifications\ManufacturerVerificationFailed;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturerVerificationFailedTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GTIN14 = '30301164005162';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $fdaOrganizationIds = [];

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $fdaPackagingIds = [];

    #[Test]
    public function failed_verify_prefers_fda_labeler_over_product_trading_partner(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $fdaOrg = FdaOrganization::query()->create([
                'name' => 'FDA Labeler '.uniqid('', true),
                'original_name' => 'FDA Labeler',
                'canonical_name' => 'FDA LABELER',
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'fda-labeler-'.uniqid('', true).'@example.test',
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->fdaOrganizationIds[] = (int) $fdaOrg->getKey();

            $fdaProduct = FdaProduct::query()->create([
                'product_id' => 'VRS-FDA-'.uniqid('', true),
                'product_ndc' => '0116-4005-62',
                'name' => 'FDA Demo Product',
                'fda_organization_id' => $fdaOrg->getKey(),
                'is_active' => true,
            ]);
            $this->fdaProductIds[] = (int) $fdaProduct->getKey();

            $packaging = FdaProductPackaging::query()->create([
                'fda_product_id' => $fdaProduct->getKey(),
                'package_ndc' => '0116-4005-62',
                'gtin' => self::GTIN14,
                'ndc11' => '01164005162',
                'description' => 'FDA packaging for VRS notify test',
                'is_active' => true,
            ]);
            $this->fdaPackagingIds[] = (int) $packaging->getKey();

            $productPartner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'product-partner-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $productPartner->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $productPartner->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)FAIL-FDA-PREF',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->exceptionIds[] = (int) $verification->exception_id;

            $fdaPartner = TradingPartner::query()
                ->where('fda_organization_id', $fdaOrg->getKey())
                ->first();

            $this->assertNotNull($fdaPartner);
            $this->partnerIds[] = (int) $fdaPartner->getKey();

            Notification::assertSentOnDemand(
                ManufacturerVerificationFailed::class,
                fn (ManufacturerVerificationFailed $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $fdaOrg->email
                    && $notification->verification->is($verification),
            );

            Notification::assertSentOnDemandTimes(ManufacturerVerificationFailed::class, 1);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_verify_prefers_vrs_notify_email_over_partner_email(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $manufacturer = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'generic-'.uniqid('', true).'@example.test',
                'vrs_notify_email' => 'vrs-override-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $manufacturer->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)FAIL-VRS-OVERRIDE',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->exceptionIds[] = (int) $verification->exception_id;

            Notification::assertSentOnDemand(
                ManufacturerVerificationFailed::class,
                fn (ManufacturerVerificationFailed $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $manufacturer->vrs_notify_email
                    && $notification->verification->is($verification),
            );

            Notification::assertSentOnDemandTimes(ManufacturerVerificationFailed::class, 1);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_verify_notifies_manufacturer_when_product_has_manufacturer_email(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $manufacturer = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'mfg-notify-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $manufacturer->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $user = User::factory()->create();
            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)FAIL-MFG-001',
                $user,
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('failed', $verification->status);
            $this->assertNotNull($verification->exception_id);
            $this->exceptionIds[] = (int) $verification->exception_id;

            Notification::assertSentOnDemand(
                ManufacturerVerificationFailed::class,
                fn (ManufacturerVerificationFailed $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $manufacturer->email
                    && $notification->verification->is($verification)
                    && $notification->exception->is($verification->exception),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function suspect_verify_notifies_manufacturer_when_product_has_manufacturer_email(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $manufacturer = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'mfg-suspect-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $manufacturer->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)NETWORK-SUSPECT',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('suspect', $verification->status);
            $this->exceptionIds[] = (int) $verification->exception_id;

            Notification::assertSentOnDemand(
                ManufacturerVerificationFailed::class,
                fn (ManufacturerVerificationFailed $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $manufacturer->email
                    && $notification->verification->is($verification),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_verify_without_manufacturer_email_does_not_crash_or_notify(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)FAIL-NO-MFG',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('failed', $verification->status);
            $this->exceptionIds[] = (int) $verification->exception_id;

            Notification::assertSentOnDemandTimes(ManufacturerVerificationFailed::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_verify_skips_non_manufacturer_trading_partner_email(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $wholesaler = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
                'email' => 'wholesaler-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $wholesaler->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $wholesaler->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)FAIL-WHOLESALER',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->exceptionIds[] = (int) $verification->exception_id;

            Notification::assertSentOnDemandTimes(ManufacturerVerificationFailed::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function verified_scan_does_not_notify_manufacturer(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $manufacturer = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'mfg-verified-'.uniqid('', true).'@example.test',
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $product = Product::factory()->create([
                'gtin' => self::GTIN14,
                'trading_partner_id' => $manufacturer->getKey(),
            ]);
            $this->productIds[] = (int) $product->getKey();

            ResolveProductFromIdentifier::clearCache();

            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)GOOD123',
            );

            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('verified', $verification->status);
            $this->assertNull($verification->exception_id);

            Notification::assertSentOnDemandTimes(ManufacturerVerificationFailed::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_mail_includes_tenant_context(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $manufacturer = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'email' => 'mfg-mail-body@example.test',
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $verification = Verification::query()->create([
                'gtin14' => self::GTIN14,
                'serial' => 'FAIL-MAIL',
                'status' => 'failed',
                'scanned_barcode' => '(01)'.self::GTIN14.'(21)FAIL-MAIL',
                'message' => 'GTIN and serial do not match manufacturer records.',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $exception = ExceptionCase::query()->create([
                'exception_type_id' => app(\App\Services\Exceptions\ExceptionService::class)
                    ->resolveType('VERIFICATION_FAILED')
                    ->getKey(),
                'title' => 'VRS failed · '.self::GTIN14.' / FAIL-MAIL',
                'description' => 'GTIN and serial do not match manufacturer records.',
                'severity' => 'high',
                'status' => 'new',
            ]);
            $this->exceptionIds[] = (int) $exception->getKey();

            $mail = (new ManufacturerVerificationFailed($verification, $exception))
                ->toMail(Notification::route('mail', $manufacturer->email));

            $this->assertStringContainsString(self::GTIN14, (string) $mail->subject);
            $this->assertStringContainsString('FAIL-MAIL', implode("\n", $mail->introLines));
            $this->assertStringContainsString(tenant('name') ?? 'Dispenser', implode("\n", $mail->introLines));
        } finally {
            $this->cleanup();
        }
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

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        ResolveProductFromIdentifier::clearCache();

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        foreach ($this->exceptionIds as $id) {
            $case = ExceptionCase::query()->find($id);
            if ($case === null) {
                continue;
            }

            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $id)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->exceptionIds = [];

        if ($this->productIds !== []) {
            Product::query()->whereKey($this->productIds)->delete();
            $this->productIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereKey($this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        if ($this->fdaPackagingIds !== []) {
            FdaProductPackaging::query()->whereKey($this->fdaPackagingIds)->delete();
            $this->fdaPackagingIds = [];
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereKey($this->fdaProductIds)->delete();
            $this->fdaProductIds = [];
        }

        if ($this->fdaOrganizationIds !== []) {
            FdaOrganization::query()->whereKey($this->fdaOrganizationIds)->delete();
            $this->fdaOrganizationIds = [];
        }

        tenancy()->end();
    }
}

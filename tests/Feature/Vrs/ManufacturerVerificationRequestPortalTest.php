<?php

declare(strict_types=1);

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Enums\VerificationRequestCaseStatus;
use App\Enums\VerificationRequestOutcome;
use App\Enums\VerificationRequestTrigger;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationRequestCase;
use App\Models\VerificationRequestResponse;
use App\Notifications\ManufacturerVerificationRequestMail;
use App\Notifications\VerificationRequestPositiveConfirmationMail;
use App\Services\Vrs\VerificationRequestCaseService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturerVerificationRequestPortalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GTIN14 = '30301164005162';

    private function uniqueGtin14(): string
    {
        return '3'.str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT);
    }

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function portal_submit_rejects_invalid_reason_code(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg portal submit',
                'gln' => '1234567890126',
                'partner_type' => 'manufacturer',
                'vrs_notify_email' => 'mfg@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Test',
                'is_active' => true,
            ]);

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => 'SER-VALIDATION',
                'status' => 'failed',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->setVrsContactEmail('ops@example.test');

            $opened = app(VerificationRequestCaseService::class)->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsFailed,
                User::query()->first(),
            );
            $this->caseIds[] = (int) $opened['case']->getKey();
            $case = $opened['case'];

            $this->withSession([
                'verification_request_unlocked' => $case->uuid,
                'verification_request_responder_email' => 'responder@example.test',
            ])->post('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$case->uuid.'/respond', [
                'outcome' => VerificationRequestOutcome::Positive->value,
                'reason_code' => 'not-a-valid-reason',
                'comments' => null,
                'terms_accepted' => '1',
            ])->assertSessionHasErrors('reason_code');
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function open_case_emails_manufacturer_and_positive_response_emails_requestor(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            $this->enablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg '.uniqid('', true),
                'gln' => '1234567890123',
                'partner_type' => 'manufacturer',
                'email' => 'mfg-'.uniqid('', true).'@example.test',
                'vrs_notify_email' => 'mfg-notify-'.uniqid('', true).'@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $product = Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Test Product',
                'ndc11' => '00116200116',
                'is_active' => true,
            ]);
            $this->productIds[] = (int) $product->getKey();

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => '10000082309203',
                'lot' => '606421T',
                'status' => 'unavailable',
                'message' => 'VRS could not be reached',
                'response_payload' => ['expiry_yymmdd' => '290531'],
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $user = User::query()->first();
            $this->assertNotNull($user);

            $this->setVrsContactEmail('requestor-'.uniqid('', true).'@example.test');

            $service = app(VerificationRequestCaseService::class);
            $opened = $service->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsUnavailable,
                $user,
                'VRS could not be reached',
            );

            $this->caseIds[] = (int) $opened['case']->getKey();

            Notification::assertSentOnDemand(ManufacturerVerificationRequestMail::class);

            $service->submitResponse($opened['case'], [
                'outcome' => VerificationRequestOutcome::Positive->value,
                'reason_code' => 'barcode_scan_issue',
                'comments' => 'Confirmed',
                'responder_email' => 'responder@example.test',
                'terms_accepted' => true,
            ]);

            $verification->refresh();
            $this->assertSame('verified', $verification->status);

            Notification::assertSentOnDemand(VerificationRequestPositiveConfirmationMail::class);

            $opened['case']->refresh();
            $this->assertSame(VerificationRequestCaseStatus::Responded, $opened['case']->status);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_unlock_requires_valid_secure_code(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg portal',
                'gln' => '1234567890124',
                'partner_type' => 'manufacturer',
                'vrs_notify_email' => 'mfg@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Test',
                'is_active' => true,
            ]);

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => 'SER1',
                'status' => 'failed',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->setVrsContactEmail('ops@example.test');

            $opened = app(VerificationRequestCaseService::class)->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsFailed,
                User::query()->first(),
            );
            $this->caseIds[] = (int) $opened['case']->getKey();

            $this->post('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$opened['case']->uuid.'/unlock', [
                'secure_code' => 'wrong-code',
                'responder_email' => 'bad@example.test',
                'terms_accepted' => '1',
            ])->assertSessionHasErrors('secure_code');

            $this->post('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$opened['case']->uuid.'/unlock', [
                'secure_code' => $opened['secure_code'],
                'responder_email' => 'good@example.test',
                'terms_accepted' => '1',
            ])->assertRedirect();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_show_does_not_leak_product_details_before_unlock(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg portal gate',
                'gln' => '1234567890125',
                'partner_type' => 'manufacturer',
                'vrs_notify_email' => 'mfg@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Secret Product Name',
                'is_active' => true,
            ]);

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => 'SECRET-SERIAL',
                'status' => 'failed',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->setVrsContactEmail('ops@example.test');

            $opened = app(VerificationRequestCaseService::class)->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsFailed,
                User::query()->first(),
            );
            $this->caseIds[] = (int) $opened['case']->getKey();

            $response = $this->get('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$opened['case']->uuid);

            $response->assertOk();
            $response->assertDontSee($gtin14, false);
            $response->assertDontSee('SECRET-SERIAL', false);
            $response->assertDontSee('Secret Product Name', false);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_expired_and_responded_cases_show_indistinguishable_invalid_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg portal invalid',
                'gln' => '1234567890127',
                'partner_type' => 'manufacturer',
                'vrs_notify_email' => 'mfg@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Leaked Product',
                'is_active' => true,
            ]);

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => 'LEAKED-SERIAL',
                'status' => 'failed',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->setVrsContactEmail('ops@example.test');

            $opened = app(VerificationRequestCaseService::class)->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsFailed,
                User::query()->first(),
            );
            $this->caseIds[] = (int) $opened['case']->getKey();
            $case = $opened['case'];

            $case->forceFill(['expires_at' => now()->subDay()])->save();

            $this->get('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$case->uuid)
                ->assertOk()
                ->assertViewIs('verification-request.invalid')
                ->assertDontSee($gtin14, false)
                ->assertDontSee('LEAKED-SERIAL', false);

            $case->forceFill([
                'expires_at' => now()->addWeek(),
                'status' => VerificationRequestCaseStatus::Responded,
                'responded_at' => now(),
            ])->save();

            $this->withSession([
                'verification_request_unlocked' => $case->uuid,
                'verification_request_responder_email' => 'responder@example.test',
            ])->get('http://'.self::DEMO2_DOMAIN.'/verification-request/'.$case->uuid.'/respond')
                ->assertOk()
                ->assertViewIs('verification-request.invalid')
                ->assertDontSee($gtin14, false)
                ->assertDontSee('Leaked Product', false);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function open_from_verification_rejects_when_portal_feature_disabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->disablePortalFeature();

            $gtin14 = $this->uniqueGtin14();

            $partner = TradingPartner::query()->create([
                'name' => 'Mfg disabled portal',
                'gln' => '1234567890128',
                'partner_type' => 'manufacturer',
                'vrs_notify_email' => 'mfg@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            Product::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'gtin' => $gtin14,
                'name' => 'Test',
                'is_active' => true,
            ]);

            $verification = Verification::query()->create([
                'gtin14' => $gtin14,
                'serial' => 'SER-DISABLED',
                'status' => 'failed',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $this->setVrsContactEmail('ops@example.test');

            $this->expectException(ValidationException::class);

            app(VerificationRequestCaseService::class)->openFromVerification(
                $verification,
                VerificationRequestTrigger::VrsFailed,
                User::query()->first(),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    private function disablePortalFeature(): void
    {
        $tenant = tenant();
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        data_set($settings, 'features.manufacturer_verification_portal', false);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    private function enablePortalFeature(): void
    {
        $tenant = tenant();
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        data_set($settings, 'features.manufacturer_verification_portal', true);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    private function setVrsContactEmail(string $email): void
    {
        $tenant = tenant();
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        data_set($settings, 'vrs.verification_contact_email', $email);
        $tenant->forceFill(['settings' => $settings])->save();
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

    private function cleanup(): void
    {
        if ($this->caseIds !== []) {
            VerificationRequestResponse::query()->whereIn('verification_request_case_id', $this->caseIds)->delete();
            VerificationRequestCase::query()->whereIn('id', $this->caseIds)->delete();
        }
        if ($this->verificationIds !== []) {
            Verification::query()->whereIn('id', $this->verificationIds)->delete();
        }
        if ($this->productIds !== []) {
            Product::query()->whereIn('id', $this->productIds)->delete();
        }
        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }
        $this->caseIds = [];
        $this->verificationIds = [];
        $this->productIds = [];
        $this->partnerIds = [];
    }
}

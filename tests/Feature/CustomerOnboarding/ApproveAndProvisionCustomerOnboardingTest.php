<?php

namespace Tests\Feature\CustomerOnboarding;

use App\Actions\CustomerOnboarding\ApproveAndProvisionCustomerOnboarding;
use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ApproveAndProvisionCustomerOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('customer_onboardings')) {
            $this->artisan('migrate', [
                '--force' => true,
                '--path' => 'database/migrations/2026_08_12_210001_create_customer_onboardings_table.php',
            ])->assertSuccessful();
        }
    }

    #[Test]
    public function reserved_admin_slug_is_rejected_before_tenant_create(): void
    {
        config(['tracepharma.admin_domain' => 'admin2.localhost']);

        $onboarding = $this->submittedOnboarding();

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => 'admin2',
                'owner_password' => 'password12',
            ], 1);
            $this->fail('Expected reserved slug to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reserved', strtolower($exception->getMessage()));
        }

        $this->assertNull($onboarding->fresh()->tenant_id);
        $this->assertNull($onboarding->fresh()->approved_by_admin_user_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->fresh()->status);
    }

    #[Test]
    public function existing_unrelated_tenant_id_does_not_receive_a_pair_host(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Unrelated onboarding fixture',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_unrelated_onboard_'.Str::lower(Str::random(8)),
        ]));
        $slug = 'resume-'.Str::lower(Str::random(6));
        $domainCount = $tenant->domains()->count();

        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $tenant->id,
            'tenant_slug' => $slug,
        ]);

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => $slug,
                'owner_name' => 'Resume Owner',
                'owner_email' => 'resume-owner-'.Str::lower(Str::random(8)).'@example.test',
                'owner_password' => 'password12',
            ], 1);
            $this->fail('Unrelated tenant_id must not receive a pair host.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different tenant', strtolower($exception->getMessage()));
        }

        $this->assertSame($domainCount, $tenant->fresh()?->domains()->count());
        $this->assertSame($tenant->id, $onboarding->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->fresh()?->status);

        Tenant::withoutEvents(fn () => $tenant->delete());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submittedOnboarding(array $overrides = []): CustomerOnboarding
    {
        return CustomerOnboarding::query()->create(array_merge([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Resume Pharmacy LLC',
            'company_display_name' => 'Resume Pharmacy',
            'contact_name' => 'Alex Pharmacist',
            'contact_email' => 'alex-resume@example-pharmacy.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ], $overrides));
    }
}

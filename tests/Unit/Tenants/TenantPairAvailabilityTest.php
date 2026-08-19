<?php

namespace Tests\Unit\Tenants;

use App\Actions\CustomerOnboarding\ApproveAndProvisionCustomerOnboarding;
use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class TenantPairAvailabilityTest extends TestCase
{
    /** @var list<string> */
    private array $orphanTenantIds = [];

    /** @var list<int> */
    private array $onboardingIds = [];

    protected function tearDown(): void
    {
        if ($this->onboardingIds !== []) {
            CustomerOnboarding::query()->whereIn('id', $this->onboardingIds)->delete();
        }

        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        parent::tearDown();
    }

    #[Test]
    public function a_free_slug_is_open_for_provisioning(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));

        TenantPairAvailability::assertOpenForProvisioning($slug);

        $this->assertFalse(TenantPairAvailability::ownsSlug($this->orphanTenant('other-'.$slug), $slug));
    }

    #[Test]
    public function a_host_without_pair_meta_does_not_own_the_slug(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $stage = $this->orphanTenant($slug, 'stage', withPairMeta: false);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->assertFalse(TenantPairAvailability::ownsSlug($stage, $slug));
    }

    #[Test]
    public function suggest_slug_reuses_an_unclaimed_complete_pair(): void
    {
        $slug = 'suggest-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->assertSame(
            $slug,
            ApproveAndProvisionCustomerOnboarding::suggestSlug(str_replace('-', ' ', $slug)),
        );
    }

    #[Test]
    public function suggest_slug_skips_a_claimed_pair(): void
    {
        $slug = 'suggest-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $this->submittedOnboarding(['tenant_id' => $prod->id]);

        $suggested = ApproveAndProvisionCustomerOnboarding::suggestSlug(str_replace('-', ' ', $slug));

        $this->assertNotSame($slug, $suggested);
        $this->assertStringStartsWith($slug.'-', $suggested);
    }

    #[Test]
    public function a_matching_prod_only_pair_is_resumable(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        TenantPairAvailability::assertOpenForProvisioning($slug);

        $this->assertTrue(TenantPairAvailability::ownsSlug($prod, $slug));
    }

    #[Test]
    public function a_complete_matching_pair_is_taken_for_a_new_create(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already taken');

        TenantPairAvailability::assertOpenForProvisioning($slug);
    }

    #[Test]
    public function a_complete_matching_pair_is_resumable_for_the_prod_tenant(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        TenantPairAvailability::assertOpenForProvisioning($slug, $prod->id);
    }

    #[Test]
    public function a_stage_only_pair_is_rejected(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $stage = $this->orphanTenant($slug, 'stage');
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a matching prod host');

        TenantPairAvailability::assertOpenForProvisioning($slug);
    }

    #[Test]
    public function a_prod_only_pair_claimed_by_another_onboarding_is_not_resumable(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Claimed LLC',
            'company_display_name' => 'Claimed',
            'contact_name' => 'Claimed Owner',
            'contact_email' => 'claimed-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'tenant_id' => $prod->id,
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked to another onboarding request');

        TenantPairAvailability::assertOpenForProvisioning($slug);
    }

    #[Test]
    public function a_prod_only_pair_is_resumable_when_linked_onboarding_matches(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        TenantPairAvailability::assertOpenForProvisioning($slug, (string) $prod->id);

        $this->assertTrue(TenantPairAvailability::ownsSlug($prod, $slug));
    }

    #[Test]
    public function a_complete_pair_with_a_domain_owned_foreign_stage_is_not_resumable(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $foreignStage = $this->orphanTenant($slug, 'stage', withPairMeta: false);
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $foreignStage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already taken');

        TenantPairAvailability::assertOpenForProvisioning($slug, (string) $prod->id);
    }

    #[Test]
    public function resume_tenant_id_for_discovers_an_unclaimed_prod_host(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Discover LLC',
            'company_display_name' => 'Discover',
            'contact_name' => 'Discover Owner',
            'contact_email' => 'discover-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $this->assertSame((string) $prod->id, TenantPairAvailability::resumeTenantIdFor($onboarding, $slug));
    }

    #[Test]
    public function resume_tenant_id_for_refuses_a_prod_host_claimed_by_another_onboarding(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Claimed LLC',
            'company_display_name' => 'Claimed',
            'contact_name' => 'Claimed Owner',
            'contact_email' => 'claimed-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'tenant_id' => $prod->id,
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);

        $intruder = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Intruder LLC',
            'company_display_name' => 'Intruder',
            'contact_name' => 'Intruder Owner',
            'contact_email' => 'intruder-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $intruder->id;

        $this->assertNull(TenantPairAvailability::resumeTenantIdFor($intruder, $slug));
    }

    #[Test]
    public function resume_id_for_an_unclaimed_complete_pair_is_the_prod_tenant(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $onboarding = $this->submittedOnboarding();

        $resumeId = TenantPairAvailability::resumeTenantIdFor($onboarding, $slug);

        $this->assertSame($prod->id, $resumeId);
        $this->assertNull(TenantPairAvailability::validationMessage($slug, $resumeId));
    }

    #[Test]
    public function a_rejected_onboarding_does_not_claim_the_prod_host(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $rejected = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'contact_email' => 'rejected-'.$slug.'@example.test',
        ]);
        $rejected->reject('not a fit');

        $this->assertNull($rejected->fresh()?->tenant_id);
        $this->assertFalse(TenantPairAvailability::prodClaimedByOnboarding((string) $prod->id));

        TenantPairAvailability::assertOpenForProvisioning($slug);
    }

    #[Test]
    public function a_rejected_linked_pair_can_be_resumed_by_another_application(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $rejected = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'contact_email' => 'rejected-'.$slug.'@example.test',
        ]);
        $rejected->reject('not a fit');

        $intruder = $this->submittedOnboarding([
            'contact_email' => 'intruder-'.$slug.'@example.test',
        ]);

        $this->assertSame((string) $prod->id, TenantPairAvailability::resumeTenantIdFor($intruder, $slug));
        $this->assertNull(TenantPairAvailability::validationMessage($slug));
    }

    #[Test]
    public function two_onboardings_cannot_share_the_same_tenant_id(): void
    {
        $this->artisan('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_16_040000_unique_customer_onboardings_tenant_id.php',
        ])->assertSuccessful();

        $prod = $this->orphanTenant('unique-'.Str::lower(Str::random(6)));
        $this->submittedOnboarding(['tenant_id' => $prod->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->submittedOnboarding(['tenant_id' => $prod->id]);
    }

    #[Test]
    public function releasing_a_tenant_clears_onboarding_links_and_reopens_provisioning(): void
    {
        $prod = $this->orphanTenant('rel-'.Str::lower(Str::random(6)));
        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'status' => CustomerOnboardingStatus::Provisioned,
            'provisioned_at' => now(),
        ]);

        CustomerOnboarding::releaseTenant((string) $prod->id);

        $onboarding->refresh();
        $this->assertNull($onboarding->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->status);
        $this->assertNull($onboarding->provisioned_at);
        $this->assertTrue($onboarding->isProvisionable());
    }

    #[Test]
    public function a_rejected_row_with_a_missing_tenant_releases_the_unique_slot(): void
    {
        $deadId = (string) Str::uuid();
        $rejected = $this->submittedOnboarding([
            'status' => CustomerOnboardingStatus::Rejected,
            'tenant_id' => $deadId,
            'rejected_at' => now(),
            'rejection_reason' => 'tenant already gone',
        ]);

        CustomerOnboarding::clearDeadRejectedClaims();

        $rejected->refresh();
        $this->assertNull($rejected->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Rejected, $rejected->status);
    }

    #[Test]
    public function openable_tenant_requires_a_live_tenant_and_domain(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $withDomain = $this->submittedOnboarding(['tenant_id' => $prod->id]);
        $this->assertFalse($withDomain->hasOpenableTenant());

        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $this->assertTrue($withDomain->fresh()?->hasOpenableTenant());

        $missing = $this->submittedOnboarding(['tenant_id' => (string) Str::uuid()]);
        $this->assertFalse($missing->hasOpenableTenant());
    }

    #[Test]
    public function an_unrelated_tenant_owning_a_pair_host_is_taken(): void
    {
        $slug = 'avail-'.Str::lower(Str::random(6));
        $unrelated = $this->orphanTenant('other-'.$slug, 'prod');
        $unrelated->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $this->assertFalse(TenantPairAvailability::ownsSlug($unrelated, $slug));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already taken');

        TenantPairAvailability::assertOpenForProvisioning($slug);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submittedOnboarding(array $overrides = []): CustomerOnboarding
    {
        $onboarding = CustomerOnboarding::query()->create(array_merge([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Avail LLC',
            'company_display_name' => 'Avail',
            'contact_name' => 'Avail Owner',
            'contact_email' => 'avail-'.Str::lower(Str::random(8)).'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ], $overrides));
        $this->onboardingIds[] = (int) $onboarding->id;

        return $onboarding;
    }

    private function orphanTenant(string $pairSlug, string $environment = 'prod', bool $withPairMeta = true): Tenant
    {
        $id = (string) Str::uuid();
        $this->orphanTenantIds[] = $id;

        $attributes = [
            'id' => $id,
            'name' => 'Pair availability orphan',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_avail_'.substr(str_replace('-', '', $id), 0, 16),
            'inbound_environment' => $environment,
        ];

        if ($withPairMeta) {
            $attributes['tenant_pair_slug'] = $pairSlug;
            $attributes['tenant_pair_environment'] = $environment;
        }

        return Tenant::withoutEvents(fn () => Tenant::query()->create($attributes));
    }
}

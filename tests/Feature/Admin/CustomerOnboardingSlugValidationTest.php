<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\CustomerOnboardings\Pages\ViewCustomerOnboarding;
use App\Models\Admin;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use App\Support\TenantHostname;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class CustomerOnboardingSlugValidationTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $onboardingIds = [];

    /** @var list<string> */
    private array $orphanTenantIds = [];

    protected function tearDown(): void
    {
        if ($this->onboardingIds !== []) {
            CustomerOnboarding::query()->whereIn('id', $this->onboardingIds)->delete();
        }

        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function provision_form_prefills_a_stored_tenant_slug(): void
    {
        $slug = 'ob-stored-'.Str::lower(Str::random(6));
        $onboarding = $this->submittedOnboarding([
            'tenant_slug' => $slug,
            'company_display_name' => 'Totally Different Name',
        ]);

        $this->actAsPlatformAdmin();

        Livewire::test(ViewCustomerOnboarding::class, ['record' => $onboarding->getKey()])
            ->mountAction(TestAction::make('approveAndProvision'))
            ->assertActionDataSet(['tenant_slug' => $slug]);
    }

    #[Test]
    public function provision_form_rejects_a_slug_the_linked_tenant_does_not_own(): void
    {
        $linkedSlug = 'ob-linked-'.Str::lower(Str::random(6));
        $otherSlug = 'ob-other-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($linkedSlug, 'prod');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($linkedSlug, 'prod')]);

        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'tenant_slug' => $linkedSlug,
        ]);

        $this->actAsPlatformAdmin();

        Livewire::test(ViewCustomerOnboarding::class, ['record' => $onboarding->getKey()])
            ->callAction(TestAction::make('approveAndProvision'), [
                'tenant_slug' => $otherSlug,
                'owner_name' => 'Slug Owner',
                'owner_email' => 'slug-'.$otherSlug.'@example.test',
                'owner_password' => 'password12',
            ])
            ->assertHasFormErrors(['tenant_slug']);
    }

    #[Test]
    public function provision_form_rejects_a_slug_whose_pair_host_is_taken(): void
    {
        $slug = 'ob-taken-'.Str::lower(Str::random(6));
        $unrelated = $this->orphanTenant('other-'.$slug);
        $unrelated->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Slug Check LLC',
            'company_display_name' => 'Slug Check',
            'contact_name' => 'Slug Owner',
            'contact_email' => 'slug-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $this->actAsPlatformAdmin();

        Livewire::test(ViewCustomerOnboarding::class, ['record' => $onboarding->getKey()])
            ->callAction(TestAction::make('approveAndProvision'), [
                'tenant_slug' => $slug,
                'owner_name' => 'Slug Owner',
                'owner_email' => 'slug-'.$slug.'@example.test',
                'owner_password' => 'password12',
            ])
            ->assertHasFormErrors(['tenant_slug']);
    }

    #[Test]
    public function provision_form_accepts_a_complete_pair_slug_for_resume_without_tenant_id(): void
    {
        $slug = 'ob-resume-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Resume LLC',
            'company_display_name' => 'Resume',
            'contact_name' => 'Resume Owner',
            'contact_email' => 'resume-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $this->actAsPlatformAdmin();

        $resumeId = \App\Support\TenantPairAvailability::resumeTenantIdFor($onboarding, $slug);
        $this->assertSame($prod->id, $resumeId);
        $this->assertNull(\App\Support\TenantPairAvailability::validationMessage($slug, $resumeId));

        Livewire::test(ViewCustomerOnboarding::class, ['record' => $onboarding->getKey()])
            ->mountAction(TestAction::make('approveAndProvision'))
            ->assertSuccessful();
    }

    #[Test]
    public function provision_form_rejects_a_domain_owned_foreign_stage_with_matching_prod(): void
    {
        $slug = 'ob-foreign-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $foreignStage = $this->orphanTenant('other-'.$slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $foreignStage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Foreign LLC',
            'company_display_name' => 'Foreign',
            'contact_name' => 'Foreign Owner',
            'contact_email' => 'foreign-'.$slug.'@example.test',
            'organization_type' => 'independent_pharmacy',
            'tenant_id' => $prod->id,
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $this->actAsPlatformAdmin();

        Livewire::test(ViewCustomerOnboarding::class, ['record' => $onboarding->getKey()])
            ->callAction(TestAction::make('approveAndProvision'), [
                'tenant_slug' => $slug,
                'owner_name' => 'Foreign Owner',
                'owner_email' => 'foreign-'.$slug.'@example.test',
                'owner_password' => 'password12',
            ])
            ->assertHasFormErrors(['tenant_slug']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submittedOnboarding(array $overrides = []): CustomerOnboarding
    {
        $onboarding = CustomerOnboarding::query()->create(array_merge([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Slug Check LLC',
            'company_display_name' => 'Slug Check',
            'contact_name' => 'Slug Owner',
            'contact_email' => 'slug-'.Str::lower(Str::random(8)).'@example.test',
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
            'name' => 'Onboarding slug orphan',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_slug_'.substr(str_replace('-', '', $id), 0, 16),
            'inbound_environment' => $environment,
        ];

        if ($withPairMeta) {
            $attributes['tenant_pair_slug'] = $pairSlug;
            $attributes['tenant_pair_environment'] = $environment;
        }

        return Tenant::withoutEvents(fn () => Tenant::query()->create($attributes));
    }

    private function actAsPlatformAdmin(): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

<?php

namespace Tests\Feature\Tenants;

use App\Actions\CustomerOnboarding\ApproveAndProvisionCustomerOnboarding;
use App\Actions\Tenants\DeleteTenantPair;
use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\AdminRole;
use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Models\Admin;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use App\Support\TenantDatabaseName;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class TenantPairProvisionTest extends TestCase
{
    /** @var list<string> */
    private array $slugs = [];

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $onboardingIds = [];

    /** @var list<string> */
    private array $orphanTenantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->slugs as $slug) {
            $this->destroyPair($slug);
        }

        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        if ($this->onboardingIds !== []) {
            CustomerOnboarding::query()->whereIn('id', $this->onboardingIds)->delete();
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
    public function pair_create_writes_two_hosts_and_databases(): void
    {
        $slug = 'ssor-pair-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Pair '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');

        $this->assertNotNull($stage);
        $this->assertNotSame($prod->id, $stage->id);
        $this->assertSame(TenantHostname::forSlug($slug, 'prod'), $prod->domains->first()?->domain);
        $this->assertSame(TenantHostname::forSlug($slug, 'stage'), $stage->domains->first()?->domain);
        $this->assertSame(TenantDatabaseName::fromDomain(TenantHostname::forSlug($slug, 'prod')), $prod->tenancy_db_name);
        $this->assertSame(TenantDatabaseName::fromDomain(TenantHostname::forSlug($slug, 'stage')), $stage->tenancy_db_name);
        $this->assertSame('prod', $prod->tenant_pair_environment);
        $this->assertSame('stage', $stage->tenant_pair_environment);
        $this->assertSame($slug, $prod->tenant_pair_slug);
        $this->assertSame('prod', $prod->inbound_environment);
        $this->assertSame('stage', $stage->inbound_environment);

        $again = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Pair '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ]);
        $this->assertSame($prod->id, $again->id);
        $this->assertSame(1, Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->count());
        $this->assertSame(1, Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->count());
    }

    #[Test]
    public function admin_create_rejects_a_missing_owner(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'SSOR Missing Owner',
                'profile' => TenantProfile::Pharmacy->value,
                'status' => 'active',
                'tenant_slug' => 'ssor-no-owner',
            ])
            ->call('create')
            ->assertHasFormErrors(['owner_name', 'owner_email', 'owner_password']);
    }

    #[Test]
    public function admin_create_uses_slug_and_creates_owners_on_both_hosts(): void
    {
        $slug = 'ssor-lw-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';
        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'SSOR Livewire '.$slug,
                'profile' => TenantProfile::Pharmacy->value,
                'status' => 'active',
                'tenant_slug' => $slug,
                'owner_name' => 'Livewire Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password12',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(
            Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first()
        );
        $this->assertNotNull(
            Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->first()
        );
        $this->assertNull(Domain::query()->where('domain', $slug)->first());

        $prod = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'prod');
        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($prod);
        $this->assertNotNull($stage);

        foreach ([$prod, $stage] as $tenant) {
            $tenant->run(function () use ($ownerEmail): void {
                $user = User::query()->where('email', $ownerEmail)->first();
                $this->assertNotNull($user);
                $this->assertTrue($user->hasRole(TenantRole::Owner->value));
            });
        }
    }

    #[Test]
    public function pair_create_resumes_when_prod_exists_and_stage_is_missing(): void
    {
        $slug = 'ssor-resume-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Resume '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $this->assertNull(app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage'));

        $again = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Resume '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertSame($prod->id, $again->id);
        $this->assertNotNull($stage);
        $this->assertNotSame($prod->id, $stage->id);
    }

    #[Test]
    public function pair_create_resume_cleans_foreign_stage_squatter(): void
    {
        $slug = 'ssor-squatter-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Squatter '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $squatterId = (string) Str::uuid();
        $this->orphanTenantIds[] = $squatterId;
        $squatter = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $squatterId,
            'name' => 'Foreign stage squatter',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_squatter_'.substr(str_replace('-', '', $squatterId), 0, 16),
            'inbound_environment' => 'stage',
        ]));
        $squatter->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);
        $squatter->domains()->create(['domain' => 'squatter-extra-'.$slug.'.example.test']);

        $again = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Squatter '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertSame($prod->id, $again->id);
        $this->assertNotNull($stage);
        $this->assertNotSame($squatter->id, $stage->id);
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->where('tenant_id', $squatterId)->first());
        $squatter->refresh();
        $this->assertSame(1, $squatter->domains()->count());
        $this->orphanTenantIds[] = $squatterId;
    }

    #[Test]
    public function pair_create_resume_updates_existing_owner_password_and_name(): void
    {
        $slug = 'ssor-owner-retry-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Owner Retry '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        app(\App\Actions\Tenants\EnsureTenantOwner::class)->handle($prod, [
            'name' => 'Original Owner',
            'email' => $ownerEmail,
            'password' => 'old-password12',
        ]);

        app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Owner Retry '.$slug,
            'profile' => TenantProfile::Pharmacy,
        ], owner: [
            'name' => 'Updated Owner',
            'email' => $ownerEmail,
            'password' => 'new-password12',
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);

        foreach ([$prod->fresh(), $stage] as $tenant) {
            $tenant->run(function () use ($ownerEmail): void {
                $user = User::query()->where('email', $ownerEmail)->first();
                $this->assertNotNull($user);
                $this->assertSame('Updated Owner', $user->name);
                $this->assertTrue($user->hasRole(TenantRole::Owner->value));
                $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password12', (string) $user->password));
            });
        }
    }

    #[Test]
    public function onboarding_resumes_a_prod_only_pair_without_tenant_id(): void
    {
        $slug = 'ssor-ob-resume-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Onboard Resume '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $onboarding = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Onboard Resume '.$slug,
            'contact_email' => $ownerEmail,
        ]);

        $admin = $this->actAsPlatformAdmin();

        $result = app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
            'tenant_slug' => $slug,
            'owner_name' => 'Onboard Owner',
            'owner_email' => $ownerEmail,
            'owner_password' => 'password12',
        ], (int) $admin->id);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertSame($prod->id, $result->id);
        $this->assertNotNull($stage);
        $this->assertSame($prod->id, $onboarding->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Provisioned, $onboarding->fresh()?->status);
    }

    #[Test]
    public function pair_create_rolls_back_prod_when_stage_fails_on_fresh_create(): void
    {
        $slug = 'ssor-rollback-'.Str::lower(Str::random(6));

        $onEnvironment = new class extends ProvisionTenantOnEnvironment
        {
            public function provision(string $slug, array $attributes, string $environment, array $address = []): Tenant
            {
                if ($environment === 'stage') {
                    throw new RuntimeException('forced stage failure');
                }

                return parent::provision($slug, $attributes, $environment, $address);
            }
        };

        $pair = new ProvisionTenantPair(
            $onEnvironment,
            app(\App\Actions\Tenants\EnsureTenantOwner::class),
            app(DeleteTenantPair::class),
        );

        try {
            $pair->create($slug, [
                'name' => 'SSOR Rollback '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ]);
            $this->fail('Stage failure should abort provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forced stage failure', $exception->getMessage());
            $this->assertStringContainsString('rolled back', strtolower($exception->getMessage()));
            $this->assertStringNotContainsString('--environment=stage', $exception->getMessage());
        }

        $this->assertNull($onEnvironment->findBySlugAndEnvironment($slug, 'prod'));
        $this->assertNull($onEnvironment->findBySlugAndEnvironment($slug, 'stage'));
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first());
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->first());
    }

    #[Test]
    public function pair_create_keeps_prod_when_stage_fails_on_resume(): void
    {
        $slug = 'ssor-resume-fail-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Resume Fail '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');
        $prodId = $prod->id;

        $onEnvironment = new class extends ProvisionTenantOnEnvironment
        {
            public function provision(string $slug, array $attributes, string $environment, array $address = []): Tenant
            {
                if ($environment === 'stage') {
                    throw new RuntimeException('forced stage failure');
                }

                return parent::provision($slug, $attributes, $environment, $address);
            }
        };

        $pair = new ProvisionTenantPair(
            $onEnvironment,
            app(\App\Actions\Tenants\EnsureTenantOwner::class),
            app(DeleteTenantPair::class),
        );

        try {
            $pair->create($slug, [
                'name' => 'SSOR Resume Fail '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ]);
            $this->fail('Stage failure should abort provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forced stage failure', $exception->getMessage());
            $this->assertStringContainsString('--environment=stage', $exception->getMessage());
            $this->assertStringContainsString('pair_status=partial', $exception->getMessage());
        }

        $this->assertSame($prodId, $onEnvironment->findBySlugAndEnvironment($slug, 'prod')?->id);
        $this->assertNull($onEnvironment->findBySlugAndEnvironment($slug, 'stage'));
        $this->assertSame('partial', TenantSettings::forTenant(
            $onEnvironment->findBySlugAndEnvironment($slug, 'prod'),
        )->pairStatus());
    }

    #[Test]
    public function onboarding_clears_tenant_id_when_stage_fails_on_fresh_create(): void
    {
        $slug = 'ssor-ob-partial-'.Str::lower(Str::random(6));
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $onEnvironment = new class extends ProvisionTenantOnEnvironment
        {
            public function provision(string $slug, array $attributes, string $environment, array $address = []): Tenant
            {
                if ($environment === 'stage') {
                    throw new RuntimeException('forced stage failure');
                }

                return parent::provision($slug, $attributes, $environment, $address);
            }
        };

        $pair = new ProvisionTenantPair(
            $onEnvironment,
            app(\App\Actions\Tenants\EnsureTenantOwner::class),
            app(DeleteTenantPair::class),
        );
        $action = new ApproveAndProvisionCustomerOnboarding(
            $pair,
            $onEnvironment,
        );

        $onboarding = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Partial '.$slug,
            'contact_email' => $ownerEmail,
        ]);
        $admin = $this->actAsPlatformAdmin();

        try {
            $action->execute($onboarding, [
                'tenant_slug' => $slug,
                'owner_name' => 'Onboard Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password12',
            ], (int) $admin->id);
            $this->fail('Stage failure should abort provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forced stage failure', $exception->getMessage());
            $this->assertStringContainsString('rolled back', strtolower($exception->getMessage()));
        }

        $this->assertNull($onEnvironment->findBySlugAndEnvironment($slug, 'prod'));
        $this->assertNull($onboarding->fresh()?->tenant_id);
        $this->assertNotSame(CustomerOnboardingStatus::Provisioned, $onboarding->fresh()?->status);
        $this->assertNull($onboarding->fresh()?->approved_by_admin_user_id);
    }

    #[Test]
    public function onboarding_cannot_claim_another_onboardings_partial_prod(): void
    {
        $slug = 'ssor-ob-steal-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Steal '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $claimedBy = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Claimed '.$slug,
            'contact_email' => 'claimed-'.$slug.'@example.test',
            'tenant_id' => $prod->id,
        ]);

        $intruder = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Intruder '.$slug,
            'contact_email' => $ownerEmail,
        ]);

        $admin = $this->actAsPlatformAdmin();

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($intruder, [
                'tenant_slug' => $slug,
                'owner_name' => 'Intruder Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password12',
            ], (int) $admin->id);
            $this->fail('Another onboarding partial prod must not be claimable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('linked to another onboarding request', $exception->getMessage());
        }

        $this->assertSame($prod->id, $claimedBy->fresh()?->tenant_id);
        $this->assertNull($intruder->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $intruder->fresh()?->status);
        $this->assertNull(app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage'));
    }

    #[Test]
    public function onboarding_reprovisions_same_slug_after_rejecting_linked_claim(): void
    {
        $slug = 'ssor-ob-reject-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Reject Resume '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $rejected = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Rejected '.$slug,
            'contact_email' => 'rejected-'.$slug.'@example.test',
            'tenant_id' => $prod->id,
        ]);
        $rejected->reject('not a fit');

        $this->assertNull($rejected->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Rejected, $rejected->fresh()?->status);
        $this->assertFalse(TenantPairAvailability::prodClaimedByOnboarding((string) $prod->id));
        $this->assertNull(TenantPairAvailability::validationMessage($slug));

        $successor = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Successor '.$slug,
            'contact_email' => $ownerEmail,
        ]);

        $admin = $this->actAsPlatformAdmin();

        $result = app(ApproveAndProvisionCustomerOnboarding::class)->execute($successor, [
            'tenant_slug' => $slug,
            'owner_name' => 'Successor Owner',
            'owner_email' => $ownerEmail,
            'owner_password' => 'password12',
        ], (int) $admin->id);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertSame($prod->id, $result->id);
        $this->assertNotNull($stage);
        $this->assertSame($prod->id, $successor->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Provisioned, $successor->fresh()?->status);
    }

    #[Test]
    public function onboarding_resumes_a_complete_pair_without_tenant_id(): void
    {
        $slug = 'ssor-ob-complete-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Complete '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $onboarding = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Complete '.$slug,
            'contact_email' => $ownerEmail,
        ]);
        $admin = $this->actAsPlatformAdmin();

        $result = app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
            'tenant_slug' => $slug,
            'owner_name' => 'Onboard Owner',
            'owner_email' => $ownerEmail,
            'owner_password' => 'password12',
        ], (int) $admin->id);

        $this->assertSame($prod->id, $result->id);
        $this->assertSame($prod->id, $onboarding->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Provisioned, $onboarding->fresh()?->status);
        $this->assertSame((int) $admin->id, $onboarding->fresh()?->approved_by_admin_user_id);
    }

    #[Test]
    public function onboarding_refuses_a_foreign_stage_host_even_when_prod_matches(): void
    {
        $slug = 'ssor-ob-foreign-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $unrelated = $this->orphanTenant('other-'.$slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $unrelated->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'company_display_name' => 'SSOR Foreign '.$slug,
        ]);
        $admin = $this->actAsPlatformAdmin();

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => $slug,
                'owner_name' => 'Onboard Owner',
                'owner_email' => 'owner-'.$slug.'@example.test',
                'owner_password' => 'password12',
            ], (int) $admin->id);
            $this->fail('A foreign stage host must block onboarding resume.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already taken', $exception->getMessage());
        }

        $this->assertSame($unrelated->id, Domain::query()
            ->where('domain', TenantHostname::forSlug($slug, 'stage'))
            ->value('tenant_id'));
        $this->assertNull($onboarding->fresh()?->approved_by_admin_user_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->fresh()?->status);
    }

    #[Test]
    public function onboarding_refuses_a_complete_pair_already_linked_to_another_application(): void
    {
        $slug = 'ssor-ob-claimed-'.Str::lower(Str::random(6));
        $prod = $this->orphanTenant($slug, 'prod');
        $stage = $this->orphanTenant($slug, 'stage');
        $prod->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);
        $stage->domains()->create(['domain' => TenantHostname::forSlug($slug, 'stage')]);

        $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'company_display_name' => 'SSOR Claimed '.$slug,
        ]);

        $intruder = $this->submittedOnboarding([
            'company_display_name' => 'SSOR Intruder '.$slug,
        ]);
        $admin = $this->actAsPlatformAdmin();

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($intruder, [
                'tenant_slug' => $slug,
                'owner_name' => 'Intruder',
                'owner_email' => 'intruder-'.$slug.'@example.test',
                'owner_password' => 'password12',
            ], (int) $admin->id);
            $this->fail('A claimed complete pair must not be resumed by another application.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already taken', $exception->getMessage());
        }

        $this->assertNull($intruder->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $intruder->fresh()?->status);
    }

    #[Test]
    public function onboarding_refuses_to_attach_a_pair_host_onto_an_unrelated_tenant(): void
    {
        $slug = 'ssor-ob-attach-'.Str::lower(Str::random(6));
        $unrelated = $this->orphanTenant();
        $domainCount = $unrelated->domains()->count();

        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $unrelated->id,
            'company_display_name' => 'SSOR Attach '.$slug,
        ]);
        $admin = $this->actAsPlatformAdmin();

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => $slug,
                'owner_name' => 'Onboard Owner',
                'owner_email' => 'owner-'.$slug.'@example.test',
                'owner_password' => 'password12',
            ], (int) $admin->id);
            $this->fail('Unrelated tenant_id must not receive a pair host.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different tenant', strtolower($exception->getMessage()));
        }

        $this->assertSame($domainCount, $unrelated->domains()->count());
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first());
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->first());
        $this->assertSame($unrelated->id, $onboarding->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->fresh()?->status);
    }

    #[Test]
    public function admin_create_resumes_a_matching_prod_only_slug(): void
    {
        $slug = 'ssor-lw-resume-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $prod = app(ProvisionTenantOnEnvironment::class)->provision($slug, [
            'name' => 'SSOR Livewire Resume '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], 'prod');

        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'SSOR Livewire Resume '.$slug,
                'profile' => TenantProfile::Pharmacy->value,
                'status' => 'active',
                'tenant_slug' => $slug,
                'owner_name' => 'Livewire Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password12',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);
        $this->assertSame($prod->id, app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'prod')?->id);
    }

    #[Test]
    public function deleting_one_pair_sibling_deletes_the_other(): void
    {
        $slug = 'ssor-del-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR Delete '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);
        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);

        $prodId = $prod->id;
        $stageId = $stage->id;

        $onboarding = $this->submittedOnboarding([
            'tenant_id' => $prod->id,
            'status' => CustomerOnboardingStatus::Provisioned,
            'provisioned_at' => now(),
            'company_display_name' => 'SSOR Delete '.$slug,
        ]);

        app(DeleteTenantPair::class)->deleteSibling($prod);
        $prod->delete();

        $this->assertNull(Tenant::query()->find($prodId));
        $this->assertNull(Tenant::query()->find($stageId));
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'prod'))->first());
        $this->assertNull(Domain::query()->where('domain', TenantHostname::forSlug($slug, 'stage'))->first());

        $onboarding->refresh();
        $this->assertNull($onboarding->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Submitted, $onboarding->status);
        $this->assertTrue($onboarding->isProvisionable());
    }

    #[Test]
    public function onboarding_provision_creates_both_hosts_and_owners(): void
    {
        $slug = 'ssor-ob-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'SSOR Onboard LLC',
            'company_display_name' => 'SSOR Onboard '.$slug,
            'contact_name' => 'Onboard Owner',
            'contact_email' => $ownerEmail,
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        $admin = $this->actAsPlatformAdmin();

        $prod = app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
            'tenant_slug' => $slug,
            'owner_name' => 'Onboard Owner',
            'owner_email' => $ownerEmail,
            'owner_password' => 'password12',
        ], (int) $admin->id);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);
        $this->assertSame($prod->id, $onboarding->fresh()?->tenant_id);
        $this->assertSame(CustomerOnboardingStatus::Provisioned, $onboarding->fresh()?->status);

        foreach ([$prod, $stage] as $tenant) {
            $tenant->run(function () use ($ownerEmail): void {
                $user = User::query()->where('email', $ownerEmail)->first();
                $this->assertNotNull($user);
                $this->assertTrue($user->hasRole(TenantRole::Owner->value));
            });
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submittedOnboarding(array $overrides = []): CustomerOnboarding
    {
        $onboarding = CustomerOnboarding::query()->create(array_merge([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'SSOR Onboard LLC',
            'company_display_name' => 'SSOR Onboard',
            'contact_name' => 'Onboard Owner',
            'contact_email' => 'onboard@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ], $overrides));
        $this->onboardingIds[] = (int) $onboarding->id;

        return $onboarding;
    }

    private function orphanTenant(?string $pairSlug = null, string $environment = 'prod'): Tenant
    {
        $id = (string) Str::uuid();
        $this->orphanTenantIds[] = $id;

        $attributes = [
            'id' => $id,
            'name' => 'Unrelated onboarding tenant',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_unrelated_'.substr(str_replace('-', '', $id), 0, 16),
            'inbound_environment' => $environment,
        ];

        if ($pairSlug !== null) {
            $attributes['tenant_pair_slug'] = $pairSlug;
            $attributes['tenant_pair_environment'] = $environment;
        }

        return Tenant::withoutEvents(fn () => Tenant::query()->create($attributes));
    }

    #[Test]
    public function stage_host_is_replicated_to_pair_sibling_central(): void
    {
        $sibling = 'tracepharma_test_sibling';
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$sibling}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::statement("DROP TABLE IF EXISTS `{$sibling}`.domains");
        DB::statement("DROP TABLE IF EXISTS `{$sibling}`.tenants");
        DB::statement("CREATE TABLE `{$sibling}`.tenants LIKE tenants");
        DB::statement("CREATE TABLE `{$sibling}`.domains LIKE domains");

        config([
            'tracepharma.pair_sibling_database' => $sibling,
            'tracepharma.tenant_environment' => 'prod',
            'database.connections.pair_sibling.driver' => 'mysql',
            'database.connections.pair_sibling.database' => $sibling,
        ]);
        DB::purge('pair_sibling');

        $slug = 'ssor-sib-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        try {
            app(ProvisionTenantPair::class)->create($slug, [
                'name' => 'Sibling Sync '.$slug,
                'profile' => TenantProfile::Pharmacy,
            ]);

            $this->assertTrue(
                DB::connection('pair_sibling')->table('domains')
                    ->where('domain', TenantHostname::forSlug($slug, 'stage'))
                    ->exists()
            );
            $this->assertFalse(
                DB::connection('pair_sibling')->table('domains')
                    ->where('domain', TenantHostname::forSlug($slug, 'prod'))
                    ->exists()
            );
        } finally {
            DB::connection('pair_sibling')->table('domains')->delete();
            DB::connection('pair_sibling')->table('tenants')->delete();
        }
    }

    private function destroyPair(string $slug): void
    {
        foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
            $domain = Domain::query()
                ->where('domain', TenantHostname::forSlug($slug, $environment))
                ->first();

            if ($domain === null) {
                continue;
            }

            Tenant::query()->find($domain->tenant_id)?->delete();
        }
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

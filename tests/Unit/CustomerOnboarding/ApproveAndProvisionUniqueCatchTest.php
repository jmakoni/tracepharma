<?php

namespace Tests\Unit\CustomerOnboarding;

use App\Actions\CustomerOnboarding\ApproveAndProvisionCustomerOnboarding;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\CustomerOnboardingStatus;
use App\Models\CustomerOnboarding;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ApproveAndProvisionUniqueCatchTest extends TestCase
{
    /** @var list<int> */
    private array $onboardingIds = [];

    protected function tearDown(): void
    {
        if ($this->onboardingIds !== []) {
            CustomerOnboarding::query()->whereIn('id', $this->onboardingIds)->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function a_tenant_id_unique_violation_is_remapped_to_already_linked(): void
    {
        $onboarding = $this->submittedOnboarding();
        $this->bindPairThatThrows($this->uniqueViolation('customer_onboardings_tenant_id_unique'));

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => 'ob-uniq-'.Str::lower(Str::random(6)),
                'owner_name' => 'Owner',
                'owner_email' => 'owner-'.Str::lower(Str::random(6)).'@example.test',
                'owner_password' => 'password12',
            ], 1);
            $this->fail('Tenant id unique must be remapped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already linked to another onboarding', $exception->getMessage());
        }
    }

    #[Test]
    public function a_domain_unique_violation_is_not_remapped_to_already_linked(): void
    {
        $onboarding = $this->submittedOnboarding();
        $this->bindPairThatThrows($this->uniqueViolation('domains_domain_unique'));

        try {
            app(ApproveAndProvisionCustomerOnboarding::class)->execute($onboarding, [
                'tenant_slug' => 'ob-dom-'.Str::lower(Str::random(6)),
                'owner_name' => 'Owner',
                'owner_email' => 'owner-'.Str::lower(Str::random(6)).'@example.test',
                'owner_password' => 'password12',
            ], 1);
            $this->fail('Domain unique must not be remapped.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame('domains_domain_unique', $exception->index);
        } catch (RuntimeException $exception) {
            $this->fail('Domain unique was remapped: '.$exception->getMessage());
        }
    }

    private function bindPairThatThrows(UniqueConstraintViolationException $exception): void
    {
        $pair = Mockery::mock(ProvisionTenantPair::class);
        $pair->shouldReceive('create')->once()->andThrow($exception);
        $this->app->instance(ProvisionTenantPair::class, $pair);
    }

    private function uniqueViolation(string $index): UniqueConstraintViolationException
    {
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key \''.$index.'\'');

        return (new UniqueConstraintViolationException(
            'mysql',
            'insert into example',
            [],
            $previous,
        ))->setIndex($index);
    }

    private function submittedOnboarding(): CustomerOnboarding
    {
        $onboarding = CustomerOnboarding::query()->create([
            'status' => CustomerOnboardingStatus::Submitted,
            'legal_company_name' => 'Unique Catch LLC',
            'company_display_name' => 'Unique Catch',
            'contact_name' => 'Owner',
            'contact_email' => 'uniq-'.Str::lower(Str::random(8)).'@example.test',
            'organization_type' => 'independent_pharmacy',
            'terms_version' => TermsOfService::version(),
            'privacy_version' => PrivacyPolicy::version(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
        $this->onboardingIds[] = (int) $onboarding->id;

        return $onboarding;
    }
}

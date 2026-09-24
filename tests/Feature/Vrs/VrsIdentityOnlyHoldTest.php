<?php

namespace Tests\Feature\Vrs;

use App\Actions\Vrs\RunProductVerification;
use App\Enums\TenantProfile;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\ExceptionTypeSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VrsIdentityOnlyHoldTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GTIN14 = '30301164005162';

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    #[Test]
    public function identity_only_vrs_fail_opens_a_documentless_hold(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $serial = 'FAIL-IDONLY-'.uniqid();
            $result = app(RunProductVerification::class)->handle(
                '(01)'.self::GTIN14.'(21)'.$serial,
                $user,
            );

            $this->assertNotNull($result['exception_id']);
            $this->exceptionIds[] = (int) $result['exception_id'];
            $this->verificationIds[] = (int) $result['verification']->getKey();

            $hold = QuarantineHold::query()
                ->where('exception_id', $result['exception_id'])
                ->where('status', 'open')
                ->first();

            $this->assertNotNull($hold, 'Identity-only VRS fail must open a hold.');
            $this->assertNull($hold->epc_id);
            $this->assertSame($serial, data_get($hold->meta, 'serial'));
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

        tenancy()->initialize($tenant);
        (new ExceptionTypeSeeder)->run();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

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

        tenancy()->end();
    }
}

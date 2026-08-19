<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\EnsureManufacturerPartnerFromCatalog;
use App\Actions\MasterData\EnsureWholesalerPartnerFromCatalog;
use App\Models\Fda\FdaOrganization;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deleted FDA organization leaves tenant `fda_organization_id` values pointing at
 * nothing, and the admin panel cannot reach tenant databases to heal them. The ensure
 * actions are on the ingest path, so a dangling id has to degrade to "no partner
 * mirrored" rather than take the whole import down.
 */
class EnsurePartnerFromMissingCatalogTest extends TestCase
{
    #[Test]
    public function a_missing_fda_manufacturer_organization_yields_no_partner_and_a_warning(): void
    {
        $missingId = $this->missingFdaOrganizationId();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($missingId): bool {
                return str_contains($message, 'no longer exists')
                    && $context['fda_organization_id'] === $missingId;
            });

        $this->assertNull(app(EnsureManufacturerPartnerFromCatalog::class)->handle($missingId));
    }

    #[Test]
    public function a_missing_fda_wholesaler_organization_yields_no_partner_and_a_warning(): void
    {
        $missingId = $this->missingFdaOrganizationId();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($missingId): bool {
                return str_contains($message, 'no longer exists')
                    && $context['fda_organization_id'] === $missingId;
            });

        $this->assertNull(app(EnsureWholesalerPartnerFromCatalog::class)->handle($missingId));
    }

    private function missingFdaOrganizationId(): int
    {
        return ((int) FdaOrganization::query()->max('id')) + 1_000_000;
    }
}

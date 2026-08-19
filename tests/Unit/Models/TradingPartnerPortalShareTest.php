<?php

namespace Tests\Unit\Models;

use App\Models\TradingPartner;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TradingPartnerPortalShareTest extends TestCase
{
    #[Test]
    public function the_portal_share_uuid_is_not_mass_assignable(): void
    {
        $partner = new TradingPartner;

        $this->assertFalse($partner->isFillable('portal_share_uuid'));
    }

    #[Test]
    public function filling_a_partner_cannot_set_a_portal_share_uuid(): void
    {
        $partner = new TradingPartner;
        $partner->fill([
            'name' => 'Assortment Partner',
            'portal_share_uuid' => 'aa11bb22-cccc-dddd-eeee-ff0011223344',
        ]);

        $this->assertSame('Assortment Partner', $partner->name);
        $this->assertNull($partner->portal_share_uuid);
    }

    #[Test]
    public function the_portal_index_route_is_signed_and_throttled(): void
    {
        $middleware = Route::getRoutes()
            ->getByName('tenant.supplier-exceptions.index')
            ->gatherMiddleware();

        $this->assertContains('signed', $middleware);
        $this->assertContains('throttle:20,1', $middleware);
    }
}

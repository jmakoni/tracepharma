<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Epcis;

use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Epcis\EpcisXmlReader;
use App\Support\Epcis\ResolveEpcisUploadShippingSites;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisApiSiteAccessTest extends TestCase
{
    #[Test]
    public function assert_store_allowed_fails_closed_when_outbound_ship_from_site_is_null(): void
    {
        $access = $this->makeAccess();

        $this->expectException(AuthorizationException::class);

        // Missing file → resolver returns null ship-from / ship-to.
        $access->assertStoreAllowed($this->restrictedUser(), '/tmp/does-not-exist-epcis.xml', 'outbound');
    }

    #[Test]
    public function assert_store_allowed_fails_closed_when_inbound_ship_to_site_is_null(): void
    {
        $access = $this->makeAccess();

        $this->expectException(AuthorizationException::class);

        $access->assertStoreAllowed($this->restrictedUser(), '/tmp/does-not-exist-epcis.xml', 'inbound');
    }

    #[Test]
    public function assert_store_allowed_skips_checks_for_sites_access_all_users(): void
    {
        $access = $this->makeAccess();

        $access->assertStoreAllowed($this->accessAllUser(), '/tmp/does-not-exist-epcis.xml', 'outbound');
        $this->assertTrue(true);
    }

    private function makeAccess(): EpcisApiSiteAccess
    {
        return new EpcisApiSiteAccess(
            new ResolveEpcisUploadShippingSites(app(EpcisXmlReader::class)),
        );
    }

    private function restrictedUser(): User
    {
        return new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return false;
            }
        };
    }

    private function accessAllUser(): User
    {
        return new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return $ability === Permissions::SitesAccessAll;
            }
        };
    }
}

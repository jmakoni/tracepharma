<?php

namespace Tests\Feature;

use App\Actions\Fda\PromoteFdaWdd3plToCatalogSites;
use App\Models\Fda\FdaWdd3plStaging;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catalog promotion is retired. WDD facilities come from ImportFdaWddToRegistry.
 * This file used to be a 24-case catalog write suite; it now documents the no-op.
 */
class PromoteFdaWdd3plToCatalogSitesTest extends TestCase
{
    #[Test]
    public function promote_is_a_noop_and_does_not_create_catalog_sites(): void
    {
        $stagingBefore = FdaWdd3plStaging::query()->count();

        $counts = app(PromoteFdaWdd3plToCatalogSites::class)->handle();

        $this->assertSame(0, $counts['processed']);
        $this->assertSame(0, $counts['sites_created']);
        $this->assertSame(0, $counts['sites_matched']);
        $this->assertSame(0, $counts['licenses_upserted']);
        $this->assertSame(0, $counts['licenses_relocated']);
        $this->assertSame(0, $counts['licenses_delisted']);
        $this->assertSame($stagingBefore, FdaWdd3plStaging::query()->count());
    }

    #[Test]
    public function force_and_dry_run_are_also_noops(): void
    {
        $this->assertSame(0, app(PromoteFdaWdd3plToCatalogSites::class)->handle(true, true)['processed']);
        $this->assertSame(0, app(PromoteFdaWdd3plToCatalogSites::class)->handle(false, true)['processed']);
    }
}

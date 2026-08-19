<?php

namespace Tests\Unit;

use App\Support\MasterData\MajorWholesalers;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MajorWholesalersTest extends TestCase
{
    #[Test]
    public function definitions_returns_six_major_wholesalers(): void
    {
        $definitions = MajorWholesalers::definitions();

        $this->assertCount(6, $definitions);
        $this->assertSame(
            ['mckesson', 'cardinal-health', 'cencora', 'anda', 'morris-dickson', 'smith-drug'],
            MajorWholesalers::slugs(),
        );
    }

    /**
     * The FDA report names the licensed entity and its branch, which is not a name
     * any catalog organization carries.
     */
    #[Test]
    public function wdd_entity_slugs_roll_up_to_a_top_six_slug(): void
    {
        $this->assertSame('mckesson', MajorWholesalers::canonicalSlug('mckesson-corp-anchorage'));
        $this->assertSame('mckesson', MajorWholesalers::canonicalSlug('mckesson-drug-co'));
        $this->assertSame('mckesson', MajorWholesalers::canonicalSlug('mckesson'));
        $this->assertSame('cardinal-health', MajorWholesalers::canonicalSlug('cardinal-health-110-llc'));
        $this->assertSame('cencora', MajorWholesalers::canonicalSlug('amerisourcebergen-drug-corp'));
        $this->assertSame('cencora', MajorWholesalers::canonicalSlug('cencora-global-procurement-ltd'));
        $this->assertSame('anda', MajorWholesalers::canonicalSlug('anda-inc'));
        $this->assertSame('morris-dickson', MajorWholesalers::canonicalSlug('morris-dickson-co-llc'));
        $this->assertSame('smith-drug', MajorWholesalers::canonicalSlug('j-m-smith-corp'));

        $this->assertNull(MajorWholesalers::canonicalSlug('mckessonx-holdings'));
        $this->assertNull(MajorWholesalers::canonicalSlug('unrelated-regional-distributor'));
        $this->assertNull(MajorWholesalers::canonicalSlug(''));
    }

    #[Test]
    public function sentinel_roundtrips_catalog_id(): void
    {
        $catalogId = 42;
        $sentinel = MajorWholesalers::sentinel($catalogId);

        $this->assertSame('fda_wholesaler:42', $sentinel);
        $this->assertTrue(MajorWholesalers::isSentinel($sentinel));
        $this->assertSame($catalogId, MajorWholesalers::catalogIdFromSentinel($sentinel));
        $this->assertSame(42, MajorWholesalers::catalogIdFromSentinel('catalog_wholesaler:42'));
        $this->assertNull(MajorWholesalers::catalogIdFromSentinel(99));
        $this->assertNull(MajorWholesalers::catalogIdFromSentinel('catalog_wholesaler:'));
        $this->assertFalse(MajorWholesalers::isSentinel('99'));
    }
}

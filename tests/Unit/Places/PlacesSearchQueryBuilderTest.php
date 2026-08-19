<?php

namespace Tests\Unit\Places;

use App\Support\Places\PlacesSearchQueryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PlacesSearchQueryBuilderTest extends TestCase
{
    private PlacesSearchQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PlacesSearchQueryBuilder;
    }

    #[Test]
    public function includes_original_and_legal_suffix_stripped_variant(): void
    {
        $queries = $this->builder->queries('Amneal Pharmaceuticals LLC');

        $this->assertSame('Amneal Pharmaceuticals LLC', $queries[0]);
        $this->assertContains('Amneal Pharmaceuticals', $queries);
        $this->assertLessThanOrEqual(4, count($queries));
    }

    #[Test]
    public function extracts_parenthetical_alias_for_masco_mid_style_names(): void
    {
        $queries = $this->builder->queries(
            'The Egyptian Saudi Co. For Medical Manufacturing (Masco-Mid) S.A.E'
        );

        $this->assertSame(
            'The Egyptian Saudi Co. For Medical Manufacturing (Masco-Mid) S.A.E',
            $queries[0]
        );
        $this->assertContains('Masco-Mid', $queries);
        $this->assertLessThanOrEqual(4, count($queries));
    }

    #[Test]
    public function deduplicates_case_insensitively_and_caps_at_four(): void
    {
        $queries = $this->builder->queries('Metcure Pharmaceuticals, Inc.');

        $normalized = array_map(strtolower(...), $queries);
        $this->assertSame($normalized, array_values(array_unique($normalized)));
        $this->assertCount(4, $queries);
        $this->assertContains('Metcure Pharmaceuticals pharmaceutical', $queries);
    }

    #[Test]
    public function skips_empty_and_too_short_variants(): void
    {
        $queries = $this->builder->queries('AB');

        $this->assertSame([], $queries);
    }

    #[Test]
    public function strips_chained_trailing_legal_suffixes(): void
    {
        $queries = $this->builder->queries('Acme Pharma Corp., Inc.');

        $this->assertContains('Acme Pharma', $queries);
    }
}

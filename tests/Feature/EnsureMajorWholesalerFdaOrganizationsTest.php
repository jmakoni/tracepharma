<?php

namespace Tests\Feature;

use App\Actions\Catalog\EnsureMajorWholesalerFdaOrganizations;
use App\Models\Fda\FdaOrganization;
use App\Support\MasterData\MajorWholesalers;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureMajorWholesalerFdaOrganizationsTest extends TestCase
{
    #[Test]
    public function ensures_six_fda_organizations_by_definition_gln_without_writing_catalog(): void
    {
        $ensured = app(EnsureMajorWholesalerFdaOrganizations::class)->handle();

        $this->assertSame(6, $ensured);

        $glns = array_column(MajorWholesalers::definitions(), 'gln');
        $organizations = FdaOrganization::query()->whereIn('gln', $glns)->get();
        $this->assertCount(6, $organizations);

        foreach ($organizations as $organization) {
            $this->assertTrue(filled($organization->name));
        }

        $this->assertSame(
            $organizations->where('is_active', true)->count(),
            MajorWholesalers::fdaOrganizations()->count()
        );
    }

    #[Test]
    public function second_run_is_idempotent_and_still_writes_no_catalog_partners(): void
    {
        app(EnsureMajorWholesalerFdaOrganizations::class)->handle();

        $ids = FdaOrganization::query()
            ->whereIn('gln', array_column(MajorWholesalers::definitions(), 'gln'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $ensured = app(EnsureMajorWholesalerFdaOrganizations::class)->handle();

        $this->assertSame(6, $ensured);
        $this->assertSame(
            $ids,
            FdaOrganization::query()
                ->whereIn('gln', array_column(MajorWholesalers::definitions(), 'gln'))
                ->orderBy('id')
                ->pluck('id')
                ->all()
        );
    }
}

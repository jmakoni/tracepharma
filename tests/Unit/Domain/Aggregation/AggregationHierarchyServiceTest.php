<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Aggregation;

use App\Domain\Aggregation\AggregationHierarchyService;
use App\Domain\Aggregation\HierarchyLink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AggregationHierarchyServiceTest extends TestCase
{
    private AggregationHierarchyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AggregationHierarchyService;
    }

    #[Test]
    public function it_rebuilds_a_two_level_tree(): void
    {
        $roots = $this->service->rebuildFromLinks([
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.1'),
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.2'),
        ]);

        $this->assertCount(1, $roots);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $roots[0]->epcUri);
        $this->assertCount(2, $roots[0]->children);
    }

    #[Test]
    public function it_detects_orphans_when_parent_removed_leaving_live_children(): void
    {
        $roots = $this->service->rebuildFromLinks([
            ['parent' => 'urn:epc:id:sscc:030116.01001235403', 'child' => 'urn:epc:id:sgtin:030116.3400516.1'],
            ['parent' => 'urn:epc:id:sscc:030116.01001235403', 'child' => 'urn:epc:id:sgtin:030116.3400516.2'],
        ]);

        $report = $this->service->detectDriftAfterDecommission(
            $roots[0],
            ['urn:epc:id:sscc:030116.01001235403'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertContains('urn:epc:id:sgtin:030116.3400516.1', $report->orphanedUris);
        $this->assertContains('urn:epc:id:sgtin:030116.3400516.2', $report->orphanedUris);
        $this->assertContains('urn:epc:id:sscc:030116.01001235403', $report->brokenParentRefs);
        $this->assertSame(2, $report->quantityGapsByParent['urn:epc:id:sscc:030116.01001235403'] ?? 0);
    }

    #[Test]
    public function leaf_only_removal_reports_quantity_gap_without_broken_parent(): void
    {
        $roots = $this->service->rebuildFromLinks([
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.1'),
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.2'),
        ]);

        $report = $this->service->detectDriftAfterDecommission(
            $roots[0],
            ['urn:epc:id:sgtin:030116.3400516.1'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame([], $report->orphanedUris);
        $this->assertSame([], $report->brokenParentRefs);
        $this->assertSame(1, $report->quantityGapsByParent['urn:epc:id:sscc:030116.01001235403'] ?? 0);
    }

    #[Test]
    public function full_subtree_removal_reports_no_drift(): void
    {
        $roots = $this->service->rebuildFromLinks([
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.1'),
            new HierarchyLink('urn:epc:id:sscc:030116.01001235403', 'urn:epc:id:sgtin:030116.3400516.2'),
        ]);

        $report = $this->service->detectDriftAfterDecommission(
            $roots[0],
            [
                'urn:epc:id:sscc:030116.01001235403',
                'urn:epc:id:sgtin:030116.3400516.1',
                'urn:epc:id:sgtin:030116.3400516.2',
            ],
        );

        $this->assertFalse($report->hasDrift());
    }

    #[Test]
    public function it_reports_no_drift_when_nothing_removed(): void
    {
        $roots = $this->service->rebuildFromLinks([
            [0 => 'urn:epc:id:sscc:030116.01001235403', 1 => 'urn:epc:id:sgtin:030116.3400516.1'],
        ]);

        $report = $this->service->detectDriftAfterDecommission($roots[0], []);

        $this->assertFalse($report->hasDrift());
    }

    #[Test]
    public function three_level_removal_of_case_subtree_under_live_pallet_reports_quantity_gap(): void
    {
        $pallet = 'urn:epc:id:sscc:030116.01001235403';
        $case = 'urn:epc:id:sscc:030116.01001235404';
        $unit1 = 'urn:epc:id:sgtin:030116.3400516.1';
        $unit2 = 'urn:epc:id:sgtin:030116.3400516.2';
        $case2 = 'urn:epc:id:sscc:030116.01001235405';

        $roots = $this->service->rebuildFromLinks([
            new HierarchyLink($pallet, $case),
            new HierarchyLink($pallet, $case2),
            new HierarchyLink($case, $unit1),
            new HierarchyLink($case, $unit2),
        ]);

        $report = $this->service->detectDriftAfterDecommission(
            $roots[0],
            [$case, $unit1, $unit2],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame(1, $report->quantityGapsByParent[$pallet] ?? 0);
        $this->assertSame([], $report->orphanedUris);
    }

    #[Test]
    public function three_level_full_tree_removal_reports_no_drift(): void
    {
        $pallet = 'urn:epc:id:sscc:030116.01001235403';
        $case = 'urn:epc:id:sscc:030116.01001235404';
        $unit1 = 'urn:epc:id:sgtin:030116.3400516.1';

        $roots = $this->service->rebuildFromLinks([
            new HierarchyLink($pallet, $case),
            new HierarchyLink($case, $unit1),
        ]);

        $report = $this->service->detectDriftAfterDecommission(
            $roots[0],
            [$pallet, $case, $unit1],
        );

        $this->assertFalse($report->hasDrift());
    }
}

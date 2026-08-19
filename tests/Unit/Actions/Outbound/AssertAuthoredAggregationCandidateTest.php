<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\AssertAuthoredAggregationCandidate;
use App\Domain\Epcis\Enums\EpcisAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssertAuthoredAggregationCandidateTest extends TestCase
{
    #[Test]
    public function test_accepts_valid_packing_aggregation(): void
    {
        $data = app(AssertAuthoredAggregationCandidate::class)->handle(
            parentUri: 'urn:epc:id:sscc:030116.00000210167',
            childEpcs: ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            action: EpcisAction::Add,
            bizStep: 'packing',
            disposition: 'in_progress',
        );

        $this->assertSame('urn:epc:id:sscc:030116.00000210167', $data->parentId);
        $this->assertCount(1, $data->childEpcs);
    }

    #[Test]
    public function test_accepts_class_only_lgtin_quantity_children(): void
    {
        $data = app(AssertAuthoredAggregationCandidate::class)->handle(
            parentUri: 'urn:epc:id:sscc:030116.00000210168',
            childEpcs: [],
            action: EpcisAction::Add,
            bizStep: 'packing',
            disposition: 'in_progress',
            quantityChildren: [[
                'epcClass' => 'urn:epc:class:lgtin:4054739.099902.P2',
                'quantity' => 12,
                'uom' => 'KGM',
            ]],
        );

        $this->assertNotEmpty($data->childQuantityList);
    }

    #[Test]
    public function test_rejects_malformed_parent_uri(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AssertAuthoredAggregationCandidate::class)->handle(
            parentUri: 'not-a-valid-epc-uri',
            childEpcs: ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            action: EpcisAction::Add,
            bizStep: 'packing',
            disposition: 'in_progress',
        );
    }
}

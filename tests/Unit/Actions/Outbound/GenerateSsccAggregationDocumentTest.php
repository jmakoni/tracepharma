<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccAggregationDocument;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccAggregationDocumentTest extends TestCase
{
    private const TEST_SETTINGS = ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'];

    #[Test]
    public function test_builds_aggregation_document_for_batch(): void
    {
        $batch = new SsccLabelBatch([
            'company_prefix' => '030116',
            'extension_digit' => '0',
        ]);
        $batch->id = 1;

        $label = new SsccLabel([
            'sscc_18' => '003011600002101675',
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
            'extension_digit' => '0',
            'company_prefix' => '030116',
        ]);
        $label->id = 10;

        $child = new SsccLabelChild([
            'child_epc' => 'urn:epc:id:sgtin:030116.5200116.00000000413101',
        ]);

        $label->setRelation('children', new Collection([$child]));
        $batch->setRelation('labels', new Collection([$label]));

        $xml = app(GenerateSsccAggregationDocument::class)->forBatch($batch, settings: self::TEST_SETTINGS);

        $this->assertStringContainsString('<AggregationEvent>', $xml);
        $this->assertStringContainsString('<action>ADD</action>', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.00000210167', $xml);
        $this->assertStringContainsString('urn:epc:id:sgtin:030116.5200116.00000000413101', $xml);
        $this->assertStringContainsString('EPCISDocument', $xml);
    }
}

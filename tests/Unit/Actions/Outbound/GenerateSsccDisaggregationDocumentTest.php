<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccDisaggregationDocument;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccDisaggregationDocumentTest extends TestCase
{
    private const TEST_SETTINGS = ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'];

    #[Test]
    public function test_builds_delete_aggregation_document_for_source_pallet(): void
    {
        $xml = app(GenerateSsccDisaggregationDocument::class)->forSourcePallet(
            'urn:epc:id:sscc:030116.00000210167',
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            settings: self::TEST_SETTINGS,
        );

        $this->assertStringContainsString('<action>DELETE</action>', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.00000210167', $xml);
        $this->assertStringContainsString('urn:epc:id:sgtin:030116.5200116.00000000413101', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:unpacking', $xml);
    }

    #[Test]
    public function test_builds_disaggregation_document_from_batch(): void
    {
        $batch = new SsccLabelBatch([
            'company_prefix' => '030116',
            'extension_digit' => '0',
            'source_parent_sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);
        $batch->id = 2;

        $label = new SsccLabel([
            'sscc_18' => '003011600002101683',
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210168',
        ]);
        $label->id = 20;

        $child = new SsccLabelChild([
            'child_epc' => 'urn:epc:id:sgtin:030116.5200116.00000000413101',
        ]);

        $label->setRelation('children', new Collection([$child]));
        $batch->setRelation('labels', new Collection([$label]));

        $xml = app(GenerateSsccDisaggregationDocument::class)->forBatch($batch, settings: self::TEST_SETTINGS);

        $this->assertStringContainsString('<action>DELETE</action>', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.00000210167', $xml);
    }
}

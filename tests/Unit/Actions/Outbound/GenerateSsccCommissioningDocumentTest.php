<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccCommissioningDocument;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccCommissioningDocumentTest extends TestCase
{
    #[Test]
    public function test_builds_commissioning_document_for_batch_labels(): void
    {
        $batch = new SsccLabelBatch([
            'company_prefix' => '030116',
            'extension_digit' => '0',
        ]);
        $batch->id = 5;

        $label = new SsccLabel([
            'sscc_18' => '003011610012354038',
            'sscc_urn' => 'urn:epc:id:sscc:030116.01001235403',
        ]);
        $label->id = 50;

        $xml = app(GenerateSsccCommissioningDocument::class)->forBatch(
            $batch,
            new Collection([$label]),
            settings: ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'],
        );

        $this->assertStringContainsString('<ObjectEvent>', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:commissioning', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.01001235403', $xml);
        $this->assertStringContainsString('EPCISDocument', $xml);
    }
}

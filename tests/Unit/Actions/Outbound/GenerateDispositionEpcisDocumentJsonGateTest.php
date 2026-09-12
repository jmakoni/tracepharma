<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Support\Epcis\EpcisSchemaVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateDispositionEpcisDocumentJsonGateTest extends TestCase
{
    #[Test]
    public function json_20_path_runs_hard_gate_before_emit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GenerateDispositionEpcisDocument::class)->execute(
            epcUris: ['urn:epc:id:sgtin:not-a-valid-uri'],
            kind: GenerateDispositionObjectEvent::KIND_COMMISSIONING,
            siteId: null,
            correlationId: null,
            settings: [
                'epcis_document_version' => EpcisSchemaVersion::V20,
                'sgln_urn' => 'urn:epc:id:sgln:030116.000001.0',
            ],
        );
    }
}

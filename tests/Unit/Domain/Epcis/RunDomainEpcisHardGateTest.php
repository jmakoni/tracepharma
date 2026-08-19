<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Epcis;

use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Domain\Epcis\Validation\ValidationPipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RunDomainEpcisHardGateTest extends TestCase
{
    #[Test]
    public function validate_candidate_does_not_persist_on_failure(): void
    {
        $gate = new RunDomainEpcisHardGate(ValidationPipeline::default());

        $result = $gate->validateCandidate([
            [
                'event_type' => 'ObjectEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'epc_list' => ['bad-uri'],
            ],
        ]);

        $this->assertTrue($result->isFailed());
        $this->assertSame('INVALID_EPC_URI', $result->failure?->code);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Epcis;

use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationPipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ValidationPipelineTest extends TestCase
{
    #[Test]
    public function it_passes_a_valid_object_event_candidate(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'ObjectEvent',
                'action' => 'OBSERVE',
                'event_time' => '2026-08-12T16:00:00Z',
                'epc_list' => ['urn:epc:id:sgtin:030116.3400516.10000002877732'],
                'biz_step' => 'receiving',
                'disposition' => 'in_progress',
            ],
        ]));

        $this->assertTrue($result->passed);
        $this->assertNull($result->failure);
    }

    #[Test]
    public function it_short_circuits_on_syntax_before_gs1(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'ObjectEvent',
                'action' => 'NOPE',
                'event_time' => '2026-08-12T16:00:00Z',
                'epc_list' => ['not-even-checked'],
            ],
        ]));

        $this->assertTrue($result->isFailed());
        $this->assertSame('syntax', $result->failure?->stage);
        $this->assertSame('INVALID_ACTION', $result->failure?->code);
    }

    #[Test]
    public function it_fails_gs1_stage_on_bad_uri(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'ObjectEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'epc_list' => ['urn:epc:id:sgtin:030116.340051.1'],
            ],
        ]));

        $this->assertTrue($result->isFailed());
        $this->assertSame('gs1_schema', $result->failure?->stage);
        $this->assertSame('INVALID_EPC_URI', $result->failure?->code);
    }

    #[Test]
    public function it_fails_business_rules_for_empty_aggregation_children_on_add(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
            ],
        ]));

        $this->assertTrue($result->isFailed());
        $this->assertSame('business_rules', $result->failure?->stage);
        $this->assertSame('AGGREGATION_MISSING_CHILDREN', $result->failure?->code);
    }

    #[Test]
    public function it_allows_aggregation_delete_with_empty_children(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'DELETE',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
            ],
        ]));

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function it_allows_aggregation_add_with_quantity_list_only(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
                'child_quantity_list' => [
                    ['epc_class' => 'urn:epc:idpat:sgtin:030116.3400516.*', 'quantity' => 10],
                ],
            ],
        ]));

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function it_allows_aggregation_add_with_quantity_list_key_alias(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
                'quantity_list' => [
                    ['epc_class' => 'urn:epc:idpat:sgtin:030116.3400516.*', 'quantity' => 10],
                ],
            ],
        ]));

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function it_rejects_garbage_quantity_epc_class_in_gs1_stage(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
                'child_quantity_list' => [
                    ['epc_class' => 'not-a-valid-class', 'quantity' => 10],
                ],
            ],
        ]));

        $this->assertTrue($result->isFailed());
        $this->assertSame('gs1_schema', $result->failure?->stage);
        $this->assertSame('INVALID_EPC_URI', $result->failure?->code);
    }

    #[Test]
    public function it_rejects_empty_epc_class_quantity_entries_as_missing_children(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => '2026-08-12T16:00:00Z',
                'parent_id' => 'urn:epc:id:sscc:030116.01001235403',
                'child_epcs' => [],
                'child_quantity_list' => [
                    ['epc_class' => '', 'quantity' => 10],
                ],
            ],
        ]));

        $this->assertTrue($result->isFailed());
        // Empty class fails GS1 before business rules.
        $this->assertSame('gs1_schema', $result->failure?->stage);
    }

    #[Test]
    public function it_accepts_case_insensitive_epc_uri_scheme(): void
    {
        $result = ValidationPipeline::default()->validate(new ValidationContext([
            [
                'event_type' => 'ObjectEvent',
                'action' => 'OBSERVE',
                'event_time' => '2026-08-12T16:00:00Z',
                'epc_list' => ['URN:EPC:ID:SGTIN:030116.3400516.10000002877732'],
            ],
        ]));

        $this->assertTrue($result->passed);
    }
}

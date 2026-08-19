<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Epcis;

use App\Domain\Epcis\EpcisEventFactory;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Domain\Epcis\Enums\EpcisEventType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EpcisEventFactoryTest extends TestCase
{
    private EpcisEventFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new EpcisEventFactory;
    }

    #[Test]
    public function it_builds_object_and_aggregation_events(): void
    {
        $when = new DateTimeImmutable('2026-08-12T16:00:00Z');

        $object = $this->factory->objectEvent(
            ['urn:epc:id:sgtin:030116.3400516.10000002877732'],
            EpcisAction::Observe,
            'receiving',
            'in_progress',
            $when,
        );

        $this->assertSame(EpcisEventType::ObjectEvent, $object->eventType);
        $this->assertSame('urn:epcglobal:cbv:bizstep:receiving', $object->bizStep);
        $this->assertSame('+00:00', $object->eventTimeZoneOffset);
        $this->assertSame('UTC', $object->eventTime->getTimezone()->getName());

        $agg = $this->factory->aggregationEvent(
            'urn:epc:id:sscc:030116.01001235403',
            ['urn:epc:id:sgtin:030116.3400516.10000002877732'],
            EpcisAction::Add,
            'packing',
            'in_progress',
            $when,
        );

        $this->assertSame(EpcisEventType::AggregationEvent, $agg->eventType);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $agg->parentId);
        $this->assertCount(1, $agg->childEpcs);
    }

    #[Test]
    public function it_rejects_invalid_epc_uris(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->objectEvent(
            ['not-a-uri'],
            EpcisAction::Add,
            'commissioning',
            'active',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    #[Test]
    public function it_allows_aggregation_delete_with_empty_children(): void
    {
        $agg = $this->factory->aggregationEvent(
            'urn:epc:id:sscc:030116.01001235403',
            [],
            EpcisAction::Delete,
            'unpacking',
            'in_progress',
            new DateTimeImmutable('2026-08-12T16:00:00Z'),
        );

        $this->assertSame(EpcisAction::Delete, $agg->action);
        $this->assertSame([], $agg->childEpcs);
    }

    #[Test]
    public function it_allows_aggregation_add_with_child_quantity_list_only(): void
    {
        $agg = $this->factory->aggregationEvent(
            'urn:epc:id:sscc:030116.01001235403',
            [],
            EpcisAction::Add,
            'packing',
            'in_progress',
            new DateTimeImmutable('2026-08-12T16:00:00Z'),
            childQuantityList: [
                ['epc_class' => 'urn:epc:idpat:sgtin:030116.3400516.*', 'quantity' => 10],
            ],
        );

        $this->assertCount(1, $agg->childQuantityList);
        $this->assertSame([], $agg->childEpcs);
    }
}

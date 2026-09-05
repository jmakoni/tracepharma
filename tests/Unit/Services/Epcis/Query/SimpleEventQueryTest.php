<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Query;

use App\Services\Epcis\Query\SimpleEventQuery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SimpleEventQueryTest extends TestCase
{
    #[Test]
    public function rejects_unknown_query_parameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QueryParameterException');

        app(SimpleEventQuery::class)->assertAllowedParams([
            'EQ_bizStep' => 'shipping',
            'FOO_bar' => 'x',
        ]);
    }

    #[Test]
    public function accepts_known_simple_event_query_params(): void
    {
        app(SimpleEventQuery::class)->assertAllowedParams([
            'EQ_bizStep' => 'urn:epcglobal:cbv:bizstep:shipping',
            'GE_eventTime' => '2026-01-01T00:00:00Z',
            'LE_eventTime' => '2026-12-31T23:59:59Z',
            'MATCH_epc' => 'urn:epc:id:sgtin:0614141.107346.2017',
            'EQ_action' => 'OBSERVE',
            'perPage' => '25',
            'nextPageToken' => 'MQ==',
        ]);

        $this->assertTrue(true);
    }
}

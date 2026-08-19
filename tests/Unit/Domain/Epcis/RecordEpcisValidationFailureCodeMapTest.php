<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Epcis;

use App\Actions\Epcis\RecordEpcisValidationFailure;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class RecordEpcisValidationFailureCodeMapTest extends TestCase
{
    #[Test]
    #[DataProvider('domainCodes')]
    public function it_maps_domain_codes_onto_catalog_codes(string $domainCode, string $expectedCatalog): void
    {
        $instance = (new ReflectionClass(RecordEpcisValidationFailure::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(RecordEpcisValidationFailure::class, 'toCatalogCode');
        $mapped = $method->invoke($instance, $domainCode);

        $this->assertSame($expectedCatalog, $mapped);
        $this->assertContains($mapped, EpcisValidationCatalog::CODES);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function domainCodes(): array
    {
        return [
            ['AGGREGATION_MISSING_PARENT', 'MISSING_PARENT'],
            ['AGGREGATION_MISSING_CHILDREN', 'MISSING_CHILDREN'],
            ['AGGREGATION_PARENT_IN_CHILDREN', 'BROKEN_AGGREGATION'],
            ['INVALID_EPC_URI', 'INVALID_EPC_URI'],
            ['INVALID_ACTION', 'INVALID_ACTION'],
            ['MISSING_EVENT_TIME', 'MISSING_MANDATORY_FIELD'],
            ['EMPTY_EVENT_LIST', 'MISSING_MANDATORY_FIELD'],
            ['OBJECT_EVENT_EMPTY_EPC_LIST', 'MISSING_MANDATORY_FIELD'],
            ['MALFORMED_XML', 'INGESTION_PARSE_ERROR'],
            ['UNKNOWN_DOMAIN_CODE', 'INTERNAL_VALIDATION_FAILED'],
        ];
    }
}

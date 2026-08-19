<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Data;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Domain\Epcis\Enums\EpcisEventType;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class ObjectEventData extends Data
{
    /**
     * @param  list<string>  $epcList
     * @param  list<array{epc_class: string, quantity?: mixed, uom?: mixed}>  $quantityList
     */
    public function __construct(
        public readonly EpcisEventType $eventType,
        public readonly EpcisAction $action,
        public readonly DateTimeImmutable $eventTime,
        public readonly string $eventTimeZoneOffset,
        public readonly array $epcList,
        public readonly string $bizStep,
        public readonly string $disposition,
        public readonly ?string $readPoint = null,
        public readonly ?string $bizLocation = null,
        public readonly array $quantityList = [],
    ) {}
}

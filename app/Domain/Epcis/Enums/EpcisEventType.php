<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Enums;

enum EpcisEventType: string
{
    case ObjectEvent = 'ObjectEvent';
    case AggregationEvent = 'AggregationEvent';
    case TransactionEvent = 'TransactionEvent';
    case TransformationEvent = 'TransformationEvent';
    case AssociationEvent = 'AssociationEvent';
}

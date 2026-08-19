<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Enums;

enum EpcisAction: string
{
    case Add = 'ADD';
    case Observe = 'OBSERVE';
    case Delete = 'DELETE';
}

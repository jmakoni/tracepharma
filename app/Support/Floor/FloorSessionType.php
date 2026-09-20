<?php

namespace App\Support\Floor;

enum FloorSessionType: string
{
    case Receiving = 'receiving';
    case Shipping = 'shipping';
    case Transferring = 'transferring';
    case Packing = 'packing';
    case Disposition = 'disposition';
}

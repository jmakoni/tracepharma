<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation\Contracts;

use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationFailure;

interface ValidationStage
{
    public function name(): string;

    public function validate(ValidationContext $context): ?ValidationFailure;
}

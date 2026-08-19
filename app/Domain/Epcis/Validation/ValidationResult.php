<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation;

final readonly class ValidationResult
{
    private function __construct(
        public bool $passed,
        public ?ValidationFailure $failure,
    ) {}

    public static function passed(): self
    {
        return new self(true, null);
    }

    public static function fromFailure(ValidationFailure $failure): self
    {
        return new self(false, $failure);
    }

    public function isFailed(): bool
    {
        return ! $this->passed;
    }
}

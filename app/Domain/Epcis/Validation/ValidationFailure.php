<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation;

/**
 * Hard-gate failure — fully valid or dead-letter; never partial commit.
 */
final readonly class ValidationFailure
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $stage,
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}

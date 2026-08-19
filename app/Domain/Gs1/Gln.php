<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * Validated GLN-13 value object.
 */
final readonly class Gln implements Stringable
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws InvalidArgumentException when length or check digit is invalid
     */
    public static function fromDigits(string $digits): self
    {
        $normalized = preg_replace('/\D+/', '', $digits) ?? '';

        if (strlen($normalized) !== 13) {
            throw new InvalidArgumentException('GLN must be 13 digits.');
        }

        $body = substr($normalized, 0, 12);
        $provided = substr($normalized, 12, 1);

        if ($provided !== CheckDigit::mod10($body)) {
            throw new InvalidArgumentException('GLN check digit is invalid.');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

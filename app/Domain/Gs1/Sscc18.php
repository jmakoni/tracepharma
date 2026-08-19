<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * Validated 18-digit SSCC value object.
 */
final readonly class Sscc18 implements Stringable
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

        if (strlen($normalized) !== 18) {
            throw new InvalidArgumentException('SSCC must be 18 digits.');
        }

        $body = substr($normalized, 0, 17);
        $provided = substr($normalized, 17, 1);

        if ($provided !== CheckDigit::mod10($body)) {
            throw new InvalidArgumentException('SSCC check digit is invalid.');
        }

        return new self($normalized);
    }

    /**
     * Build from company prefix, extension digit, and serial body (digits after extension).
     *
     * @throws InvalidArgumentException when the 17-digit body cannot be formed
     */
    public static function fromCompanyPrefixAndSerialRef(
        string $companyPrefix,
        string $extensionDigit,
        string $serialBody,
    ): self {
        $companyPrefix = preg_replace('/\D+/', '', $companyPrefix) ?? '';
        $extensionDigit = preg_replace('/\D+/', '', $extensionDigit) ?? '';
        $serialBody = preg_replace('/\D+/', '', $serialBody) ?? '';

        if ($companyPrefix === '' || strlen($extensionDigit) !== 1) {
            throw new InvalidArgumentException('SSCC requires a company prefix and a single extension digit.');
        }

        $body17 = $extensionDigit.$companyPrefix.$serialBody;

        if (strlen($body17) !== 17 || ! ctype_digit($body17)) {
            throw new InvalidArgumentException('SSCC body must be exactly 17 digits.');
        }

        return new self($body17.CheckDigit::mod10($body17));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function extensionDigit(): string
    {
        return $this->value[0];
    }

    /**
     * 17-digit body without check digit.
     */
    public function body(): string
    {
        return substr($this->value, 0, 17);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * Validated GTIN-14 value object.
 */
final readonly class Gtin14 implements Stringable
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * Accept 8/12/13/14 digit GS1 keys (or a padded GTIN-14) and return a validated GTIN-14.
     *
     * @throws InvalidArgumentException when structure or check digit is invalid
     */
    public static function fromDigits(string $digits): self
    {
        $normalized = preg_replace('/\D+/', '', $digits) ?? '';

        if (! in_array(strlen($normalized), [8, 12, 13, 14], true)) {
            throw new InvalidArgumentException('GTIN must be 8, 12, 13, or 14 digits.');
        }

        $gtin14 = str_pad($normalized, 14, '0', STR_PAD_LEFT);
        $body = substr($gtin14, 0, 13);
        $provided = substr($gtin14, 13, 1);

        if ($provided !== CheckDigit::mod10($body)) {
            throw new InvalidArgumentException('GTIN check digit is invalid.');
        }

        return new self($gtin14);
    }

    /**
     * @throws InvalidArgumentException when the NDC is not 10 digits
     */
    public static function fromPackageNdc(string $packageNdc): self
    {
        $ndc = preg_replace('/\D+/', '', $packageNdc) ?? '';

        if (strlen($ndc) !== 10) {
            throw new InvalidArgumentException('Package NDC must be 10 digits.');
        }

        $body = '003'.$ndc;

        return new self($body.CheckDigit::mod10($body));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function indicatorDigit(): string
    {
        return $this->value[0];
    }

    /**
     * 13-digit body without check digit.
     */
    public function body(): string
    {
        return substr($this->value, 0, 13);
    }
}

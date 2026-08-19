<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * EPC Pure Identity URI for an SSCC.
 */
final readonly class SsccUri implements Stringable
{
    private function __construct(
        private string $uri,
        private string $companyPrefix,
        private Sscc18 $sscc,
    ) {}

    /**
     * Build an SSCC URI from a validated SSCC-18 and known company prefix length.
     *
     * @throws InvalidArgumentException when the prefix does not partition the SSCC body
     */
    public static function fromSscc(Sscc18 $sscc, string $companyPrefix): self
    {
        $companyPrefix = preg_replace('/\D+/', '', $companyPrefix) ?? '';

        if ($companyPrefix === '' || ! ctype_digit($companyPrefix)) {
            throw new InvalidArgumentException('SSCC company prefix must be numeric.');
        }

        $body = $sscc->body();
        $extensionDigit = $body[0];
        $remainder = substr($body, 1);

        if (! str_starts_with($remainder, $companyPrefix)) {
            throw new InvalidArgumentException('Company prefix does not match SSCC body.');
        }

        $serialBody = substr($remainder, strlen($companyPrefix));
        $serialReference = $extensionDigit.$serialBody;
        $uri = 'urn:epc:id:sscc:'.$companyPrefix.'.'.$serialReference;

        return new self($uri, $companyPrefix, $sscc);
    }

    /**
     * @throws InvalidArgumentException when the URN cannot be parsed
     */
    public static function fromUrn(string $uri): self
    {
        $uri = trim($uri);
        $uri = (string) preg_replace('/^urn:epc:id:sscc:/i', 'urn:epc:id:sscc:', $uri);

        if (! preg_match('/^urn:epc:id:sscc:(\d+)\.(\d+)$/', $uri, $matches)) {
            throw new InvalidArgumentException('Invalid SSCC URN.');
        }

        $companyPrefix = $matches[1];
        $serialReference = $matches[2];

        if ($serialReference === '') {
            throw new InvalidArgumentException('Invalid SSCC URN serial reference.');
        }

        $extensionDigit = $serialReference[0];
        $serialBody = substr($serialReference, 1);
        $sscc = Sscc18::fromCompanyPrefixAndSerialRef($companyPrefix, $extensionDigit, $serialBody);

        return new self($uri, $companyPrefix, $sscc);
    }

    public function toString(): string
    {
        return $this->uri;
    }

    public function __toString(): string
    {
        return $this->uri;
    }

    public function companyPrefix(): string
    {
        return $this->companyPrefix;
    }

    public function sscc(): Sscc18
    {
        return $this->sscc;
    }
}

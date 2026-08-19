<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * EPC Pure Identity URI for an SGTIN.
 */
final readonly class SgtinUri implements Stringable
{
    private function __construct(
        private string $uri,
        private string $companyPrefix,
        private string $serial,
        private Gtin14 $gtin,
    ) {}

    /**
     * @throws InvalidArgumentException when company prefix / serial / GTIN partition is invalid
     */
    public static function fromGtinAndSerial(Gtin14 $gtin, string $serial, string $companyPrefix): self
    {
        $companyPrefix = preg_replace('/\D+/', '', $companyPrefix) ?? '';
        $serial = trim($serial);

        if ($companyPrefix === '' || ! ctype_digit($companyPrefix)) {
            throw new InvalidArgumentException('SGTIN company prefix must be numeric.');
        }

        if ($serial === '') {
            throw new InvalidArgumentException('SGTIN serial number is required.');
        }

        $body = $gtin->body();
        $indicator = $body[0];
        $remainder = substr($body, 1);

        if (! str_starts_with($remainder, $companyPrefix)) {
            throw new InvalidArgumentException('Company prefix does not match GTIN body.');
        }

        $itemReference = substr($remainder, strlen($companyPrefix));

        if ($itemReference === '' || ! ctype_digit($itemReference)) {
            throw new InvalidArgumentException('SGTIN item reference is invalid for the given company prefix.');
        }

        $uri = 'urn:epc:id:sgtin:'.$companyPrefix.'.'.$indicator.$itemReference.'.'.$serial;

        return new self($uri, $companyPrefix, $serial, $gtin);
    }

    /**
     * @throws InvalidArgumentException when the URN cannot be parsed or check digit fails
     */
    public static function fromUrn(string $uri): self
    {
        $uri = trim($uri);
        $uri = (string) preg_replace('/^urn:epc:id:sgtin:/i', 'urn:epc:id:sgtin:', $uri);

        if (! preg_match('/^urn:epc:id:sgtin:(\d+)\.(\d+)\.(.+)$/', $uri, $matches)) {
            throw new InvalidArgumentException('Invalid SGTIN URN.');
        }

        $companyPrefix = $matches[1];
        $indicatorItemRef = $matches[2];
        $serial = $matches[3];

        if ($indicatorItemRef === '' || $serial === '') {
            throw new InvalidArgumentException('Invalid SGTIN URN components.');
        }

        $indicatorDigit = $indicatorItemRef[0];
        $itemReference = substr($indicatorItemRef, 1);
        $body13 = $indicatorDigit.$companyPrefix.$itemReference;

        if (strlen($body13) !== 13 || ! ctype_digit($body13)) {
            throw new InvalidArgumentException('SGTIN URN does not encode a 13-digit GTIN body.');
        }

        $gtin = Gtin14::fromDigits($body13.CheckDigit::mod10($body13));

        return new self($uri, $companyPrefix, $serial, $gtin);
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

    public function serial(): string
    {
        return $this->serial;
    }

    public function gtin(): Gtin14
    {
        return $this->gtin;
    }
}

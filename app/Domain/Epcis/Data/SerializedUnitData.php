<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Data;

use App\Domain\Gs1\Gtin14;
use App\Domain\Gs1\SgtinUri;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Serialized trade-item unit (Python SerializedUnit / Pydantic equivalent).
 */
final class SerializedUnitData extends Data
{
    public function __construct(
        public readonly string $gtin,
        public readonly string $serial,
        public readonly string $lot,
        public readonly DateTimeImmutable $expiry,
        public readonly ?string $epcUri = null,
        public readonly ?string $companyPrefix = null,
    ) {}

    /**
     * @param  array{gtin: string, serial: string, lot: string, expiry: string|DateTimeImmutable, epcUri?: ?string, companyPrefix?: ?string}  $payload
     */
    public static function fromValidated(array $payload): self
    {
        $gtin = Gtin14::fromDigits($payload['gtin']);
        $serial = trim((string) $payload['serial']);
        $lot = trim((string) $payload['lot']);

        if ($serial === '') {
            throw new InvalidArgumentException('Serial number is required.');
        }

        if ($lot === '') {
            throw new InvalidArgumentException('Lot number is required.');
        }

        $expiry = $payload['expiry'] instanceof DateTimeImmutable
            ? $payload['expiry']->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable((string) $payload['expiry'], new DateTimeZone('UTC'));

        $companyPrefix = isset($payload['companyPrefix']) ? (string) $payload['companyPrefix'] : null;
        $epcUri = isset($payload['epcUri']) ? $payload['epcUri'] : null;

        if ($epcUri === null && $companyPrefix !== null && $companyPrefix !== '') {
            $epcUri = SgtinUri::fromGtinAndSerial($gtin, $serial, $companyPrefix)->toString();
        }

        if (is_string($epcUri) && $epcUri !== '') {
            $parsed = SgtinUri::fromUrn($epcUri);
            if ($parsed->gtin()->toString() !== $gtin->toString() || $parsed->serial() !== $serial) {
                throw new InvalidArgumentException('epcUri does not match gtin and serial.');
            }
            $epcUri = $parsed->toString();
        }

        return new self(
            gtin: $gtin->toString(),
            serial: $serial,
            lot: $lot,
            expiry: $expiry,
            epcUri: $epcUri,
            companyPrefix: $companyPrefix,
        );
    }
}

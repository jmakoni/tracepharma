<?php

declare(strict_types=1);

namespace App\Domain\Epcis;

use App\Domain\Epcis\Data\AggregationEventData;
use App\Domain\Epcis\Data\ObjectEventData;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Domain\Epcis\Enums\EpcisEventType;
use App\Domain\Gs1\EpcClassUri;
use App\Domain\Gs1\SgtinUri;
use App\Domain\Gs1\SsccUri;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Builds typed EPCIS 2.0-style event Data objects with hard GS1 identity gates.
 */
final class EpcisEventFactory
{
    /**
     * @param  list<string>  $epcList
     * @param  list<array{epc_class?: string, epcClass?: string, quantity?: mixed, uom?: mixed}>  $quantityList
     */
    public function objectEvent(
        array $epcList,
        EpcisAction $action,
        string $bizStep,
        string $disposition,
        DateTimeImmutable $eventTimeUtc,
        string $eventTimeZoneOffset = '+00:00',
        ?string $readPoint = null,
        ?string $bizLocation = null,
        array $quantityList = [],
    ): ObjectEventData {
        $normalizedQty = $this->normalizeQuantityEntries($quantityList);

        if ($epcList === [] && $normalizedQty === []) {
            throw new InvalidArgumentException('ObjectEvent requires at least one EPC or quantityList entry.');
        }

        $normalized = [];
        foreach ($epcList as $uri) {
            $normalized[] = $this->assertEpcUri($uri);
        }

        return new ObjectEventData(
            eventType: EpcisEventType::ObjectEvent,
            action: $action,
            eventTime: $eventTimeUtc->setTimezone(new DateTimeZone('UTC')),
            eventTimeZoneOffset: $eventTimeZoneOffset,
            epcList: $normalized,
            bizStep: $this->normalizeCbv($bizStep, 'bizstep'),
            disposition: $this->normalizeCbv($disposition, 'disp'),
            readPoint: $readPoint,
            bizLocation: $bizLocation,
            quantityList: $normalizedQty,
        );
    }

    /**
     * @param  list<string>  $childUris
     * @param  list<array{epc_class?: string, epcClass?: string, quantity?: mixed, uom?: mixed}>  $childQuantityList
     */
    public function aggregationEvent(
        string $parentUri,
        array $childUris,
        EpcisAction $action,
        string $bizStep,
        string $disposition,
        DateTimeImmutable $eventTimeUtc,
        string $eventTimeZoneOffset = '+00:00',
        ?string $readPoint = null,
        ?string $bizLocation = null,
        array $childQuantityList = [],
    ): AggregationEventData {
        $normalizedQty = $this->normalizeQuantityEntries($childQuantityList);
        $allowsEmptyChildren = $action === EpcisAction::Delete || $normalizedQty !== [];

        if ($childUris === [] && ! $allowsEmptyChildren) {
            throw new InvalidArgumentException('AggregationEvent requires child EPCs or childQuantityList.');
        }

        $parent = $this->assertEpcUri($parentUri);
        $children = [];
        foreach ($childUris as $uri) {
            $children[] = $this->assertEpcUri($uri);
        }

        return new AggregationEventData(
            eventType: EpcisEventType::AggregationEvent,
            action: $action,
            eventTime: $eventTimeUtc->setTimezone(new DateTimeZone('UTC')),
            eventTimeZoneOffset: $eventTimeZoneOffset,
            parentId: $parent,
            childEpcs: $children,
            bizStep: $this->normalizeCbv($bizStep, 'bizstep'),
            disposition: $this->normalizeCbv($disposition, 'disp'),
            readPoint: $readPoint,
            bizLocation: $bizLocation,
            childQuantityList: $normalizedQty,
        );
    }

    private function assertEpcUri(string $uri): string
    {
        $uri = trim($uri);

        if (preg_match('/^urn:epc:id:sgtin:/i', $uri) === 1) {
            return SgtinUri::fromUrn($uri)->toString();
        }

        if (preg_match('/^urn:epc:id:sscc:/i', $uri) === 1) {
            return SsccUri::fromUrn($uri)->toString();
        }

        throw new InvalidArgumentException('EPC URI must be an SGTIN or SSCC Pure Identity URN.');
    }

    /**
     * @param  list<array{epc_class?: string, epcClass?: string, quantity?: mixed, uom?: mixed}>  $entries
     * @return list<array{epc_class: string, quantity: mixed, uom: mixed}>
     */
    private function normalizeQuantityEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $epcClass = trim((string) ($entry['epc_class'] ?? $entry['epcClass'] ?? ''));
            if ($epcClass === '') {
                throw new InvalidArgumentException('Quantity entry requires epc_class.');
            }

            $normalized[] = [
                'epc_class' => EpcClassUri::fromString($epcClass)->toString(),
                'quantity' => $entry['quantity'] ?? null,
                'uom' => $entry['uom'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeCbv(string $value, string $kind): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("CBV {$kind} is required.");
        }

        if (str_starts_with($value, 'urn:epcglobal:cbv:')) {
            return $value;
        }

        return 'urn:epcglobal:cbv:'.$kind.':'.$value;
    }
}

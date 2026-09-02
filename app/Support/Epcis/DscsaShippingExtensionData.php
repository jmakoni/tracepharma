<?php

declare(strict_types=1);

namespace App\Support\Epcis;

/**
 * GS1 US HC shipping-event DSCSA purchase extensions (R1.2 boolean / R1.3 qualifier).
 */
final readonly class DscsaShippingExtensionData
{
    public function __construct(
        public ?DscsaPurchaseExtension $directPurchase = null,
        public ?DscsaPurchaseExtension $receivedPrevWholesaler = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->directPurchase === null && $this->receivedPrevWholesaler === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->directPurchase !== null) {
            $out['direct_purchase'] = $this->directPurchase->toArray();
        }

        if ($this->receivedPrevWholesaler !== null) {
            $out['received_prev_wholesaler'] = $this->receivedPrevWholesaler->toArray();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $direct = is_array($data['direct_purchase'] ?? null)
            ? DscsaPurchaseExtension::fromArray($data['direct_purchase'])
            : null;
        $received = is_array($data['received_prev_wholesaler'] ?? null)
            ? DscsaPurchaseExtension::fromArray($data['received_prev_wholesaler'])
            : null;

        return new self(
            directPurchase: $direct,
            receivedPrevWholesaler: $received,
        );
    }
}

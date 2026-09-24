<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\DscsaPurchaseExtension;
use App\Support\Epcis\DscsaShippingExtensionParser;
use Illuminate\Support\Facades\Schema;

final class PromoteDscsaShippingExtensions
{
    /**
     * @param  array<string, mixed>  $eventData
     */
    /**
     * @return bool True when at least one DSCSA shipping-extension column was written.
     */
    public function handle(EpcisDocument $document, array $eventData): bool
    {
        if (! Schema::hasColumn('epcis_documents', 'direct_purchase_statement')) {
            return false;
        }

        $parsed = DscsaShippingExtensionParser::fromEventData($eventData);
        if ($parsed === null) {
            return false;
        }

        $attributes = [];

        if ($parsed->directPurchase !== null) {
            $attributes = array_merge($attributes, $this->purchaseAttributes(
                $parsed->directPurchase,
                'direct_purchase',
            ));
        }

        if ($parsed->receivedPrevWholesaler !== null) {
            $attributes = array_merge($attributes, $this->purchaseAttributes(
                $parsed->receivedPrevWholesaler,
                'received_prev_wholesaler',
            ));
        }

        if ($attributes === []) {
            return false;
        }

        $document->forceFill($attributes)->save();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseAttributes(DscsaPurchaseExtension $extension, string $prefix): array
    {
        $out = [];

        if ($extension->qualifier !== null) {
            $out["{$prefix}_qualifier"] = $extension->qualifier;
        }

        if ($extension->statement !== null) {
            $out["{$prefix}_statement"] = $extension->statement;
        }

        if ($extension->indirectEpcUris !== []) {
            $out["{$prefix}_indirect_epc_uris"] = $extension->indirectEpcUris;
        }

        return $out;
    }
}

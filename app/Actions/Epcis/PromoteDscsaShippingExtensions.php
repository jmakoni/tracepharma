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
    public function handle(EpcisDocument $document, array $eventData): void
    {
        if (! Schema::hasColumn('epcis_documents', 'direct_purchase_statement')) {
            return;
        }

        $parsed = DscsaShippingExtensionParser::fromEventData($eventData);
        if ($parsed === null) {
            return;
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

        if ($attributes !== []) {
            $document->forceFill($attributes)->save();
        }
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

<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Enums\OutboundEpcisAggregationMode;

final class AggregationEventChildrenRenderer
{
    /**
     * @param  list<string>  $childEpcs
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    public function renderForMovement(
        array $childEpcs,
        array $quantityChildren,
        OutboundEpcisAggregationMode $mode,
    ): string {
        $xml = '';

        if ($mode->emitsInstanceChildren()) {
            $xml .= $this->childEpcsXml($childEpcs);
        }

        if ($mode->emitsClassChildren()) {
            $xml .= $this->childQuantityListXml($quantityChildren);
        }

        return $xml;
    }

    /**
     * @param  list<string>  $childEpcs
     */
    public function childEpcsXml(array $childEpcs): string
    {
        $rows = '';

        foreach (array_unique($childEpcs) as $childEpc) {
            $childEpc = trim((string) $childEpc);

            if ($childEpc === '') {
                continue;
            }

            $rows .= '                    <epc>'.htmlspecialchars($childEpc, ENT_XML1)."</epc>\n";
        }

        if ($rows === '') {
            return '';
        }

        return "                <childEPCs>\n{$rows}                </childEPCs>\n";
    }

    /**
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    public function childQuantityListXml(array $quantityChildren): string
    {
        if ($quantityChildren === []) {
            return '';
        }

        $rows = '';

        foreach ($quantityChildren as $row) {
            if (! is_array($row)) {
                continue;
            }

            $epcClass = (string) ($row['epcClass'] ?? $row['epc_class'] ?? '');

            if ($epcClass === '') {
                continue;
            }

            $epcClassXml = htmlspecialchars($epcClass, ENT_XML1);
            $quantity = max(0, (float) ($row['quantity'] ?? 0));
            $uom = isset($row['uom']) ? (string) $row['uom'] : '';
            $uomXml = $uom !== ''
                ? "\n                        <uom>".htmlspecialchars($uom, ENT_XML1).'</uom>'
                : '';

            $rows .= <<<XML
                    <quantityElement>
                        <epcClass>{$epcClassXml}</epcClass>
                        <quantity>{$quantity}</quantity>{$uomXml}
                    </quantityElement>

XML;
        }

        if ($rows === '') {
            return '';
        }

        return "                <childQuantityList>\n{$rows}                </childQuantityList>\n";
    }
}

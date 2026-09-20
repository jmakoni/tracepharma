<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Models\Epcis\Epc;
use App\Models\Product;
use App\Support\Gs1\Ndc;
use App\Support\Gs1\Sgtin;
use Illuminate\Support\Collection;

/**
 * Lean outbound EPCClass vocabulary from scanned SGTINs + product master.
 */
final class OutboundEpcClassVocabulary
{
    /**
     * @param  Collection<int, Epc>  $epcs
     */
    public static function masterDataXml(Collection $epcs, EpcisGuideline $guideline): string
    {
        $vocabulary = self::xml($epcs, $guideline);
        if ($vocabulary === '') {
            return '';
        }

        return
            "    <extension>\n".
            "      <EPCISMasterData>\n".
            "        <VocabularyList>\n".
            $vocabulary.
            "        </VocabularyList>\n".
            "      </EPCISMasterData>\n".
            "    </extension>\n";
    }

    /**
     * @param  Collection<int, Epc>  $epcs
     */
    public static function xml(Collection $epcs, EpcisGuideline $guideline): string
    {
        $patterns = [];
        foreach ($epcs as $epc) {
            $parsed = Sgtin::fromUrn((string) $epc->epc_uri);
            if ($parsed === null) {
                continue;
            }

            $key = $parsed['company_prefix'].'.'.$parsed['indicator_digit'].$parsed['item_reference'];
            $patterns[$key] = [
                'company_prefix' => $parsed['company_prefix'],
                'indicator_digit' => $parsed['indicator_digit'],
                'item_reference' => $parsed['item_reference'],
                'gtin14' => $parsed['gtin14'],
            ];
        }

        if ($patterns === []) {
            return '';
        }

        $elements = '';
        foreach ($patterns as $parsed) {
            $idpat = 'urn:epc:idpat:sgtin:'.$parsed['company_prefix'].'.'.$parsed['indicator_digit'].$parsed['item_reference'].'.*';
            $product = Product::query()->where('gtin', $parsed['gtin14'])->first();
            $ndc11 = Ndc::toNdc11($product?->ndc11)
                ?? Ndc::derive($product?->package_ndc, $product?->ndc);
            $name = filled($product?->name) ? (string) $product->name : 'Trade item '.$parsed['gtin14'];

            $attrs = '';
            if ($ndc11 !== null) {
                if ($guideline === EpcisGuideline::R13) {
                    $dashed = Ndc::formatPackageDisplay($ndc11, $product?->package_ndc);
                    if ($dashed !== null) {
                        $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">'.self::e($dashed)."</attribute>\n";
                        $attrs .= "                <attribute id=\"urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode\">US_FDA_NDC</attribute>\n";
                    }
                } else {
                    $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">'.self::e($ndc11)."</attribute>\n";
                    $attrs .= "                <attribute id=\"urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode\">FDA_NDC_11</attribute>\n";
                }
            }
            $attrs .= '                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">'.self::e($name)."</attribute>\n";

            $elements .=
                '              <VocabularyElement id="'.self::e($idpat)."\">\n".
                $attrs.
                "              </VocabularyElement>\n";
        }

        return
            "          <Vocabulary type=\"urn:epcglobal:epcis:vtype:EPCClass\">\n".
            "            <VocabularyElementList>\n".
            $elements.
            "            </VocabularyElementList>\n".
            "          </Vocabulary>\n";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

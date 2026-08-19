<?php

namespace App\Services\Epcis;

use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Models\Product;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\Sgtin;
use App\Support\Gs1\Sscc;

/**
 * Normalize EPC Pure Identity URNs and GS1 AI element strings into epcs-shaped keys.
 */
final class EpcNormalizer
{
    /** @var callable(?string, ?string): (?Product) */
    private $resolveProduct;

    /**
     * @param  (callable(?string, ?string): (?Product))|null  $resolveProduct
     */
    public function __construct(?callable $resolveProduct = null)
    {
        $this->resolveProduct = $resolveProduct ?? static function (?string $gtin14, ?string $ndc11): ?Product {
            return app(ResolveProductFromIdentifier::class)->handle(
                gtin14: $gtin14,
                ndc11: $ndc11,
            );
        };
    }

    /**
     * Materialize epcs column attributes from an SGTIN/SSCC Pure Identity URN.
     *
     * @return array<string, mixed>|null
     */
    public function fromUri(string $epcUri, ?string $ndc11 = null): ?array
    {
        $uri = trim($epcUri);

        if ($uri === '') {
            return null;
        }

        if ($sgtin = Sgtin::fromUrn($uri)) {
            $attrs = [
                'epc_uri' => $sgtin['epc_uri'],
                'epc_type' => 'sgtin',
                'company_prefix' => $sgtin['company_prefix'],
                'indicator_digit' => (int) $sgtin['indicator_digit'],
                'item_reference' => $sgtin['item_reference'],
                'serial_number' => $sgtin['serial_number'],
                'gtin14' => $sgtin['gtin14'],
                'ai_01_21' => $sgtin['ai_01_21'],
            ];

            return $this->withProductId($attrs, $sgtin['gtin14'], $ndc11);
        }

        if ($sscc = Sscc::fromUrn($uri)) {
            return [
                'epc_uri' => $sscc['epc_uri'],
                'epc_type' => 'sscc',
                'company_prefix' => $sscc['company_prefix'],
                'extension_digit' => (int) $sscc['extension_digit'],
                'serial_number' => $sscc['serial_reference'],
                'sscc18' => $sscc['sscc18'],
                'ai_00' => $sscc['ai_00'],
            ];
        }

        return null;
    }

    /**
     * Materialize scan keys from a GS1 AI element string (parenthesized or concatenated).
     *
     * Does not force an EPC URN when company-prefix length is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function fromAiElementString(string $input): ?array
    {
        $normalized = ElementString::normalize($input);

        if ($normalized === '') {
            return null;
        }

        if ($sscc = ElementString::ssccIdentity($normalized)) {
            return [
                'epc_type' => 'sscc',
                'sscc18' => $sscc['sscc18'],
                'ai_00' => $sscc['ai_00'],
            ];
        }

        if ($sgtin = ElementString::sgtinIdentity($normalized)) {
            $attrs = [
                'epc_type' => 'sgtin',
                'gtin14' => $sgtin['gtin14'],
                'serial_number' => $sgtin['serial'],
                'ai_01_21' => $sgtin['ai_01_21'],
            ];

            if (isset($sgtin['lot_number'])) {
                $attrs['lot_number'] = $sgtin['lot_number'];
            }

            if (isset($sgtin['expiry_yymmdd'])) {
                $attrs['expiry_yymmdd'] = $sgtin['expiry_yymmdd'];
            }

            return $this->withProductId($attrs, $sgtin['gtin14'], null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function withProductId(array $attrs, ?string $gtin14, ?string $ndc11): array
    {
        $product = ($this->resolveProduct)($gtin14, $ndc11);
        if ($product !== null) {
            $attrs['product_id'] = (int) $product->getKey();
        }

        return $attrs;
    }
}

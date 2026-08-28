<?php

declare(strict_types=1);

namespace App\Support\Epcis\Validation;

/**
 * Map CBV 2.0 short / HTTPS vocabulary terms to canonical CBV 1.x URNs
 * used in TracePharma custody gates and outbound Domain authoring.
 */
final class EpcisCbv20Mapper
{
    /**
     * @var array<string, string>
     */
    private const BIZ_STEP_ALIASES = [
        'commissioning' => 'urn:epcglobal:cbv:bizstep:commissioning',
        'packing' => 'urn:epcglobal:cbv:bizstep:packing',
        'unpacking' => 'urn:epcglobal:cbv:bizstep:unpacking',
        'shipping' => 'urn:epcglobal:cbv:bizstep:shipping',
        'receiving' => 'urn:epcglobal:cbv:bizstep:receiving',
        'accepting' => 'urn:epcglobal:cbv:bizstep:accepting',
        'arriving' => 'urn:epcglobal:cbv:bizstep:arriving',
        'departing' => 'urn:epcglobal:cbv:bizstep:departing',
        'inspecting' => 'urn:epcglobal:cbv:bizstep:inspecting',
        'holding' => 'urn:epcglobal:cbv:bizstep:holding',
        'storing' => 'urn:epcglobal:cbv:bizstep:storing',
        'picking' => 'urn:epcglobal:cbv:bizstep:picking',
        'loading' => 'urn:epcglobal:cbv:bizstep:loading',
        'unloading' => 'urn:epcglobal:cbv:bizstep:unloading',
        'void_shipping' => 'urn:epcglobal:cbv:bizstep:void_shipping',
        'decommissioning' => 'urn:epcglobal:cbv:bizstep:decommissioning',
        'destroying' => 'urn:epcglobal:cbv:bizstep:destroying',
        'returning' => 'urn:epcglobal:cbv:bizstep:returning',
        'stocking' => 'urn:epcglobal:cbv:bizstep:stocking',
        'stock_taking' => 'urn:epcglobal:cbv:bizstep:stock_taking',
        'inventory_check' => 'urn:epcglobal:cbv:bizstep:stock_taking',
        'dispensing' => 'urn:epcglobal:cbv:bizstep:dispensing',
        'repackaging' => 'urn:epcglobal:cbv:bizstep:repackaging',
        'sampling' => 'urn:epcglobal:cbv:bizstep:sampling',
        'reserving' => 'urn:epcglobal:cbv:bizstep:reserving',
    ];

    /**
     * @var array<string, string>
     */
    private const DISPOSITION_ALIASES = [
        'active' => 'urn:epcglobal:cbv:disp:active',
        'in_progress' => 'urn:epcglobal:cbv:disp:in_progress',
        'in_transit' => 'urn:epcglobal:cbv:disp:in_transit',
        'encoded' => 'urn:epcglobal:cbv:disp:encoded',
        'destroyed' => 'urn:epcglobal:cbv:disp:destroyed',
        'decommissioned' => 'urn:epcglobal:cbv:disp:decommissioned',
        'reserved' => 'urn:epcglobal:cbv:disp:reserved',
        'retail_sold' => 'urn:epcglobal:cbv:disp:retail_sold',
        'returned' => 'urn:epcglobal:cbv:disp:returned',
        'expired' => 'urn:epcglobal:cbv:disp:expired',
        'recalled' => 'urn:epcglobal:cbv:disp:recalled',
        'inactive' => 'urn:epcglobal:cbv:disp:inactive',
        'stolen' => 'urn:epcglobal:cbv:disp:stolen',
        'disposed' => 'urn:epcglobal:cbv:disp:disposed',
        'damaged' => 'urn:epcglobal:cbv:disp:damaged',
        'dispensed' => 'urn:epcglobal:cbv:disp:dispensed',
        'non_sellable_other' => 'urn:epcglobal:cbv:disp:non_sellable_other',
        'sellable_accessible' => 'urn:epcglobal:cbv:disp:sellable_accessible',
        'sellable_not_accessible' => 'urn:epcglobal:cbv:disp:sellable_not_accessible',
        'container_closed' => 'urn:epcglobal:cbv:disp:container_closed',
        'container_open' => 'urn:epcglobal:cbv:disp:container_open',
    ];

    public static function toCanonicalBizStep(?string $value): ?string
    {
        return self::map($value, self::BIZ_STEP_ALIASES, 'bizstep');
    }

    public static function toCanonicalDisposition(?string $value): ?string
    {
        return self::map($value, self::DISPOSITION_ALIASES, 'disp');
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function map(?string $value, array $aliases, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'urn:epcglobal:cbv:')) {
            return $trimmed;
        }

        // CBV 2.0 HTTPS vocabulary, e.g. https://ref.gs1.org/cbv/BizStep-shipping
        if (preg_match('#/(?:BizStep|Disposition|Disp)-([A-Za-z0-9_]+)$#', $trimmed, $matches) === 1) {
            $local = strtolower($matches[1]);
            if (isset($aliases[$local])) {
                return $aliases[$local];
            }
        }

        $local = strtolower($trimmed);
        if (isset($aliases[$local])) {
            return $aliases[$local];
        }

        // Unknown short form — keep as canonical URN so allowlist can reject
        if (! str_contains($trimmed, ':') && ! str_contains($trimmed, '/')) {
            return 'urn:epcglobal:cbv:'.$kind.':'.$local;
        }

        return $trimmed;
    }

    public static function isAllowedBizStep(?string $value): bool
    {
        return EpcisCbvAllowlist::isAllowedBizStep(self::toCanonicalBizStep($value) ?? $value);
    }

    public static function isAllowedDisposition(?string $value): bool
    {
        return EpcisCbvAllowlist::isAllowedDisposition(self::toCanonicalDisposition($value) ?? $value);
    }
}

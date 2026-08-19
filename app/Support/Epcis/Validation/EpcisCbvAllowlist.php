<?php

namespace App\Support\Epcis\Validation;

/**
 * Core Business Vocabulary allowlists used by aggressive EPCIS 1.2 validation.
 */
final class EpcisCbvAllowlist
{
    public const ACTIONS = ['ADD', 'OBSERVE', 'DELETE'];

    /**
     * Common CBV bizStep URIs (and bare local names) accepted for inbound DSCSA traffic.
     *
     * @var list<string>
     */
    public const BIZ_STEPS = [
        'urn:epcglobal:cbv:bizstep:commissioning',
        'urn:epcglobal:cbv:bizstep:packing',
        'urn:epcglobal:cbv:bizstep:unpacking',
        'urn:epcglobal:cbv:bizstep:shipping',
        'urn:epcglobal:cbv:bizstep:receiving',
        'urn:epcglobal:cbv:bizstep:accepting',
        'urn:epcglobal:cbv:bizstep:arriving',
        'urn:epcglobal:cbv:bizstep:departing',
        'urn:epcglobal:cbv:bizstep:inspecting',
        'urn:epcglobal:cbv:bizstep:holding',
        'urn:epcglobal:cbv:bizstep:storing',
        'urn:epcglobal:cbv:bizstep:picking',
        'urn:epcglobal:cbv:bizstep:loading',
        'urn:epcglobal:cbv:bizstep:unloading',
        'urn:epcglobal:cbv:bizstep:void_shipping',
        // Carries the terminal dispositions custody honours ({@see \App\Support\Custody\TerminalEpcDisposition}),
        // so flagging it as non-CBV would fault a document we act on.
        'urn:epcglobal:cbv:bizstep:decommissioning',
        'urn:epcglobal:cbv:bizstep:destroying',
        'urn:epcglobal:cbv:bizstep:returning',
        'commissioning',
        'packing',
        'shipping',
        'receiving',
        'returning',
        'decommissioning',
    ];

    /**
     * @var list<string>
     */
    public const DISPOSITIONS = [
        'urn:epcglobal:cbv:disp:active',
        'urn:epcglobal:cbv:disp:in_progress',
        'urn:epcglobal:cbv:disp:in_transit',
        'urn:epcglobal:cbv:disp:encoded',
        'urn:epcglobal:cbv:disp:destroyed',
        'urn:epcglobal:cbv:disp:decommissioned',
        'urn:epcglobal:cbv:disp:reserved',
        'urn:epcglobal:cbv:disp:retail_sold',
        'urn:epcglobal:cbv:disp:returned',
        'urn:epcglobal:cbv:disp:expired',
        'urn:epcglobal:cbv:disp:recalled',
        // Terminal states custody refuses to operate on: a partner may report any of
        // them, and a value we act on cannot also be reported as non-CBV.
        'urn:epcglobal:cbv:disp:inactive',
        'urn:epcglobal:cbv:disp:stolen',
        'urn:epcglobal:cbv:disp:disposed',
        'active',
        'in_progress',
        'in_transit',
    ];

    public static function isAllowedAction(?string $action): bool
    {
        if ($action === null || $action === '') {
            return false;
        }

        return in_array(strtoupper(trim($action)), self::ACTIONS, true);
    }

    public static function isAllowedBizStep(?string $bizStep): bool
    {
        if ($bizStep === null || trim($bizStep) === '') {
            return true; // optional at CBV layer; mandatory rules handled elsewhere
        }

        $normalized = strtolower(trim($bizStep));

        foreach (self::BIZ_STEPS as $allowed) {
            if ($normalized === strtolower($allowed)) {
                return true;
            }
        }

        return false;
    }

    public static function isAllowedDisposition(?string $disposition): bool
    {
        if ($disposition === null || trim($disposition) === '') {
            return true;
        }

        $normalized = strtolower(trim($disposition));

        foreach (self::DISPOSITIONS as $allowed) {
            if ($normalized === strtolower($allowed)) {
                return true;
            }
        }

        return false;
    }

    public static function isCommissioning(?string $bizStep): bool
    {
        $normalized = strtolower((string) $bizStep);

        return str_contains($normalized, 'commissioning');
    }

    public static function isPacking(?string $bizStep): bool
    {
        $normalized = strtolower((string) $bizStep);

        return str_contains($normalized, 'packing') && ! str_contains($normalized, 'unpacking');
    }

    public static function isShipping(?string $bizStep): bool
    {
        return str_contains(strtolower((string) $bizStep), 'shipping');
    }

    public static function isReceiving(?string $bizStep): bool
    {
        return str_contains(strtolower((string) $bizStep), 'receiving');
    }

    public static function isUnpacking(?string $bizStep): bool
    {
        return str_contains(strtolower((string) $bizStep), 'unpacking');
    }
}

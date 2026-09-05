<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of authored (self-generated, direction=outbound) EPCIS document.
 *
 * Authored docs use direction=outbound (we wrote the file) but must not read
 * as a partner DSCSA shipment — this enum distinguishes what we authored it for.
 */
enum EpcisAuthoredKind: string
{
    case Shipping = 'shipping';
    case Receiving = 'receiving';
    case Transferring = 'transferring';
    case SsccCommissioning = 'sscc_commissioning';
    case SsccAggregation = 'sscc_aggregation';
    case SsccDisaggregation = 'sscc_disaggregation';
    case Decommissioning = 'decommissioning';
    case Returning = 'returning';
    case Commissioning = 'commissioning';
    case Transformation = 'transformation';

    /**
     * Friendly label for Type filter options and general UI use.
     */
    public function label(): string
    {
        return match ($this) {
            self::Shipping => 'Shipping',
            self::Receiving => 'Receiving',
            self::Transferring => 'Transferring',
            self::SsccCommissioning => 'SSCC commissioning',
            self::SsccAggregation => 'SSCC aggregation',
            self::SsccDisaggregation => 'SSCC disaggregation',
            self::Decommissioning => 'Decommissioning',
            self::Returning => 'Returning',
            self::Commissioning => 'Commissioning',
            self::Transformation => 'Transformation (repack)',
        };
    }

    /**
     * Alias of {@see label()} for Type filter dropdown options.
     */
    public function filterLabel(): string
    {
        return $this->label();
    }

    /**
     * Badge label matching EpcisDocument::directionDisplayLabel() historical text.
     * Shipping stays "Outbound" so authored shipping docs don't read as inbound
     * DSCSA shipments; the others are prefixed "Generated ..." for clarity.
     */
    public function displayLabel(): string
    {
        return match ($this) {
            self::Shipping => 'Outbound',
            self::Receiving => 'Generated receiving',
            self::Transferring => 'Generated transferring',
            self::SsccCommissioning => 'Generated SSCC commissioning',
            self::SsccAggregation => 'Generated SSCC aggregation',
            self::SsccDisaggregation => 'Generated SSCC disaggregation',
            self::Decommissioning => 'Generated decommissioning',
            self::Returning => 'Generated returning',
            self::Commissioning => 'Generated commissioning',
            self::Transformation => 'Generated transformation',
        };
    }

    /**
     * Infer the authored kind from legacy notes/filename heuristics for documents
     * generated before authored_kind existed. Mirrors the historical
     * EpcisDocument::directionDisplayLabel() detection order exactly.
     *
     * Returns null when no generated-kind heuristic matches (e.g. genuine partner
     * outbound documents with no TracePharma authoring markers).
     */
    public static function inferAuthoredKindFromNotesAndFilename(string $notes, string $filename): ?self
    {
        if (str_contains($notes, 'Generated receiving') || str_starts_with($filename, 'receiving-')) {
            return self::Receiving;
        }

        if (
            str_contains($notes, 'Generated SSCC commissioning')
            || str_contains($filename, '-commission.xml')
        ) {
            return self::SsccCommissioning;
        }

        if (
            str_contains($notes, 'Generated commissioning')
            || str_starts_with($filename, 'commission-all-')
        ) {
            return self::Commissioning;
        }

        if (
            str_contains($notes, 'Generated decommissioning')
            || str_starts_with($filename, 'decommission-')
        ) {
            return self::Decommissioning;
        }

        if (
            str_contains($notes, 'Generated returning')
            || str_starts_with($filename, 'returning-')
        ) {
            return self::Returning;
        }

        if (
            str_contains($notes, 'Generated SSCC aggregation')
            || (
                str_starts_with($filename, 'sscc-batch-')
                && ! str_contains($filename, 'commission')
            )
            || str_starts_with($filename, 'sscc-label-')
        ) {
            return self::SsccAggregation;
        }

        if (
            str_contains($notes, 'Generated SSCC disaggregation')
            || str_starts_with($filename, 'sscc-disaggregation-')
        ) {
            return self::SsccDisaggregation;
        }

        if (str_contains($notes, 'Generated transferring') || str_starts_with($filename, 'transfer-')) {
            return self::Transferring;
        }

        if (
            str_contains($notes, 'Generated TransformationEvent')
            || str_contains($notes, 'Generated transformation')
            || str_starts_with($filename, 'transformation-')
        ) {
            return self::Transformation;
        }

        if (
            str_contains($notes, 'Generated outbound shipping')
            || str_contains($notes, 'ship order session')
        ) {
            return self::Shipping;
        }

        return null;
    }
}

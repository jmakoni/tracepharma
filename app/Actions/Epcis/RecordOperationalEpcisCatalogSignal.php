<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;

/**
 * Operational hooks for catalog exception types that are not produced by inbound XML rules
 * (MDN, VRS, L2/L3, partner reject, auto-decommission). Call from future jobs/workflows.
 *
 * Deliberately excludes any code owned/emitted by {@see EpcisCatalogBusinessRules}
 * or {@see ValidateEpcis12Document} (e.g. TIMING_INVERSION, MISSING_COMMISSIONING,
 * DECOMMISSIONED_SERIAL_SHIPPED, DELETE_WITHOUT_PRIOR_ADD) — those are clearable/rewritten on every
 * validation pass, so a manual hook writing the same code would be immediately clobbered or would
 * fight the validator for ownership of the row.
 */
final class RecordOperationalEpcisCatalogSignal
{
    public function __construct(
        private readonly RecordOperationalEpcisException $recorder,
    ) {}

    public function partnerRejected(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle(
            $document,
            'PARTNER_REJECTED_FILE',
            'Trading partner rejected the EPCIS document: '.$detail,
        );
    }

    public function missingMdn(EpcisDocument $document, string $detail = 'No Message Delivery Notification received.'): EpcisException
    {
        return $this->recorder->handle($document, 'MISSING_MDN', $detail);
    }

    public function lateMdn(EpcisDocument $document, string $detail = 'MDN received after the expected window.'): EpcisException
    {
        return $this->recorder->handle($document, 'LATE_MDN', $detail);
    }

    public function verificationFailed(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'VERIFICATION_FAILED', $detail);
    }

    public function suspectProduct(EpcisDocument $document, string $detail, ?int $epcId = null): EpcisException
    {
        return $this->recorder->handle($document, 'SUSPECT_PRODUCT', $detail, null, null, $epcId);
    }

    public function l2L3ReconciliationFailure(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'L2_L3_RECONCILIATION_FAILURE', $detail);
    }

    public function l3TransmissionFailure(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'L3_TRANSMISSION_FAILURE', $detail);
    }

    public function autoDecommissionFailed(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'AUTO_DECOMMISSION_FAILED', $detail);
    }

    public function leadingZeroStripped(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'LEADING_ZERO_STRIPPED', $detail);
    }

    public function encodingError(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'ENCODING_ERROR', $detail);
    }

    public function packagingTypeConflict(EpcisDocument $document, string $detail, ?int $eventId = null): EpcisException
    {
        return $this->recorder->handle($document, 'PACKAGING_TYPE_CONFLICT', $detail, null, $eventId);
    }

    public function gtinSerialMismatch(EpcisDocument $document, string $detail, ?int $epcId = null): EpcisException
    {
        return $this->recorder->handle($document, 'GTIN_SERIAL_MISMATCH', $detail, null, null, $epcId);
    }

    public function lotMismatch(EpcisDocument $document, string $detail, ?int $epcId = null): EpcisException
    {
        return $this->recorder->handle($document, 'LOT_MISMATCH', $detail, null, null, $epcId);
    }

    public function quantityMismatch(EpcisDocument $document, string $detail, ?int $eventId = null): EpcisException
    {
        return $this->recorder->handle($document, 'QUANTITY_MISMATCH', $detail, null, $eventId);
    }

    public function partialShipmentUndeclared(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'PARTIAL_SHIPMENT_UNDECLARED', $detail);
    }

    public function overShipment(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'OVER_SHIPMENT', $detail);
    }

    public function invalidExtensionNamespace(EpcisDocument $document, string $detail, ?int $eventId = null): EpcisException
    {
        return $this->recorder->handle($document, 'INVALID_EXTENSION_NAMESPACE', $detail, null, $eventId);
    }

    public function unclassified(EpcisDocument $document, string $detail): EpcisException
    {
        return $this->recorder->handle($document, 'UNCLASSIFIED', $detail);
    }
}

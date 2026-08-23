<?php

namespace App\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;

/**
 * Profile-aware receiving behavior: which unit level a tenant scans first,
 * whether children auto-confirm with their parent, and who is allowed to
 * unpack at (or after) receive. HUD copy in ViewReceivingSession / its blade
 * is driven from promptCopy() instead of hardcoded pallet-only strings.
 */
final class ReceivingPolicy
{
    public function __construct(
        private readonly TenantProfile $profile,
        private readonly ?ReceivingEdgeMode $edgeModeOverride = null,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        $profile = $tenant?->profile ?? TenantProfile::Pharmacy;
        $override = $tenant !== null
            ? TenantSettings::forTenant($tenant)->receivingEdgeMode()
            : null;

        return new self($profile, $override);
    }

    public static function forProfile(TenantProfile $profile): self
    {
        return new self($profile);
    }

    public function edgeMode(): ReceivingEdgeMode
    {
        if ($this->edgeModeOverride !== null) {
            return $this->edgeModeOverride;
        }

        if ($this->profileDefaultAutoConfirmChildren()) {
            return $this->profilePreferredScanLevel() === ReceivingScanLevel::ToteOrCase
                ? ReceivingEdgeMode::ToteLpn
                : ReceivingEdgeMode::SealedParent;
        }

        return ReceivingEdgeMode::OpenCount;
    }

    public function resolveKind(?ReceivingSession $session): ReceivingSessionKind
    {
        if ($session === null) {
            return ReceivingSessionKind::InboundAsn;
        }

        return $session->session_kind ?? ReceivingSessionKind::InboundAsn;
    }

    /**
     * Pharmacy dispensers scan tote/case first (they don't typically receive
     * full sealed pallets); distributor-style profiles scan the pallet SSCC.
     */
    public function preferredScanLevel(): ReceivingScanLevel
    {
        return match ($this->edgeMode()) {
            ReceivingEdgeMode::ToteLpn => ReceivingScanLevel::ToteOrCase,
            default => $this->profilePreferredScanLevel(),
        };
    }

    /**
     * Whether the "sealed pallet" checkbox defaults to checked for this profile.
     */
    public function defaultAutoConfirmChildren(): bool
    {
        return match ($this->edgeMode()) {
            ReceivingEdgeMode::SealedParent, ReceivingEdgeMode::ToteLpn => true,
            ReceivingEdgeMode::OpenCount, ReceivingEdgeMode::OpenTote => false,
        };
    }

    private function profilePreferredScanLevel(): ReceivingScanLevel
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy => ReceivingScanLevel::ToteOrCase,
            default => ReceivingScanLevel::Pallet,
        };
    }

    private function profileDefaultAutoConfirmChildren(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    /**
     * Authored receiving ObjectEvent epcList includes confirmed children
     * (auto-confirmed under sealed parents) for local custody completeness.
     * Partner transmit may later filter to observed parents only.
     */
    public function receiptIncludesConfirmedChildren(): bool
    {
        return true;
    }

    /**
     * Only pharmacy (dispenser) can unpack inline while receiving.
     */
    public function canUnpackAtReceive(): bool
    {
        return $this->profile === TenantProfile::Pharmacy;
    }

    /**
     * Wholesaler/3PL/prepackager/dental style profiles that support unpacking
     * generally, but not inline at receive — they unpack as a separate step.
     */
    public function canUnpackAfterReceive(): bool
    {
        $features = new TenantFeatures($this->profile);

        return $features->supportsUnpacking() && ! $this->canUnpackAtReceive();
    }

    /**
     * HUD copy for the scan input helper, sealed-pallet checkbox, and confirm button.
     *
     * @return array{scanHelper: string, sealedPalletLabel: string, sealedPalletHelper: string, confirmLabelSealed: string, confirmLabel: string, kindHelper: string, confirmButton: string, unexpectedTitle: string, unexpectedBody: string, completeTitle: string, completeBody: string}
     */
    public function promptCopy(?ReceivingSession $session = null): array
    {
        $base = match ($this->preferredScanLevel()) {
            ReceivingScanLevel::ToteOrCase => [
                'scanHelper' => 'Scan SSCC or Case barcode',
                'sealedPalletLabel' => 'Sealed tote/case — confirm all units when I scan it',
                'sealedPalletHelper' => 'Applies to the next tote/case scan.',
                'confirmLabelSealed' => 'Confirm tote/case + units',
                'confirmLabel' => 'Confirm',
            ],
            ReceivingScanLevel::Case => [
                'scanHelper' => 'Scan case SSCC first, then units if needed.',
                'sealedPalletLabel' => 'Sealed case — confirm all units when I scan it',
                'sealedPalletHelper' => 'Applies to the next case scan.',
                'confirmLabelSealed' => 'Confirm case + units',
                'confirmLabel' => 'Confirm',
            ],
            ReceivingScanLevel::Pallet => [
                'scanHelper' => 'Scan pallet SSCC first.',
                'sealedPalletLabel' => 'Sealed pallet — confirm all units when I scan the pallet',
                'sealedPalletHelper' => 'Applies to the next pallet scan.',
                'confirmLabelSealed' => 'Confirm pallet + units',
                'confirmLabel' => 'Confirm',
            ],
        };

        $kind = $this->resolveKind($session);
        $sop = $this->edgeMode()->chipLabel();
        $copy = [
            ...$base,
            ...$this->kindHudCopy($kind, $base['scanHelper']),
        ];

        $copy['scanHelper'] = $sop.'. '.$copy['scanHelper'];
        $copy['kindHelper'] = $sop.'. '.$copy['kindHelper'];

        return $copy;
    }

    /**
     * @return array{kindHelper: string, confirmButton: string, unexpectedTitle: string, unexpectedBody: string, completeTitle: string, completeBody: string, scanHelper: string}
     */
    public function kindHudCopy(ReceivingSessionKind $kind, ?string $baseScanHelper = null): array
    {
        $scanHelper = $baseScanHelper ?? match ($this->preferredScanLevel()) {
            ReceivingScanLevel::ToteOrCase => 'Scan SSCC or Case barcode',
            ReceivingScanLevel::Case => 'Scan case SSCC first, then units if needed.',
            ReceivingScanLevel::Pallet => 'Scan pallet SSCC first.',
        };

        return match ($kind) {
            ReceivingSessionKind::ScanFirst => [
                'scanHelper' => $scanHelper.' — we check TI and match ASN/transfer when possible.',
                'kindHelper' => 'Scan-first: no ASN required. Known barcodes only (prior EPCIS or commissioning).',
                'confirmButton' => 'ADD',
                'unexpectedTitle' => 'Could not confirm this barcode',
                'unexpectedBody' => 'Barcode must already exist from prior EPCIS or commissioning. Check the label or raise an exception.',
                'completeTitle' => 'Receiving complete',
                'completeBody' => 'Confirmed units are received at this site. Receiving EPCIS events are authored on complete.',
            ],
            ReceivingSessionKind::TransferReceive => [
                'scanHelper' => 'Scan SSCC or SGTIN to receive at destination',
                'kindHelper' => 'Transfer receive: scan each expected EPC shipped on this transfer.',
                'confirmButton' => 'RECEIVE',
                'unexpectedTitle' => 'Not on this transfer',
                'unexpectedBody' => 'Logged as unexpected. Confirm the label matches a shipped transfer line.',
                'completeTitle' => 'Transfer receive complete',
                'completeBody' => 'All transfer lines received. Destination receiving EPCIS is on the transfer document.',
            ],
            ReceivingSessionKind::InboundAsn => [
                'scanHelper' => $scanHelper,
                'kindHelper' => 'ASN receive: confirm expected pallets and units from the inbound file.',
                'confirmButton' => 'ADD',
                'unexpectedTitle' => 'Not on this ASN — do not put away',
                'unexpectedBody' => 'Logged as Unexpected below. Check the label or raise an exception.',
                'completeTitle' => 'Receiving complete',
                'completeBody' => 'All expected pallets and units are confirmed for this session.',
            ],
        };
    }
}

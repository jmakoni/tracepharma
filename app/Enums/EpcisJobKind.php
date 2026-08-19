<?php

declare(strict_types=1);

namespace App\Enums;

enum EpcisJobKind: string
{
    case OutboundShipping = 'outbound_shipping';
    case OutboundReceiving = 'outbound_receiving';
    case OutboundTransferring = 'outbound_transferring';
    case OutboundSsccCommissioning = 'outbound_sscc_commissioning';
    case OutboundSsccAggregation = 'outbound_sscc_aggregation';
    case OutboundSsccDisaggregation = 'outbound_sscc_disaggregation';
    case InboundProcess = 'inbound_process'; // Phase 2
    case OutboundDecommission = 'outbound_decommission';
    case OutboundDestroy = 'outbound_destroy'; // reserved
    case OutboundReturning = 'outbound_returning';
    case OutboundCommissioning = 'outbound_commissioning';
    case OutboundApi = 'outbound_api';

    public function label(): string
    {
        return match ($this) {
            self::OutboundShipping => 'Outbound shipping',
            self::OutboundReceiving => 'Outbound receiving',
            self::OutboundTransferring => 'Outbound transferring',
            self::OutboundSsccCommissioning => 'SSCC commissioning',
            self::OutboundSsccAggregation => 'SSCC aggregation',
            self::OutboundSsccDisaggregation => 'SSCC disaggregation',
            self::InboundProcess => 'Inbound process',
            self::OutboundDecommission => 'Decommission',
            self::OutboundDestroy => 'Destroy',
            self::OutboundReturning => 'Returning',
            self::OutboundCommissioning => 'Commissioning',
            self::OutboundApi => 'Outbound API',
        };
    }

    public function isPhase1Outbound(): bool
    {
        return in_array($this, [
            self::OutboundShipping,
            self::OutboundReceiving,
            self::OutboundTransferring,
            self::OutboundSsccCommissioning,
            self::OutboundSsccAggregation,
            self::OutboundSsccDisaggregation,
            self::OutboundDecommission,
            self::OutboundReturning,
            self::OutboundCommissioning,
            self::OutboundApi,
        ], true);
    }

    public function isInboundProcess(): bool
    {
        return $this === self::InboundProcess;
    }

    public static function fromAuthoredKind(EpcisAuthoredKind $kind): self
    {
        return match ($kind) {
            EpcisAuthoredKind::Shipping => self::OutboundShipping,
            EpcisAuthoredKind::Receiving => self::OutboundReceiving,
            EpcisAuthoredKind::Transferring => self::OutboundTransferring,
            EpcisAuthoredKind::SsccCommissioning => self::OutboundSsccCommissioning,
            EpcisAuthoredKind::SsccAggregation => self::OutboundSsccAggregation,
            EpcisAuthoredKind::SsccDisaggregation => self::OutboundSsccDisaggregation,
            EpcisAuthoredKind::Decommissioning => self::OutboundDecommission,
            EpcisAuthoredKind::Returning => self::OutboundReturning,
            EpcisAuthoredKind::Commissioning => self::OutboundCommissioning,
        };
    }
}

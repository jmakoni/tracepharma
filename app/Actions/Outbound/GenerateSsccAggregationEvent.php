<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Enums\OutboundEpcisAggregationMode;
use App\Models\SsccLabel;
use App\Services\Epcis\Outbound\AggregationEventChildrenRenderer;

final class GenerateSsccAggregationEvent
{
    public function __construct(
        private readonly AggregationEventChildrenRenderer $childrenRenderer,
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly AssertAuthoredAggregationCandidate $assertCandidate,
    ) {}

    /**
     * @param  list<string>  $childEpcs
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function execute(
        SsccLabel $label,
        array $childEpcs,
        array $quantityChildren = [],
        ?OutboundEpcisAggregationMode $mode = null,
        ?array $settings = null,
        ?int $siteId = null,
    ): string {
        $mode ??= $this->resolveMode($childEpcs, $quantityChildren);
        $this->assertHasChildren($childEpcs, $quantityChildren, $mode);

        $settings ??= [];
        $bizStep = (string) ($settings['biz_step'] ?? 'packing');
        $disposition = (string) ($settings['disposition'] ?? 'in_progress');

        $candidate = $this->assertCandidate->handle(
            parentUri: (string) $label->sscc_urn,
            childEpcs: $childEpcs,
            action: EpcisAction::Add,
            bizStep: $bizStep,
            disposition: $disposition,
            quantityChildren: $quantityChildren,
        );

        $sglnUrn = htmlspecialchars($this->resolveSglnUrn($settings, $siteId), ENT_XML1);
        $eventTime = htmlspecialchars($this->resolveEventTimeIso($settings), ENT_XML1);
        $parent = htmlspecialchars($candidate->parentId, ENT_XML1);
        $bizStepXml = htmlspecialchars($candidate->bizStep, ENT_XML1);
        $dispositionXml = htmlspecialchars($candidate->disposition, ENT_XML1);
        $childrenXml = $this->childrenRenderer->renderForMovement(
            $candidate->childEpcs,
            $candidate->childQuantityList,
            $mode,
        );

        return <<<XML
            <AggregationEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
                <parentID>{$parent}</parentID>
{$childrenXml}                <action>ADD</action>
                <bizStep>{$bizStepXml}</bizStep>
                <disposition>{$dispositionXml}</disposition>
                <readPoint>
                    <id>{$sglnUrn}</id>
                </readPoint>
                <bizLocation>
                    <id>{$sglnUrn}</id>
                </bizLocation>
            </AggregationEvent>
XML;
    }

    /**
     * @param  list<string>  $childEpcs
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    private function resolveMode(array $childEpcs, array $quantityChildren): OutboundEpcisAggregationMode
    {
        $hasInstances = $this->hasInstanceChildren($childEpcs);
        $hasClasses = $this->hasQuantityChildren($quantityChildren);

        return match (true) {
            $hasInstances && $hasClasses => OutboundEpcisAggregationMode::Hybrid,
            $hasClasses => OutboundEpcisAggregationMode::ClassOnly,
            default => OutboundEpcisAggregationMode::InstanceOnly,
        };
    }

    /**
     * @param  list<string>  $childEpcs
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    private function assertHasChildren(
        array $childEpcs,
        array $quantityChildren,
        OutboundEpcisAggregationMode $mode,
    ): void {
        $hasInstances = $this->hasInstanceChildren($childEpcs);
        $hasClasses = $this->hasQuantityChildren($quantityChildren);

        if ($mode === OutboundEpcisAggregationMode::ClassOnly && $hasClasses) {
            return;
        }

        if ($mode->emitsInstanceChildren() && $hasInstances) {
            return;
        }

        if ($mode->emitsClassChildren() && $hasClasses) {
            return;
        }

        throw new \InvalidArgumentException('At least one child EPC or quantity child is required for aggregation.');
    }

    /**
     * @param  list<string>  $childEpcs
     */
    private function hasInstanceChildren(array $childEpcs): bool
    {
        foreach ($childEpcs as $childEpc) {
            if (trim((string) $childEpc) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    private function hasQuantityChildren(array $quantityChildren): bool
    {
        foreach ($quantityChildren as $row) {
            if (! is_array($row)) {
                continue;
            }

            $epcClass = (string) ($row['epcClass'] ?? $row['epc_class'] ?? '');

            if ($epcClass !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}  $settings
     */
    private function resolveSglnUrn(array $settings, ?int $siteId): string
    {
        $sglnUrn = trim((string) ($settings['sgln_urn'] ?? ''));

        if ($sglnUrn !== '') {
            return $sglnUrn;
        }

        return $this->resolveLocation->handle($siteId)['sgln_urn'];
    }

    /**
     * @param  array{event_time?: \Carbon\CarbonInterface|string}  $settings
     */
    private function resolveEventTimeIso(array $settings): string
    {
        $eventTime = $settings['event_time'] ?? now();

        if ($eventTime instanceof \Carbon\CarbonInterface) {
            return $eventTime->toIso8601String();
        }

        return \Illuminate\Support\Carbon::parse($eventTime)->toIso8601String();
    }
}

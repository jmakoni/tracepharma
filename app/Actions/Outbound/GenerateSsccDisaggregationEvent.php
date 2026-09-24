<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Models\Site;
use App\Support\Epcis\AuthoredEventTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class GenerateSsccDisaggregationEvent
{
    public function __construct(
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly AssertAuthoredAggregationCandidate $assertCandidate,
    ) {}

    /**
     * @param  list<string>  $childEpcs
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: CarbonInterface|string}|null  $settings
     */
    public function execute(
        string $parentEpcUrn,
        array $childEpcs,
        ?array $settings = null,
        ?int $siteId = null,
        string $bizTransactionsXml = '',
    ): string {
        $settings ??= [];
        $bizStep = (string) ($settings['biz_step'] ?? 'unpacking');
        $disposition = (string) ($settings['disposition'] ?? 'in_progress');

        $normalizedChildren = [];
        foreach (array_unique($childEpcs) as $childEpc) {
            $childEpc = trim((string) $childEpc);
            if ($childEpc !== '') {
                $normalizedChildren[] = $childEpc;
            }
        }

        if ($normalizedChildren === []) {
            throw new \InvalidArgumentException('At least one child EPC is required for disaggregation.');
        }

        $candidate = $this->assertCandidate->handle(
            parentUri: $parentEpcUrn,
            childEpcs: $normalizedChildren,
            action: EpcisAction::Delete,
            bizStep: $bizStep,
            disposition: $disposition,
        );

        $sglnUrn = htmlspecialchars($this->resolveSglnUrn($settings, $siteId), ENT_XML1);
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $eventTimeCarbon = $this->resolveEventTimeCarbon($settings);
        $timezoneOffset = htmlspecialchars(
            AuthoredEventTimezone::offsetForSite($site instanceof Site ? $site : null, $eventTimeCarbon),
            ENT_XML1,
        );
        $eventTime = htmlspecialchars($this->resolveEventTimeIso($settings), ENT_XML1);
        $parent = htmlspecialchars($candidate->parentId, ENT_XML1);
        $bizStepXml = htmlspecialchars($candidate->bizStep, ENT_XML1);
        $dispositionXml = htmlspecialchars($candidate->disposition, ENT_XML1);

        $childXml = '';
        foreach ($candidate->childEpcs as $childEpc) {
            $childEpc = htmlspecialchars($childEpc, ENT_XML1);
            $childXml .= "                    <epc>{$childEpc}</epc>\n";
        }

        $bizTransactionsBlock = $bizTransactionsXml !== ''
            ? "\n                {$bizTransactionsXml}\n"
            : '';

        return <<<XML
            <AggregationEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>{$timezoneOffset}</eventTimeZoneOffset>
                <parentID>{$parent}</parentID>
                <childEPCs>
{$childXml}                </childEPCs>
                <action>DELETE</action>
                <bizStep>{$bizStepXml}</bizStep>
                <disposition>{$dispositionXml}</disposition>
                <readPoint>
                    <id>{$sglnUrn}</id>
                </readPoint>
                <bizLocation>
                    <id>{$sglnUrn}</id>
                </bizLocation>{$bizTransactionsBlock}
            </AggregationEvent>
XML;
    }

    /**
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: CarbonInterface|string}  $settings
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
     * @param  array{event_time?: CarbonInterface|string}  $settings
     */
    private function resolveEventTimeIso(array $settings): string
    {
        $eventTime = $settings['event_time'] ?? now();

        if ($eventTime instanceof CarbonInterface) {
            return $eventTime->toIso8601String();
        }

        return Carbon::parse($eventTime)->toIso8601String();
    }

    /**
     * @param  array{event_time?: CarbonInterface|string}  $settings
     */
    private function resolveEventTimeCarbon(array $settings): Carbon
    {
        $eventTime = $settings['event_time'] ?? now();

        if ($eventTime instanceof CarbonInterface) {
            return Carbon::instance($eventTime);
        }

        return Carbon::parse($eventTime);
    }
}

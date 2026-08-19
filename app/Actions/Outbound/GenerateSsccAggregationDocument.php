<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;

final class GenerateSsccAggregationDocument
{
    public function __construct(
        private readonly GenerateSsccAggregationEvent $aggregationEvent,
        private readonly OutboundEpcisXmlBuilder $xmlBuilder,
    ) {}

    /**
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function forBatch(
        SsccLabelBatch $batch,
        ?string $correlationId = null,
        ?int $siteId = null,
        ?array $settings = null,
    ): string {
        $batch->loadMissing(['labels.children']);

        $events = '';

        foreach ($batch->labels as $label) {
            $childEpcs = $label->children->pluck('child_epc')->all();

            if ($childEpcs === []) {
                continue;
            }

            $events .= $this->aggregationEvent->execute($label, $childEpcs, settings: $settings, siteId: $siteId)."\n";
        }

        if (trim($events) === '') {
            throw new \InvalidArgumentException('No labels in this batch have child EPCs for aggregation.');
        }

        return $this->xmlBuilder->buildDocument($this->resolveCreationDateIso($settings), $events, $correlationId);
    }

    /**
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function forLabel(
        SsccLabel $label,
        ?string $correlationId = null,
        ?int $siteId = null,
        ?array $settings = null,
    ): string {
        $label->loadMissing('children');

        return $this->forLabelChildren(
            $label,
            $label->children->pluck('child_epc')->all(),
            $correlationId,
            $siteId,
            $settings,
        );
    }

    /**
     * Incremental packing ADD for a specific child set (GS1 action=ADD).
     *
     * @param  list<string>  $childEpcs
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function forLabelChildren(
        SsccLabel $label,
        array $childEpcs,
        ?string $correlationId = null,
        ?int $siteId = null,
        ?array $settings = null,
    ): string {
        $event = $this->aggregationEvent->execute($label, $childEpcs, settings: $settings, siteId: $siteId);

        return $this->xmlBuilder->buildDocument($this->resolveCreationDateIso($settings), $event, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(array $payload): string
    {
        if (isset($payload['sscc_label_batch_id'])) {
            $batch = SsccLabelBatch::query()
                ->with(['labels.children'])
                ->findOrFail((int) $payload['sscc_label_batch_id']);

            return $this->forBatch($batch, $payload['correlation_id'] ?? null);
        }

        if (isset($payload['sscc_label_id'])) {
            $label = SsccLabel::query()->findOrFail((int) $payload['sscc_label_id']);

            return $this->forLabel($label, $payload['correlation_id'] ?? null);
        }

        throw new \InvalidArgumentException('Aggregation payload requires sscc_label_batch_id or sscc_label_id.');
    }

    /**
     * @param  array{event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    private function resolveCreationDateIso(?array $settings): string
    {
        if ($settings === null || ! array_key_exists('event_time', $settings) || $settings['event_time'] === null) {
            return now()->toIso8601String();
        }

        $eventTime = $settings['event_time'];

        if ($eventTime instanceof \Carbon\CarbonInterface) {
            return $eventTime->toIso8601String();
        }

        return \Illuminate\Support\Carbon::parse($eventTime)->toIso8601String();
    }
}

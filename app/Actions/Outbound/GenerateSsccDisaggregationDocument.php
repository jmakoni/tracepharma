<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Models\SsccLabelBatch;
use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;

final class GenerateSsccDisaggregationDocument
{
    public function __construct(
        private readonly GenerateSsccDisaggregationEvent $disaggregationEvent,
        private readonly OutboundEpcisXmlBuilder $xmlBuilder,
    ) {}

    /**
     * @param  list<string>  $childEpcs
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function forSourcePallet(
        string $parentEpcUrn,
        array $childEpcs,
        ?string $correlationId = null,
        ?int $siteId = null,
        ?array $settings = null,
    ): string {
        if ($childEpcs === []) {
            throw new \InvalidArgumentException('At least one child EPC is required for disaggregation.');
        }

        $event = $this->disaggregationEvent->execute($parentEpcUrn, $childEpcs, $settings, $siteId);

        return $this->xmlBuilder->buildDocument($this->resolveCreationDateIso($settings), $event, $correlationId);
    }

    /**
     * @param  array{biz_step?: string, disposition?: string, sgln_urn?: string, gln?: string, event_time?: \Carbon\CarbonInterface|string}|null  $settings
     */
    public function forBatch(
        SsccLabelBatch $batch,
        ?string $correlationId = null,
        ?int $siteId = null,
        ?array $settings = null,
    ): string {
        if ($batch->source_parent_sscc_urn === null) {
            throw new \InvalidArgumentException('This batch is not linked to a source pallet for disaggregation.');
        }

        $batch->loadMissing(['labels.children']);

        $childEpcs = $batch->labels
            ->flatMap(fn ($label) => $label->children->pluck('child_epc'))
            ->unique()
            ->values()
            ->all();

        return $this->forSourcePallet(
            (string) $batch->source_parent_sscc_urn,
            $childEpcs,
            $correlationId,
            $siteId,
            $settings,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(array $payload): string
    {
        $parent = (string) ($payload['parent_epc_urn'] ?? $payload['source_parent_sscc_urn'] ?? '');

        if ($parent === '') {
            throw new \InvalidArgumentException('Disaggregation payload requires parent_epc_urn.');
        }

        $childEpcs = $payload['child_epcs'] ?? [];

        if (is_string($childEpcs)) {
            $childEpcs = preg_split('/\R/', $childEpcs) ?: [];
        }

        return $this->forSourcePallet(
            $parent,
            array_values(array_filter(array_map('trim', $childEpcs))),
            $payload['correlation_id'] ?? null,
        );
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

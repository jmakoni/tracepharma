<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;
use Illuminate\Support\Collection;

final class GenerateSsccCommissioningDocument
{
    public function __construct(
        private readonly GenerateSsccCommissioningEvent $commissioningEvent,
        private readonly OutboundEpcisXmlBuilder $xmlBuilder,
    ) {}

    /**
     * @param  Collection<int, SsccLabel>|null  $labels
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function forBatch(SsccLabelBatch $batch, ?Collection $labels = null, ?string $correlationId = null, ?int $siteId = null, ?array $settings = null): string
    {
        $labels ??= $batch->labels()->whereNull('commissioned_at')->get();

        $events = '';

        foreach ($labels as $label) {
            if (strlen(preg_replace('/\D/', '', (string) $label->sscc_18) ?? '') !== 18) {
                continue;
            }

            if (! filled($label->sscc_urn)) {
                continue;
            }

            $events .= $this->commissioningEvent->execute($label, $siteId, $settings)."\n";
        }

        if (trim($events) === '') {
            throw new \InvalidArgumentException('No SSCC labels available for commissioning.');
        }

        return $this->xmlBuilder->buildDocument(now()->toIso8601String(), $events, $correlationId);
    }

    /**
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function forLabel(SsccLabel $label, ?string $correlationId = null, ?int $siteId = null, ?array $settings = null): string
    {
        $event = $this->commissioningEvent->execute($label, $siteId, $settings);

        return $this->xmlBuilder->buildDocument(now()->toIso8601String(), $event, $correlationId);
    }
}

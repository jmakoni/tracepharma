<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;
use InvalidArgumentException;

/**
 * Wrap one or more disposition ObjectEvent fragments in an EPCIS 1.2 document.
 */
final class GenerateDispositionEpcisDocument
{
    public function __construct(
        private readonly GenerateDispositionObjectEvent $eventBuilder,
        private readonly OutboundEpcisXmlBuilder $xmlBuilder,
    ) {}

    /**
     * @param  list<string>  $epcUris
     * @param  GenerateDispositionObjectEvent::KIND_*  $kind
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function execute(
        array $epcUris,
        string $kind,
        ?int $siteId = null,
        ?string $correlationId = null,
        ?array $settings = null,
    ): string {
        $events = '';

        foreach ($epcUris as $epcUri) {
            $uri = trim((string) $epcUri);
            if ($uri === '') {
                continue;
            }

            $events .= $this->eventBuilder->execute($uri, $kind, $siteId, $settings)."\n";
        }

        if (trim($events) === '') {
            throw new InvalidArgumentException('No EPC URIs available for disposition EPCIS.');
        }

        return $this->xmlBuilder->buildDocument(now()->toIso8601String(), $events, $correlationId);
    }
}

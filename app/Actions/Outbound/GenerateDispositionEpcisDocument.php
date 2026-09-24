<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Domain\Epcis\EpcisEventFactory;
use App\Enums\EpcisGuideline;
use App\Models\Epcis\Epc;
use App\Services\Epcis\Outbound\JsonLd20Writer;
use App\Services\Epcis\Outbound\OutboundEpcisWriterResolver;
use App\Services\Epcis\Outbound\Xml12Writer;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\GroupCommissioningEpcUris;
use App\Support\Epcis\OutboundCorrelationGlns;
use App\Support\Epcis\OutboundEpcClassVocabulary;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Wrap one or more disposition ObjectEvents in an EPCIS document (1.2 XML default; 2.0 JSON-LD opt-in).
 */
final class GenerateDispositionEpcisDocument
{
    public function __construct(
        private readonly GenerateDispositionObjectEvent $eventBuilder,
        private readonly AssertAuthoredObjectEventCandidate $assertCandidate,
        private readonly Xml12Writer $xml12Writer,
        private readonly JsonLd20Writer $jsonLd20Writer,
        private readonly OutboundEpcisWriterResolver $writerResolver,
        private readonly EpcisEventFactory $eventFactory,
    ) {}

    /**
     * @param  list<string>  $epcUris
     * @param  GenerateDispositionObjectEvent::KIND_*  $kind
     * @param  array{sgln_urn?: string, epcis_document_version?: string}|null  $settings
     */
    public function execute(
        array $epcUris,
        string $kind,
        ?int $siteId = null,
        ?string $correlationId = null,
        ?array $settings = null,
    ): string {
        $settings ??= [];
        $version = (string) ($settings['epcis_document_version'] ?? EpcisSchemaVersion::V12);
        $writer = $this->writerResolver->forVersion($version);

        if ($writer->schemaVersion() === EpcisSchemaVersion::V20) {
            return $this->buildJson20($epcUris, $kind, $siteId, $correlationId, $settings);
        }

        $events = '';

        foreach ($this->eventUriGroups($epcUris, $kind) as $uris) {
            $events .= $this->eventBuilder->executeGroup($uris, $kind, $siteId, $settings)."\n";
        }

        if (trim($events) === '') {
            throw new InvalidArgumentException('No EPC URIs available for disposition EPCIS.');
        }

        [$senderGln, $receiverGln] = OutboundCorrelationGlns::forSelfAuthored($correlationId, $settings, $siteId);

        $epcs = Epc::query()
            ->whereIn('epc_uri', array_values(array_filter(array_map(
                static fn (string $uri): string => trim($uri),
                $epcUris,
            ))))
            ->get();

        return $this->xml12Writer->buildDocument(
            now()->toIso8601String(),
            $events,
            $correlationId,
            $senderGln,
            $receiverGln,
            OutboundEpcClassVocabulary::masterDataXml($epcs, EpcisGuideline::R12),
        );
    }

    /**
     * @param  list<string>  $epcUris
     * @param  GenerateDispositionObjectEvent::KIND_*  $kind
     * @param  array{sgln_urn?: string}  $settings
     */
    private function buildJson20(
        array $epcUris,
        string $kind,
        ?int $siteId,
        ?string $correlationId,
        array $settings,
    ): string {
        [$action, $bizStep, $disposition] = match ($kind) {
            GenerateDispositionObjectEvent::KIND_COMMISSIONING => [
                EpcisAction::Add,
                'commissioning',
                'active',
            ],
            GenerateDispositionObjectEvent::KIND_DECOMMISSIONING => [
                EpcisAction::Delete,
                ...$this->eventBuilder->decommissioningStepAndDisposition($settings),
            ],
            GenerateDispositionObjectEvent::KIND_RETURNING => [
                EpcisAction::Observe,
                'returning',
                'returned',
            ],
            GenerateDispositionObjectEvent::KIND_DISPENSING => [
                EpcisAction::Observe,
                'dispensing',
                'dispensed',
            ],
            GenerateDispositionObjectEvent::KIND_INSPECTING => [
                EpcisAction::Observe,
                'inspecting',
                'active',
            ],
            default => throw new InvalidArgumentException("Unsupported disposition kind [{$kind}]."),
        };

        $sglnUrn = $this->eventBuilder->resolveLocationUrn($settings, $siteId);
        $eventTimeUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $domainEvents = [];

        foreach ($this->eventUriGroups($epcUris, $kind) as $uris) {
            // Same pre-author hard-gate as XML path (GenerateDispositionObjectEvent::executeGroup).
            $this->assertCandidate->handle(
                epcList: $uris,
                action: $action,
                bizStep: $bizStep,
                disposition: $disposition,
                eventTimeUtc: $eventTimeUtc,
            );

            $domainEvents[] = $this->eventFactory->objectEvent(
                epcList: $uris,
                action: $action,
                bizStep: $bizStep,
                disposition: $disposition,
                eventTimeUtc: $eventTimeUtc,
                readPoint: $sglnUrn,
                bizLocation: $sglnUrn,
            );
        }

        if ($domainEvents === []) {
            throw new InvalidArgumentException('No EPC URIs available for disposition EPCIS.');
        }

        return $this->jsonLd20Writer->buildFromDomainEvents($domainEvents, now()->toIso8601String(), $correlationId);
    }

    /**
     * Commission: one ObjectEvent per GTIN-14 + lot + expiry. Other kinds stay one URI each.
     *
     * @param  list<string>  $epcUris
     * @param  GenerateDispositionObjectEvent::KIND_*  $kind
     * @return list<list<string>>
     */
    private function eventUriGroups(array $epcUris, string $kind): array
    {
        if ($kind === GenerateDispositionObjectEvent::KIND_COMMISSIONING) {
            return GroupCommissioningEpcUris::group($epcUris);
        }

        $groups = [];
        foreach ($epcUris as $epcUri) {
            $uri = trim((string) $epcUri);
            if ($uri !== '') {
                $groups[] = [$uri];
            }
        }

        return $groups;
    }

    private function resolveDispositionLocal(?string $disposition, string $default): string
    {
        $value = strtolower(trim((string) $disposition));
        if ($value === '') {
            return $default;
        }

        if (str_contains($value, ':')) {
            $value = (string) str($value)->afterLast(':');
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }
}

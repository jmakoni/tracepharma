<?php

declare(strict_types=1);

namespace App\Actions\L3;

use App\Actions\Outbound\AssertAuthoredAggregationCandidate;
use App\Actions\Outbound\AssertAuthoredObjectEventCandidate;
use App\Actions\Outbound\ResolveSsccAuthoredLocation;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Jobs\L3\ConvertAndAcceptGuardianLotJob;
use App\Models\Site;
use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;
use App\Services\L3\GuardianDataFeedParser;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Author EPCIS 1.2 commissioning + aggregation XML from a parsed Guardian
 * lot-close `DataFeed` ({@see GuardianDataFeedParser}).
 *
 * Commissioning ObjectEvents run in chunks of ~200 EPCs (further split by
 * distinct resolved event time), carrying ILMD lotNumber/itemExpirationDate
 * for product-identity containers (Case/Bottle); pallets commission as pure
 * logistics identity, no ILMD. AggregationEvents (packing / in_progress) are
 * emitted bottom-up — bottle-into-case before case-into-pallet — so hierarchy
 * events are never authored ahead of the container they depend on.
 *
 * `xmlns:cbvmda` is declared locally on each `<ilmd>` element rather than on
 * the document root: {@see OutboundEpcisXmlBuilder} owns the shared document
 * wrapper, and a namespace scoped to the ilmd subtree is equally valid XML
 * that the ingest parser's namespace-aware lookup already resolves.
 *
 * Every candidate ObjectEvent/AggregationEvent runs through the same
 * pre-persist hard gates as interactively-authored SSCC events
 * ({@see AssertAuthoredObjectEventCandidate}, {@see AssertAuthoredAggregationCandidate})
 * before any XML is emitted: a malformed GS1 identifier in the Guardian feed
 * fails the whole authoring call rather than reaching the EPCIS pipeline.
 * Structural hard gates (missing container URI, unsupported container Type,
 * Case/Bottle count vs `LotControlData.CaseQty`) run even earlier, before any
 * event grouping happens at all.
 *
 * `eventID` is deterministic (UUID v5 over the correlation id + event shape),
 * not random: re-authoring the same feed (retry, reprocess) reproduces the
 * same event IDs instead of minting new ones every attempt.
 */
final class AuthorGuardianLotEpcisDocument
{
    private const CHUNK_SIZE = 200;

    /**
     * The only container types this authoring path understands. A Guardian
     * `Type` outside this set (e.g. `Bundle`) is rejected fail-closed rather
     * than silently commissioned as an unknown logistics unit.
     */
    private const SUPPORTED_CONTAINER_TYPES = ['Pallet', 'Case', 'Bottle'];

    /**
     * Fixed namespace for {@see Uuid::uuid5()} event-ID derivation — arbitrary but
     * stable, so the same (correlation id, event shape) always yields the same UUID.
     */
    private const EVENT_ID_NAMESPACE = '2f7b6b6e-2f1a-4b7d-9b7e-8b7b6a6f9d21';

    public function __construct(
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly OutboundEpcisXmlBuilder $xmlBuilder,
        private readonly AssertAuthoredObjectEventCandidate $assertObjectEvent,
        private readonly AssertAuthoredAggregationCandidate $assertAggregation,
    ) {}

    /**
     * @param  array{
     *     lot_number: ?string,
     *     expire_date: ?string,
     *     lot_processed_at: ?string,
     *     timezone_offset: ?string,
     *     lot_control_data: array<string, string>,
     *     containers: list<array{type: ?string, epc_uri: ?string, parent_epc_uri: ?string, fields: array<string, string>, event_time: ?string, timezone_offset: ?string}>
     * }  $parsed
     * @return array{xml: string, epc_count: int}
     */
    public function handle(array $parsed, ?int $siteId = null, ?string $correlationId = null): array
    {
        $containers = array_values($parsed['containers'] ?? []);

        if ($containers === []) {
            throw new \InvalidArgumentException('Guardian DataFeed has no containers to author.');
        }

        // Structural hard gates run before any grouping/authoring: a malformed
        // or incomplete container must fail the whole feed, never be silently
        // dropped or half-authored.
        $this->assertContainersAreAuthorable($containers);

        $sglnUrn = $this->resolveSglnUrn($siteId);

        $lot = filled($parsed['lot_number'] ?? null) ? (string) $parsed['lot_number'] : null;
        $expiry = $this->formatDate($parsed['expire_date'] ?? null);
        $feedOffset = $parsed['timezone_offset'] ?? null;

        $baseTime = $this->resolveBaseTime($parsed);
        $commissionTime = $baseTime->copy()->subMinutes(30);
        $aggregationTime = $baseTime->copy();

        $byType = ['Pallet' => [], 'Case' => [], 'Bottle' => []];
        $typeByUri = [];
        $resolvedTimeByUri = [];
        foreach ($containers as $container) {
            $type = $container['type'];
            $epcUri = $container['epc_uri'];
            $typeByUri[$epcUri] = $type;
            $resolvedTimeByUri[$epcUri] = $this->resolveContainerEventTime($container, $feedOffset);

            if (isset($byType[$type])) {
                $byType[$type][] = $epcUri;
            }
        }

        $childrenByParent = [];
        foreach ($containers as $container) {
            $parentUri = $container['parent_epc_uri'];
            if ($parentUri === null) {
                continue;
            }
            $childrenByParent[$parentUri][] = $container['epc_uri'];
        }

        $this->assertCaseQuantityMatchesHierarchy($parsed['lot_control_data'] ?? [], $childrenByParent, $typeByUri);

        $events = [];

        // Bottles and cases carry product identity — ILMD when lot/expiry are known.
        // Pallets are pure logistics identity — no ILMD.
        foreach (['Bottle', 'Case', 'Pallet'] as $type) {
            $withIlmd = $type !== 'Pallet';

            foreach ($this->groupContainersByEventTime($byType[$type], $resolvedTimeByUri) as $group) {
                foreach (array_chunk($group['uris'], self::CHUNK_SIZE) as $chunk) {
                    if ($group['utc'] !== null) {
                        $eventTime = $group['utc'];
                        $offset = $group['offset'];
                    } else {
                        $eventTime = $commissionTime;
                        $offset = '+00:00';
                        $commissionTime = $commissionTime->copy()->addSeconds(2);
                    }

                    $events[] = $this->commissionXml(
                        $eventTime,
                        $offset,
                        $chunk,
                        $sglnUrn,
                        $withIlmd ? $lot : null,
                        $withIlmd ? $expiry : null,
                        $correlationId,
                    );
                }
            }
        }

        // Bottom-up: bottle-into-case aggregations before case-into-pallet.
        $levels = ['Other' => [], 'Case' => [], 'Pallet' => []];
        foreach ($childrenByParent as $parentUri => $childUris) {
            $level = match ($typeByUri[$parentUri] ?? null) {
                'Case' => 'Case',
                'Pallet' => 'Pallet',
                default => 'Other',
            };
            $levels[$level][$parentUri] = $childUris;
        }

        foreach (['Other', 'Case', 'Pallet'] as $level) {
            foreach ($levels[$level] as $parentUri => $childUris) {
                [$eventTime, $offset] = $this->resolveAggregationEventTime(
                    $resolvedTimeByUri[$parentUri] ?? null,
                    $childUris,
                    $resolvedTimeByUri,
                );

                if ($eventTime === null) {
                    $eventTime = $aggregationTime;
                    $offset = '+00:00';
                    $aggregationTime = $aggregationTime->copy()->addSeconds(2);
                }

                $events[] = $this->aggregationXml($eventTime, $offset, (string) $parentUri, $childUris, $sglnUrn, $correlationId);
            }
        }

        $events = array_values(array_filter($events, fn (string $xml): bool => trim($xml) !== ''));

        if ($events === []) {
            throw new \InvalidArgumentException('Guardian DataFeed produced no authorable EPCIS events.');
        }

        [$senderGln, $receiverGln] = $this->resolveCorrelationGlns($correlationId, $siteId);

        $xml = $this->xmlBuilder->buildDocument(
            $baseTime->toIso8601String(),
            implode("\n", $events),
            $correlationId,
            $senderGln,
            $receiverGln,
        );

        return [
            'xml' => $xml,
            'epc_count' => count($containers),
        ];
    }

    /**
     * Fail-closed structural gates, run before any event is authored:
     *
     * - Every container must resolve a `ContainerId[@Name="URI"]` identity.
     *   The parser only maps that field into `epc_uri` today (no
     *   `GS1_EPCIS`-derived fallback exists anywhere in this codebase — a raw
     *   `GS1_EPCIS` digit string is not itself a valid EPC URI), so a missing
     *   `URI` field is a hard failure, never a silent drop.
     * - Every container must have a non-blank `Type` in
     *   {@see self::SUPPORTED_CONTAINER_TYPES}.
     *
     * @param  list<array{type: ?string, epc_uri: ?string, parent_epc_uri: ?string, fields: array<string, string>, event_time: ?string, timezone_offset: ?string}>  $containers
     */
    private function assertContainersAreAuthorable(array $containers): void
    {
        foreach ($containers as $index => $container) {
            $epcUri = $container['epc_uri'] ?? null;
            $type = $container['type'] ?? null;

            if (blank($epcUri)) {
                $gs1Epcis = $container['fields']['GS1_EPCIS'] ?? null;

                throw new \InvalidArgumentException(sprintf(
                    'Guardian DataFeed container at position %d (type: %s) has no resolvable identity URI (ContainerId[@Name="URI"] is missing%s).',
                    $index,
                    $type ?? 'unknown',
                    filled($gs1Epcis) ? '; GS1_EPCIS is present but is not a usable EPC URI fallback' : '',
                ));
            }

            if (blank($type) || ! in_array($type, self::SUPPORTED_CONTAINER_TYPES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Guardian DataFeed container %s has %s Type (expected one of: %s).',
                    $epcUri,
                    blank($type) ? 'missing or blank' : 'unsupported "'.$type.'"',
                    implode(', ', self::SUPPORTED_CONTAINER_TYPES),
                ));
            }
        }
    }

    /**
     * When Case→Bottle hierarchy exists, `LotControlData.CaseQty` is required and
     * must be a positive integer matching every Case parent's Bottle child count.
     * Missing/non-numeric CaseQty fails closed (do not silently skip the gate).
     *
     * @param  array<string, string>  $lotControlData
     * @param  array<string, list<string>>  $childrenByParent
     * @param  array<string, ?string>  $typeByUri
     */
    private function assertCaseQuantityMatchesHierarchy(array $lotControlData, array $childrenByParent, array $typeByUri): void
    {
        $caseParentsWithBottles = [];
        foreach ($childrenByParent as $parentUri => $childUris) {
            if (($typeByUri[$parentUri] ?? null) !== 'Case') {
                continue;
            }

            $bottleCount = count(array_filter(
                $childUris,
                fn (string $uri): bool => ($typeByUri[$uri] ?? null) === 'Bottle',
            ));

            if ($bottleCount > 0) {
                $caseParentsWithBottles[$parentUri] = $bottleCount;
            }
        }

        if ($caseParentsWithBottles === []) {
            return;
        }

        $caseQty = $this->parsePositiveInt($lotControlData['CaseQty'] ?? null);
        if ($caseQty === null) {
            throw new \InvalidArgumentException(
                'Guardian DataFeed has Case→Bottle hierarchy but LotControlData.CaseQty is missing or not a positive integer.'
            );
        }

        foreach ($caseParentsWithBottles as $parentUri => $bottleCount) {
            if ($bottleCount !== $caseQty) {
                throw new \InvalidArgumentException(sprintf(
                    'Guardian DataFeed Case %s has %d Bottle child(ren) but LotControlData.CaseQty=%d.',
                    $parentUri,
                    $bottleCount,
                    $caseQty,
                ));
            }
        }
    }

    private function parsePositiveInt(mixed $raw): ?int
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Resolve a single container's own commissioning time to true UTC, using
     * its own `TimeZoneOffset` when present (falling back to the feed-level
     * `LotInfo/TimeZoneOffset`). `EventTimeStampUTC` is, despite the name, a
     * local timestamp in Guardian feeds — it always needs an offset applied.
     * Returns null when no event_time is present/parseable, so the caller can
     * fall back to a synthetic sequential timestamp.
     *
     * @param  array{type: ?string, epc_uri: ?string, parent_epc_uri: ?string, fields: array<string, string>, event_time: ?string, timezone_offset: ?string}  $container
     * @return array{utc: Carbon, offset: string}|null
     */
    private function resolveContainerEventTime(array $container, ?string $feedOffset): ?array
    {
        $raw = $container['event_time'] ?? null;
        if (blank($raw)) {
            return null;
        }

        $offset = $this->normalizeOffset($container['timezone_offset'] ?? null) ?? $this->normalizeOffset($feedOffset);

        try {
            $utc = $offset !== null
                ? Carbon::parse($raw, $offset)->utc()
                : Carbon::parse($raw, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }

        return ['utc' => $utc, 'offset' => $offset ?? '+00:00'];
    }

    /**
     * Normalizes Guardian's `±HH:MM:SS` (or bare `±HH:MM`) offset strings to
     * the GS1 CBV `±HH:MM` form used everywhere else in this codebase.
     */
    private function normalizeOffset(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        if (preg_match('/^([+-])(\d{1,2}):?(\d{2})(?::?\d{2})?$/', trim($raw), $matches) !== 1) {
            return null;
        }

        return sprintf('%s%02d:%02d', $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    /**
     * Groups a type's EPC URIs by their exact resolved (UTC time, offset)
     * pair, preserving first-seen order across groups: each commissioning
     * ObjectEvent then carries the real per-container event time rather than
     * a synthetic one, while containers with no resolvable time share a
     * single fallback bucket authored with the sequential commissionTime
     * cursor.
     *
     * @param  list<string>  $uris
     * @param  array<string, array{utc: Carbon, offset: string}|null>  $resolvedTimeByUri
     * @return list<array{utc: ?Carbon, offset: string, uris: list<string>}>
     */
    private function groupContainersByEventTime(array $uris, array $resolvedTimeByUri): array
    {
        $groups = [];
        $order = [];

        foreach ($uris as $uri) {
            $resolved = $resolvedTimeByUri[$uri] ?? null;
            $key = $resolved !== null
                ? $resolved['utc']->format('Y-m-d\TH:i:s.u').'|'.$resolved['offset']
                : '__fallback__';

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'utc' => $resolved['utc'] ?? null,
                    'offset' => $resolved['offset'] ?? '+00:00',
                    'uris' => [],
                ];
                $order[] = $key;
            }

            $groups[$key]['uris'][] = $uri;
        }

        return array_map(static fn (string $key): array => $groups[$key], $order);
    }

    /**
     * Aggregation event time prefers the parent container's own resolved
     * event time; when the parent has none, falls back to the max of its
     * children's resolved event times. Returns [null, null] when neither is
     * available, so the caller substitutes the sequential aggregationTime
     * cursor.
     *
     * @param  array{utc: Carbon, offset: string}|null  $parentResolved
     * @param  list<string>  $childUris
     * @param  array<string, array{utc: Carbon, offset: string}|null>  $resolvedTimeByUri
     * @return array{0: ?Carbon, 1: ?string}
     */
    private function resolveAggregationEventTime(?array $parentResolved, array $childUris, array $resolvedTimeByUri): array
    {
        if ($parentResolved !== null) {
            return [$parentResolved['utc'], $parentResolved['offset']];
        }

        $best = null;
        foreach ($childUris as $uri) {
            $resolved = $resolvedTimeByUri[$uri] ?? null;
            if ($resolved === null) {
                continue;
            }

            if ($best === null || $resolved['utc']->gt($best['utc'])) {
                $best = $resolved;
            }
        }

        return $best !== null ? [$best['utc'], $best['offset']] : [null, null];
    }

    /**
     * The Guardian feed already names the producing site by GLN
     * ({@see ConvertAndAcceptGuardianLotJob::resolveSiteId}) — derive its
     * SGLN directly rather than through {@see ResolveSsccAuthoredLocation}, whose
     * "default ship-from site" policy is for interactively-authored SSCC events and
     * would reject this site outright when the job runs without an authenticated user.
     * Falls back to the tenant-wide resolution when no site matched.
     */
    private function resolveSglnUrn(?int $siteId): string
    {
        if ($siteId !== null) {
            $site = Site::query()->find($siteId);
            $gln = $site !== null ? Sgln::normalizeGln($site->gln) : null;

            if ($gln !== null) {
                $sgln = SglnResolution::resolve(
                    $gln,
                    [$site->sgln ?? null],
                    TenantSettings::forTenant(tenant())->companyPrefix(),
                );

                if ($sgln !== null) {
                    return $sgln;
                }
            }
        }

        return $this->resolveLocation->handle(null)['sgln_urn'];
    }

    /**
     * Real org/site GLNs for SBDH when a correlation header is emitted.
     * Self-authored Guardian lot-close uses the same GLN as sender and receiver.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCorrelationGlns(?string $correlationId, ?int $siteId): array
    {
        if ($correlationId === null || trim($correlationId) === '') {
            return [null, null];
        }

        $gln = null;

        if ($siteId !== null) {
            $gln = Sgln::normalizeGln(Site::query()->find($siteId)?->gln);
        }

        if ($gln === null) {
            $gln = Sgln::normalizeGln(TenantSettings::forTenant(tenant())->gln());
        }

        return [$gln, $gln];
    }

    /**
     * Prefer LotProcessedTime, then LotInfoSaved, then a fixed UTC epoch so retries
     * author identical XML (stable event times / file SHA) when the feed omits times.
     * Never fall back to `now()` — that breaks duplicate-hash recovery on retry.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function resolveBaseTime(array $parsed): Carbon
    {
        foreach (['lot_processed_at', 'lot_info_saved_at'] as $key) {
            $raw = $parsed[$key] ?? null;
            if (! filled($raw)) {
                continue;
            }

            try {
                return Carbon::parse((string) $raw);
            } catch (\Throwable) {
                // Try the next candidate.
            }
        }

        return Carbon::parse('1970-01-01T00:00:00Z');
    }

    private function formatDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $epcs
     */
    private function commissionXml(Carbon $eventTime, string $offset, array $epcs, string $sgln, ?string $lot, ?string $expiry, ?string $correlationId): string
    {
        if ($epcs === []) {
            return '';
        }

        $eventTimeUtc = $eventTime->copy()->utc();

        // Hard gate before any XML is emitted: a malformed GS1 identifier in the
        // Guardian feed throws here (InvalidArgumentException), never reaching
        // ReceiveEpcisUpload / the EPCIS ingest pipeline.
        $candidate = $this->assertObjectEvent->handle(
            epcList: $epcs,
            action: EpcisAction::Add,
            bizStep: 'commissioning',
            disposition: 'active',
            eventTimeUtc: \DateTimeImmutable::createFromInterface($eventTimeUtc),
        );

        $epcs = $candidate->epcList;
        $eventId = $this->deterministicEventId($correlationId, 'commission', $epcs);

        $recordTime = $eventTimeUtc->copy()->addSecond();
        $epcXml = collect($epcs)
            ->map(fn (string $uri): string => '          <epc>'.$this->e($uri).'</epc>')
            ->implode("\n");

        // lotNumber and itemExpirationDate are independent elements: a lot
        // number is still worth emitting even when the expiry date could not
        // be parsed, and vice versa.
        $ilmdFields = '';
        if ($lot !== null && $lot !== '') {
            $ilmdFields .= '            <cbvmda:lotNumber>'.$this->e($lot)."</cbvmda:lotNumber>\n";
        }
        if ($expiry !== null && $expiry !== '') {
            $ilmdFields .= '            <cbvmda:itemExpirationDate>'.$this->e($expiry)."</cbvmda:itemExpirationDate>\n";
        }

        $ilmd = '';
        if ($ilmdFields !== '') {
            $ilmd =
                "        <extension>\n".
                "          <ilmd xmlns:cbvmda=\"urn:epcglobal:cbv:mda\">\n".
                $ilmdFields.
                "          </ilmd>\n".
                "        </extension>\n";
        }

        return
            "      <ObjectEvent>\n".
            '        <eventTime>'.$eventTimeUtc->format('Y-m-d\TH:i:s.v\Z')."</eventTime>\n".
            '        <recordTime>'.$recordTime->format('Y-m-d\TH:i:s.v\Z')."</recordTime>\n".
            '        <eventTimeZoneOffset>'.$this->e($offset)."</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            '          <eventID>'.$eventId."</eventID>\n".
            "        </baseExtension>\n".
            "        <epcList>\n".
            $epcXml."\n".
            "        </epcList>\n".
            "        <action>ADD</action>\n".
            '        <bizStep>'.$this->e($candidate->bizStep)."</bizStep>\n".
            '        <disposition>'.$this->e($candidate->disposition)."</disposition>\n".
            "        <readPoint>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </bizLocation>\n".
            $ilmd.
            '      </ObjectEvent>';
    }

    /**
     * @param  list<string>  $childUris
     */
    private function aggregationXml(Carbon $eventTime, string $offset, string $parentUri, array $childUris, string $sgln, ?string $correlationId): string
    {
        if ($childUris === []) {
            return '';
        }

        $eventTimeUtc = $eventTime->copy()->utc();
        $uniqueChildren = collect($childUris)->unique()->values()->all();

        // Hard gate before any XML is emitted — see commissionXml().
        $candidate = $this->assertAggregation->handle(
            parentUri: $parentUri,
            childEpcs: $uniqueChildren,
            action: EpcisAction::Add,
            bizStep: 'packing',
            disposition: 'in_progress',
            eventTimeUtc: \DateTimeImmutable::createFromInterface($eventTimeUtc),
        );

        $parentUri = $candidate->parentId;
        $childUris = $candidate->childEpcs;
        $eventId = $this->deterministicEventId($correlationId, 'agg', [$parentUri, ...$childUris]);

        $recordTime = $eventTimeUtc->copy()->addSecond();
        $childXml = collect($childUris)
            ->map(fn (string $uri): string => '          <epc>'.$this->e($uri).'</epc>')
            ->implode("\n");

        return
            "      <AggregationEvent>\n".
            '        <eventTime>'.$eventTimeUtc->format('Y-m-d\TH:i:s.v\Z')."</eventTime>\n".
            '        <recordTime>'.$recordTime->format('Y-m-d\TH:i:s.v\Z')."</recordTime>\n".
            '        <eventTimeZoneOffset>'.$this->e($offset)."</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            '          <eventID>'.$eventId."</eventID>\n".
            "        </baseExtension>\n".
            '        <parentID>'.$this->e($parentUri)."</parentID>\n".
            "        <childEPCs>\n".
            $childXml."\n".
            "        </childEPCs>\n".
            "        <action>ADD</action>\n".
            '        <bizStep>'.$this->e($candidate->bizStep)."</bizStep>\n".
            '        <disposition>'.$this->e($candidate->disposition)."</disposition>\n".
            "        <readPoint>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            '          <id>'.$this->e($sgln)."</id>\n".
            "        </bizLocation>\n".
            '      </AggregationEvent>';
    }

    /**
     * Deterministic `eventID`: same (correlation id, kind, event shape) always
     * derives the same UUID v5, so re-authoring the same feed does not mint new
     * event identities on every retry/reprocess attempt.
     *
     * @param  list<string>  $parts
     */
    private function deterministicEventId(?string $correlationId, string $kind, array $parts): string
    {
        $sorted = $parts;
        sort($sorted);

        $seed = implode('|', [(string) $correlationId, $kind, implode(',', $sorted)]);

        return 'urn:uuid:'.Uuid::uuid5(self::EVENT_ID_NAMESPACE, $seed)->toString();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

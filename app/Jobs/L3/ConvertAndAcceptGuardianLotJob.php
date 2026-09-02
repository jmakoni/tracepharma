<?php

declare(strict_types=1);

namespace App\Jobs\L3;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\L3\AuthorGuardianLotEpcisDocument;
use App\Actions\L3\ReceiveGuardianLotFeed;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Epcis\EpcisDocument;
use App\Models\L3\L3LotFeed;
use App\Models\L3\SerializationLot;
use App\Models\L3\SerializationLotContainerField;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\L3\GuardianDataFeedParser;
use App\Support\Epcis\EpcisTempFile;
use App\Support\Gs1\Sgln;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Convert an archived Guardian lot-close `DataFeed` into a lot record and an
 * accepted EPCIS document: parse -> upsert `serialization_lots` +
 * `serialization_lot_container_fields` -> author commissioning/aggregation
 * XML -> {@see ReceiveEpcisUpload} (sync) -> mark the feed terminal.
 *
 * `$tries = 1`: this job is not retry-safe by the queue's own backoff — a failed
 * attempt already marks the feed (and any lot) `failed` inside the catch below,
 * and the only supported retry path is Guardian resubmitting the DataFeed with a
 * (new or identical) MessageID, which re-enters via {@see ReceiveGuardianLotFeed}
 * and dispatches a fresh job run. A queue-level retry would just replay the same
 * failure against the same archived payload.
 */
final class ConvertAndAcceptGuardianLotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Must not exceed {@see ReceiveGuardianLotFeed::STALE_PROCESSING_SECONDS}:
     * otherwise a stale-processing redispatch is dropped by the unique lock.
     */
    public int $uniqueFor = ReceiveGuardianLotFeed::STALE_PROCESSING_SECONDS;

    public function __construct(
        public string $tenantId,
        public int $feedId,
    ) {
        $this->onQueue('epcis');
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->feedId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        GuardianDataFeedParser $parser,
        AuthorGuardianLotEpcisDocument $author,
        ReceiveEpcisUpload $receiveEpcisUpload,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        TenantRunner::run($tenant, function () use ($parser, $author, $receiveEpcisUpload, $tenant): void {
            $feed = L3LotFeed::query()->find($this->feedId);
            if ($feed === null || $feed->isTerminal()) {
                return;
            }

            if ($feed->status === 'failed') {
                $feed->forceFill([
                    'status' => 'processing',
                    'error_summary' => null,
                ])->save();
            }

            // Re-check access and Guardian enablement on every run (not just at receive
            // time): a tenant can be suspended, have inbound EPCIS killed, or have L3
            // / Guardian / Systech settings changed between receive and this job
            // executing. Mark failed and return rather than throw — retrying against a
            // blocked tenant is a pointless storm ($tries = 1 makes this moot for the
            // queue itself, but WithoutOverlapping releases could still re-attempt).
            // Both checks take the freshly-queried $tenant above (not the possibly-stale
            // tenant() singleton) so a settings change made just before this job runs is
            // never missed.
            if ($settingsBlockReason = $this->guardianIngestBlockReason($tenant)) {
                $feed->forceFill([
                    'status' => 'failed',
                    'error_summary' => $settingsBlockReason,
                ])->save();

                return;
            }

            $feed->forceFill(['status' => 'processing'])->save();

            $localPath = null;

            try {
                $localPath = $this->downloadPayload($feed);
                $parsed = $parser->parse($localPath);

                $siteId = $this->resolveSiteId($parsed['site_id_gln'] ?? null);

                $lot = $this->upsertLot($feed, $parsed, $siteId);

                $authored = $author->handle($parsed, $siteId, 'guardian-lot:'.$feed->message_id);

                $xmlPath = EpcisTempFile::write($authored['xml'], "guardian-lot-{$feed->id}.xml", 'guardian_lot_epcis_');

                try {
                    // Self-authored (like PersistAuthoredSsccEpcis), not a partner webhook
                    // upload: `outbound` is the correct direction for internally-generated
                    // commissioning/aggregation XML.
                    $document = $receiveEpcisUpload->handle($xmlPath, [
                        'direction' => 'outbound',
                        'received_via' => EpcisReceivedVia::GuardianLotClose,
                        'original_filename' => "guardian-lot-{$feed->message_id}.xml",
                        'notes' => 'Guardian lot-close feed #'.$feed->id,
                        'dispatch' => true,
                        'sync' => true,
                    ]);
                } catch (DuplicateEpcisUploadException $duplicate) {
                    // Crash after validate → retry authors the same XML → same hash.
                    // If the prior document is already validated (and has events), recover
                    // by attaching it rather than marking the lot/feed failed.
                    $document = $duplicate->existing;
                    if ($document->status !== 'validated') {
                        throw $duplicate;
                    }
                } finally {
                    @unlink($xmlPath);
                }

                // Fail closed: ReceiveEpcisUpload/ProcessEpcisDocumentJob normally throw on
                // ingest failure (caught below), but a document that lands in any status
                // other than `validated` — without an exception ever reaching here — must
                // never be treated as accepted. Also reject validated-with-zero-events
                // (silent event_id replay skip can leave an empty SoR).
                if ($document->status !== 'validated') {
                    $summary = $this->summarizeDocumentFailure($document);

                    $lot->forceFill(['status' => 'failed'])->save();

                    $feed->forceFill([
                        'status' => 'failed',
                        'error_summary' => Str::limit($summary, 2000),
                    ])->save();

                    throw new \RuntimeException($summary);
                }

                if ($emptyReason = $this->emptyValidatedDocumentReason($document)) {
                    $lot->forceFill(['status' => 'failed'])->save();

                    $feed->forceFill([
                        'status' => 'failed',
                        'error_summary' => Str::limit($emptyReason, 2000),
                    ])->save();

                    throw new \RuntimeException($emptyReason);
                }

                // Only replace container fields on the accept path — wiping before
                // author/validate would destroy prior enrichment when conversion fails.
                $this->replaceContainerFields($lot, $parsed['containers'] ?? []);

                $lot->forceFill([
                    'epcis_document_id' => (int) $document->getKey(),
                    'status' => 'accepted',
                ])->save();

                $feed->forceFill([
                    'status' => 'accepted',
                    'error_summary' => null,
                ])->save();
            } catch (Throwable $e) {
                SerializationLot::query()->where('feed_id', $feed->id)->update(['status' => 'failed']);

                $feed->forceFill([
                    'status' => 'failed',
                    'error_summary' => Str::limit($e->getMessage(), 2000),
                ])->save();

                throw $e;
            } finally {
                if ($localPath !== null) {
                    @unlink($localPath);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function upsertLot(L3LotFeed $feed, array $parsed, ?int $siteId): SerializationLot
    {
        $lotNumber = trim((string) ($parsed['lot_number'] ?? ''));
        if ($lotNumber === '') {
            throw new \InvalidArgumentException('Guardian DataFeed is missing LotInfo/LotNumber.');
        }

        $containers = $parsed['containers'] ?? [];
        $counts = ['Pallet' => 0, 'Case' => 0, 'Bottle' => 0];
        foreach ($containers as $container) {
            $type = $container['type'] ?? null;
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }

        $unitGtin14 = trim((string) ($parsed['unit_gtin14'] ?? ''));
        if ($unitGtin14 === '' || ! ctype_digit($unitGtin14) || strlen($unitGtin14) !== 14) {
            throw new \InvalidArgumentException(
                'Guardian DataFeed is missing a valid 14-digit LotControlData/UnitGTIN.'
            );
        }

        $attributes = [
            'feed_id' => $feed->id,
            'ndc' => $parsed['ndc'] ?? null,
            'case_gtin14' => $parsed['case_gtin14'] ?? null,
            'product_name' => $parsed['product_name'] ?? null,
            'expire_date' => $this->parseDate($parsed['expire_date'] ?? null),
            'mfg_date' => $this->parseDate($parsed['mfg_date'] ?? null),
            'site_id' => $siteId,
            'line_name' => $parsed['line_name'] ?? null,
            'lot_processed_at' => $this->parseDateTime($parsed['lot_processed_at'] ?? null),
            'timezone_offset' => $parsed['timezone_offset'] ?? null,
            'lot_info_saved_at' => $this->parseDateTime($parsed['lot_info_saved_at'] ?? null),
            'lot_control_data' => $parsed['lot_control_data'] ?? [],
            'pallet_count' => $counts['Pallet'],
            'case_count' => $counts['Case'],
            'unit_count' => $counts['Bottle'],
            'status' => 'processing',
        ];

        $lot = DB::transaction(function () use ($lotNumber, $unitGtin14, $attributes, $feed): SerializationLot {
            $lot = SerializationLot::query()
                ->where('lot_number', $lotNumber)
                ->where('unit_gtin14', $unitGtin14)
                ->lockForUpdate()
                ->first();

            if ($lot !== null) {
                if ($lot->status === 'accepted' && (int) $lot->feed_id !== (int) $feed->id) {
                    throw new \InvalidArgumentException(
                        "Guardian lot-close feed #{$feed->id} cannot overwrite accepted lot {$lotNumber} (unit GTIN {$unitGtin14}) linked to feed #{$lot->feed_id}."
                    );
                }

                $lot->forceFill($attributes)->save();

                return $lot;
            }

            return SerializationLot::query()->create($attributes + [
                'lot_number' => $lotNumber,
                'unit_gtin14' => $unitGtin14,
            ]);
        });

        return $lot;
    }

    /**
     * @param  list<array{type: ?string, epc_uri: ?string, parent_epc_uri: ?string, fields: array<string, string>, event_time: ?string}>  $containers
     */
    private function replaceContainerFields(SerializationLot $lot, array $containers): void
    {
        SerializationLotContainerField::query()->where('lot_id', $lot->id)->delete();

        $rows = [];
        $now = now();

        foreach ($containers as $container) {
            $epcUri = $container['epc_uri'] ?? null;
            if (blank($epcUri)) {
                continue;
            }

            $rows[] = [
                'lot_id' => $lot->id,
                'epc_uri' => $epcUri,
                'container_type' => (string) ($container['type'] ?? 'Unknown'),
                'parent_epc_uri' => $container['parent_epc_uri'] ?? null,
                'fields' => json_encode($container['fields'] ?? [], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('serialization_lot_container_fields')->insert($chunk);
        }
    }

    /**
     * Only matches an organization-owned, active site: {@see ResolveSsccAuthoredLocation}
     * rejects an explicit site_id that isn't (trading-partner sites have a GLN too, but
     * they are never a valid commissioning readPoint/bizLocation for this tenant).
     */
    private function resolveSiteId(?string $siteIdGln): ?int
    {
        $normalized = Sgln::normalizeGln($siteIdGln ?? '');

        if (blank($normalized)) {
            return null;
        }

        return Site::query()
            ->ownedByOrganization()
            ->where('is_active', true)
            ->where('gln', $normalized)
            ->value('id');
    }

    private function summarizeDocumentFailure(EpcisDocument $document): string
    {
        $parts = ["Guardian lot-close EPCIS document #{$document->getKey()} did not validate (status: {$document->status})."];

        if (filled($document->error_message)) {
            $parts[] = (string) $document->error_message;
        }

        $exceptionSummaries = $document->exceptions()
            ->latest('id')
            ->limit(5)
            ->pluck('description')
            ->filter()
            ->all();

        if ($exceptionSummaries !== []) {
            $parts[] = implode('; ', $exceptionSummaries);
        }

        return implode(' ', $parts);
    }

    /**
     * Validated documents with zero events (or zero EPCs when the column exists)
     * must not accept the lot — typically event_id replay skipped every event.
     */
    private function emptyValidatedDocumentReason(EpcisDocument $document): ?string
    {
        $eventCount = (int) ($document->event_count ?? 0);
        if ($eventCount <= 0) {
            return "Guardian lot-close EPCIS document #{$document->getKey()} validated with zero events.";
        }

        if (Schema::hasColumn($document->getTable(), 'epc_count')) {
            $epcCount = (int) ($document->epc_count ?? 0);
            if ($epcCount <= 0) {
                return "Guardian lot-close EPCIS document #{$document->getKey()} validated with zero EPCs.";
            }
        }

        return null;
    }

    private function guardianIngestBlockReason(Tenant $tenant): ?string
    {
        if (! TenantAccess::isActive($tenant) || TenantKillSwitches::forTenant($tenant)->isKilled(TenantKillSwitches::INBOUND_EPCIS)) {
            return 'Guardian lot-close ingest blocked: tenant is inactive or inbound EPCIS is disabled for this organization.';
        }

        $settings = TenantSettings::forTenant($tenant);

        if (! $settings->l3Enabled() || ! $settings->l3GuardianLotCloseEnabled()) {
            return 'Guardian lot-close ingest blocked: L3 or Guardian lot-close is not enabled for this organization.';
        }

        $profile = $tenant->profile instanceof TenantProfile ? $tenant->profile : null;
        if ($profile !== TenantProfile::Manufacturer) {
            return 'Guardian lot-close ingest blocked: only Manufacturer organizations may ingest Guardian lot-close feeds.';
        }

        $provider = $settings->l3Provider();
        if ($provider === null || strcasecmp($provider, 'systech') !== 0) {
            return 'Guardian lot-close ingest blocked: the Systech L3 provider is required.';
        }

        return null;
    }

    private function downloadPayload(L3LotFeed $feed): string
    {
        $contents = Storage::disk($feed->payload_disk)->get($feed->payload_path);
        if (! is_string($contents) || $contents === '') {
            throw new \RuntimeException("Guardian DataFeed payload is missing or empty: {$feed->payload_path}");
        }

        return EpcisTempFile::write($contents, 'guardian-datafeed.xml', 'guardian_lot_raw_');
    }

    private function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDateTime(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Actions\Labeling;

use App\Actions\Receiving\QueueReceivingLpnLabelPrint;
use App\DTOs\Labeling\SsccAllocationRequest;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Exceptions\SsccNumberRangeCapacityException;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Services\Labeling\ResolveSsccNumberRange;
use App\Services\Labeling\SsccBuilder;
use App\Services\Labeling\SsccChildCustodyGuard;
use App\Services\Labeling\SsccLabelChildAttacher;
use App\Services\Labeling\SsccLabelPdfGenerator;
use App\Services\Labeling\SsccSerialAllocator;
use App\Services\Labeling\SsccSerialPoolService;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class GenerateSsccLabelBatch
{
    public function __construct(
        private readonly SsccBuilder $ssccBuilder,
        private readonly SsccSerialAllocator $allocator,
        private readonly SsccSerialPoolService $poolService,
        private readonly ResolveSsccNumberRange $resolveSsccNumberRange,
        private readonly SsccLabelPdfGenerator $pdfGenerator,
        private readonly SsccLabelChildAttacher $childAttacher,
        private readonly DispatchSsccBatchPrint $batchPrintDispatcher,
        private readonly EmitSsccBatchCommissioningEpcis $commissioningEmitter,
        private readonly EmitSsccBatchEpcis $epcisEmitter,
        private readonly EmitSsccDisaggregationEpcis $disaggregationEmitter,
        private readonly ResolveShipFromSite $resolveShipFromSite,
        private readonly SsccChildCustodyGuard $childCustodyGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * When {@code send_to_printer} is enabled, the returned batch carries a transient
     * {@see SsccLabelBatch::$printDispatch} payload ({@code mode}, {@code bridge}, {@code jobs}).
     * Livewire/Filament callers should fire {@code tp-client-print} when {@code mode === 'client'}.
     * Plain Actions (e.g. {@see QueueReceivingLpnLabelPrint}) must inspect
     * {@code $batch->printDispatch} themselves — this action does not emit browser events.
     *
     * EPCIS aggregation/disaggregation are ingested synchronously unless {@code epcis_sync} is
     * false. Non-fatal PDF/print/EPCIS failures are appended to {@code error_message} and also
     * exposed on the transient {@see SsccLabelBatch::$emitErrors} so callers can warn instead of
     * reporting success.
     */
    public function execute(array $input): SsccLabelBatch
    {
        $tenantSettings = TenantSsccSettings::resolve();
        $tenantPrefix = (string) ($tenantSettings['company_prefix'] ?? '');
        $inputPrefix = isset($input['company_prefix']) && $input['company_prefix'] !== null && $input['company_prefix'] !== ''
            ? (string) $input['company_prefix']
            : '';

        if ($inputPrefix !== '' && $inputPrefix !== $tenantPrefix) {
            throw new InvalidArgumentException(
                'SSCC company prefix must match the tenant organization company prefix in Organization Settings.',
            );
        }

        $companyPrefix = $tenantPrefix;
        $extensionDigit = (int) ($input['extension_digit'] ?? $tenantSettings['extension_digit'] ?? 0);

        if ($companyPrefix === '') {
            throw new InvalidArgumentException('Configure a GS1 Company Prefix in Settings before generating SSCC labels.');
        }

        // Built before validation below (never throws) so a Failed batch stays auditable
        // if any of the checks in the following try block throw.
        $allocationRequest = SsccAllocationRequest::fromInput($input, $companyPrefix, $extensionDigit);

        try {
            $this->ssccBuilder->normalizeCompanyPrefix($companyPrefix);

            app(AssertOrganizationSsccIdentity::class)->handle(
                TenantSettings::forTenant(tenant())->gln(),
                $companyPrefix,
            );

            TenantSettings::assertValidCompanyPrefix(
                $companyPrefix,
                TenantSettings::forTenant(tenant())->gln(),
            );

            $maxBatch = (int) config('sscc.max_batch_size', 50);
            if ($allocationRequest->labelCount > $maxBatch) {
                throw new InvalidArgumentException("Label count cannot exceed {$maxBatch}.");
            }

            // Flat child_epcs would be duplicated onto every parent label — require 1 label or per-label lists.
            $hasFlatChildren = ! empty($input['child_epcs']) && trim((string) $input['child_epcs']) !== '';
            $hasPerLabelChildren = ! empty($input['child_epcs_per_label']) && is_array($input['child_epcs_per_label']);
            if ($hasFlatChildren && ! $hasPerLabelChildren && $allocationRequest->labelCount > 1) {
                throw new InvalidArgumentException(
                    'Cannot attach the same child EPCs to multiple parent labels. Use label_count 1 or child_epcs_per_label.',
                );
            }

            $copiesPerLabel = max(1, (int) ($input['copies_per_label'] ?? 1));
            $sendToPrinter = (bool) ($input['send_to_printer'] ?? false);
            $emitEpcis = (bool) ($input['emit_epcis'] ?? false);
            $emitDisaggregation = (bool) ($input['emit_disaggregation'] ?? false);

            // Hierarchy durability: aggregation_links must reflect the new parent/child state
            // before the caller returns, otherwise a queued ingest leaves children packable twice.
            $emitSync = (bool) ($input['epcis_sync'] ?? true);

            $printerId = isset($input['label_printer_id']) && $input['label_printer_id'] !== '' ? (int) $input['label_printer_id'] : null;
            $siteId = $this->resolveCommissionSiteId($input);

            if ($sendToPrinter && $printerId === null) {
                throw new InvalidArgumentException('Select a label printer or disable send to printer.');
            }
        } catch (InvalidArgumentException $exception) {
            // Prefix/extension are known at this point, so the failed attempt is still worth auditing.
            $this->recordFailedBatch(
                $this->earlyFailureBatchAttributes($input, $companyPrefix, $extensionDigit, $allocationRequest),
                $allocationRequest,
                null,
                $exception->getMessage(),
            );

            throw $exception;
        }

        $partnerId = isset($input['trading_partner_id']) && $input['trading_partner_id'] !== '' && $input['trading_partner_id'] !== null
            ? (int) $input['trading_partner_id']
            : null;

        $sourceDocumentId = isset($input['source_epcis_document_id']) && $input['source_epcis_document_id'] !== '' && $input['source_epcis_document_id'] !== null
            ? (int) $input['source_epcis_document_id']
            : null;

        $batchAttributes = [
            'company_prefix' => $companyPrefix,
            'extension_digit' => (string) $extensionDigit,
            'allocation_mode' => $allocationRequest->mode,
            'label_count' => $allocationRequest->labelCount,
            'copies_per_label' => $copiesPerLabel,
            'label_printer_id' => $printerId,
            'commission_site_id' => $siteId,
            'send_to_printer' => $sendToPrinter,
            'emit_epcis' => $emitEpcis,
            'emit_disaggregation' => $emitDisaggregation,
            'source_epcis_document_id' => $sourceDocumentId,
            'source_parent_sscc_urn' => filled($input['source_parent_sscc_urn'] ?? null)
                ? (string) $input['source_parent_sscc_urn']
                : null,
            'trading_partner_id' => $partnerId,
            'ship_to_name' => $input['ship_to_name'] ?? null,
            'ship_to_gln' => $input['ship_to_gln'] ?? null,
            'notes' => $input['notes'] ?? null,
            'created_by' => Auth::id(),
        ];

        $resolvedRangeId = null;

        try {
            $batch = DB::transaction(function () use (
                $input,
                $companyPrefix,
                $extensionDigit,
                $allocationRequest,
                $siteId,
                $partnerId,
                $batchAttributes,
                &$resolvedRangeId,
            ): SsccLabelBatch {
                $pool = $this->poolService->lockOrCreate($companyPrefix, $extensionDigit);

                $anyApplicable = $this->resolveSsccNumberRange->resolve(
                    $companyPrefix,
                    $extensionDigit,
                    $siteId,
                    $partnerId,
                    1,
                );

                $numberRange = $this->resolveSsccNumberRange->resolve(
                    $companyPrefix,
                    $extensionDigit,
                    $siteId,
                    $partnerId,
                    $allocationRequest->labelCount,
                );

                if ($numberRange === null && $anyApplicable !== null) {
                    throw new InvalidArgumentException(
                        'Active SSCC number range(s) apply to this site/partner/tenant but none have enough remaining serials for this request.',
                    );
                }

                if ($numberRange === null && (bool) config('sscc.require_number_range', false)) {
                    throw new InvalidArgumentException(
                        'Configure an active SSCC number range in Settings before generating labels.',
                    );
                }

                if ($numberRange !== null && $allocationRequest->mode !== SsccAllocationMode::Sequential) {
                    throw new InvalidArgumentException(
                        'An active SSCC number range applies to this site/partner/tenant. Use Sequential allocation, or deactivate the range.',
                    );
                }

                $resolvedRangeId = $numberRange?->getKey();

                $batch = SsccLabelBatch::query()->create(array_merge($batchAttributes, [
                    'allocation_config' => array_merge($allocationRequest->toConfigArray(), [
                        'sscc_number_range_id' => $resolvedRangeId,
                    ]),
                    'status' => SsccLabelBatchStatus::Generating,
                ]));

                $fromNumberRange = false;

                if ($numberRange !== null) {
                    $issued = $this->resolveSsccNumberRange->resolveAndIssue(
                        $companyPrefix,
                        $extensionDigit,
                        $allocationRequest->labelCount,
                        $siteId,
                        $partnerId,
                    );

                    if ($issued === null) {
                        throw new InvalidArgumentException(
                            'No SSCC number range with enough remaining serials is available for this request.',
                        );
                    }

                    [$numberRange, $serials] = $issued;
                    $resolvedRangeId = $numberRange->getKey();
                    $fromNumberRange = true;
                    $batch->update([
                        'allocation_config' => array_merge($allocationRequest->toConfigArray(), [
                            'sscc_number_range_id' => $resolvedRangeId,
                        ]),
                    ]);
                } else {
                    $serials = $this->allocator->allocate($allocationRequest, $pool);
                }

                $this->createLabels($batch, $serials, $companyPrefix, $extensionDigit, $allocationRequest, $input, $batchAttributes['label_printer_id']);

                // Packing a child authors an aggregation claiming we hold it. Callers such as
                // {@see BreakPalletAndReship} gate their own selection; re-checking here covers
                // the paths that reach this action directly (forms, workstations, LPN reuse).
                $this->childCustodyGuard->assertBatchInputOperable($input);

                if (! empty($input['child_epcs_per_label']) && is_array($input['child_epcs_per_label'])) {
                    $this->attachChildrenPerLabel($batch, $input['child_epcs_per_label']);
                } elseif (! empty($input['child_epcs'])) {
                    $this->childAttacher->attachToBatch($batch, (string) $input['child_epcs']);
                }

                // Range-managed serials must not advance the shared pool high-water mark
                // (high bands would permanently burn forward-only pool space).
                if (! $fromNumberRange) {
                    $this->poolService->updateHighWaterMark($pool, $serials);
                    TenantSsccSettings::syncNextSerialReference($pool->last_serial_reference_int + 1);
                }

                $batch->update([
                    'status' => SsccLabelBatchStatus::Completed,
                    'completed_at' => now(),
                ]);

                return $batch->fresh(['labels.children']);
            });
        } catch (SsccNumberRangeCapacityException $exception) {
            // Capacity failures carry the last range actually attempted — more accurate than
            // whatever resolvedRangeId happened to be set to before the failing attempt.
            $this->recordFailedBatch($batchAttributes, $allocationRequest, $exception->rangeId, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailedBatch($batchAttributes, $allocationRequest, $resolvedRangeId, $exception->getMessage());

            throw $exception;
        } finally {
            // Self-heals queued during resolveAndIssue retries must persist only after this
            // outer transaction has fully committed or rolled back — on MySQL, flushing while
            // the outer transaction still holds the range row lock self-deadlocks.
            $this->resolveSsccNumberRange->flushPendingHeals();
        }

        // Render PDFs after the pool lock is released so heavy IO does not block allocations.
        $pdfOk = true;

        /** @var list<string> $emitErrors */
        $emitErrors = [];

        try {
            $this->writeLabelPdfs($batch);
        } catch (Throwable $exception) {
            $pdfOk = false;
            $emitErrors[] = 'PDF: '.$exception->getMessage();
            $this->appendBatchError($batch, 'PDF: '.$exception->getMessage());
        }

        try {
            $this->commissioningEmitter->execute($batch, [
                'site_id' => $siteId,
                'sync' => true,
            ]);
        } catch (Throwable $exception) {
            // Keep the batch Completed (labels/serials remain valid) so a retry can find it via
            // the normal Completed+uncommissioned lookup (e.g. receiving LPN reuse) and simply
            // re-run commissioning, instead of orphaning consumed serials behind a Failed batch.
            $this->appendBatchError($batch, 'Commissioning: '.$exception->getMessage());

            throw $exception;
        }

        // Skip print when PDF write failed — labels are still commissioned for Trace.
        $printDispatch = null;

        if ($sendToPrinter && $pdfOk) {
            try {
                $printDispatch = $this->batchPrintDispatcher->execute($batch);
            } catch (InvalidArgumentException|LockTimeoutException $exception) {
                $emitErrors[] = 'Print: '.$exception->getMessage();
                $this->appendBatchError($batch, 'Print: '.$exception->getMessage());
                $batch->labels()->update([
                    'print_status' => SsccLabelPrintStatus::Failed,
                ]);
                $printDispatch = null;
            }
        } else {
            $batch->labels()->update(['print_status' => SsccLabelPrintStatus::Skipped]);
        }

        // Disaggregation first: closing the source links before the new aggregation is ingested
        // keeps a child from holding two open parents at any point.
        // Packing ADD is staggered +1s after unpacking DELETE when both emit in one run.
        $clock = now();
        $willDisaggregate = $emitDisaggregation && $batch->source_parent_sscc_urn !== null && $batch->labels->flatMap->children->isNotEmpty();
        $willAggregate = $emitEpcis && $batch->labels->flatMap->children->isNotEmpty();

        $disaggregationTime = $input['disaggregation_event_time'] ?? $clock;
        $aggregationTime = $input['event_time']
            ?? ($willDisaggregate
                ? \Illuminate\Support\Carbon::parse($disaggregationTime)->copy()->addSecond()
                : $clock);

        if ($willDisaggregate) {
            try {
                $this->disaggregationEmitter->execute($batch, [
                    'site_id' => $siteId,
                    'sync' => $emitSync,
                    'event_time' => $disaggregationTime,
                ]);
            } catch (Throwable $exception) {
                $emitErrors[] = 'EPCIS disaggregation: '.$exception->getMessage();
                $this->appendBatchError($batch, 'EPCIS disaggregation: '.$exception->getMessage());
            }
        }

        if ($willAggregate) {
            try {
                $this->epcisEmitter->execute($batch, [
                    'site_id' => $siteId,
                    'sync' => $emitSync,
                    'event_time' => $aggregationTime,
                ]);
            } catch (Throwable $exception) {
                $this->failAfterAggregationError($batch, $exception);

                throw $exception;
            }
        }

        $fresh = $batch->fresh(['labels.children']);
        $fresh->printDispatch = $printDispatch;
        $fresh->emitErrors = $emitErrors;

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $batchAttributes
     */
    private function recordFailedBatch(
        array $batchAttributes,
        SsccAllocationRequest $allocationRequest,
        ?int $rangeId,
        string $errorMessage,
    ): void {
        try {
            SsccLabelBatch::query()->create(array_merge($batchAttributes, [
                'allocation_config' => array_merge($allocationRequest->toConfigArray(), [
                    'sscc_number_range_id' => $rangeId,
                ]),
                'status' => SsccLabelBatchStatus::Failed,
                'error_message' => $errorMessage,
            ]));
        } catch (Throwable $auditException) {
            // Auditing the failure must never mask the original exception.
            report($auditException);
        }
    }

    /**
     * Best-effort batch attributes for an InvalidArgumentException raised before the batch's
     * normal $batchAttributes (which depends on later-validated values) is built.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function earlyFailureBatchAttributes(
        array $input,
        string $companyPrefix,
        int $extensionDigit,
        SsccAllocationRequest $allocationRequest,
    ): array {
        return [
            'company_prefix' => $companyPrefix,
            'extension_digit' => (string) $extensionDigit,
            'allocation_mode' => $allocationRequest->mode,
            'label_count' => $allocationRequest->labelCount,
            'copies_per_label' => max(1, (int) ($input['copies_per_label'] ?? 1)),
            'label_printer_id' => isset($input['label_printer_id']) && $input['label_printer_id'] !== ''
                ? (int) $input['label_printer_id']
                : null,
            'commission_site_id' => isset($input['site_id']) && $input['site_id'] !== '' && $input['site_id'] !== null
                ? (int) $input['site_id']
                : null,
            'send_to_printer' => (bool) ($input['send_to_printer'] ?? false),
            'emit_epcis' => (bool) ($input['emit_epcis'] ?? false),
            'emit_disaggregation' => (bool) ($input['emit_disaggregation'] ?? false),
            'source_epcis_document_id' => isset($input['source_epcis_document_id']) && $input['source_epcis_document_id'] !== '' && $input['source_epcis_document_id'] !== null
                ? (int) $input['source_epcis_document_id']
                : null,
            'source_parent_sscc_urn' => filled($input['source_parent_sscc_urn'] ?? null)
                ? (string) $input['source_parent_sscc_urn']
                : null,
            'trading_partner_id' => isset($input['trading_partner_id']) && $input['trading_partner_id'] !== '' && $input['trading_partner_id'] !== null
                ? (int) $input['trading_partner_id']
                : null,
            'ship_to_name' => $input['ship_to_name'] ?? null,
            'ship_to_gln' => $input['ship_to_gln'] ?? null,
            'notes' => $input['notes'] ?? null,
            'created_by' => Auth::id(),
        ];
    }

    private function appendBatchError(SsccLabelBatch $batch, string $message): void
    {
        $batch->refresh();
        $existing = trim((string) ($batch->error_message ?? ''));
        $batch->update([
            'error_message' => $existing !== '' ? $existing."\n".$message : $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveCommissionSiteId(array $input): int
    {
        if (! isset($input['site_id']) || $input['site_id'] === '' || $input['site_id'] === null) {
            throw new InvalidArgumentException('Select a commission site before generating SSCC labels.');
        }

        $siteId = (int) $input['site_id'];

        if ($siteId <= 0) {
            throw new InvalidArgumentException('Select a commission site before generating SSCC labels.');
        }

        $site = EligibleReceiveSites::forOrganization()->whereKey($siteId)->first();

        if ($site === null) {
            throw new InvalidArgumentException('Commission site must be an organization-owned facility with a GLN.');
        }

        $user = Auth::user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $siteId);
        }

        return $siteId;
    }

    private function failAfterAggregationError(SsccLabelBatch $batch, Throwable $exception): void
    {
        $this->detachBatchChildren($batch);
        $this->appendBatchError($batch, 'EPCIS aggregation: '.$exception->getMessage());
    }

    private function detachBatchChildren(SsccLabelBatch $batch): void
    {
        $labelIds = $batch->labels()->pluck('id')->all();

        if ($labelIds === []) {
            return;
        }

        SsccLabelChild::query()->whereIn('sscc_label_id', $labelIds)->delete();
    }

    private function writeLabelPdfs(SsccLabelBatch $batch): void
    {
        $batch->loadMissing('labels');

        $shipFrom = $this->resolveShipFromForBatch();

        foreach ($batch->labels as $label) {
            if ($label->label_path === null || $label->label_disk === null) {
                continue;
            }

            $pdf = $this->pdfGenerator->generate([
                'sscc_18' => $label->sscc_18,
                'hrt' => $label->hrt,
                'element_string' => $label->element_string,
                'sscc_urn' => $label->sscc_urn,
                'ship_to_name' => $label->ship_to_name,
                'ship_to_gln' => $label->ship_to_gln,
                'ship_from_name' => $shipFrom['name'],
                'ship_from_gln' => $shipFrom['gln'],
                'notes' => $label->notes,
            ]);

            $disk = Storage::disk($label->label_disk);

            try {
                if (! $disk->directoryExists('labels/sscc')) {
                    $disk->makeDirectory('labels/sscc');
                }

                $disk->put($label->label_path, $pdf);
            } catch (Throwable $exception) {
                throw new \RuntimeException(
                    'Unable to write SSCC label PDF to tenant storage (labels/sscc). '.
                    'Run `php artisan tracepharma:ensure-tenant-storage` and ensure the directory is group-writable by www-data. '.
                    $exception->getMessage(),
                    previous: $exception,
                );
            }
        }
    }

    /**
     * @return array{name: ?string, gln: ?string}
     */
    private function resolveShipFromForBatch(): array
    {
        try {
            $resolved = $this->resolveShipFromSite->locationGlnsForAuthoring();

            return [
                'name' => $resolved['site']->name ?? tenant()?->name,
                'gln' => $resolved['gln'],
            ];
        } catch (Throwable) {
            return [
                'name' => tenant()?->name,
                'gln' => TenantSettings::forTenant(tenant())->gln(),
            ];
        }
    }

    /**
     * @param  list<list<string>>  $childEpcsPerLabel
     */
    private function attachChildrenPerLabel(SsccLabelBatch $batch, array $childEpcsPerLabel): void
    {
        $batch->loadMissing('labels');

        foreach ($batch->labels->values() as $index => $label) {
            $epcs = $childEpcsPerLabel[$index] ?? [];

            if ($epcs === []) {
                continue;
            }

            $this->childAttacher->attachToLabel($label, implode("\n", $epcs));
        }
    }

    /**
     * @param  list<int>  $serials
     * @param  array<string, mixed>  $input
     * @return Collection<int, SsccLabel>
     */
    private function createLabels(
        SsccLabelBatch $batch,
        array $serials,
        string $companyPrefix,
        int $extensionDigit,
        SsccAllocationRequest $allocationRequest,
        array $input,
        ?int $printerId = null,
    ): Collection {
        $disk = config('filesystems.default', 'local');
        $labels = collect();

        foreach ($serials as $serialReference) {
            $built = $this->ssccBuilder->build($companyPrefix, $serialReference, $extensionDigit);
            $path = 'labels/sscc/'.$built['sscc_18'].'-'.Str::uuid().'.pdf';

            $labels->push(SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printerId,
                'sscc_18' => $built['sscc_18'],
                'sscc_urn' => $built['sscc_urn'],
                'extension_digit' => $built['extension_digit'],
                'company_prefix' => $built['company_prefix'],
                'serial_reference' => $built['serial_reference'],
                'serial_reference_int' => $built['serial_reference_int'],
                'allocation_mode' => $allocationRequest->mode,
                'element_string' => $built['element_string'],
                'hrt' => $built['hrt'],
                'ship_to_name' => $input['ship_to_name'] ?? null,
                'ship_to_gln' => $input['ship_to_gln'] ?? null,
                'notes' => $input['notes'] ?? null,
                'label_disk' => $disk,
                'label_path' => $path,
                'template_version' => (string) config('sscc.label_template_version', 'v1'),
                'print_status' => SsccLabelPrintStatus::Pending,
                'created_by' => Auth::id(),
            ]));
        }

        return $labels;
    }
}

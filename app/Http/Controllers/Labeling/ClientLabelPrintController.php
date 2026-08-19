<?php

namespace App\Http\Controllers\Labeling;

use App\Enums\ClientPrintBridge;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\User;
use App\Services\Labeling\SsccSerialPoolService;
use App\Services\Labeling\ZplLabelRenderer;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Labeling\ResolveClientPrintBridge;
use App\Support\Labeling\SsccBatchPrintCompletion;
use App\Support\Labeling\SsccPrintJobLabelGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ClientLabelPrintController extends Controller
{
    public function setBridge(Request $request, ResolveClientPrintBridge $resolve): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $validated = $request->validate([
            'bridge' => ['required', 'string', 'in:network_tcp,qz_tray,zebra_browser_print'],
        ]);

        $bridge = ClientPrintBridge::from($validated['bridge']);
        $resolve->setSessionOverride($bridge);

        return response()->json([
            'bridge' => $bridge->value,
            'label' => $bridge->shortLabel(),
        ]);
    }

    public function clearBridge(ResolveClientPrintBridge $resolve): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $resolve->setSessionOverride(null);

        return response()->json([
            'bridge' => $resolve->handle()->value,
        ]);
    }

    public function zpl(SsccLabel $label, ZplLabelRenderer $renderer, Request $request): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $label = $this->findAccessibleLabel($label);

        $copies = max(1, (int) $request->query('copies', 1));

        $zpl = $renderer->render([
            'sscc_18' => (string) $label->sscc_18,
            'hrt' => (string) $label->hrt,
            'ship_to_name' => $label->ship_to_name,
            'ship_from_name' => tenant()?->name,
            'copies' => $copies,
        ]);

        return response()->json([
            'label_id' => (int) $label->id,
            'sscc_18' => (string) $label->sscc_18,
            'zpl' => $zpl,
            'copies' => $copies,
        ]);
    }

    /**
     * Mark a client print job as Printing and issue an ownership token for this browser run.
     */
    public function startJob(Request $request, SsccPrintJob $printJob): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $printJob = $this->findAccessiblePrintJob($printJob);
        $this->assertCanMutatePrintJobBatch($printJob);

        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:64'],
        ]);

        $printJob->loadMissing('label');

        if (! SsccPrintDeliveryMode::isClient($printJob->delivery_mode)) {
            return response()->json([
                'message' => 'Only client-delivered print jobs can be started from the browser.',
            ], 422);
        }

        $newToken = (string) Str::uuid();

        $affected = SsccPrintJob::query()
            ->whereKey($printJob->id)
            ->where('delivery_mode', SsccPrintDeliveryMode::Client)
            ->where('status', SsccPrintJobStatus::Queued)
            ->update([
                'status' => SsccPrintJobStatus::Printing,
                'client_print_token' => $newToken,
            ]);

        if ($affected === 1) {
            return response()->json([
                'ok' => true,
                'status' => 'printing',
                'token' => $newToken,
            ]);
        }

        $printJob->refresh();

        if ($printJob->status === SsccPrintJobStatus::Printing) {
            $presented = (string) ($validated['token'] ?? '');
            $stored = (string) ($printJob->client_print_token ?? '');

            if ($stored !== '' && $presented !== '' && hash_equals($stored, $presented)) {
                return response()->json([
                    'ok' => true,
                    'status' => 'printing',
                    'token' => $stored,
                    'idempotent' => true,
                ]);
            }

            return response()->json([
                'message' => 'Print job is already in progress in another browser session.',
                'current_status' => $printJob->status->value,
            ], 409);
        }

        return response()->json([
            'message' => 'Invalid print job state transition.',
            'current_status' => $printJob->status->value,
            'requested_status' => 'printing',
        ], 422);
    }

    /**
     * Confirm the job is still Printing under this ownership token before sending ZPL.
     */
    public function assertJob(Request $request, SsccPrintJob $printJob): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $printJob = $this->findAccessiblePrintJob($printJob);
        $this->assertCanMutatePrintJobBatch($printJob);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
        ]);

        if (! SsccPrintDeliveryMode::isClient($printJob->delivery_mode)) {
            return response()->json([
                'message' => 'Only client-delivered print jobs can be asserted from the browser.',
            ], 422);
        }

        $printJob->refresh();

        if ($printJob->status !== SsccPrintJobStatus::Printing) {
            return response()->json([
                'message' => 'Print job is no longer printable (superseded or finished).',
                'current_status' => $printJob->status->value,
            ], 422);
        }

        $stored = (string) ($printJob->client_print_token ?? '');
        if ($stored === '' || ! hash_equals($stored, $validated['token'])) {
            return response()->json([
                'message' => 'Print job ownership token mismatch.',
            ], 409);
        }

        // Keep FailStale Printing window from reclaiming long single-label prints.
        $printJob->touch();

        return response()->json([
            'ok' => true,
            'status' => 'printing',
        ]);
    }

    public function completeJob(
        SsccPrintJob $printJob,
        Request $request,
        SsccSerialPoolService $poolService,
        SsccBatchPrintCompletion $batchPrintCompletion,
    ): JsonResponse {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $printJob = $this->findAccessiblePrintJob($printJob);
        $this->assertCanMutatePrintJobBatch($printJob);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:printed,failed'],
            'error' => ['nullable', 'string', 'max:2000'],
            'token' => ['nullable', 'string', 'max:64'],
        ]);

        $printJob->loadMissing(['label', 'batch']);

        $requestedStatus = $validated['status'];

        if (! SsccPrintDeliveryMode::isClient($printJob->delivery_mode)) {
            return response()->json([
                'message' => 'Only client-delivered print jobs can be completed from the browser.',
            ], 422);
        }

        if ($requestedStatus === 'printed' && $printJob->status === SsccPrintJobStatus::Printed) {
            return response()->json([
                'ok' => true,
                'status' => 'printed',
                'idempotent' => true,
            ]);
        }

        if ($requestedStatus === 'failed' && $printJob->status === SsccPrintJobStatus::Failed) {
            return response()->json([
                'ok' => true,
                'status' => 'failed',
                'idempotent' => true,
            ]);
        }

        return DB::transaction(function () use ($printJob, $validated, $requestedStatus, $poolService, $batchPrintCompletion): JsonResponse {
            $token = (string) ($validated['token'] ?? '');

            if ($requestedStatus === 'failed') {
                // Without a token, only abandon never-started Queued jobs.
                // Printing jobs always require the ownership token so a 409 start
                // cannot kill another browser session's in-flight print.
                $query = SsccPrintJob::query()
                    ->whereKey($printJob->id)
                    ->where('delivery_mode', SsccPrintDeliveryMode::Client);

                if ($token === '') {
                    $query->where('status', SsccPrintJobStatus::Queued);
                } else {
                    $query
                        ->whereIn('status', [
                            SsccPrintJobStatus::Queued->value,
                            SsccPrintJobStatus::Printing->value,
                        ])
                        ->where('client_print_token', $token);
                }

                $affected = $query->update([
                    'status' => SsccPrintJobStatus::Failed,
                    'last_error' => $validated['error'] ?? 'Client print failed.',
                    'client_print_token' => null,
                ]);

                if ($affected === 0) {
                    $printJob->refresh();

                    if ($printJob->status === SsccPrintJobStatus::Failed) {
                        return response()->json([
                            'ok' => true,
                            'status' => 'failed',
                            'idempotent' => true,
                        ]);
                    }

                    if ($printJob->status === SsccPrintJobStatus::Printing) {
                        return response()->json([
                            'message' => $token === ''
                                ? 'Print ownership token is required to fail an in-progress job.'
                                : 'Print job ownership token mismatch.',
                        ], $token === '' ? 422 : 409);
                    }

                    return response()->json([
                        'message' => 'Invalid print job state transition.',
                        'current_status' => $printJob->status->value,
                        'requested_status' => 'failed',
                    ], 422);
                }

                $printJob->refresh()->loadMissing('label');

                if ($printJob->label !== null && ! SsccPrintJobLabelGuard::labelHasNewerPrintedJob($printJob)) {
                    $printJob->label->update([
                        'print_status' => SsccLabelPrintStatus::Failed,
                    ]);
                }

                return response()->json(['ok' => true, 'status' => 'failed']);
            }

            // Printed: require ownership token and Printing state (must have called start).
            if ($token === '') {
                return response()->json([
                    'message' => 'Print ownership token is required to complete a printed job.',
                ], 422);
            }

            $printedAt = now();
            $affected = SsccPrintJob::query()
                ->whereKey($printJob->id)
                ->where('delivery_mode', SsccPrintDeliveryMode::Client)
                ->where('status', SsccPrintJobStatus::Printing)
                ->where('client_print_token', $token)
                ->update([
                    'status' => SsccPrintJobStatus::Printed,
                    'printed_at' => $printedAt,
                    'last_error' => null,
                    'client_print_token' => null,
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            if ($affected === 0) {
                $printJob->refresh();

                if ($printJob->status === SsccPrintJobStatus::Printed) {
                    return response()->json([
                        'ok' => true,
                        'status' => 'printed',
                        'idempotent' => true,
                    ]);
                }

                if ($printJob->status === SsccPrintJobStatus::Printing) {
                    return response()->json([
                        'message' => 'Print job ownership token mismatch.',
                    ], 409);
                }

                return response()->json([
                    'message' => 'Invalid print job state transition.',
                    'current_status' => $printJob->status->value,
                    'requested_status' => 'printed',
                ], 422);
            }

            $printJob->refresh()->loadMissing(['label', 'batch']);

            $label = $printJob->label;
            if ($label !== null) {
                $label->update([
                    'print_status' => SsccLabelPrintStatus::Printed,
                    'printed_copies' => $printJob->copies,
                    'printed_at' => $printedAt,
                ]);

                $pool = $poolService->lockOrCreate(
                    (string) $label->company_prefix,
                    (int) $label->extension_digit,
                );
                $poolService->recordPrinted($pool, (int) $label->serial_reference_int, $printedAt);
            }

            $batchPrintCompletion->refreshBatchPrintedAt($printJob->sscc_label_batch_id);

            return response()->json(['ok' => true, 'status' => 'printed']);
        });
    }

    public function config(ResolveClientPrintBridge $resolve): JsonResponse
    {
        abort_unless(SsccLabelResource::canAccess(), 403);

        $bridge = $resolve->handle();

        return response()->json([
            'bridge' => $bridge->value,
            'bridge_label' => $bridge->shortLabel(),
            'is_client_side' => $bridge->isClientSide(),
            'routes' => [
                'set_bridge' => route('tenant.label-print.bridge.set'),
                'clear_bridge' => route('tenant.label-print.bridge.clear'),
                'start_job' => route('tenant.label-print.job.start', ['printJob' => 0]),
                'assert_job' => route('tenant.label-print.job.assert', ['printJob' => 0]),
                'complete_job' => route('tenant.label-print.job.complete', ['printJob' => 0]),
            ],
        ]);
    }

    private function findAccessibleLabel(SsccLabel $label): SsccLabel
    {
        $label->loadMissing('batch');
        $this->findAccessibleBatch($label->batch);

        return $label;
    }

    private function findAccessiblePrintJob(SsccPrintJob $printJob): SsccPrintJob
    {
        $printJob->loadMissing('batch');
        $this->findAccessibleBatch($printJob->batch);

        return $printJob;
    }

    private function findAccessibleBatch(?SsccLabelBatch $batch): SsccLabelBatch
    {
        if ($batch === null) {
            throw new NotFoundHttpException();
        }

        return SsccLabelResource::constrainBatchQuery(
            SsccLabelBatch::query()->whereKey($batch->getKey()),
        )->firstOrFail();
    }

    private function assertCanMutatePrintJobBatch(SsccPrintJob $printJob): void
    {
        $printJob->loadMissing('batch');
        $batch = $printJob->batch;

        if ($batch === null) {
            throw new NotFoundHttpException();
        }

        $this->findAccessibleBatch($batch);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $siteId = $batch->commission_site_id;

        if ($siteId === null) {
            abort_unless($user->can(Permissions::SitesAccessAll), 403);

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $siteId);
    }
}

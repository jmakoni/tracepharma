<?php

declare(strict_types=1);

namespace App\Jobs\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Logging\RedactsUrls;
use App\Support\TenantSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST a receive-confirm payload to the tenant WMS after inbound ASN / scan-first complete.
 *
 * Failures rethrow so the queue can retry ($tries + backoff). Session completion is
 * independent — CompleteReceivingSession never reverts on WMS errors. Durable
 * wms_receive_confirmed_at is stamped only after every chunk POST succeeds.
 */
final class NotifyWmsReceiveConfirm implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAX_SCANS_PER_POST = 5000;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public int $sessionId,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->sessionId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        Tenant::query()->findOrFail($this->tenantId)->run(function (): void {
            $settings = TenantSettings::forTenant(tenant());

            if ($settings->wmsWebhooksKilled()) {
                return;
            }

            $endpoint = $settings->wmsReceiveConfirmUrl();
            if (blank($endpoint)) {
                return;
            }

            try {
                TenantSettings::assertWmsReceiveConfirmHostAtConnect($endpoint);
            } catch (\InvalidArgumentException $e) {
                Log::warning('WMS receive-confirm URL rejected.', [
                    'tenant_id' => $this->tenantId,
                    'session_id' => $this->sessionId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session === null || $session->status !== 'completed' || $session->completed_at === null) {
                return;
            }

            if ($session->isTransferReceive()) {
                return;
            }

            if ($session->wms_receive_confirmed_at !== null) {
                return;
            }

            $scans = $this->confirmedScans($session);
            $chunks = array_chunk($scans, $this->maxScansPerPost());
            if ($chunks === []) {
                $chunks = [[]];
            }

            $basePayload = $this->payloadWithoutScans($session);
            $multiChunk = count($chunks) > 1;

            try {
                foreach ($chunks as $index => $chunkScans) {
                    $idempotencyKey = $this->idempotencyKey($session);
                    if ($multiChunk) {
                        $idempotencyKey .= '-chunk-'.$index;
                    }

                    $response = $this->httpClient($settings)
                        ->withHeaders([
                            'Accept' => 'application/json',
                            'Idempotency-Key' => $idempotencyKey,
                        ])
                        ->post($endpoint, array_merge($basePayload, [
                            'scans' => array_values($chunkScans),
                        ]));

                    if (! $response->successful()) {
                        throw new \RuntimeException(
                            'WMS receive-confirm POST failed (HTTP '.$response->status().').',
                        );
                    }
                }
            } catch (Throwable $e) {
                Log::warning('WMS receive-confirm forward failed.', [
                    'tenant_id' => $this->tenantId,
                    'session_id' => $this->sessionId,
                    'endpoint' => RedactsUrls::redactUrl($endpoint),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            ReceivingSession::query()
                ->whereKey($session->getKey())
                ->whereNull('wms_receive_confirmed_at')
                ->update(['wms_receive_confirmed_at' => now()]);
        });
    }

    /**
     * @return array{
     *     session_id: int,
     *     site_id: int|null,
     *     document_id: int|null,
     *     asn: string|null,
     *     po: string|null,
     *     confirmed_parent_count: int,
     *     confirmed_child_count: int
     * }
     */
    private function payloadWithoutScans(ReceivingSession $session): array
    {
        $session->loadMissing(['document', 'matchedDocument']);
        $document = $session->document ?? $session->matchedDocument;

        return [
            'session_id' => (int) $session->getKey(),
            'site_id' => $session->site_id !== null ? (int) $session->site_id : null,
            'document_id' => $session->epcis_document_id !== null
                ? (int) $session->epcis_document_id
                : ($session->matched_epcis_document_id !== null ? (int) $session->matched_epcis_document_id : null),
            'asn' => filled($document?->asn_number) ? (string) $document->asn_number : null,
            'po' => filled($document?->customer_po) ? (string) $document->customer_po : null,
            'confirmed_parent_count' => (int) $session->confirmed_parent_count,
            'confirmed_child_count' => (int) $session->confirmed_child_count,
        ];
    }

    /**
     * @return list<string>
     */
    private function confirmedScans(ReceivingSession $session): array
    {
        $scans = [];

        ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->select(['id', 'epc_id', 'scan_raw'])
            ->orderBy('id')
            ->chunkById(500, function ($lines) use (&$scans): void {
                $epcIds = $lines->pluck('epc_id')
                    ->filter(fn ($id): bool => $id !== null)
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $urisById = $epcIds === []
                    ? collect()
                    : Epc::query()
                        ->whereIn('id', $epcIds)
                        ->pluck('epc_uri', 'id');

                foreach ($lines as $line) {
                    $uri = $line->epc_id !== null
                        ? $urisById->get((int) $line->epc_id)
                        : null;

                    if (filled($uri)) {
                        $scans[] = (string) $uri;

                        continue;
                    }

                    $raw = (string) ($line->scan_raw ?? '');
                    if ($raw !== '') {
                        $scans[] = $raw;
                    }
                }
            });

        return $scans;
    }

    private function maxScansPerPost(): int
    {
        $configured = (int) config('integrations.wms.receive_confirm_max_scans', self::MAX_SCANS_PER_POST);

        return max(1, $configured > 0 ? $configured : self::MAX_SCANS_PER_POST);
    }

    private function idempotencyKey(ReceivingSession $session): string
    {
        return 'receive:'.$this->tenantId.':'.$this->sessionId.':'
            .$session->completed_at->toIso8601String();
    }

    private function httpClient(TenantSettings $settings): PendingRequest
    {
        $endpoint = $settings->wmsReceiveConfirmUrl();
        $client = filled($endpoint)
            ? TenantSettings::wmsPinnedHttpClient($endpoint, 30)
            : Http::timeout(30)->withoutRedirecting();

        $apiKey = $settings->wmsBridgeApiKey();
        if (filled($apiKey)) {
            $client = $client
                ->withToken($apiKey)
                ->withHeaders(['X-Wms-Api-Key' => $apiKey]);
        }

        return $client;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'wms-receive-confirm', 'session:'.$this->sessionId];
    }
}

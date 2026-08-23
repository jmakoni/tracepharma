<?php

declare(strict_types=1);

namespace App\Jobs\Receiving;

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
 */
final class NotifyWmsReceiveConfirm implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

            try {
                $response = $this->httpClient($settings)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Idempotency-Key' => $this->idempotencyKey($session),
                    ])
                    ->post($endpoint, $this->payload($session));

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'WMS receive-confirm POST failed (HTTP '.$response->status().').',
                    );
                }
            } catch (Throwable $e) {
                Log::warning('WMS receive-confirm forward failed.', [
                    'tenant_id' => $this->tenantId,
                    'session_id' => $this->sessionId,
                    'endpoint' => RedactsUrls::redactUrl($endpoint),
                    'error' => $e->getMessage(),
                ]);
            }
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
     *     confirmed_child_count: int,
     *     scans: list<string>
     * }
     */
    private function payload(ReceivingSession $session): array
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
            'scans' => $this->confirmedScans($session),
        ];
    }

    /**
     * @return list<string>
     */
    private function confirmedScans(ReceivingSession $session): array
    {
        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->with('epc')
            ->get()
            ->map(function (ReceivingScanLine $line): string {
                $uri = $line->epc?->epc_uri;

                if (filled($uri)) {
                    return (string) $uri;
                }

                return (string) ($line->scan_raw ?? '');
            })
            ->filter(fn (string $scan): bool => $scan !== '')
            ->values()
            ->all();
    }

    private function idempotencyKey(ReceivingSession $session): string
    {
        return 'receive:'.$this->tenantId.':'.$this->sessionId.':'
            .$session->completed_at->toIso8601String();
    }

    private function httpClient(TenantSettings $settings): PendingRequest
    {
        $client = Http::timeout(30)->withoutRedirecting();

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

<?php

declare(strict_types=1);

namespace App\Jobs\Labeling;

use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Models\Epcis\EpcisDocument;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * POST authored commissioning EPCIS XML to the tenant's external L3 endpoint.
 */
final class ForwardCommissioningToL3 implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public int $documentId,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->documentId;
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

    public function handle(RecordOperationalEpcisCatalogSignal $recordSignal): void
    {
        Tenant::query()->findOrFail($this->tenantId)->run(function () use ($recordSignal): void {
            $settings = TenantSettings::forTenant(tenant());

            if (! $settings->l3Enabled()) {
                return;
            }

            $endpoint = $settings->l3EndpointUrl();
            if (blank($endpoint)) {
                return;
            }

            $document = EpcisDocument::query()->find($this->documentId);
            if ($document === null || ! filled($document->payload_path)) {
                return;
            }

            if ($document->l3_forwarded_at !== null) {
                return;
            }

            try {
                $xml = Storage::disk($document->payloadFilesystemDisk())
                    ->get((string) $document->payload_path);

                if (! is_string($xml) || $xml === '') {
                    throw new \RuntimeException('Commissioning payload is empty.');
                }

                $response = $this->httpClient($settings)
                    ->withHeaders([
                        'Content-Type' => 'application/xml',
                        'Accept' => 'application/xml',
                        'Idempotency-Key' => 'l3-commission:'.$this->tenantId.':'.$this->documentId,
                    ])
                    ->withBody($xml, 'application/xml')
                    ->post($endpoint);

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'L3 POST failed (HTTP '.$response->status().'): '.substr((string) $response->body(), 0, 500),
                    );
                }

                $marked = EpcisDocument::query()
                    ->whereKey($this->documentId)
                    ->whereNull('l3_forwarded_at')
                    ->update(['l3_forwarded_at' => now()]);

                if ($marked === 0) {
                    return;
                }
            } catch (Throwable $e) {
                Log::warning('External L3 commissioning forward failed.', [
                    'tenant_id' => $this->tenantId,
                    'document_id' => $this->documentId,
                    'endpoint' => RedactsUrls::redactUrl($endpoint),
                    'error' => $e->getMessage(),
                ]);

                $recordSignal->l3TransmissionFailure(
                    $document->fresh() ?? $document,
                    $e->getMessage(),
                );

                throw $e;
            }
        });
    }

    private function httpClient(TenantSettings $settings): PendingRequest
    {
        $client = Http::timeout(60);

        $apiKey = $settings->l3ApiKey();
        if (filled($apiKey)) {
            $client = $client
                ->withToken($apiKey)
                ->withHeaders(['X-L3-Api-Key' => $apiKey]);
        }

        return $client;
    }

    /**
     * @deprecated Use {@see RedactsUrls::redactUrl()} instead.
     */
    public static function redactUrlForLog(string $url): string
    {
        return RedactsUrls::redactUrl($url);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'l3-forward', 'document:'.$this->documentId];
    }
}

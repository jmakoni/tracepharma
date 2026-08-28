<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisSubscription;
use App\Models\Tenant;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use App\Support\Epcis\EpcisSubscriptionUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

final class DeliverEpcisSubscriptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public string $tenantId,
        public int $subscriptionId,
        public int $documentId,
        public string $trigger = 'validated',
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(CanonicalEventsToJsonLd20 $projector): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $tenant->run(function () use ($projector): void {
            $subscription = EpcisSubscription::query()->find($this->subscriptionId);
            $document = EpcisDocument::query()->find($this->documentId);

            if ($subscription === null || ! $subscription->is_active || $document === null) {
                return;
            }

            try {
                EpcisSubscriptionUrl::assertSafeAtConnect((string) $subscription->target_url);
            } catch (\InvalidArgumentException $exception) {
                $subscription->forceFill([
                    'last_error_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ])->save();

                return;
            }

            $payload = $this->buildPayload($subscription, $document, $projector);
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $subscription->secret);

            try {
                $response = Http::timeout(20)
                    ->withoutRedirecting()
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-TracePharma-Signature' => 't='.$timestamp.',v1='.$signature,
                        'X-TracePharma-Trigger' => $this->trigger,
                        'User-Agent' => 'TracePharma-EpcisSubscription/1.0',
                    ])
                    ->withBody($body, 'application/json')
                    ->post((string) $subscription->target_url);

                if ($response->redirect()) {
                    throw new \RuntimeException(
                        'Subscription delivery refused HTTP redirect '.$response->status().' (SSRF protection).',
                    );
                }

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'Subscription delivery HTTP '.$response->status().': '.Str::limit($response->body(), 500),
                    );
                }

                $subscription->forceFill([
                    'last_delivered_at' => now(),
                    'last_error_at' => null,
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $subscription->forceFill([
                    'last_error_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ])->save();

                throw $exception;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        EpcisSubscription $subscription,
        EpcisDocument $document,
        CanonicalEventsToJsonLd20 $projector,
    ): array {
        $threshold = max(1, (int) config('tracepharma.epcis.subscription_inline_event_threshold', 50));
        $eventCount = (int) $document->event_count;
        $includeBody = $eventCount > 0 && $eventCount <= $threshold;

        $payload = [
            'subscription_id' => $subscription->getKey(),
            'trigger' => $this->trigger,
            'document_uuid' => $document->document_uuid,
            'document_id' => $document->getKey(),
            'schema_version' => '2.0',
            'format' => 'jsonld_20',
            'direction' => $document->direction,
            'status' => $document->status,
            'event_count' => $eventCount,
            'download_url' => $this->signedDownloadUrl($document),
        ];

        if ($includeBody) {
            try {
                $json = $projector->projectDocument($document);
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $payload['epcis_document'] = $decoded;
                }
            } catch (Throwable $exception) {
                Log::warning('EPCIS subscription inline projection failed; sending reference only.', [
                    'document_id' => $document->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $payload;
    }

    private function signedDownloadUrl(EpcisDocument $document): string
    {
        $minutes = max(5, (int) config('tracepharma.epcis.subscription_download_ttl_minutes', 60));

        return URL::temporarySignedRoute(
            'tenant.epcis-subscription.download',
            now()->addMinutes($minutes),
            ['document' => $document->getKey()],
        );
    }
}

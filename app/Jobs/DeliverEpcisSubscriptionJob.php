<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisSubscription;
use App\Models\Epcis\EpcisSubscriptionDelivery;
use App\Models\Tenant;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use App\Support\Epcis\EpcisSubscriptionUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deliver one EPCIS document to one subscription webhook.
 *
 * At-most-once ledger: insert unique (subscription_id, document_id) BEFORE POST.
 * On HTTP failure delete the claim so retries can re-attempt. On success keep the
 * row so a crash between POST success and last_delivered_at cannot double-POST.
 */
final class DeliverEpcisSubscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public int $subscriptionId,
        public int $documentId,
        public string $trigger = 'validated',
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':epcis-sub:'.$this->subscriptionId.':doc:'.$this->documentId;
    }

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

            if (! $this->claimDeliveryInLedger()) {
                return;
            }

            $payload = $this->buildPayload($subscription, $document, $projector);
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $subscription->secret);
            $targetUrl = (string) $subscription->target_url;

            try {
                $response = EpcisSubscriptionUrl::httpClient($targetUrl, 20)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-TracePharma-Signature' => 't='.$timestamp.',v1='.$signature,
                        'X-TracePharma-Trigger' => $this->trigger,
                        'User-Agent' => 'TracePharma-EpcisSubscription/1.0',
                    ])
                    ->withBody($body, 'application/json')
                    ->post($targetUrl);

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
            } catch (\InvalidArgumentException $exception) {
                $this->releaseDeliveryClaim();
                $subscription->forceFill([
                    'last_error_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ])->save();
            } catch (Throwable $exception) {
                $this->releaseDeliveryClaim();
                $subscription->forceFill([
                    'last_error_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ])->save();

                throw $exception;
            }
        });
    }

    private function claimDeliveryInLedger(): bool
    {
        if ($this->deliveryAlreadyRecorded()) {
            return false;
        }

        try {
            EpcisSubscriptionDelivery::query()->create([
                'subscription_id' => $this->subscriptionId,
                'document_id' => $this->documentId,
                'trigger' => $this->trigger,
                'delivered_at' => now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        return true;
    }

    private function releaseDeliveryClaim(): void
    {
        EpcisSubscriptionDelivery::query()
            ->where('subscription_id', $this->subscriptionId)
            ->where('document_id', $this->documentId)
            ->delete();
    }

    private function deliveryAlreadyRecorded(): bool
    {
        return EpcisSubscriptionDelivery::query()
            ->where('subscription_id', $this->subscriptionId)
            ->where('document_id', $this->documentId)
            ->exists();
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

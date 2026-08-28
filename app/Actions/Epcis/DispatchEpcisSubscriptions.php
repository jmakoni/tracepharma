<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Jobs\DeliverEpcisSubscriptionJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisSubscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

/**
 * Queue HTTPS subscription deliveries when a document reaches validated (inbound)
 * or outbound sent. Trigger must match document direction so outbound hooks do
 * not fire on validate-time for documents that still need transmit.
 *
 * Not a GS1 Query Control scheduler: `schedule` / `query_params` on the subscription
 * row are not executed here (except bizStep matching already stored on the row).
 */
final class DispatchEpcisSubscriptions
{
    public function handle(EpcisDocument $document, string $trigger): void
    {
        if (! Schema::hasTable('epcis_subscriptions')) {
            return;
        }

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return;
        }

        $direction = (string) $document->direction;
        $expectedTrigger = match ($direction) {
            'inbound' => 'validated',
            'outbound' => 'sent',
            default => null,
        };

        if ($expectedTrigger === null || $trigger !== $expectedTrigger) {
            return;
        }

        $bizSteps = $this->documentBizSteps($document);

        $subscriptions = EpcisSubscription::query()
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->matchesDirection($direction)) {
                continue;
            }

            if (! $subscription->matchesBizSteps($bizSteps)) {
                continue;
            }

            DeliverEpcisSubscriptionJob::dispatch(
                (string) $tenant->getKey(),
                (int) $subscription->getKey(),
                (int) $document->getKey(),
                $trigger,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function documentBizSteps(EpcisDocument $document): array
    {
        return $document->activeEvents()
            ->whereNotNull('biz_step')
            ->distinct()
            ->pluck('biz_step')
            ->map(static fn (mixed $step): string => (string) $step)
            ->values()
            ->all();
    }
}

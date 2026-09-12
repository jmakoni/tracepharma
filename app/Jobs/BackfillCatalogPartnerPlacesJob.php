<?php

namespace App\Jobs;

use App\Actions\Places\BackfillCatalogPartnerPlaces;
use App\Models\Fda\FdaOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class BackfillCatalogPartnerPlacesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * High try budget: RateLimited releases must not exhaust attempts before retryUntil (2 days).
     */
    public int $tries = 400;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $partnerId,
        public readonly bool $onlyMissing = true,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('places-backfill')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function handle(BackfillCatalogPartnerPlaces $action): void
    {
        $organization = FdaOrganization::query()->find($this->partnerId);

        if ($organization === null) {
            return;
        }

        $action->handle($organization, $this->onlyMissing, dryRun: false);
    }
}

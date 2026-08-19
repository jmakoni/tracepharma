<?php

namespace App\Actions\Fda;

use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaProduct;
use App\Support\Fda\CompanyNameNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

final class ResolveFdaOrganizationMatchReview
{
    public function link(FdaOrganizationMatchReview $review, FdaOrganization $organization, ?Admin $actor = null): void
    {
        $this->assertPending($review);
        $this->mark($review, FdaOrganizationMatchReview::STATUS_LINKED, $organization, $actor);
        $this->backfillOpenFdaLinks($review, $organization);
    }

    public function createOrganization(FdaOrganizationMatchReview $review, ?Admin $actor = null): FdaOrganization
    {
        $this->assertPending($review);

        $canonical = $review->canonical_name
            ?: CompanyNameNormalizer::canonical((string) $review->original_name);

        if ($canonical === '') {
            $canonical = (string) $review->original_name;
        }

        try {
            $organization = FdaOrganization::query()->create([
                'original_name' => $review->original_name,
                'canonical_name' => $canonical,
                'name' => $review->original_name,
                'duns_number' => $review->duns_number,
            ]);
            $status = FdaOrganizationMatchReview::STATUS_CREATED_NEW;
        } catch (QueryException $exception) {
            $organization = ($review->duns_number !== null
                    ? FdaOrganization::query()->where('duns_number', $review->duns_number)->first()
                    : null)
                ?? FdaOrganization::query()->where('canonical_name', $canonical)->whereNull('duns_number')->first()
                ?? ($review->duns_number === null
                    ? FdaOrganization::query()->where('canonical_name', $canonical)->first()
                    : null);

            if (! $organization instanceof FdaOrganization) {
                throw $exception;
            }

            $status = FdaOrganizationMatchReview::STATUS_LINKED;
        }

        $this->mark($review, $status, $organization, $actor);
        $this->backfillOpenFdaLinks($review, $organization);

        return $organization;
    }

    public function reject(FdaOrganizationMatchReview $review, Admin $actor): void
    {
        $this->assertPending($review);
        $this->mark($review, FdaOrganizationMatchReview::STATUS_REJECTED, null, $actor);
    }

    private function assertPending(FdaOrganizationMatchReview $review): void
    {
        if ($review->status !== FdaOrganizationMatchReview::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending match reviews can be resolved.');
        }
    }

    private function mark(
        FdaOrganizationMatchReview $review,
        string $status,
        ?FdaOrganization $organization,
        ?Admin $actor,
    ): void {
        $wasPending = $review->status === FdaOrganizationMatchReview::STATUS_PENDING;

        try {
            $review->forceFill([
                'status' => $status,
                'resolved_fda_organization_id' => $organization?->id,
                'resolved_by_admin_id' => $actor?->id,
                'resolved_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $duplicate = $exception instanceof UniqueConstraintViolationException
                || (int) ($exception->errorInfo[1] ?? 0) === 1062;

            // Another review already occupies (source, original_name, proposed, status).
            // This pending duplicate is superseded — drop it.
            if ($duplicate && $wasPending) {
                $fresh = FdaOrganizationMatchReview::query()->find($review->getKey());
                if ($fresh?->status === FdaOrganizationMatchReview::STATUS_PENDING) {
                    $fresh->delete();
                }

                return;
            }

            throw $exception;
        }
    }

    private function backfillOpenFdaLinks(FdaOrganizationMatchReview $review, FdaOrganization $organization): void
    {
        if ($review->source !== 'openfda_ndc' || blank($review->original_name)) {
            return;
        }

        // labeler_name was dropped from fda_products; products are linked at import via org slug.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('fda_products', 'labeler_name')) {
            return;
        }

        FdaProduct::query()
            ->whereNull('fda_organization_id')
            ->where('labeler_name', $review->original_name)
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($organization): void {
                FdaProduct::query()
                    ->whereIn('id', $products->pluck('id'))
                    ->update(['fda_organization_id' => $organization->id]);
            });
    }
}

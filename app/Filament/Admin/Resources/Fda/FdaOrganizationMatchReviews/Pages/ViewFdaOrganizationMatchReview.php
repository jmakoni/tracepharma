<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Support\MatchReviewActions;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaOrganizationMatchReview extends ViewRecord
{
    protected static string $resource = FdaOrganizationMatchReviewResource::class;

    protected function getHeaderActions(): array
    {
        return MatchReviewActions::all();
    }
}

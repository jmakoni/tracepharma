<?php

namespace App\Actions\Fda3911;

use App\Enums\Fda3911ReportStatus;
use App\Models\Fda3911Report;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;

class MarkFda3911Submitted
{
    public function execute(Fda3911Report $report, User $user): Fda3911Report
    {
        $allowed = SiteAccess::constrainExceptionCaseRelation(
            Fda3911Report::query()->whereKey($report->getKey()),
            'exceptionCase',
            $user,
        )->exists();

        if (! $allowed) {
            throw new AuthorizationException('You do not have access to this FDA 3911 report.');
        }

        $report->update([
            'status' => Fda3911ReportStatus::Submitted,
            'submitted_at' => now(),
            'submitted_by' => $user->id,
        ]);

        return $report->refresh();
    }
}

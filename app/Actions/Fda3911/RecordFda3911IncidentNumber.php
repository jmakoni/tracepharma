<?php

namespace App\Actions\Fda3911;

use App\Enums\Fda3911ReportStatus;
use App\Models\Fda3911Report;

class RecordFda3911IncidentNumber
{
    public function execute(Fda3911Report $report, string $incidentNumber): Fda3911Report
    {
        $report->update([
            'status' => Fda3911ReportStatus::Acknowledged,
            'incident_number' => $incidentNumber,
            'acknowledged_at' => now(),
        ]);

        return $report->refresh();
    }
}

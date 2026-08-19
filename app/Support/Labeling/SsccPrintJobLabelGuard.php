<?php

namespace App\Support\Labeling;

use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintJobStatus;
use App\Models\SsccPrintJob;

class SsccPrintJobLabelGuard
{
    public static function shouldSkipFailureOnLabel(SsccPrintJob $printJob): bool
    {
        return self::labelHasNewerPrintedJob($printJob);
    }

    public static function labelHasNewerPrintedJob(SsccPrintJob $printJob): bool
    {
        $label = $printJob->label;

        if ($label === null) {
            return false;
        }

        if ($label->print_status === SsccLabelPrintStatus::Printed) {
            return true;
        }

        return SsccPrintJob::query()
            ->where('sscc_label_id', $printJob->sscc_label_id)
            ->where('id', '>', $printJob->id)
            ->where('status', SsccPrintJobStatus::Printed)
            ->exists();
    }

    public static function isSupersededJob(SsccPrintJob $job): bool
    {
        if ($job->status !== SsccPrintJobStatus::Failed) {
            return false;
        }

        return str_contains((string) ($job->last_error ?? ''), 'Superseded by a newer print request');
    }

    public static function hasNewerJobForLabel(SsccPrintJob $job): bool
    {
        return SsccPrintJob::query()
            ->where('sscc_label_id', $job->sscc_label_id)
            ->where('id', '>', $job->id)
            ->exists();
    }
}

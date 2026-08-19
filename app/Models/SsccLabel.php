<?php

namespace App\Models;

use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SsccLabel extends Model
{
    protected $fillable = [
        'batch_id',
        'label_printer_id',
        'sscc_18',
        'sscc_urn',
        'extension_digit',
        'company_prefix',
        'serial_reference',
        'serial_reference_int',
        'allocation_mode',
        'element_string',
        'hrt',
        'ship_to_name',
        'ship_to_gln',
        'notes',
        'label_disk',
        'label_path',
        'template_version',
        'print_status',
        'printed_copies',
        'printed_at',
        'epcis_file_path',
        'epcis_emitted_at',
        'commissioning_epcis_file_path',
        'commissioned_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'serial_reference_int' => 'integer',
            'allocation_mode' => SsccAllocationMode::class,
            'print_status' => SsccLabelPrintStatus::class,
            'printed_copies' => 'integer',
            'printed_at' => 'datetime',
            'epcis_emitted_at' => 'datetime',
            'commissioned_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SsccLabelBatch::class, 'batch_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(LabelPrinter::class, 'label_printer_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SsccLabelChild::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(SsccPrintJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * True when the label's own print status is Failed, or its most recent print job failed.
     * When the printJobs relation is loaded, callers must load it latest-first
     * (e.g. `->latest('id')`) so `first()` reflects the most recent attempt.
     */
    public function printHasFailed(): bool
    {
        if ($this->print_status === SsccLabelPrintStatus::Failed) {
            return true;
        }

        $latestJob = $this->relationLoaded('printJobs')
            ? $this->printJobs->first()
            : $this->printJobs()->latest('id')->first();

        return $latestJob?->status === SsccPrintJobStatus::Failed;
    }
}

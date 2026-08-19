<?php

namespace App\Models;

use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsccPrintJob extends Model
{
    protected $fillable = [
        'sscc_label_batch_id',
        'sscc_label_id',
        'label_printer_id',
        'copies',
        'status',
        'delivery_mode',
        'client_print_token',
        'attempts',
        'last_error',
        'queued_at',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SsccPrintJobStatus::class,
            'delivery_mode' => SsccPrintDeliveryMode::class,
            'copies' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SsccLabelBatch::class, 'sscc_label_batch_id');
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(SsccLabel::class, 'sscc_label_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(LabelPrinter::class, 'label_printer_id');
    }
}

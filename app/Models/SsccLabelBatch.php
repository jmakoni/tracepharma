<?php

namespace App\Models;

use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\EpcisDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SsccLabelBatch extends Model
{
    /**
     * Transient print dispatch payload from {@see GenerateSsccLabelBatch}; not persisted.
     *
     * @var array{mode: 'network'|'client', bridge: string, jobs: list<array<string, mixed>>}|null
     */
    public ?array $printDispatch = null;

    /**
     * Transient non-fatal failure lines (PDF, print, EPCIS) from
     * {@see GenerateSsccLabelBatch}; not persisted.
     *
     * @var list<string>
     */
    public array $emitErrors = [];

    protected $fillable = [
        'company_prefix',
        'extension_digit',
        'allocation_mode',
        'label_count',
        'copies_per_label',
        'label_printer_id',
        'commission_site_id',
        'send_to_printer',
        'emit_epcis',
        'emit_disaggregation',
        'source_epcis_document_id',
        'source_parent_sscc_urn',
        'trading_partner_id',
        'allocation_config',
        'status',
        'ship_to_name',
        'ship_to_gln',
        'notes',
        'created_by',
        'completed_at',
        'error_message',
        'epcis_file_path',
        'epcis_emitted_at',
        'commissioning_epcis_file_path',
        'commissioned_at',
        'disaggregation_file_path',
        'disaggregation_emitted_at',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'allocation_mode' => SsccAllocationMode::class,
            'status' => SsccLabelBatchStatus::class,
            'allocation_config' => 'array',
            'label_count' => 'integer',
            'copies_per_label' => 'integer',
            'send_to_printer' => 'boolean',
            'emit_epcis' => 'boolean',
            'emit_disaggregation' => 'boolean',
            'completed_at' => 'datetime',
            'epcis_emitted_at' => 'datetime',
            'commissioned_at' => 'datetime',
            'disaggregation_emitted_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function labels(): HasMany
    {
        return $this->hasMany(SsccLabel::class, 'batch_id');
    }

    /**
     * Any recorded failure — allocation, PDF, print, commissioning or EPCIS emit.
     */
    public function hasErrors(): bool
    {
        return filled($this->error_message) || $this->emitErrors !== [];
    }

    public function hasCommissioningError(): bool
    {
        return str_contains((string) $this->error_message, 'Commissioning:');
    }

    public function hasAggregationError(): bool
    {
        return str_contains((string) $this->error_message, 'EPCIS aggregation:');
    }

    /**
     * Whether pack / break-pack may treat this batch as fully successful
     * (commissioned parent plus ingested aggregation when children were attached).
     */
    public function packingSucceeded(): bool
    {
        if ($this->status !== SsccLabelBatchStatus::Completed) {
            return false;
        }

        if ($this->hasCommissioningError() || $this->hasAggregationError()) {
            return false;
        }

        if (! $this->requiresAggregation()) {
            return true;
        }

        return $this->epcis_emitted_at !== null && ! $this->hasAggregationError();
    }

    public function requiresAggregation(): bool
    {
        if (! $this->emit_epcis) {
            return false;
        }

        $this->loadMissing('labels.children');

        return $this->labels->flatMap->children->isNotEmpty();
    }

    /**
     * Recorded failure lines, transient emit errors first.
     *
     * @return list<string>
     */
    public function errorLines(): array
    {
        $persisted = preg_split("/\r\n|\n|\r/", (string) $this->error_message) ?: [];

        return array_values(array_unique(array_filter(array_map(
            'trim',
            [...$this->emitErrors, ...$persisted],
        ))));
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(LabelPrinter::class, 'label_printer_id');
    }

    public function commissionSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'commission_site_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'source_epcis_document_id');
    }

    /**
     * Resolve the authored commissioning EPCIS document by payload path, then notes.
     */
    public function commissioningEpcisDocument(): ?EpcisDocument
    {
        $path = $this->commissioning_epcis_file_path;
        if (is_string($path) && $path !== '') {
            $byPath = EpcisDocument::query()->where('payload_path', $path)->first();
            if ($byPath !== null) {
                return $byPath;
            }
        }

        $batchId = $this->getKey();
        if ($batchId === null) {
            return null;
        }

        return EpcisDocument::query()
            ->where('notes', 'like', 'Generated SSCC commissioning EPCIS for sscc_label_batch_id='.$batchId.'.%')
            ->orderByDesc('id')
            ->first();
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(SsccPrintJob::class, 'sscc_label_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<x-filament-panels::page>
    <div class="space-y-6">
        @if (filled($this->batch->error_message))
            @php
                $errorIsPdf = str_starts_with((string) $this->batch->error_message, 'PDF:');
            @endphp
            <div
                role="alert"
                class="alert {{ $errorIsPdf ? 'alert-warning' : 'alert-error' }}"
            >
                <span>{{ $this->batch->error_message }}</span>
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Batch summary</x-slot>
            <x-slot name="afterHeader">
                @if ($this->batch->commissioned_at !== null)
                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-printer"
                        id="reprint"
                        wire:click="mountAction('reprintBatch')"
                    >
                        Reprint batch
                    </x-filament::button>
                @endif
            </x-slot>

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="opacity-70">Strategy</dt>
                    <dd class="font-medium">{{ $this->batch->allocation_mode->label() }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Status</dt>
                    <dd class="font-medium">{{ $this->batch->status->label() }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Labels generated</dt>
                    <dd class="font-medium">{{ $this->batch->label_count }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Copies per label</dt>
                    <dd class="font-medium">{{ $this->batch->copies_per_label }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Printer</dt>
                    <dd>{{ $this->batch->printer?->displayName() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Commissioned</dt>
                    <dd>{{ $this->batch->commissioned_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Commissioning EPCIS</dt>
                    <dd>
                        @php
                            $commissioningDoc = $this->batch->commissioningEpcisDocument();
                        @endphp
                        @if ($commissioningDoc)
                            <a
                                href="{{ $commissioningDoc->filamentViewUrl() }}"
                                class="text-primary-600 hover:underline"
                            >
                                Document #{{ $commissioningDoc->id }}
                            </a>
                        @elseif (filled($this->batch->commissioning_epcis_file_path))
                            <span class="font-mono text-xs">{{ $this->batch->commissioning_epcis_file_path }}</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="opacity-70">Commission site</dt>
                    <dd>
                        @if ($this->batch->commissionSite)
                            {{ $this->batch->commissionSite->name }}
                            @if (filled($this->batch->commissionSite->gln))
                                <span class="opacity-70">({{ $this->batch->commissionSite->gln }})</span>
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="opacity-70">Aggregation EPCIS emitted</dt>
                    <dd>{{ $this->batch->epcis_emitted_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Batch printed</dt>
                    <dd>{{ $this->batch->printed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Source document</dt>
                    <dd>
                        @if ($this->batch->sourceDocument)
                            <a
                                href="{{ $this->batch->sourceDocument->filamentViewUrl() }}"
                                class="text-primary-600 hover:underline"
                            >
                                Document #{{ $this->batch->sourceDocument->id }}
                            </a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="opacity-70">Source pallet</dt>
                    <dd class="font-mono text-xs">{{ $this->batch->source_parent_sscc_urn ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Disaggregation emitted</dt>
                    <dd>{{ $this->batch->disaggregation_emitted_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="opacity-70">Generated by</dt>
                    <dd>{{ $this->batch->creator?->name ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        @if ($editingLabelId)
            <x-filament::section>
                <x-slot name="heading">Edit child EPCs</x-slot>

                <div class="space-y-4">
                    <textarea
                        wire:model="childEpcsText"
                        rows="6"
                        class="fi-input block w-full rounded-lg font-mono text-sm"
                        placeholder="One child EPC URN per line"
                    ></textarea>
                    <div class="flex gap-3">
                        <x-filament::button icon="heroicon-o-check-circle" wire:click="saveChildren">
                            Save children
                        </x-filament::button>
                        <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="cancelChildrenEditor">
                            Cancel
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Generated SSCCs</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pr-4">SSCC-18</th>
                            <th class="py-2 pr-4">Serial ref</th>
                            <th class="py-2 pr-4">Children</th>
                            <th class="py-2 pr-4">Print</th>
                            <th class="py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->batch->labels as $label)
                            @php
                                $latestPrintJob = $this->latestPrintJobForLabel($label);
                                $printFailed = $label->printHasFailed();
                                $printError = $printFailed
                                    ? (filled($latestPrintJob?->last_error) ? (string) $latestPrintJob->last_error : 'Print failed.')
                                    : null;
                            @endphp
                            <tr class="border-b border-gray-200 dark:border-white/10" wire:key="label-{{ $label->id }}">
                                <td class="py-2 pr-4 font-mono">
                                    <x-copyable-identifier :value="$label->sscc_18" title="Copy SSCC">
                                        @php
                                            $ssccTraceScan = $this->batch->commissioned_at !== null
                                                ? \App\Support\Tracing\AssetTrackingUrl::scanForSsccLabel([
                                                    'sscc18' => $label->sscc_18,
                                                    'element_string' => $label->element_string,
                                                ])
                                                : null;
                                        @endphp
                                        @if ($ssccTraceScan)
                                            <a
                                                href="{{ \App\Filament\App\Pages\AssetTracking::getUrl(['scan' => $ssccTraceScan]) }}"
                                                class="tp-trace-link"
                                            >{{ $label->sscc_18 }}</a>
                                        @else
                                            <span>{{ $label->sscc_18 }}</span>
                                        @endif
                                    </x-copyable-identifier>
                                </td>
                                <td class="py-2 pr-4 font-mono">{{ $label->serial_reference_int }}</td>
                                <td class="py-2 pr-4">{{ $label->children->count() }}</td>
                                <td class="py-2 pr-4">
                                    <div class="space-y-1">
                                        <span class="{{ $this->printStatusBadgeClass($label->print_status) }}">
                                            {{ $label->print_status?->label() ?? '—' }}
                                        </span>
                                        @if ($printError)
                                            <p class="text-xs text-error max-w-xs">{{ $printError }}</p>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2">
                                    <div class="flex flex-wrap gap-3">
                                        <button
                                            type="button"
                                            wire:click="openChildrenEditor({{ $label->id }})"
                                            class="text-primary-600 hover:underline"
                                        >
                                            Children
                                        </button>
                                        @if (filled($label->label_path) && filled($label->label_disk) && ($label->commissioned_at !== null || $this->batch->commissioned_at !== null))
                                            <button
                                                type="button"
                                                wire:click="downloadLabel({{ $label->id }})"
                                                class="text-primary-600 hover:underline"
                                            >
                                                PDF
                                            </button>
                                        @endif
                                        @if ($printFailed)
                                            <button
                                                type="button"
                                                wire:click="retryPrintLabel({{ $label->id }})"
                                                class="text-error hover:underline font-medium"
                                            >
                                                Retry print
                                            </button>
                                        @endif
                                        @if ($this->batch->commissioned_at !== null)
                                            <button
                                                type="button"
                                                wire:click="mountAction('reprintBatch')"
                                                class="text-primary-600 hover:underline"
                                            >
                                                Reprint…
                                            </button>
                                        @endif
                                        @if ($this->batch->commissioned_at !== null && filled($label->sscc_18))
                                            @php
                                                $traceScan = \App\Support\Tracing\AssetTrackingUrl::scanForSsccLabel([
                                                    'sscc18' => $label->sscc_18,
                                                    'element_string' => $label->element_string,
                                                ]);
                                            @endphp
                                            @if ($traceScan)
                                                <a
                                                    href="{{ \App\Filament\App\Pages\AssetTracking::getUrl(['scan' => $traceScan]) }}"
                                                    class="tp-trace-link"
                                                >
                                                    Open in Trace
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @php
            $failedPrintJobs = $this->batch->printJobs
                ->where('status', \App\Enums\SsccPrintJobStatus::Failed)
                ->filter(function ($printJob) {
                    $label = $this->batch->labels->firstWhere('id', $printJob->sscc_label_id);
                    if ($label === null) {
                        return false;
                    }
                    $latestPrintJob = $this->latestPrintJobForLabel($label);

                    return $latestPrintJob?->id === $printJob->id;
                })
                ->values();
        @endphp
        @if ($failedPrintJobs->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Failed print jobs</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left dark:border-white/10">
                                <th class="py-2 pr-4">Job</th>
                                <th class="py-2 pr-4">SSCC-18</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Attempts</th>
                                <th class="py-2">Last error</th>
                                <th class="py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($failedPrintJobs as $printJob)
                                @php
                                    $jobLabel = $this->batch->labels->firstWhere('id', $printJob->sscc_label_id);
                                @endphp
                                <tr class="border-b border-gray-200 dark:border-white/10" wire:key="print-job-{{ $printJob->id }}">
                                    <td class="py-2 pr-4 font-mono">#{{ $printJob->id }}</td>
                                    <td class="py-2 pr-4 font-mono">
                                        <x-copyable-identifier :value="$jobLabel?->sscc_18" title="Copy SSCC" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <span class="badge badge-error">{{ $printJob->status->label() }}</span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $printJob->attempts }}</td>
                                    <td class="py-2 text-error">{{ $printJob->last_error ?: '—' }}</td>
                                    <td class="py-2 whitespace-nowrap">
                                        @if ($jobLabel)
                                            <button
                                                type="button"
                                                wire:click="retryPrintLabel({{ $jobLabel->id }})"
                                                class="text-error hover:underline font-medium"
                                            >
                                                Retry print
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

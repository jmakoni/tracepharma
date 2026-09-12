@php
    $shipWizardSteps = [
        1 => ['label' => 'Scan', 'description' => 'Confirm shippable EPCs'],
        2 => ['label' => 'Customer', 'description' => 'Partner and ship-to'],
        3 => ['label' => 'Send', 'description' => 'ASN, refs, TI/TS'],
    ];
@endphp
<div class="fi-sc-wizard fi-contained rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
    <nav class="fi-sc-wizard-header" aria-label="Ship order steps">
        @foreach ($shipWizardSteps as $stepNumber => $stepMeta)
            <div
                @class([
                    'fi-sc-wizard-header-step',
                    'fi-active' => $this->wizardStep === $stepNumber,
                    'fi-completed' => $this->wizardStep > $stepNumber,
                ])
            >
                <button
                    type="button"
                    wire:click="goToStep({{ $stepNumber }})"
                    class="fi-sc-wizard-header-step-btn"
                >
                    <div class="fi-sc-wizard-header-step-icon-ctn">
                        @if ($this->wizardStep > $stepNumber)
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-primary-600 dark:text-primary-400" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        @else
                            <span class="fi-sc-wizard-header-step-number">
                                {{ str_pad((string) $stepNumber, 2, '0', STR_PAD_LEFT) }}
                            </span>
                        @endif
                    </div>

                    <div class="fi-sc-wizard-header-step-text">
                        <span class="fi-sc-wizard-header-step-label">
                            {{ $stepMeta['label'] }}
                        </span>
                        <span class="fi-sc-wizard-header-step-description">
                            {{ $stepMeta['description'] }}
                        </span>
                    </div>
                </button>

                @if (! $loop->last)
                    <div class="fi-sc-wizard-header-step-separator" aria-hidden="true"></div>
                @endif
            </div>
        @endforeach
    </nav>
</div>

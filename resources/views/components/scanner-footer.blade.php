@props([
    'showExceptions' => false,
])

{{-- Slot for scan-only primary macros (Confirm / Complete). Discrepancy claims are out of band. --}}
<div class="space-y-3">
    @if ($showExceptions)
        {{-- Reserved — do not enable discrepancy macros on Phase 3 floor scan. --}}
    @endif
    {{ $slot }}
</div>

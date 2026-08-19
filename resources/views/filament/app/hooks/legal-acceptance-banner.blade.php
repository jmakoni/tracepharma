@php
    use App\Support\Admin\TenantImpersonation;
    use App\Support\Legal\LegalAcceptance;
    use App\Filament\App\Pages\AcceptLegalDocuments;

    $user = auth()->user();
@endphp

@if (
    $user !== null
    && ! TenantImpersonation::isActive()
    && LegalAcceptance::isGated($user)
    && LegalAcceptance::isStale($user)
    && ! LegalAcceptance::isHardBlocked($user)
)
    @php
        $graceEndsAt = LegalAcceptance::graceEndsAt($user);
        $deadline = $graceEndsAt?->timezone(config('app.timezone'))->format('M j, Y g:i A T');
        $acceptUrl = AcceptLegalDocuments::getUrl(panel: 'app');
    @endphp

    <div class="alert alert-warning rounded-none border-x-0 border-t-0 shadow-none mx-0 w-full justify-center gap-2 py-2 text-sm">
        <span class="font-semibold">Legal documents updated</span>
        <span class="opacity-80">
            Accept the current Terms of Service and Privacy Policy
            @if ($deadline !== null)
                by {{ $deadline }}
            @endif
            to keep using organization settings.
        </span>
        <a href="{{ $acceptUrl }}" class="link font-semibold">
            Review and accept
        </a>
    </div>
@endif

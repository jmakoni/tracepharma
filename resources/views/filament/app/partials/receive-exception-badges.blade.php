@php
    $exceptionBadges = $this->receiveExceptionBadgeCounts();
    $inboxUrls = $this->receiveExceptionInboxUrls();
@endphp
<div class="flex flex-wrap items-center gap-1.5" aria-label="Receive exceptions">
    @if (filled($inboxUrls['shortage'] ?? null) && ($exceptionBadges['shortage'] ?? 0) > 0)
        <a href="{{ $inboxUrls['shortage'] }}" class="badge badge-ghost">Shortage {{ $exceptionBadges['shortage'] }}</a>
    @else
        <span class="badge badge-ghost">Shortage {{ $exceptionBadges['shortage'] }}</span>
    @endif
    @if (filled($inboxUrls['no_data'] ?? null) && ($exceptionBadges['no_data'] ?? 0) > 0)
        <a href="{{ $inboxUrls['no_data'] }}" class="badge badge-ghost">No data {{ $exceptionBadges['no_data'] }}</a>
    @else
        <span class="badge badge-ghost">No data {{ $exceptionBadges['no_data'] }}</span>
    @endif
    @if (filled($inboxUrls['quarantine'] ?? null) && ($exceptionBadges['quarantine'] ?? 0) > 0)
        <a href="{{ $inboxUrls['quarantine'] }}" class="badge badge-ghost">Quarantine {{ $exceptionBadges['quarantine'] }}</a>
    @else
        <span class="badge badge-ghost">Quarantine {{ $exceptionBadges['quarantine'] }}</span>
    @endif
    @if (filled($inboxUrls['mismatch'] ?? null) && ($exceptionBadges['mismatch'] ?? 0) > 0)
        <a href="{{ $inboxUrls['mismatch'] }}" class="badge badge-ghost">Mismatch {{ $exceptionBadges['mismatch'] }}</a>
    @else
        <span class="badge badge-ghost">Mismatch {{ $exceptionBadges['mismatch'] }}</span>
    @endif
    @if (filled($inboxUrls['overage'] ?? null) && ($exceptionBadges['overage'] ?? 0) > 0)
        <a href="{{ $inboxUrls['overage'] }}" class="badge badge-ghost">Overage {{ $exceptionBadges['overage'] }}</a>
    @else
        <span class="badge badge-ghost">Overage {{ $exceptionBadges['overage'] }}</span>
    @endif
    @if (filled($inboxUrls['wrong_site'] ?? null) && ($exceptionBadges['wrong_site'] ?? 0) > 0)
        <a href="{{ $inboxUrls['wrong_site'] }}" class="badge badge-ghost">Wrong site {{ $exceptionBadges['wrong_site'] }}</a>
    @else
        <span class="badge badge-ghost">Wrong site {{ $exceptionBadges['wrong_site'] }}</span>
    @endif
    @if (filled($inboxUrls['document_hold'] ?? null) && ($exceptionBadges['document_hold'] ?? 0) > 0)
        <a href="{{ $inboxUrls['document_hold'] }}" class="badge badge-ghost">Document hold {{ $exceptionBadges['document_hold'] }}</a>
    @else
        <span class="badge badge-ghost">Document hold {{ $exceptionBadges['document_hold'] }}</span>
    @endif
    @if (filled($inboxUrls['wrong_item'] ?? null) && ($exceptionBadges['wrong_item'] ?? 0) > 0)
        <a href="{{ $inboxUrls['wrong_item'] }}" class="badge badge-ghost">Wrong item {{ $exceptionBadges['wrong_item'] }}</a>
    @else
        <span class="badge badge-ghost">Wrong item {{ $exceptionBadges['wrong_item'] }}</span>
    @endif
    @if (filled($inboxUrls['damaged'] ?? null) && ($exceptionBadges['damaged'] ?? 0) > 0)
        <a href="{{ $inboxUrls['damaged'] }}" class="badge badge-ghost">Damaged {{ $exceptionBadges['damaged'] }}</a>
    @else
        <span class="badge badge-ghost">Damaged {{ $exceptionBadges['damaged'] }}</span>
    @endif
</div>

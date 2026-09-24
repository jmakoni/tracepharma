@props([
    'header' => null,
    'class' => 'flex flex-wrap items-center gap-1.5',
])

@php
    /** @var array{po: ?string, asn: ?string, status: string, parents_confirmed: int, parents_expected: int, eaches_confirmed: int, eaches_expected: int, child_type_label?: string}|null $header */
    $parentsExpected = is_array($header) ? (int) ($header['parents_expected'] ?? 0) : 0;
    $eachesExpected = is_array($header) ? (int) ($header['eaches_expected'] ?? 0) : 0;
    $showCounts = $parentsExpected > 0 || $eachesExpected > 0;
    $childTypeLabel = is_array($header) && filled($header['child_type_label'] ?? null)
        ? (string) $header['child_type_label']
        : 'Cases';
    $status = is_array($header) ? (string) ($header['status'] ?? 'open') : 'open';
    $statusLabel = match ($status) {
        'complete' => 'ASN complete',
        'open' => 'ASN partial',
        'cancelled' => 'ASN cancelled',
        'expected' => 'ASN expected',
        default => 'ASN '.$status,
    };
@endphp

@if (is_array($header))
    <div
        {{ $attributes->class([$class]) }}
        aria-label="Expected ASN {{ filled($header['asn'] ?? null) ? $header['asn'] : '' }}"
    >
        @if (filled($header['po'] ?? null))
            <span class="badge badge-outline">PO {{ $header['po'] }}</span>
        @endif

        @if (filled($header['asn'] ?? null))
            <span class="badge badge-outline">ASN {{ $header['asn'] }}</span>
        @endif

        <span @class([
            'badge badge-outline',
            'badge-success' => $status === 'complete',
            'badge-warning' => $status === 'open',
            'badge-info' => $status === 'expected',
            'badge-ghost' => $status === 'cancelled',
        ])>{{ $statusLabel }}</span>

        @if ($showCounts)
            <span class="badge badge-ghost">
                Parents {{ (int) ($header['parents_confirmed'] ?? 0) }}/{{ $parentsExpected }}
            </span>

            <span class="badge badge-ghost">
                {{ $childTypeLabel }} {{ (int) ($header['eaches_confirmed'] ?? 0) }}/{{ $eachesExpected }}
            </span>
        @endif
    </div>
@endif

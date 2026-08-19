@php
    /** @var array{within_30: int, within_60: int, within_90: int, licenses: list<array{id: int, license_number: string, site_name: string|null, expires_on: string|null, days_left: int|null, site_id: int|null}>} $data */
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">0–30 days</div>
        <div class="stat-value text-error text-2xl">{{ number_format($data['within_30']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">31–60 days</div>
        <div class="stat-value text-warning text-2xl">{{ number_format($data['within_60']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">61–90 days</div>
        <div class="stat-value text-2xl">{{ number_format($data['within_90']) }}</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>License</th>
                <th>Site</th>
                <th>Expires</th>
                <th>Days left</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['licenses'] as $license)
                <tr>
                    <td class="font-mono">{{ $license['license_number'] }}</td>
                    <td>
                        @if ($url = $this->siteUrl($license['site_id']))
                            <a href="{{ $url }}" class="link link-hover">{{ $license['site_name'] ?? 'Site' }}</a>
                        @else
                            {{ $license['site_name'] ?? '—' }}
                        @endif
                    </td>
                    <td>{{ $license['expires_on'] ?? '—' }}</td>
                    <td>{{ $license['days_left'] ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="opacity-70">No active licenses expiring in the next 90 days.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@php
    /** @var array{sites: list<array{id: int, name: string, receive: int, ship: int, total: int}>} $data */
    $max = max([1, ...array_column($data['sites'], 'total')]);
@endphp

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Site</th>
                <th>Receive</th>
                <th>Ship</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['sites'] as $site)
                <tr>
                    <td>
                        @if ($url = $this->siteUrl($site['id']))
                            <a href="{{ $url }}" class="link link-hover">{{ $site['name'] }}</a>
                        @else
                            {{ $site['name'] }}
                        @endif
                    </td>
                    <td>{{ number_format($site['receive']) }}</td>
                    <td>{{ number_format($site['ship']) }}</td>
                    <td>{{ number_format($site['total']) }}</td>
                    <td class="min-w-32">
                        <progress class="progress progress-primary" value="{{ $site['total'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="opacity-70">No completed site sessions in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

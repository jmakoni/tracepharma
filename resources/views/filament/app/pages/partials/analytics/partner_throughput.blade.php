@php
    /** @var array{partners: list<array{id: int, name: string, receive: int, ship: int, total: int}>} $data */
    $max = max([1, ...array_column($data['partners'], 'total')]);
@endphp

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Partner</th>
                <th>Receive</th>
                <th>Ship</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['partners'] as $partner)
                <tr>
                    <td>
                        @if ($url = $this->partnerUrl($partner['id']))
                            <a href="{{ $url }}" class="link link-hover">{{ $partner['name'] }}</a>
                        @else
                            {{ $partner['name'] }}
                        @endif
                    </td>
                    <td>{{ number_format($partner['receive']) }}</td>
                    <td>{{ number_format($partner['ship']) }}</td>
                    <td>{{ number_format($partner['total']) }}</td>
                    <td class="min-w-32">
                        <progress class="progress progress-primary" value="{{ $partner['total'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="opacity-70">No completed partner sessions in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

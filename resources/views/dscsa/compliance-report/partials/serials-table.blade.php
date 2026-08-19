@php
    /** @var list<\App\Services\Dscsa\ComplianceReport\SerialRow> $serialRows */
    /** @var bool $continued */
@endphp
<div class="section-title">
    Serial Numbers
    @if ($continued)
        <span class="continued">(continued)</span>
    @endif
</div>
<table class="serials">
    <thead>
        <tr>
            <th>GTIN</th>
            <th>SERIAL NUMBER</th>
            <th>LOT</th>
            <th>EXPIRATION DATE</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($serialRows as $row)
            <tr>
                <td class="mono">{{ $row->gtin }}</td>
                <td class="mono">{{ $row->serialNumber }}</td>
                <td class="mono">{{ $row->lot }}</td>
                <td>{{ $row->expirationDate }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="empty">No unit-level serials for this lot.</td>
            </tr>
        @endforelse
    </tbody>
</table>

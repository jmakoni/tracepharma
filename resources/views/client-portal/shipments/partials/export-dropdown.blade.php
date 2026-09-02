@php
    use App\Enums\Portal\PortalShipmentExportFormat;
    use App\Enums\Portal\PortalShipmentExportGrain;

    $formats = PortalShipmentExportFormat::cases();
    $filterQuery = array_filter([
        'from' => $filters['from'] ?? null,
        'to' => $filters['to'] ?? null,
        'po' => $filters['po'] ?? null,
    ]);

    $exportUrl = static function (PortalShipmentExportGrain $grain, PortalShipmentExportFormat $format) use ($exportRoute, $filterQuery, $documentId): string {
        $params = array_merge($filterQuery, [
            'grain' => $grain->value,
            'format' => $format->value,
        ]);

        if ($documentId !== null) {
            return route($exportRoute, ['document' => $documentId] + $params);
        }

        return route($exportRoute, $params);
    };
@endphp

<details class="dropdown dropdown-end">
    <summary class="btn btn-outline btn-sm">{{ $label ?? 'Export' }}</summary>
    <ul class="dropdown-content z-20 menu p-2 shadow bg-base-100 rounded-box w-56 mt-1 border border-base-300">
        <li class="menu-title"><span>Shipment list</span></li>
        @foreach ($formats as $format)
            <li>
                <a href="{{ $exportUrl(PortalShipmentExportGrain::Summary, $format) }}">{{ strtoupper($format->value) }}</a>
            </li>
        @endforeach
        <li class="menu-title mt-2"><span>PMS intake (serials)</span></li>
        @foreach ($formats as $format)
            <li>
                <a href="{{ $exportUrl(PortalShipmentExportGrain::Lines, $format) }}">{{ strtoupper($format->value) }}</a>
            </li>
        @endforeach
    </ul>
</details>

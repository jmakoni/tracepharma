@php
    use App\Enums\Portal\PortalShipmentExportFormat;
    use App\Enums\Portal\PortalShipmentExportGrain;

    $formats = PortalShipmentExportFormat::cases();
@endphp

<details class="dropdown dropdown-end">
    <summary class="btn btn-outline btn-sm">Export PMS intake</summary>
    <ul class="dropdown-content z-20 menu p-2 shadow bg-base-100 rounded-box w-52 mt-1 border border-base-300">
        @foreach ($formats as $format)
            <li>
                <a href="{{ route('tenant.client-portal.shipments.export-document', [
                    'document' => $document->getKey(),
                    'grain' => PortalShipmentExportGrain::Lines->value,
                    'format' => $format->value,
                ]) }}">{{ strtoupper($format->value) }}</a>
            </li>
        @endforeach
    </ul>
</details>

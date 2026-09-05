@extends('client-portal.layout')

@section('title', 'Shipments')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Shipments</h1>
                <p class="text-sm opacity-70 mt-1">Published transaction information available for your organization.</p>
            </div>
            @include('client-portal.shipments.partials.export-dropdown', [
                'exportRoute' => 'tenant.client-portal.shipments.export',
                'filters' => $filters,
                'documentId' => null,
            ])
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <form method="get" action="{{ route('tenant.client-portal.shipments.index') }}" class="flex flex-wrap gap-3 items-end">
                    <label class="form-control">
                        <span class="label-text text-xs">From</span>
                        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input input-bordered input-sm">
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">To</span>
                        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input input-bordered input-sm">
                    </label>
                    <label class="form-control grow min-w-40">
                        <span class="label-text text-xs">PO / ASN</span>
                        <input type="search" name="po" value="{{ $filters['po'] ?? '' }}" class="input input-bordered input-sm w-full" placeholder="Search PO or ASN">
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                </form>
            </div>
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body p-0 overflow-x-auto">
                @if ($publications->isEmpty())
                    <p class="p-6 text-sm opacity-70">No published shipments match your filters.</p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Published</th>
                                <th>PO / ASN</th>
                                <th>Shipment</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($publications as $publication)
                                @php
                                    $doc = $publication->document;
                                    $reportsAvailable = $doc ? \App\Support\Portal\PortalShipmentDisplay::reportsAvailable($doc) : false;
                                    $downloadAvailable = $doc ? \App\Support\Epcis\EpcisDocumentXmlDownload::available($doc) : false;
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap text-sm">
                                        {{ $publication->published_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="text-sm">
                                        {{ $doc?->customer_po ?: '—' }}
                                        @if (filled($doc?->asn_number))
                                            <div class="opacity-60 text-xs">ASN {{ $doc->asn_number }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        @if ($doc)
                                            <div>{{ \App\Support\Portal\PortalShipmentDisplay::label($doc) }}</div>
                                            @if ($subtitle = \App\Support\Portal\PortalShipmentDisplay::subtitle($doc))
                                                <div class="opacity-60 text-xs font-mono">{{ $subtitle }}</div>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap justify-end gap-1">
                                            <a
                                                href="{{ route('tenant.client-portal.shipments.show', ['document' => $publication->epcis_document_id]) }}"
                                                class="btn btn-ghost btn-xs"
                                            >View</a>
                                            @if ($downloadAvailable)
                                                <a
                                                    href="{{ route('tenant.client-portal.shipments.download', ['document' => $publication->epcis_document_id]) }}"
                                                    class="btn btn-ghost btn-xs"
                                                >EPCIS</a>
                                            @endif
                                            @if ($reportsAvailable)
                                                <a
                                                    href="{{ route('tenant.client-portal.shipments.track-trace', ['document' => $publication->epcis_document_id]) }}"
                                                    class="btn btn-ghost btn-xs"
                                                    title="Download Transaction Report PDF (one page per lot)"
                                                >Track &amp; Trace</a>
                                                <form
                                                    method="post"
                                                    action="{{ route('tenant.client-portal.shipments.serialized-track-trace', ['document' => $publication->epcis_document_id]) }}"
                                                    class="inline"
                                                >
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="btn btn-ghost btn-xs"
                                                        title="Queue DSCSA Compliance Report PDF (serials by lot)"
                                                    >Serialized T&amp;T</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="p-4">{{ $publications->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection

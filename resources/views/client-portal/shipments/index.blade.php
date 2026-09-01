@extends('client-portal.layout')

@section('title', 'Shipments')

@section('content')
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold">Shipments</h1>
            <p class="text-sm opacity-70 mt-1">Published transaction information available for your organization.</p>
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
                                <th>Document</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($publications as $publication)
                                @php $doc = $publication->document; @endphp
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
                                    <td class="text-sm font-mono truncate max-w-48">
                                        {{ $doc?->original_filename ?: ($doc?->document_uuid ?: '#'.$publication->epcis_document_id) }}
                                    </td>
                                    <td class="text-right">
                                        <a
                                            href="{{ route('tenant.client-portal.shipments.show', ['document' => $publication->epcis_document_id]) }}"
                                            class="btn btn-ghost btn-xs"
                                        >View</a>
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

@extends('client-portal.layout')

@section('title', 'Shipment')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('tenant.client-portal.shipments.index') }}" class="link link-hover text-sm">← Shipments</a>
                <h1 class="text-2xl font-semibold mt-2">Shipment detail</h1>
                <p class="text-sm opacity-70 font-mono mt-1">
                    {{ $document->original_filename ?: $document->document_uuid }}
                </p>
            </div>
            @if ($downloadAvailable)
                <a
                    href="{{ route('tenant.client-portal.shipments.download', ['document' => $document->getKey()]) }}"
                    class="btn btn-primary"
                >Download EPCIS</a>
            @endif
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body gap-2">
                <h2 class="font-semibold">Document</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="opacity-60">PO</dt><dd>{{ $document->customer_po ?: '—' }}</dd></div>
                    <div><dt class="opacity-60">ASN</dt><dd>{{ $document->asn_number ?: '—' }}</dd></div>
                    <div><dt class="opacity-60">Created</dt><dd>{{ optional($document->creation_date ?? $document->created_at)->format('Y-m-d H:i') }}</dd></div>
                    <div><dt class="opacity-60">Events / EPCs</dt><dd>{{ $document->event_count ?? '—' }} / {{ $document->epc_count ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body p-0 overflow-x-auto">
                <h2 class="font-semibold px-6 pt-5">TI / TS summary</h2>
                @if ($tiSummary === [])
                    <p class="p-6 text-sm opacity-70">No GTIN / lot summary could be built from events on this document.</p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>GTIN</th>
                                <th>Lot</th>
                                <th>Expiry</th>
                                <th>Biz step</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tiSummary as $row)
                                <tr class="text-sm">
                                    <td class="font-mono">{{ $row['gtin'] ?: '—' }}</td>
                                    <td class="font-mono">{{ $row['lot'] ?: '—' }}</td>
                                    <td>{{ $row['expiry'] ?: '—' }}</td>
                                    <td class="truncate max-w-40">{{ $row['biz_step'] ? class_basename(str_replace(['urn:epcglobal:cbv:bizstep:', 'https://ref.gs1.org/cbv/BizStep-'], '', $row['biz_step'])) : '—' }}</td>
                                    <td>{{ $row['qty'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body p-0 overflow-x-auto">
                <h2 class="font-semibold px-6 pt-5">Event timeline</h2>
                @if ($events->isEmpty())
                    <p class="p-6 text-sm opacity-70">No events on this document.</p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>Biz step</th>
                                <th>Disposition</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr class="text-sm">
                                    <td class="whitespace-nowrap">{{ $event->event_time?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $event->event_type }}</td>
                                    <td>{{ $event->action ?: '—' }}</td>
                                    <td class="truncate max-w-40">{{ $event->biz_step ? class_basename(str_replace(['urn:epcglobal:cbv:bizstep:', 'https://ref.gs1.org/cbv/BizStep-'], '', (string) $event->biz_step)) : '—' }}</td>
                                    <td class="truncate max-w-40">{{ $event->disposition ? class_basename(str_replace(['urn:epcglobal:cbv:disp:', 'https://ref.gs1.org/cbv/Disp-'], '', (string) $event->disposition)) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('client-portal.layout')

@section('title', 'Trace')

@section('content')
    <div class="flex flex-col gap-6" x-data="{ code: @js($code ?? '') }">
        <div>
            <h1 class="text-2xl font-semibold">Track &amp; trace</h1>
            <p class="text-sm opacity-70 mt-1">Look up an SGTIN or SSCC that appears in shipments published to your organization.</p>
        </div>

        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <form
                    method="get"
                    action="{{ route('tenant.client-portal.trace') }}"
                    class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end"
                >
                    <label class="form-control grow w-full">
                        <span class="label-text">SGTIN / SSCC</span>
                        <input
                            type="search"
                            name="code"
                            x-model="code"
                            class="input input-bordered w-full font-mono"
                            placeholder="Paste serial or SSCC"
                            autocomplete="off"
                            autofocus
                            @input.debounce.500ms="if ((code || '').trim().length >= 8) { $el.form.requestSubmit(); }"
                        >
                    </label>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <p class="text-xs opacity-50 mt-2">Search waits 500ms after you stop typing (server limit 30/min).</p>
            </div>
        </div>

        @if ($searched)
            @if ($epc === null || $events->isEmpty())
                <div class="alert">
                    <span>No published events found for that identifier in your portal.</span>
                </div>
            @else
                <div class="card bg-base-100 shadow">
                    <div class="card-body gap-2">
                        <h2 class="font-semibold">Identifier</h2>
                        <p class="font-mono text-sm break-all">{{ $epc->epc_uri }}</p>
                        <p class="text-sm opacity-70">
                            Type: {{ $epc->epc_type }}
                            @if (filled($epc->gtin14)) · GTIN {{ $epc->gtin14 }} @endif
                            @if (filled($epc->sscc18)) · SSCC {{ $epc->sscc18 }} @endif
                        </p>
                    </div>
                </div>

                <div class="card bg-base-100 shadow">
                    <div class="card-body p-0 overflow-x-auto">
                        <h2 class="font-semibold px-6 pt-5">Event timeline</h2>
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
                    </div>
                </div>

                @if ($children->isNotEmpty())
                    <div class="card bg-base-100 shadow">
                        <div class="card-body p-0 overflow-x-auto">
                            <h2 class="font-semibold px-6 pt-5">Aggregation children</h2>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Child</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($children as $link)
                                        @php $child = $link->childEpc; @endphp
                                        <tr class="text-sm">
                                            <td class="font-mono break-all">
                                                {{ $child?->ai_01_21 ?: ($child?->ai_00 ?: ($child?->epc_uri ?: '—')) }}
                                            </td>
                                            <td>{{ $child?->epc_type ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
@endsection

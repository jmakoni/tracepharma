@extends('verification-request.layout')

@section('title', 'Respond')

@section('content')
<div class="card bg-base-100 shadow">
    <div class="card-body gap-5 text-base">
        <h1 class="card-title text-2xl">Submit verification response</h1>
        <p>{{ $case->requestor_name }} was unable to complete VRS verification for this product. Please verify whether the product identifier corresponds to the NDC (GTIN), serial number, lot number, and expiration date assigned by you.</p>

        <div class="bg-base-200 rounded-lg p-5 space-y-2">
            <div><strong>GTIN:</strong> {{ $case->gtin14 }}</div>
            <div><strong>Serial:</strong> {{ $case->serial }}</div>
            <div><strong>Lot:</strong> {{ $case->lot ?? '—' }}</div>
            <div><strong>Expiration:</strong> {{ $case->expiry_yymmdd ?? '—' }}</div>
            <div><strong>NDC:</strong> {{ $case->ndc11 ?? '—' }}</div>
            @if (filled($case->product_description))
                <div><strong>Description:</strong> {{ $case->product_description }}</div>
            @endif
        </div>

        <form method="post" action="{{ route('tenant.verification-request.submit', $case->uuid) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            <fieldset class="flex flex-col gap-3">
                <legend class="font-medium mb-1">Please select a response</legend>
                @foreach ($outcomes as $outcome)
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="radio" name="outcome" value="{{ $outcome->value }}" class="radio radio-lg shrink-0" required @checked(old('outcome') === $outcome->value)>
                        <span>{{ $outcome->label() }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="flex flex-col gap-1.5 w-full">
                <label for="reason_code" class="font-medium">Please choose why you selected this response</label>
                <select id="reason_code" name="reason_code" class="select select-bordered select-lg w-full" required>
                    <option value="">Select…</option>
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason->value }}" @selected(old('reason_code') === $reason->value)>{{ $reason->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1.5 w-full">
                <label for="comments" class="font-medium">Additional comments</label>
                <textarea id="comments" name="comments" class="textarea textarea-bordered textarea-lg w-full" rows="5">{{ old('comments') }}</textarea>
            </div>

            <div class="flex flex-col gap-1.5 w-full">
                <label for="attachment" class="font-medium">Barcode photo (optional)</label>
                <input id="attachment" type="file" name="attachment" class="file-input file-input-bordered file-input-lg w-full" accept="image/jpeg,image/png,application/pdf">
            </div>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="terms_accepted" value="1" class="checkbox checkbox-lg shrink-0 mt-0.5" required>
                <span>I certify this response is accurate.</span>
            </label>

            <button type="submit" class="btn btn-primary btn-lg">Submit response</button>
        </form>
    </div>
</div>
@endsection

@extends('verification-request.layout')

@section('title', 'Respond')

@section('content')
<div class="card bg-base-100 shadow">
    <div class="card-body gap-4">
        <h1 class="card-title">Submit verification response</h1>
        <p class="text-sm">{{ $case->requestor_name }} was unable to complete VRS verification for this product. Please verify whether the product identifier corresponds to the NDC (GTIN), serial number, lot number, and expiration date assigned by you.</p>

        <div class="bg-base-200 rounded-lg p-4 text-sm space-y-1">
            <div><strong>GTIN:</strong> {{ $case->gtin14 }}</div>
            <div><strong>Serial:</strong> {{ $case->serial }}</div>
            <div><strong>Lot:</strong> {{ $case->lot ?? '—' }}</div>
            <div><strong>Expiration:</strong> {{ $case->expiry_yymmdd ?? '—' }}</div>
            <div><strong>NDC:</strong> {{ $case->ndc11 ?? '—' }}</div>
            @if (filled($case->product_description))
                <div><strong>Description:</strong> {{ $case->product_description }}</div>
            @endif
        </div>

        <form method="post" action="{{ route('tenant.verification-request.submit', $case->uuid) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <fieldset class="space-y-2">
                <legend class="font-medium text-sm">Please select a response</legend>
                @foreach ($outcomes as $outcome)
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="radio" name="outcome" value="{{ $outcome->value }}" class="radio" required @checked(old('outcome') === $outcome->value)>
                        <span class="label-text">{{ $outcome->label() }}</span>
                    </label>
                @endforeach
            </fieldset>

            <label class="form-control w-full">
                <span class="label-text">Please choose why you selected this response</span>
                <select name="reason_code" class="select select-bordered w-full" required>
                    <option value="">Select…</option>
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason->value }}" @selected(old('reason_code') === $reason->value)>{{ $reason->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-control w-full">
                <span class="label-text">Additional comments</span>
                <textarea name="comments" class="textarea textarea-bordered" rows="3">{{ old('comments') }}</textarea>
            </label>

            <label class="form-control w-full">
                <span class="label-text">Barcode photo (optional)</span>
                <input type="file" name="attachment" class="file-input file-input-bordered w-full" accept="image/jpeg,image/png,application/pdf">
            </label>

            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" name="terms_accepted" value="1" class="checkbox" required>
                <span class="label-text">I certify this response is accurate.</span>
            </label>

            <button type="submit" class="btn btn-primary">Submit response</button>
        </form>
    </div>
</div>
@endsection

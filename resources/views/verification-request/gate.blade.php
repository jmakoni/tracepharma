@extends('verification-request.layout')

@section('title', 'Manufacturer verification')

@section('content')
<div class="card bg-base-100 shadow">
    <div class="card-body gap-4">
        <h1 class="card-title">Manufacturer verification request</h1>
        <p class="text-sm opacity-80">Enter the secure code from your email to review and respond to this request.</p>

        <form method="post" action="{{ route('tenant.verification-request.unlock', $caseUuid) }}" class="space-y-4">
            @csrf
            <div class="flex flex-col gap-1.5 w-full">
                <label for="secure_code" class="font-medium">Secure code (from email)</label>
                <input id="secure_code" type="text" name="secure_code" class="input input-bordered input-lg w-full" required autocomplete="off" value="{{ old('secure_code') }}">
            </div>
            <div class="flex flex-col gap-1.5 w-full">
                <label for="responder_email" class="font-medium">Your email</label>
                <input id="responder_email" type="email" name="responder_email" class="input input-bordered input-lg w-full" required value="{{ old('responder_email') }}">
            </div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="terms_accepted" value="1" class="checkbox checkbox-lg shrink-0 mt-0.5" required @checked(old('terms_accepted'))>
                <span>I agree to use this portal only to respond to this verification request.</span>
            </label>
            <button type="submit" class="btn btn-primary btn-lg">Continue</button>
        </form>
    </div>
</div>
@endsection

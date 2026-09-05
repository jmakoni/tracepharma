@extends('verification-request.layout')

@section('title', 'Manufacturer verification')

@section('content')
<div class="card bg-base-100 shadow">
    <div class="card-body gap-4">
        <h1 class="card-title">Manufacturer verification request</h1>
        <p class="text-sm opacity-80">Enter the secure code from your email to review and respond to this request.</p>

        <form method="post" action="{{ route('tenant.verification-request.unlock', $caseUuid) }}" class="space-y-4">
            @csrf
            <label class="form-control w-full">
                <span class="label-text">Secure code (from email)</span>
                <input type="text" name="secure_code" class="input input-bordered w-full" required autocomplete="off" value="{{ old('secure_code') }}">
            </label>
            <label class="form-control w-full">
                <span class="label-text">Your email</span>
                <input type="email" name="responder_email" class="input input-bordered w-full" required value="{{ old('responder_email') }}">
            </label>
            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" name="terms_accepted" value="1" class="checkbox" required @checked(old('terms_accepted'))>
                <span class="label-text">I agree to use this portal only to respond to this verification request.</span>
            </label>
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    </div>
</div>
@endsection

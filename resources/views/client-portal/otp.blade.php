@extends('client-portal.layout')

@section('title', 'Enter code')

@section('content')
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <h1 class="card-title text-2xl">Enter login code</h1>
            <p class="text-sm opacity-70">We sent a 6-digit code to <span class="font-mono">{{ $email }}</span>.</p>

            <form method="post" action="{{ route('tenant.client-portal.otp.verify') }}" class="flex flex-col gap-4">
                @csrf
                <label class="form-control w-full">
                    <span class="label-text">Code</span>
                    <input
                        type="text"
                        name="code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        autofocus
                        autocomplete="one-time-code"
                        class="input input-bordered w-full font-mono tracking-widest text-lg"
                        placeholder="000000"
                    >
                </label>
                <button type="submit" class="btn btn-primary">Verify and sign in</button>
            </form>

            <a href="{{ route('tenant.client-portal.login') }}" class="link link-hover text-sm">Use a different email</a>
        </div>
    </div>
@endsection

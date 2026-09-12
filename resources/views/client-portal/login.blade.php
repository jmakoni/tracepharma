@extends('client-portal.layout')

@section('title', 'Sign in')

@section('content')
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <h1 class="card-title text-2xl">Client portal</h1>
            <p class="text-sm opacity-70">Enter your email to receive a one-time login code. No password required.</p>

            <form method="post" action="{{ route('tenant.client-portal.login.request') }}" class="flex flex-col gap-4">
                @csrf
                <label class="form-control w-full">
                    <span class="label-text">Email</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="input input-bordered w-full"
                        placeholder="you@pharmacy.example"
                    >
                </label>
                <button type="submit" class="btn btn-primary">Send login code</button>
            </form>
        </div>
    </div>
@endsection

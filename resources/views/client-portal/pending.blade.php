@extends('client-portal.layout')

@section('title', 'Pending access')

@section('content')
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <h1 class="card-title text-2xl">Access pending</h1>
            <p class="opacity-80">
                You are signed in as <span class="font-mono">{{ $user?->email }}</span>, but your account is not yet linked to a trading partner organization.
            </p>
            <p class="text-sm opacity-70">
                Ask your wholesaler or supplier to invite this email to their TracePharma client portal. You can sign out and try again after they finish.
            </p>
        </div>
    </div>
@endsection

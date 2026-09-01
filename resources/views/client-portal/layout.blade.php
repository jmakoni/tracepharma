<!DOCTYPE html>
<html lang="en" data-theme="tracepharma">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Client portal') · TracePharma</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen bg-base-200 text-base-content">
    @php
        $tenantName = tenant()?->name ?? 'TracePharma';
        $portalUser = auth('portal')->user();
    @endphp
    <div class="navbar bg-base-100 border-b border-base-300 px-4">
        <div class="flex-1 gap-3">
            <span class="font-semibold tracking-tight">TracePharma</span>
            <span class="text-sm opacity-60">{{ $tenantName }}</span>
        </div>
        @if ($portalUser)
            <div class="flex-none gap-2 items-center">
                @unless (request()->routeIs('tenant.client-portal.pending'))
                    <a href="{{ route('tenant.client-portal.shipments.index') }}" class="btn btn-ghost btn-sm">Shipments</a>
                    <a href="{{ route('tenant.client-portal.trace') }}" class="btn btn-ghost btn-sm">Trace</a>
                @endunless
                <form method="post" action="{{ route('tenant.client-portal.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm">Sign out</button>
                </form>
            </div>
        @endif
    </div>

    <main class="max-w-3xl mx-auto px-4 py-8">
        @if (session('status'))
            <div class="alert alert-success mb-6" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error mb-6" role="alert">
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="en" data-theme="tracepharma">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Verification request') · TracePharma</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 text-base-content">
    <div class="navbar bg-base-100 border-b border-base-300 px-4">
        <span class="font-semibold">DSCSA verification portal</span>
        <span class="text-sm opacity-60 ml-2">{{ tenant()?->name }}</span>
    </div>
    <main class="max-w-2xl mx-auto px-4 py-8">
        @if (session('submitted'))
            <div class="alert alert-success mb-6">Response submitted successfully!</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-6">
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

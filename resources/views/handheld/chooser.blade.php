<!DOCTYPE html>
<html lang="en" data-theme="tracepharma">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="theme-color" content="#51BC8F" />
        <meta name="mobile-web-app-capable" content="yes" />
        <link rel="manifest" href="{{ url('/manifest.webmanifest') }}" />
        <link rel="icon" href="{{ asset('images/brand/logo-mark.svg') }}" />
        <title>Open floor — TracePharma</title>
        <style>
            :root { color-scheme: dark; }
            body {
                margin: 0;
                min-height: 100dvh;
                font-family: ui-sans-serif, system-ui, sans-serif;
                background: #0f172a;
                color: #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
            }
            .card {
                width: min(28rem, 100%);
                background: #1e293b;
                border-radius: 1rem;
                padding: 1.5rem;
                box-shadow: 0 16px 40px rgb(0 0 0 / 0.35);
            }
            img { height: 2.5rem; margin-bottom: 1rem; }
            h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
            p { color: #94a3b8; margin: 0 0 1.25rem; line-height: 1.45; }
            label { display: block; font-weight: 650; font-size: 0.875rem; margin-bottom: 0.4rem; }
            input[type="text"] {
                width: 100%;
                box-sizing: border-box;
                border-radius: 0.6rem;
                border: 1px solid #475569;
                background: #0f172a;
                color: #f8fafc;
                padding: 0.7rem 0.8rem;
                font-size: 1rem;
            }
            button {
                width: 100%;
                margin-top: 1rem;
                min-height: 3.25rem;
                border: 0;
                border-radius: 0.75rem;
                background: #51bc8f;
                color: #052e16;
                font-weight: 750;
                font-size: 1.05rem;
                cursor: pointer;
            }
            .error { color: #fda4af; font-size: 0.875rem; margin-top: 0.75rem; }
        </style>
    </head>
    <body>
        <main class="card">
            <img src="{{ asset('images/brand/logo.svg') }}" alt="TracePharma" />
            <h1>Open floor</h1>
            <p>Enter your organization host. The app remembers the last host on this device.</p>
            <form method="post" action="{{ url('/handheld') }}" id="chooser-form">
                @csrf
                <label for="host">Floor host</label>
                <input
                    id="host"
                    name="host"
                    type="text"
                    inputmode="url"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    placeholder="demo2.internal.vatengi.com"
                    value="{{ $prefillHost }}"
                    required
                />
                @if (filled($error))
                    <p class="error">{{ $error }}</p>
                @endif
                <button type="submit">Open floor</button>
            </form>
        </main>
        <script>
            (function () {
                var input = document.getElementById('host');
                if (!input) return;
                var key = 'tp.handheld.lastHost';
                if (!input.value) {
                    var last = localStorage.getItem(key);
                    if (last) input.value = last;
                }
                document.getElementById('chooser-form').addEventListener('submit', function () {
                    if (input.value) localStorage.setItem(key, input.value.trim());
                });
            })();
        </script>
    </body>
</html>

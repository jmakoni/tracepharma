<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>License update · {{ $partner->name }}</title>
    <style>
        :root {
            --ink: #1c2430;
            --muted: #5b6573;
            --line: #d8dee6;
            --bg: #f4f6f8;
            --card: #ffffff;
            --accent: #0f4c5c;
            --success: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.5;
        }
        .wrap { max-width: 560px; margin: 0 auto; padding: 2rem 1.5rem 3rem; }
        h1 { font-family: "IBM Plex Serif", Georgia, serif; font-size: 1.6rem; margin: 0 0 0.35rem; }
        .meta { color: var(--muted); margin-bottom: 1.5rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 0.5rem;
            padding: 1.25rem 1.35rem;
            margin-bottom: 1rem;
        }
        label { display: block; font-weight: 600; margin: 0.9rem 0 0.25rem; }
        input[type="text"], input[type="email"], input[type="date"], input[type="file"] {
            width: 100%;
            padding: 0.5rem 0.6rem;
            border: 1px solid var(--line);
            border-radius: 0.375rem;
            font: inherit;
        }
        .hint { color: var(--muted); font-size: 0.85rem; margin-top: 0.2rem; }
        .error { color: #9b1c1c; font-size: 0.85rem; margin-top: 0.2rem; }
        button {
            margin-top: 1.25rem;
            background: var(--accent);
            color: #fff;
            border: 0;
            border-radius: 0.375rem;
            padding: 0.65rem 1.4rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: var(--success);
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>License update</h1>
    <p class="meta">{{ $partner->name }} — submit a current ATP license for verification.</p>

    @if ($submitted)
        <div class="success">
            <strong>Thank you.</strong> Your license was submitted and is pending verification by the receiving organization.
        </div>
    @else
        <div class="card">
            <form method="POST" action="{{ url()->current() }}" enctype="multipart/form-data">
                @csrf

                <label for="license_number">License number</label>
                <input type="text" id="license_number" name="license_number" value="{{ old('license_number') }}" required>
                @error('license_number') <div class="error">{{ $message }}</div> @enderror

                <label for="license_state">State / jurisdiction</label>
                <input type="text" id="license_state" name="license_state" value="{{ old('license_state') }}" placeholder="e.g. FL" required>
                @error('license_state') <div class="error">{{ $message }}</div> @enderror

                <label for="license_expiration_date">Expiration date</label>
                <input type="date" id="license_expiration_date" name="license_expiration_date" value="{{ old('license_expiration_date') }}" required>
                @error('license_expiration_date') <div class="error">{{ $message }}</div> @enderror

                <label for="contact_email">Contact email (optional)</label>
                <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email') }}">
                @error('contact_email') <div class="error">{{ $message }}</div> @enderror

                <label for="document">License document</label>
                <input type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                <div class="hint">PDF or image, up to 10 MB. Stored securely and reviewed before acceptance.</div>
                @error('document') <div class="error">{{ $message }}</div> @enderror

                <button type="submit">Submit license</button>
            </form>
        </div>
    @endif
</div>
</body>
</html>

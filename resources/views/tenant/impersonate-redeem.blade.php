<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signing in…</title>
</head>
<body>
    <p>Signing you in…</p>
    <form id="redeem" method="post" action="{{ route('tenant.impersonate.redeem', ['publicId' => $publicId]) }}">
        @csrf
    </form>
    <script>document.getElementById('redeem').submit();</script>
</body>
</html>

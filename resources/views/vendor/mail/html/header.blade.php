@props(['url'])
@php
    $logoPng = public_path('images/brand/logo.png');
    $logoSvg = public_path('images/brand/logo.svg');
    $logoSrc = null;
    if (file_exists($logoPng)) {
        $logoSrc = asset('images/brand/logo.png');
    } elseif (file_exists($logoSvg)) {
        // SVG is a last resort — many clients (Gmail/Outlook) do not render it.
        $logoSrc = asset('images/brand/logo.svg');
    }
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoSrc !== null)
<img src="{{ $logoSrc }}" alt="TracePharma" width="168" height="32" style="height: 32px; width: auto; max-width: 168px; border: 0; display: block;">
@else
{!! trim($slot) !== '' ? $slot : 'TracePharma' !!}
@endif
</a>
</td>
</tr>

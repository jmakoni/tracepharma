@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (file_exists(public_path('images/brand/logo.svg')))
<img src="{{ asset('images/brand/logo.svg') }}" alt="TracePharma" height="32" style="height: 32px; max-width: 100%; border: 0;">
@else
{!! trim($slot) !== '' ? $slot : 'TracePharma' !!}
@endif
</a>
</td>
</tr>

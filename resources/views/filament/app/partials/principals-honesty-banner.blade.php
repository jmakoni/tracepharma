@php($honesty = \App\Support\PrincipalsHonesty::forTenant())
@if ($honesty->shouldShow())
    <div class="alert alert-info mb-4">
        <div class="flex w-full flex-col gap-2">
            <span class="font-medium">Principals</span>
            <span>{{ $honesty->sentence() }}</span>
        </div>
    </div>
@endif

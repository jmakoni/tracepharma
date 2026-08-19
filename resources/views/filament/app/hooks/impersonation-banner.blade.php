@php
    use App\Models\Admin;
    use App\Support\Admin\TenantImpersonation;

    $payload = TenantImpersonation::payload();
@endphp

@if ($payload !== null)
    @php
        $central = (string) config('tenancy.database.central_connection', config('database.default'));
        $admin = Admin::on($central)->find(TenantImpersonation::adminId());
        $reason = TenantImpersonation::reason();
    @endphp

    <div class="tp-impersonation-banner alert alert-warning rounded-none border-x-0 border-t-0 shadow-none mx-0 w-full justify-center gap-2 py-2 text-sm">
        <span class="font-semibold">Impersonation active</span>
        <span class="opacity-80">
            @if ($admin instanceof Admin)
                Platform admin {{ $admin->name }}
            @else
                Platform admin
            @endif
            @if ($reason !== null)
                — {{ $reason }}
            @endif
        </span>
    </div>
@endif

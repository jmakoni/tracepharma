@php
    /** @var array{environments: list<array{environment: string, label: string, tenants_with_providers: int, tenants_with_active_routes: int, active_routes: int}>, tenants_with_providers: int, active_routes: int} $data */
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Tenants with providers</div>
        <div class="stat-value text-2xl">{{ number_format($data['tenants_with_providers']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Active routes</div>
        <div class="stat-value text-2xl">{{ number_format($data['active_routes']) }}</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Environment</th>
                <th>With providers</th>
                <th>With active routes</th>
                <th>Active routes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['environments'] as $environment)
                <tr>
                    <td>{{ $environment['label'] }}</td>
                    <td>{{ number_format($environment['tenants_with_providers']) }}</td>
                    <td>{{ number_format($environment['tenants_with_active_routes']) }}</td>
                    <td>{{ number_format($environment['active_routes']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="opacity-70">No hub coverage yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

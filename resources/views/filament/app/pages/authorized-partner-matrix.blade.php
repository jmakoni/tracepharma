<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <p class="text-sm opacity-70">
                    Denormalized partner licence facts from linked members. Filter by licence status when needed.
                </p>
                <div class="form-control w-full max-w-xs">
                    <label class="label py-0" for="licenseStatusFilter">
                        <span class="label-text text-xs">Licence status</span>
                    </label>
                    <select
                        id="licenseStatusFilter"
                        wire:model.live="licenseStatusFilter"
                        class="select select-bordered select-sm"
                    >
                        <option value="">All statuses</option>
                        <option value="ready">ready</option>
                        <option value="expiring">expiring</option>
                        <option value="expired">expired</option>
                        <option value="no_licenses">no_licenses</option>
                        <option value="unknown_expiry">unknown_expiry</option>
                        <option value="needs_receiving_state">needs_receiving_state</option>
                        <option value="fda_registered">fda_registered</option>
                        <option value="not_monitored">not_monitored</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Partner</th>
                            <th>GLN</th>
                            <th>Licence status</th>
                            <th>Expires</th>
                            <th>As of</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->matrixRows() as $row)
                            <tr @class(['opacity-70' => $row['soft_only'] || ($row['license_status'] ?? '') === 'N/A'])>
                                <td class="font-medium">{{ $row['member_name'] }}</td>
                                <td>{{ $row['partner_name'] }}</td>
                                <td class="font-mono text-xs">{{ $row['partner_gln'] ?? '—' }}</td>
                                <td><span class="badge badge-outline badge-sm">{{ $row['license_status'] }}</span></td>
                                <td>{{ $row['expires_at'] ?? '—' }}</td>
                                <td>{{ $row['as_of'] ?? '—' }}</td>
                                <td class="text-sm">{{ $row['empty_hint'] ?? '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="opacity-70">No partner facts yet. Link members and run <code>tracepharma:buying-group-rollup</code>.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>

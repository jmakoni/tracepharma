<x-filament-panels::page>
    @php
        $inboundStats = $this->inboundStats();
        $outboundStats = $this->outboundStats();
        $inboundConnections = $this->inboundConnections();
        $outboundConnections = $this->outboundConnections();
        $inboundEpcisUrl = $this->inboundEpcisIndexUrl();
        $outboundEpcisUrl = $this->outboundEpcisIndexUrl();
        $inboundConnectionsUrl = $this->inboundConnectionsIndexUrl();
        $outboundConnectionsUrl = $this->outboundConnectionsIndexUrl();
    @endphp

    <div class="flex flex-col gap-6">
        @if ($this->supportsInbound())
            <section class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">Inbound EPCIS (last 24 hours)</h2>
                            <p class="text-sm opacity-70">
                                Catalog documents received in the past day, scoped to your sites.
                            </p>
                        </div>
                        @if (filled($inboundEpcisUrl))
                            <a href="{{ $inboundEpcisUrl }}" class="btn btn-ghost btn-sm">
                                Browse inbound EPCIS
                            </a>
                        @endif
                    </div>

                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                        <div class="stat">
                            <div class="stat-title">Success</div>
                            <div class="stat-value text-success text-2xl">{{ number_format($inboundStats['success']) }}</div>
                            <div class="stat-desc">Parsed + validated</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">In flight</div>
                            <div class="stat-value text-warning text-2xl">{{ number_format($inboundStats['in_flight']) }}</div>
                            <div class="stat-desc">Received + parsing</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Error</div>
                            <div class="stat-value text-error text-2xl">{{ number_format($inboundStats['error']) }}</div>
                            <div class="stat-desc">Validation or ingest failures</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Total</div>
                            <div class="stat-value text-2xl">{{ number_format($inboundStats['total']) }}</div>
                            <div class="stat-desc">All statuses</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">Inbound connections</h2>
                            <p class="text-sm opacity-70">HTTPS webhooks and SFTP polling endpoints.</p>
                        </div>
                        @if (filled($inboundConnectionsUrl))
                            <a href="{{ $inboundConnectionsUrl }}" class="btn btn-ghost btn-sm">
                                Manage inbound connections
                            </a>
                        @endif
                    </div>

                    @if ($inboundConnections->isEmpty())
                        <div class="alert alert-info">
                            <span>No inbound connections configured yet.</span>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Partner</th>
                                        <th>Transport</th>
                                        <th>Active</th>
                                        <th>Last activity</th>
                                        <th>Last error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($inboundConnections as $connection)
                                        @php
                                            $viewUrl = $this->inboundConnectionViewUrl($connection);
                                            $lastActivity = $connection->last_received_at ?? $connection->last_polled_at;
                                        @endphp
                                        <tr>
                                            <td>
                                                @if (filled($viewUrl))
                                                    <a href="{{ $viewUrl }}" class="link link-primary font-medium">
                                                        {{ $connection->name }}
                                                    </a>
                                                @else
                                                    {{ $connection->name }}
                                                @endif
                                            </td>
                                            <td>{{ $connection->tradingPartner?->name ?? '—' }}</td>
                                            <td>
                                                <span class="badge badge-sm badge-outline">
                                                    {{ $connection->transport->label() }}
                                                </span>
                                            </td>
                                            <td>
                                                @if ($connection->is_active)
                                                    <span class="badge badge-sm badge-success">Active</span>
                                                @else
                                                    <span class="badge badge-sm badge-ghost">Inactive</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap">
                                                {{ $lastActivity?->format('M j, Y g:i A') ?? '—' }}
                                            </td>
                                            @php
                                                $redactedError = $this->redactLastError($connection->last_error);
                                            @endphp
                                            <td class="max-w-xs truncate" @if (filled($redactedError)) title="{{ $redactedError }}" @endif>
                                                {{ filled($redactedError) ? Str::limit($redactedError, 60) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($this->supportsOutbound())
            <section class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">Outbound EPCIS (last 24 hours)</h2>
                            <p class="text-sm opacity-70">
                                Transmission activity for outbound documents, scoped to your ship-from sites.
                            </p>
                        </div>
                        @if (filled($outboundEpcisUrl))
                            <a href="{{ $outboundEpcisUrl }}" class="btn btn-ghost btn-sm">
                                Browse outbound EPCIS
                            </a>
                        @endif
                    </div>

                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                        <div class="stat">
                            <div class="stat-title">Sent</div>
                            <div class="stat-value text-success text-2xl">{{ number_format($outboundStats['sent']) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Queued</div>
                            <div class="stat-value text-warning text-2xl">{{ number_format($outboundStats['queued']) }}</div>
                            <div class="stat-desc">Pending, queued, or sending</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Failed</div>
                            <div class="stat-value text-error text-2xl">{{ number_format($outboundStats['failed']) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Skipped</div>
                            <div class="stat-value text-2xl">{{ number_format($outboundStats['skipped']) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Total</div>
                            <div class="stat-value text-2xl">{{ number_format($outboundStats['total']) }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">Outbound connections</h2>
                            <p class="text-sm opacity-70">HTTPS, SFTP, and AS2 endpoints for partner EPCIS delivery.</p>
                        </div>
                        @if (filled($outboundConnectionsUrl))
                            <a href="{{ $outboundConnectionsUrl }}" class="btn btn-ghost btn-sm">
                                Manage outbound connections
                            </a>
                        @endif
                    </div>

                    @if ($outboundConnections->isEmpty())
                        <div class="alert alert-info">
                            <span>No outbound connections configured yet.</span>
                        </div>
                    @else
                        @if ($this->hasActiveLegacySftpOutbound())
                            <div class="alert alert-info">
                                <span>
                                    {{ $this->activeLegacySftpOutboundCount() }} active SFTP outbound connection(s) are configured.
                                </span>
                            </div>
                        @endif
                        @if ($outboundConnections->contains(fn ($connection) => $connection->transport === \App\Enums\OutboundTransport::As2 && ! $connection->as2SmimeActive()))
                            <div class="alert alert-warning">
                                <span>
                                    Some AS2 connections are in lab mode (raw XML with AS2 headers only).
                                    Configure signing and/or partner encryption certificates to apply lean S/MIME CMS on send.
                                </span>
                            </div>
                        @endif
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Partner</th>
                                        <th>Transport</th>
                                        <th>EPCIS</th>
                                        <th>Active</th>
                                        <th>Last sent</th>
                                        <th>Last error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($outboundConnections as $connection)
                                        @php
                                            $viewUrl = $this->outboundConnectionViewUrl($connection);
                                            $epcisVersion = is_array($connection->settings)
                                                ? (string) ($connection->settings['epcis_document_version'] ?? '1.2')
                                                : '1.2';
                                        @endphp
                                        <tr>
                                            <td>
                                                @if (filled($viewUrl))
                                                    <a href="{{ $viewUrl }}" class="link link-primary font-medium">
                                                        {{ $connection->name }}
                                                    </a>
                                                @else
                                                    {{ $connection->name }}
                                                @endif
                                            </td>
                                            <td>{{ $connection->tradingPartner?->name ?? '—' }}</td>
                                            <td>
                                                <span class="badge badge-sm badge-outline">
                                                    {{ $connection->transport->label() }}
                                                </span>
                                                @if (\App\Support\Integrations\OutboundTransportAvailability::isLegacyUnavailable($connection->transport))
                                                    <span
                                                        class="badge badge-sm badge-error ml-1"
                                                        title="{{ \App\Support\Integrations\OutboundTransportAvailability::sftpSaveMessage() }}"
                                                    >
                                                        {{ \App\Support\Integrations\OutboundTransportAvailability::legacyBadgeLabel() }}
                                                    </span>
                                                @elseif ($connection->transport === \App\Enums\OutboundTransport::As2)
                                                    @if ($connection->as2SmimeActive())
                                                        <span
                                                            class="badge badge-sm badge-success ml-1"
                                                            title="Lean S/MIME CMS signing/encryption applied when certificates are configured."
                                                        >
                                                            S/MIME
                                                        </span>
                                                    @else
                                                        <span
                                                            class="badge badge-sm badge-warning ml-1"
                                                            title="Lab mode — raw XML until signing or partner encryption certificates are configured."
                                                        >
                                                            Lab/raw
                                                        </span>
                                                    @endif
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge badge-sm {{ $epcisVersion === '2.0' ? 'badge-info' : 'badge-ghost' }}">
                                                    {{ $epcisVersion === '2.0' ? '2.0 JSON-LD' : '1.2 XML' }}
                                                </span>
                                            </td>
                                            <td>
                                                @if ($connection->is_active)
                                                    <span class="badge badge-sm badge-success">Active</span>
                                                @else
                                                    <span class="badge badge-sm badge-ghost">Inactive</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap">
                                                {{ $connection->last_sent_at?->format('M j, Y g:i A') ?? '—' }}
                                            </td>
                                            @php
                                                $redactedError = $this->redactLastError($connection->last_error);
                                            @endphp
                                            <td class="max-w-xs truncate" @if (filled($redactedError)) title="{{ $redactedError }}" @endif>
                                                {{ filled($redactedError) ? Str::limit($redactedError, 60) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>

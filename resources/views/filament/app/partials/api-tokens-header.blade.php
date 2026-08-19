<div class="mb-6 space-y-4">
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <p class="text-sm font-medium">Tenant API base URL</p>
            <p class="mt-1 font-mono text-sm text-primary">{{ $this->apiBaseUrl() }}</p>
            <p class="mt-2 text-sm opacity-70">
                Send requests with <code class="rounded bg-base-200 px-1 py-0.5 text-xs">Authorization: Bearer {token}</code>.
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <p class="text-sm font-medium">Inbound EPCIS</p>
            <p class="mt-2 text-sm opacity-70">
                Grant <code class="rounded bg-base-200 px-1 py-0.5 text-xs">epcis:upload</code> and
                <code class="rounded bg-base-200 px-1 py-0.5 text-xs">epcis:view</code> on the token (or use
                <code class="rounded bg-base-200 px-1 py-0.5 text-xs">view</code> for read-only listing).
            </p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-base-200 p-3 text-xs"><code># Upload raw EPCIS XML
curl -X POST "{{ $this->apiBaseUrl() }}/v1/epcis/inbound" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/xml" \
  --data-binary @shipment.xml

# Upload multipart file
curl -X POST "{{ $this->apiBaseUrl() }}/v1/epcis/inbound" \
  -H "Authorization: Bearer {token}" \
  -F "file=@shipment.xml"

# List inbound documents
curl "{{ $this->apiBaseUrl() }}/v1/epcis/documents?per_page=25" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"</code></pre>
        </div>
    </div>

    @if (\App\Support\TenantFeatures::forTenant(tenant())->supportsVrs())
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <p class="text-sm font-medium">Dispense-check (PMS / VRS)</p>
                <p class="mt-2 text-sm opacity-70">
                    Grant <code class="rounded bg-base-200 px-1 py-0.5 text-xs">vrs:dispense-check</code> on new tokens
                    for <code class="rounded bg-base-200 px-1 py-0.5 text-xs">POST /api/v1/dispense-check</code>.
                    Tokens issued before this ability existed keep working after you run the grant command
                    (or re-issue a token with the ability selected).
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-base-200 p-3 text-xs"><code># Preview tokens that would receive vrs:dispense-check
php artisan tracepharma:grant-dispense-check-ability --tenant={{ tenant()->getKey() }} --dry-run

# Apply the grant for this tenant
php artisan tracepharma:grant-dispense-check-ability --tenant={{ tenant()->getKey() }}</code></pre>
            </div>
        </div>
    @endif

    @if (\App\Support\TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations())
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <p class="text-sm font-medium">Outbound EPCIS transmit</p>
                <p class="mt-2 text-sm opacity-70">
                    Grant <code class="rounded bg-base-200 px-1 py-0.5 text-xs">epcis:transmit</code> to POST outbound XML.
                    Use <code class="rounded bg-base-200 px-1 py-0.5 text-xs">epcis:view</code> or
                    <code class="rounded bg-base-200 px-1 py-0.5 text-xs">epcis:transmit</code> to poll transmission status.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-base-200 p-3 text-xs"><code># Transmit raw outbound EPCIS XML
curl -X POST "{{ $this->apiBaseUrl() }}/v1/epcis/outbound" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/xml" \
  -H "X-Original-Filename: shipment.xml" \
  --data-binary @shipment.xml

# Transmit with optional routing hints
curl -X POST "{{ $this->apiBaseUrl() }}/v1/epcis/outbound" \
  -H "Authorization: Bearer {token}" \
  -F "file=@shipment.xml" \
  -F "outbound_connection_id=1" \
  -F "trading_partner_id=2"

# Poll outbound document status (+ MDN summary when available)
curl "{{ $this->apiBaseUrl() }}/v1/epcis/outbound/{document_uuid}" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"</code></pre>
            </div>
        </div>
    @endif

    @if ($this->plainTextToken)
        <div class="alert alert-success">
            <div>
                <p class="text-sm font-medium">New token (copy now)</p>
                <p class="mt-2 break-all font-mono text-sm">{{ $this->plainTextToken }}</p>
            </div>
        </div>
    @endif
</div>

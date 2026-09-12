# Inbound and outbound connections

Filament classes:

- `App\Filament\App\Resources\InboundConnections\InboundConnectionResource`
- `App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource`

## When to use

Create and maintain the routes that receive and send EPCIS documents for your partners.

## Platform approval (new connections)

Every connection you create starts as **Pending review** and cannot send or receive documents until a platform admin approves it. This protects the network from rogue endpoints.

- The **Review** column on the connections list shows the state: Pending review, Approved, or Rejected.
- While pending, the connection form shows an "Awaiting platform approval" banner. Setup can be finished as usual — traffic unlocks on approval.
- If rejected, the connection page shows the admin's note. **Edit and save the connection to resubmit it** for review.
- Hub routing (**Receive through the … hub**) can only be enabled after approval.
- Connections created before this gate existed are already approved; nothing to do.

## Outbound: one connection per network

Networks (LSPediA Exchange, UniTrace Hub, TraceLink, SAP ICH) work as **integrate once**: you open one connection into the network, then assign every customer of yours on that network to it. You do **not** open a separate endpoint per customer.

| Destination | When |
|-------------|------|
| Through a network | Customers receive via LSPediA, TraceLink, UniTrace, … — one connection reaches them all |
| Direct to one customer | The customer gave you their own endpoint (HTTPS, AS2, or SFTP) |
| Our portal or email | Customers download from your portal or get the file by email |

Transport destination = the network endpoint. Business destination = the customer's GLN in the file header.

### Create an outbound connection (wizard)

1. Open **Outbound connections** → Create.
2. **Destination** — pick how documents reach customers and give the connection a name.
3. **Network** — pick the network profile. TracePharma hub profiles (this platform) are grouped first, then external networks. The shared URL / AS2 details come from the profile; toggle **Use my own endpoint** only when the network issued you your own URL. Pick the transport (HTTPS default; AS2 only if IT has certificates).
4. **Customers** — assign every customer that receives through this connection. Required for network and direct connections.
5. **Credentials** — tokens, SFTP logins, or AS2 certificates. Save — the connection starts in **Test** status. Promote to Live from the connection page after a successful test send.

Two active connections that both include the same customer in the same Test/Live band are rejected as ambiguous.

### Network profiles (platform-managed)

Platform admins maintain shared **outbound network profiles** (Admin → Outbound networks) with the URL / AS2-To that does not change per customer. Tenants never store those shared secrets.

Use **Test connectivity** on the connection view for a lightweight reachability check. Delete is blocked while open ship sessions still reference the connection.

## Inbound: where documents come from

The inbound wizard asks for the **Source** first:

- **TracePharma hub (another TracePharma tenant)** — documents from another company on this platform.
- **An external network (Systech, UniTrace, …)** — documents arrive through a network you already use.
- **Direct from one partner** — a partner posts or drops files straight to you (HTTPS or SFTP).

For hub sources, step 2 shows **your receiving GLN** — senders address documents to it — and the **Receive through the … hub** toggle (visible when your organization is entitled; ask your platform admin). Step 4 shows a **Give this to your network operator** panel with the hub URL; the platform hub token authenticates the POST (ask your TracePharma admin for the current value).

For direct HTTPS, step 4 points to the inbound URL and token on the connection page (available after saving).

### Who sends to you

Map each sender to a partner. Turn on **More than one partner sends through this connection** to add one row per sender: the partner and **their GLN as sender** — the GLN in the file header that identifies them.

## Prerequisites

- Partner identifiers (GLN, credentials, URLs) from your trading partner or network.
- Integrations admin rights; secrets handled per tenant security policy.

## Monitor

- Integration health shows last received/sent and last error per connection.
- Disable rather than delete when temporarily pausing.

## Related pages

- [integration-health.md](integration-health.md) — health overview
- [epcis-subscriptions.md](epcis-subscriptions.md) — push/pull subscriptions
- [api-tokens.md](api-tokens.md) — API auth for programmatic ingest
- [partner-onboarding.md](partner-onboarding.md) — partner invite kit

## Notes

- Rotating credentials invalidates in-flight partner configs — coordinate cutover windows.
- Inbound and outbound are separate resources; a bidirectional partner usually needs both.
- Hub HTTP 200 means the network accepted the file — not that the partner ingested TI. Watch MDNs / Investigator.

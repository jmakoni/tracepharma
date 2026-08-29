---
title: Receiving
parent: workflows
order: 20
group: Operations
---

# Receiving

- **Slug / URL:** `/receiving-sessions`, `/scan-in`, `/receiving-sessions/{id}`
- **Filament:** `App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource`; desk: `App\Filament\App\Pages\ScanInWorkstation`
- **Who:** `supportsReceiving()` and `NavReceive`; site-scoped unless user has all-sites access
- **Produces:** `receiving` + `in_progress` (ObjectEvent on session complete)

## When to use

Confirm inbound product against an ASN/EPCIS document, scan-first receive, or transfer receive. Complete the session to author receiving EPCIS.

## Prerequisites

- Site selected in site chooser.
- Inbound EPCIS document attached (document-driven) or scan-first session opened.
- EPC not blocked by open hold, recall, or another open receive session (policy-dependent).

## Steps (with screenshots)

1. Open **Receive** (`/receiving-sessions`) or **Scan In** (`/scan-in`) from Receiving nav or Operations Hub.

![Receive entry](media/receiving/01-entry.png)

2. Select or create an open session; confirm scans until expected lines match policy.

![Scan In workstation (full)](media/receiving/05-scan-in-full.png)

3. Review the sessions list for status, partner, and site.

![Sessions list](media/receiving/02-sessions-list.png)

4. **Complete receive** — header action on Scan In or session view. This runs `GenerateReceivingEpcisEvents`.
5. Optional: open [receiving-issues.md](../workflows/receiving-issues) after completion to report shortage, overage, or damage.
6. Confirm authored events under **Receiving → Inbound EPCIS** (partner ASN) or **Ship → Outbound EPCIS** (tenant-authored receiving docs appear on the outbound ledger depending on direction).

![Authored receiving events](media/receiving/06-authored-receiving-events.png)

![Inbound EPCIS list](media/receiving/03-inbound-epcis-list.png)

## Authored EPCIS checklist

- [ ] ObjectEvent per confirmed EPC (or policy-aggregated lot level)
- [ ] `bizStep`: `urn:epcglobal:cbv:bizstep:receiving`
- [ ] `disposition`: `urn:epcglobal:cbv:disp:in_progress`
- [ ] `readPoint` / `bizLocation` at receive site GLN
- [ ] Outbound document linked on session (`authored_kind` receiving)

## Related pages

- [unpack.md](../workflows/unpack) — break hierarchy after receive
- [transferring.md](../workflows/transferring) — inter-site transfer receive leg
- [receiving-issues.md](../workflows/receiving-issues) — post-receive exceptions
- [asset-tracking.md](../workflows/asset-tracking) — verify custody after receive

## Notes / known quirks

- Scan In and Receive list share the same sessions; subheading notes a future single-screen merge.
- Edge mode chip reflects tenant receiving policy (strict vs pragmatic gates).
- Mobile receive uses `MobileViewReceivingSession` for floor layouts.

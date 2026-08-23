# Delete-session hardening (DH) — Design Spec

> Hardening pass on hard-delete of unsubmitted floor scan sessions. Builds on `2026-08-23-delete-unsubmitted-scan-sessions-design.md`.

**Goal:** Fix Critical/Important bugs in hard-delete (mobile no-op, transfer auth/eligibility, invoice orphan), then lock with Filament/job-role/mobile tests.

**Non-goals:** OutboundShippingSession fixture flakiness; AS2 inbound; branding/Stripe; ship/transfer mobile delete parity (receive mobile only for DH-8).

---

## Wave 1 — Product

| ID | Sev | Problem | Fix |
|----|-----|---------|-----|
| **DH-1** | Critical | Mobile receive Delete calls `mountAction('deleteReceiving')` but `MobileViewReceivingSession::getHeaderActions` whitelist omits `deleteReceiving` → silent no-op | Add `deleteReceiving` to the whitelist |
| **DH-2** | Important | `TransferringSessionPolicy::delete` mirrors `update` (from **or** to site); `DeleteTransferringSession` asserts **from-site only** → to-site users may see Delete then fail | Policy `delete()` requires from-site access (and NavShip); Filament visibility already uses `canHardDelete` + policy via resource |
| **DH-3** | Important | Hard-delete transfer_receive calls `RevertTransferReceiveReceivingMarks`, which throws when linked transfer has `receive_events_generated_at` set — but `canHardDeleteReceiving` still true | Refuse hard-delete (and hide Delete) when transfer receive EPCIS authored; same gate as cancel/reset |
| **DH-4** | Important | Receiving session invoice blob left on disk after hard delete | Delete `invoice_disk`/`invoice_path` file before `$session->delete()` |

**Order:** DH-1 → DH-3 → DH-2 → DH-4

---

## Wave 2 — Tests

| ID | Coverage |
|----|----------|
| **DH-5** | Livewire: confirmed scans > 0 → wrong/missing phrase fails; `DELETE` succeeds |
| **DH-6** | Job-role denial for delete Actions (`NavReceive` / `NavShip`) |
| **DH-7** | transfer_receive: `canHardDelete()` false + Action throws when transfer `receive_events_generated_at` set |
| **DH-8** | `MobileViewReceivingSession` `callAction('deleteReceiving')` succeeds after DH-1 |

---

## Success criteria

- Mobile receive Delete deletes (or surfaces Action error), not silent no-op.
- To-site-only transfer users: Delete hidden / policy denies.
- Delete/Cancel not offered when transfer receive EPCIS blocks revert.
- Invoice file removed on hard delete.
- New Pest cases + existing Delete* suites green.

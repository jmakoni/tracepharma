# Delete Unsubmitted Floor Scan Sessions — Design Spec

> Approved spec for hard-deleting unsubmitted receive, ship, and transfer floor sessions. Implementation follows TDD per domain (Receiving → Shipping → Transferring).

**Goal:** Allow operators to permanently remove mistaken or abandoned floor scan sessions that have not yet authored EPCIS, while keeping soft **Cancel** for audit-friendly retirement.

**Non-goals:** Delete after EPCIS authoring; bulk delete; regulatory password gate on delete (Cancel retains reason gate where configured).

---

## Behavior

| Domain | Hard delete eligible | Blocked when |
|--------|---------------------|--------------|
| **Receive** | `open` or `in_progress`; no `receiving_epcis_document_id` / `receiving_events_generated_at` | Completed, cancelled, receiving EPCIS authored |
| **Ship** | `open` or `in_progress`; no `epcis_document_id` | Completed, cancelled, shipping EPCIS document linked |
| **Transfer** | `open` only; no transfer EPCIS | `in_transit`, completed, cancelled, transfer EPCIS authored |

- **Hard delete:** `DELETE` row on session table; scan lines cascade via FK.
- **Soft cancel:** Unchanged — sets `cancelled` status, retains row and scan history.

### Receive-specific pre-delete

1. **Transfer receive:** call `RevertTransferReceiveReceivingMarks` (same as cancel/reset).
2. **Open-tote lock:** clear `active_parent_epc_id` and `short_closed_parent_epc_ids` when set.

---

## Authorization

Same as **Cancel** for each domain:

- Receive: `NavReceive` + `SiteAccess` (null site requires `SitesAccessAll`).
- Ship / Transfer: `NavShip` + site access (transfer: from-site for delete action).

Policies: `delete()` mirrors `update()`.

Resources: `canDelete()` = eligibility helper **and** policy.

---

## UI (Filament)

- **Delete** action: danger, `requiresConfirmation`.
- When confirmed scan count > 0: modal `TextInput` must equal `DELETE` (constant in `App\Support\Floor\UnsubmittedSessionDelete`).
- Domain Actions perform delete when eligible — no phrase check in Actions.
- Surfaces: list row actions, view header, receive mobile HUD (alongside Cancel).
- After delete from view/mobile: redirect to resource index.

Cancel actions and copy unchanged.

---

## Code layout

| Piece | Location |
|-------|----------|
| Shared phrase + eligibility | `App\Support\Floor\UnsubmittedSessionDelete` |
| Filament action builder | `App\Filament\Support\Floor\UnsubmittedSessionDeleteAction` |
| Domain actions | `DeleteReceivingSession`, `DeleteOutboundShippingSession`, `DeleteTransferringSession` |
| Model helpers | `canHardDelete()` on each session model |
| Tests | `Delete*SessionTest` mirroring cancel tests per domain |

---

## Success criteria

- Empty and scanned eligible sessions hard-delete; scan lines gone.
- EPCIS/completed/cancelled/in_transit blocked with `DomainException`.
- Transfer receive delete reverts transferring received marks.
- Auth denial matches cancel.
- Cancel still soft-deletes.
- Focused Pest/PHPUnit green for all three domains.

# Investigator SLA

Filament classes:

- `App\Filament\App\Pages\InvestigatorSla`

## When to use

Monitor investigation aging against SLA targets and prioritize overdue exceptions.

## Prerequisites

- Exceptions flowing into the queue.
- Investigator role (or supervisor view).

## Steps

1. Open **Investigator SLA**. Open the page and use Help for live UI.
2. Sort/filter overdue and at-risk items.
3. Assign or open the exception and work to resolution.
4. Recheck the SLA board after bulk closes.

## Related pages

- [exceptions.md](exceptions.md) — exception detail and actions
- [../compliance/compliance-alerts.md](../compliance/compliance-alerts.md) — related alerts
- [../compliance/tracing-and-fda3911.md](../compliance/tracing-and-fda3911.md) — tracing escalations
- [../settings/users.md](../settings/users.md) — investigator user roles

## Notes

- SLA clocks depend on tenant configuration; confirm local targets with compliance.
- The board does not auto-assign — supervisors still own workload distribution.

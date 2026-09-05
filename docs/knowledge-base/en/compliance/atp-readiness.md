---
title: ATP readiness
parent: compliance
order: 20
group: Compliance
---

# ATP readiness

Filament classes:

- `App\Filament\App\Pages\AtpPartnerReadiness`

## When to use

Review Authorized Trading Partner (ATP) license coverage and gaps before shipping or receiving with partners.

## Prerequisites

- Trading partners and sites maintained with license / ATP attributes.
- Compliance or master-data edit rights for remediating gaps.

## Steps

1. Open **ATP readiness**. Open the page and use Help for live UI.
2. Filter partners or sites with missing, expired, or mismatched licenses.
3. Open the partner or site record and update licenses / status.
4. Re-check readiness after saves; clear related compliance alerts.

## Related pages

- [../master-data/trading-partners.md](../master-data/trading-partners) — partner master records
- [../master-data/sites-and-devices.md](../master-data/sites-and-devices) — site ATP licenses
- [compliance-alerts.md](../compliance/compliance-alerts) — ATP-related alerts
- [../integrations/partner-onboarding.md](../integrations/partner-onboarding) — partner invite kit

## Notes

- ATP readiness is advisory for operations gates depending on tenant profile; treat gaps as blockers for DSCSA-ready trading.
- Outbound send: Organization Settings → **Block send when ship-to ATP license is missing or expired** (default off = soft warning; on = hard block).
- License data may also come from FDA/WDD registry sync — confirm source before editing.

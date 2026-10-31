---
title: People and mall roles
category: Floor and ops
hesk: published 2026-10-31
as_of: 2026-10-29
---

# People and mall roles

## As of

2026-10-29

## In one sentence

The owner adds cashiers, managers, and books logins on People; each role lands on a different home with a matching menu.

## When it happens

An owner opens Setup → People (`/{slug}/staff`), or a staff member signs in at `/` with their email.

## What the books / screens do

- **Owner** — full mall; home is the dashboard.
- **Manager** — floor lead: sell, rent, register, customers, vendors/inventory/booths/map/deals/wait list/email, plus read this month, payouts, and reports. Home is the register. No Settings, Company, or People; cannot pay vendors.
- **Cashier** — Sell, Rent, Register only. Home is the register.
- **Books** — read sales, this month, rent, payouts, money record, and reports. Home is Sales. Cannot sell or disburse.
- **Vendor** — portal Check / Sales / Inventory / Deals. Sign-in is set on that vendor’s page, not on People.
- People page sections: Cashiers, Managers, Books. Vendor portal note links to the parties list.

## What is refused

- A manager opening Settings, People, Company, export, year close, or payout posts.
- A books login claiming they can pay vendors (nav says View payouts).
- Second-signature from a books or vendor account.

## How to check

Add a manager on People, sign in, confirm Floor links and no Settings. Sign in as books and confirm View payouts, not Pay vendors.

## Source

`docs/business-rules.md` — Floor; Sign-in. `src/Bootstrap/RoleGate.php`, `src/Feature/Staff/staff.php`, `src/Shared/Web/layout.php`.

## Related

- Second-signature ceilings
- Email sign-in finds the mall
- Platform admin portal

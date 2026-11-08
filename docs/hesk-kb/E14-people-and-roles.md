---
title: People and mall roles
category: Floor and ops
hesk: published 2026-11-08
as_of: 2026-11-07
---

# People and mall roles

## As of

2026-11-07 — People (`/{slug}/staff`) is staff sign-in. Shoppers, sellers, and the wait list are parties, not People rows. Suspend/Restore and change-role among cashier, manager, and books are unchanged.

## In one sentence

An owner adds, suspends, restores, and (for floor staff) reassigns cashiers, managers, books logins, and co-owners on People; each role lands on a different home with a matching menu.

## When it happens

An owner opens Setup → People (`/{slug}/staff`), or a staff member or owner signs in at `/` with their email.

## What the books / screens do

- **Owner** — full mall; home is the dashboard. A mall may have more than one owner (co-owners). People shows Owners with sign-in reset, Suspend/Restore, and Remove for other owners.
- **Manager** — floor lead: sell, rent, register, customers, vendors/inventory/booths/map/deals/wait list/email, plus read this month, payouts, and reports. Home is the register. No Settings, Company, or People; cannot pay vendors.
- **Cashier** — Sell, Rent, Register only. Home is the register.
- **Books** — read sales, this month, rent, payouts, money record, and reports. Home is Sales. Cannot sell or disburse.
- **Vendor** — portal Check / Sales / Inventory / Deals. Sign-in is set on that vendor’s page, not on People.
- People: one Add person form; roster sections with Suspend/Restore, Change role (cashier/manager/books), and password reset. Suspended people cannot sign in.
- People is staff and owners. Party identity (a person or an organization, and the vendor, customer, and wait-list roles) is `/{slug}/parties`, not this screen.
- Platform `/admin` is not managed on People.

## What is refused

- A manager opening Settings, People, Company, export, year close, or payout posts.
- A books login claiming they can pay vendors (nav says View payouts).
- Second-signature from a books or vendor account.
- Removing your own owner sign-in, or removing the last owner on the mall.
- Suspending the last active owner.
- Sign-in (and admin enter-as) for a suspended owner or staff login.

## How to check

Add a manager on People, sign in, confirm Floor links and no Settings. Suspend that manager and confirm sign-in fails; Restore and confirm it works. Change a cashier to manager and confirm manager chrome. Add a co-owner; refuse suspending every owner.

## Source

`docs/business-rules.md` — Floor; Sign-in. `src/Bootstrap/RoleGate.php`, `src/Feature/Staff/staff.php`, `src/Feature/Staff/MallOwners.php`, `src/Shared/Web/layout.php`.

## Related

- Second-signature ceilings
- Email sign-in finds the mall
- Platform admin portal
- Party identity, customers, and the wait list

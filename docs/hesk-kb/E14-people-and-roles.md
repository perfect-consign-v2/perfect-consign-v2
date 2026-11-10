---
title: People and mall roles
category: Floor and ops
hesk: published 2026-11-09
as_of: 2026-11-08
---

# People and mall roles

## As of

2026-11-08 — The menu People group holds sellers, customers, and parties; owners also see Staff (`/{slug}/staff`) there. Staff is sign-in for cashiers, managers, books, and co-owners. Shoppers, sellers, and the wait list are parties, not Staff rows. Suspend/Restore and change-role among cashier, manager, and books are unchanged.

## In one sentence

An owner adds, suspends, restores, and (for floor staff) reassigns cashiers, managers, books logins, and co-owners on Staff; each role lands on a different home with a matching menu.

## When it happens

An owner opens People → Staff (`/{slug}/staff`), or a staff member or owner signs in at `/` with their email.

## What the books / screens do

- **Owner** — full mall; home is the dashboard. A mall may have more than one owner (co-owners). Staff shows Owners with sign-in reset, Suspend/Restore, and Remove for other owners.
- **Manager** — floor lead: sell, rent, register, People (customers, vendors/consignors, parties), inventory/booths/map/deals/wait list/email, plus read this month, payouts, and reports. Home is the register. No Settings, Company, or Staff; cannot pay vendors.
- **Cashier** — Sell, Rent, Register only. Home is the register.
- **Books** — read sales, this month, rent, payouts, money record, and reports. Home is Sales. Cannot sell or disburse.
- **Vendor** — portal Check / Sales / Inventory / Deals. Sign-in is set on that vendor’s page, not on Staff.
- Staff: one Add person form; roster sections with Suspend/Restore, Change role (cashier/manager/books), and password reset. Suspended people cannot sign in.
- Staff is store logins and owners. Party identity (a person or an organization, and the vendor, customer, and wait-list roles) is `/{slug}/parties`, not this screen.
- Platform `/admin` is not managed on Staff.

## What is refused

- A manager opening Settings, Staff, Company, export, year close, or payout posts.
- A books login claiming they can pay vendors (nav says View payouts).
- Second-signature from a books or vendor account.
- Removing your own owner sign-in, or removing the last owner on the mall.
- Suspending the last active owner.
- Sign-in (and admin enter-as) for a suspended owner or staff login.

## How to check

Add a manager on Staff, sign in, confirm People and Floor links and no Settings or Staff. Suspend that manager and confirm sign-in fails; Restore and confirm it works. Change a cashier to manager and confirm manager chrome. Add a co-owner; refuse suspending every owner.

## Source

`docs/business-rules.md` — Floor; Sign-in. `src/Bootstrap/RoleGate.php`, `src/Feature/Staff/staff.php`, `src/Feature/Staff/MallOwners.php`, `src/Shared/Web/layout.php`.

## Related

- Second-signature ceilings
- Email sign-in finds the mall
- Platform admin portal
- Party identity, customers, and the wait list

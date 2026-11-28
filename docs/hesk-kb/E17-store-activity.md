---
title: Store activity names the signed-in account
category: Platform notes
hesk: published 2026-11-27
as_of: 2026-11-27
---

# Store activity names the signed-in account

## As of

2026-11-27 — `/{slug}/activity` shows 50 rows at a time, with a person, an action, and Older and Newer. An index in `sql/001_tenant.sql` serves that list. Operator audit stays a separate page and no longer has a store load action.

## In one sentence

Owner, manager, and books open `/{slug}/activity` to see who signed in and what they did in that store; cashiers and vendors cannot open it, and the operator log is not this list.

## When it happens

Someone changes the store while signed in: a sale is paid, voided, or returned, staff or settings change, a booth or assignment changes, a register opens or closes, an item or offer changes, or an operator enters the store. A line scan does not write a row. A completed sale, void, or return writes one.

## What the books / screens do

- The row stores the signed-in account id, whether that account is an owner or staff, the username, the name at that moment, and the role.
- A second signature keeps the cashier on the row and adds the other staff id and username.
- An enter-as session keeps the mall account and adds the operator id and email.
- The client address is stored, at most 64 characters.
- The page shows the newest 50 matching rows. Older and Newer walk the same filter. A cursor that is not in the filter says that page is not in this list, and the list stays empty.
- Each row leads with the mall-local time, the person, the role, and a sentence for the action. Details keep the username, the action code, the record, a second signer, an enter-as email, and the address when those values are present. A missing account still says not recorded.
- Open is shown only when the signed-in role can open that record: a journal, an item that was not deleted, a booth, a vendor or consignor, a customer, an offer, or a paid ticket's receipt. A ticket with no single posted journal has no Open link.
- Filters are from, to, one person, and one action. The person list is cashiers, managers, books, and owners who can sign in, plus former accounts that still have a row. The action list is the store's own actions. A to day before from is pulled up to from. A before cursor and an after cursor together is an error. Any other filter is an error.
- `/{slug}/activity.csv` downloads the date, person, and action. It does not download one page. A cursor on the file is refused and no file is sent. More than 5,000 matches is refused with "Narrow the dates." No partial file is sent.
- Older rows with no account show as not recorded. There is no backfill and no system user.
- Nightly jobs (late fees, markdown proposals, mail) are not people. Their money stays on the journal.
- Operator audit (`STALL_ADMIN_PATH` audit page) is operator actions: enter-as, wipe, signup, and the rest of `platform.admin_audit`. Its heading is Operator audit.
- Demo seeds act as the owner row that seed just created.

## What is refused

- A new activity row with no account, kind, username, role, or name.
- An account that is not a current staff row or an owner of this mall.
- A second signer who is not a different staff row.
- An enter-as id without a matching operator email.
- Changing or deleting an activity row, including by disabling or dropping the guard.
- Cashiers and vendors opening the page or the CSV.
- A CSV that would be more than 5,000 rows.

## How to check

Sign in as the owner, save a setting, and open Activity. The row shows that owner's username, role owner, and the request. Sign in as a cashier, pay or void within the rules, and the row shows that cashier. A second signature shows the other staff username on the same row. Enter-as shows the operator email. An empty session cannot commit the change.

## Source

`docs/business-rules.md` — store activity and platform admin. `sql/001_tenant.sql`, `sql/000_platform.sql`, `sql/prove_audit_events.sql`.

## Related

- Platform admin portal
- People and mall roles

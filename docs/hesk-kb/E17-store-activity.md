---
title: Store activity names the signed-in account
category: Platform notes
hesk: published 2026-11-20
as_of: 2026-11-20
---

# Store activity names the signed-in account

## As of

2026-11-20 — `/{slug}/activity` is the store people log. Operator audit stays a separate page.

## In one sentence

Owner, manager, and books open `/{slug}/activity` to see who signed in and what they did in that store; cashiers and vendors cannot open it, and the operator log is not this list.

## When it happens

Someone changes the store while signed in: a sale is paid, voided, or returned, staff or settings change, a booth or assignment changes, a register opens or closes, an item or offer changes, or an operator enters the store. A line scan does not write a row. A completed sale, void, or return writes one.

## What the books / screens do

- The row stores the signed-in account id, whether that account is an owner or staff, the username, the name at that moment, and the role.
- A second signature keeps the cashier on the row and adds the other staff id and username.
- An enter-as session keeps the mall account and adds the operator id and email.
- The client address is stored, at most 64 characters.
- The page lists the newest 200 rows: when, username, name, role, action, entity, second signer, enter-as email, and address.
- Filters are from, to, account, and action. A to day before from is pulled up to from. Any other filter is an error.
- `/{slug}/activity.csv` downloads the same filter. More than 5,000 matches is refused with "Narrow the dates." No partial file is sent.
- Older rows with no account show as not recorded. There is no backfill and no system user.
- Nightly jobs (late fees, markdown proposals, mail) are not people. Their money stays on the journal.
- Operator audit (`STALL_ADMIN_PATH` audit page) is operator actions: enter-as, wipe, signup, a store load, and the rest of `platform.admin_audit`. Its heading is Operator audit.
- A PC1 mirror load writes one `store.load` row on that operator log for the admin named with `--admin`. Replayed history is not given a PC2 username.
- Demo seeds act as the owner row that seed just created.

## What is refused

- A new activity row with no account, kind, username, role, or name.
- An account that is not a current staff row or an owner of this mall.
- A second signer who is not a different staff row.
- An enter-as id without a matching operator email.
- Changing or deleting an activity row, including by disabling or dropping the guard.
- Cashiers and vendors opening the page or the CSV.
- A CSV that would be more than 5,000 rows.
- A mirror load that does not name a known platform admin. That refusal happens before the wipe.

## How to check

Sign in as the owner, save a setting, and open Activity. The row shows that owner's username, role owner, and the request. Sign in as a cashier, pay or void within the rules, and the row shows that cashier. A second signature shows the other staff username on the same row. Enter-as shows the operator email. An empty session cannot commit the change. `beccas-mirror` activity after a load does not invent cashiers; Operator audit shows `store.load`.

## Source

`docs/business-rules.md` — store activity and platform admin. `sql/103_audit_subject.sql`, `sql/104_store_load_audit.sql`, `sql/prove_audit_events.sql`.

## Related

- Platform admin portal
- PC1 → PC2 dev mirror (Beccas sandbox)
- People and mall roles

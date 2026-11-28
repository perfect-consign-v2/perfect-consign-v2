---
title: Settlement vs check / drawer / safe disbursement
category: Payouts and settlement
hesk: pending
as_of: 2026-11-27
---

# Settlement vs check / drawer / safe disbursement

## As of

2026-11-27 — Net first: a write-off is refused while the store still owes the vendor; pay or apply that first. 2026-11-27 — Settlement pays rent stay by stay, oldest first; vendors who have left are listed on their own. 2026-11-18 — Print checks does not ask for a check number. The stock already has the company name and the number. Print sales and rent opens the sales and rent sheet, including a payout for one vendor. 2026-11-06 — Money record Void refuses settlement and check / cash / safe disbursement journals; undo a paid run with payout reverse.

## In one sentence

Close posts a settlement that clears the smaller of open payable and open rent with no cash; paying the vendor is a later disbursement.

## When it happens

Closing a payout run, then writing a check, paying from the drawer, or paying from the safe.

## What the books / screens do

- Before settlement: apply clawback — smaller of open payable and open vendor receivable; no cash.
- Settlement: smaller of remaining open payable and open rent; no cash movement. The rent part is split across the vendor's stays by the database, oldest unpaid stay first.
- Rent for a month the run covers but nobody charged is charged on that stay first, then settled.
- Vendors who have left the store but still have money open are listed on their own, under Vendors who have left.
- Check, drawer payment, or safe payment is a later disbursement.
- A vendor with nothing open gets a report and no journal.
- Settled or paid run refuses a new journal dated inside its sales window; a reversal is dated after that window.
- Settlement, rent charged, checks already written, and rent paid in a later window are net of those journals (reversals included).
- Print checks does not ask for a check number. The check stock already has the company name and the check number, so the page prints the date, who is paid, the amount, and the memo.
- Print sales and rent on that page opens the sales and rent sheet for that payout, including a payout for one vendor. That sheet lists the sales on the check, including a sale dated after the closed month, and the rent deducted from them.
- Two check disbursements cannot share a check number.
- The Money record Void button refuses settlement and disbursement journals — use payout reverse, then re-pay if needed.

Note: older Stall PLAN text described payout clearing payable and rent against cash in one step. Current mall rules use clawback apply, then settlement, then cash later.

- Net first: before the owner can write off what a vendor owes, everything the store owes them must be paid or applied here. Each offset is its own journal.

## What is refused

- A second payout/settlement for the same closed window when rules say the run is done.
- Duplicate check numbers on disbursements.
- Dating new money journals inside a settled/paid sales window (except reversal after the window).
- Journal void of settlement or check / cash / safe disbursement (payout reverse is the undo path).

## How to check

After close: payable and rent reduced by the settled amount, cash unchanged. After check: cash/bank moves; statement shows settlement and disbursement separately.

## Source

`docs/business-rules.md` — Payouts. (Contrast `docs/PLAN.md` older single-step payout.)

## Related

- Pay vs credit-only vs hold
- Vendor memo (and payable below zero)
- Vendor clawback (they owe us after return/void)
- What a rent charge posts; what a rent payment posts

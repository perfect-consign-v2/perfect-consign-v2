---
title: Settlement vs check / drawer / safe disbursement
category: Payouts and settlement
hesk: published 2026-10-31
as_of: 2026-10-25
---

# Settlement vs check / drawer / safe disbursement

## As of

2026-10-25

## In one sentence

Close posts a settlement that clears the smaller of open payable and open rent with no cash; paying the vendor is a later disbursement.

## When it happens

Closing a payout run, then writing a check, paying from the drawer, or paying from the safe.

## What the books / screens do

- Before settlement: apply clawback — smaller of open payable and open vendor receivable; no cash.
- Settlement: smaller of remaining open payable and open rent; no cash movement.
- Check, drawer payment, or safe payment is a later disbursement.
- A vendor with nothing open gets a report and no journal.
- Settled or paid run refuses a new journal dated inside its sales window; a reversal is dated after that window.
- Settlement, rent charged, checks already written, and rent paid in a later window are net of those journals (reversals included).
- Two check disbursements cannot share a check number.

Note: older Stall PLAN text described payout clearing payable and rent against cash in one step. Current mall rules use clawback apply, then settlement, then cash later.

## What is refused

- A second payout/settlement for the same closed window when rules say the run is done.
- Duplicate check numbers on disbursements.
- Dating new money journals inside a settled/paid sales window (except reversal after the window).

## How to check

After close: payable and rent reduced by the settled amount, cash unchanged. After check: cash/bank moves; statement shows settlement and disbursement separately.

## Source

`docs/business-rules.md` — Payouts. (Contrast `docs/PLAN.md` older single-step payout.)

## Related

- Pay vs credit-only vs hold
- Vendor memo (and payable below zero)
- Vendor clawback (they owe us after return/void)
- What a rent charge posts; what a rent payment posts

# Competitor notes (PC2)

Field reports from logged-in demos (Sep 2026). Copied out of the archived EMP tree so **PC2** owns them. Steal Saturday **feel**. Refuse their **balances**.

Older wording inside each product file still says “EMP.” Read that as **PC2 books posture**: payable and rent stay two numbers; never haircut rent inside payout.

## Rules

- One file per product under this folder.
- Record **what they actually do** on screen, with numbers when you saw them.
- Separate **feel** from **books**.
- Do not put passwords, magic links, JWTs, or support codes in git.
- Do not start **their** Square, Stripe, or billing from a look-around unless the operator asks.
- **PC1** is the live Perfect Consign UX floor for Saturday chrome. **PC2** is this repo’s books + product. See [../naming.md](../naming.md).

## Files

| Product | Depth | Notes |
|---------|--------|--------|
| SimpleConsign | Forensic (posted dealer sale) | [simpleconsign.md](./simpleconsign.md) — Candy’s Consignment, 2026-09-10. $100 / 40-60 split, $60 payable, $50 booth rent **not** posted. |
| Quail | Field report + date-range payout lie | [quail.md](./quail.md) — Everything Mall, 2026-09-06 and 2026-09-10. Totals payout **$8.66** after stuffed rent. Approve-only steal list: [quail-steal.md](./quail-steal.md). |
| ConsignCloud | Field report + penny disagreement | [consigncloud.md](./consigncloud.md) — Jill’s Bargain Mall. Chair $45.01 ledger vs $45.00 line; Owed **$3.11**. |
| Resell4.me | Public demo forensic | [resell4me.md](./resell4me.md) — Magnolia seed. Record ACH is bookkeeping; JS `Number` money. |

## Named, not walked

**Ricochet**, **GoAntiquing**, **Liberty**. No logged-in demo in this pack. Do not add a file until someone sits in the product.

## Same Saturday, two books

A mall that wants “take rent out of what we owe the dealer, then print a check for the rest” does that in PC2 **without stuffing rent inside payout**:

1. Named **settlement** (clear the smaller of open payable and open rent; no cash) — see business rules / B07.
2. Later **disbursement** (check / drawer / safe) for leftover payable.

Competitors (Quail, ConsignCloud, SimpleConsign when the fee fires, **PC1** when `booth_rent_deduct_from_payout` is on) collapse that into **one number**. PC2 refuses that collapse.

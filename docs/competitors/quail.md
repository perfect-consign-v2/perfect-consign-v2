# Competitor: Quail

> Owned by **PC2** (`docs/competitors/`). Phrases that say “EMP” mean PC2’s books posture (two numbers; refuse rent-in-payout). See [../naming.md](../naming.md).

- Site: https://quailhq.com/ (store app https://app.quailhq.com/, vendor portal https://vendor.quailhq.com/)
- Apps walked: store login, vendor portal (2026-09-06), referrals mini-app; store reports / settings / layaway re-walk 2026-09-10
- Observed: 2026-09-06 and **2026-09-10** on a 30-day demo (**Everything Mall**, username `everythingmall`, Basic plan)
- Version shown: `2026.9.08 Lago-Vista` (was `2026.9.04 Fredonia-(Biscoe)` on Sep 6)
- Plan: Basic **$40/month**, **1 / 30** active vendors; next payment **$40 on October 06**. Regular $70 / 60 vendors. Full $135 / unlimited. Inactive vendors (no sale in 30 days) do not count toward the cap. All features on every plan. Marketing: switch from SimpleConsign or GoAntiquing gets a **free year**.

Do not clone Quail. Beat them by keeping antique-mall speed **and** posting rent and payout as separate documents.

## How they sell

Built by people who run antique malls. The pitch is Saturday: booth + price, line at the door, vendors who text “how did I do?”

Marketing homepage: pause a ticket, handwritten tags, barcodes optional, rent “however you work” including deducted from earnings, Sunday vendor email, free vendor app, processor-agnostic (key the amount). Switching from SimpleConsign or GoAntiquing is a free year.

They are not a clothing-consignment inventory suite and they are not a ledger.

## Money model (balances vs books)

This is the EMP contrast.

Booth **A1** (Jane Doe) on the demo: monthly rent **$50**, consignment (store cut) **10%**, dealer **card fee 4%**. Store settings also have a **customer card fee** (demo **0%**) that is *not* paid out to vendors.

September 2026 store Totals (date range **Sep 1–30**) after the Sep 6 tickets (chair, hat, $10.10 cash Book sale, $10 layaway on a $100 doll). Headline copy on **Total Vendor Payout**: “Total vendor sales, minus tax, fees and rent.”

| Line | 2026-09-06 (before $10.10 cash) | 2026-09-10 |
|------|----------------------------------|------------|
| Gross sales | 50.00 | **60.00** |
| Taxable / tax-exempt | — | 45.00 / 15.00 |
| Tax collected | 0.45 | **0.55** |
| Consignment | 5.00 | **6.00** |
| Prorated rent | 43.33 | 43.33 |
| Vendor card fees | 2.01 | 2.01 |
| Incoming layaway | 10.00 | 10.00 |
| **Vendor payout** | **-0.34** | **8.66** |

Jane Doe / A1 row on Sep 10: Sales 60.55, Sales−Tax 60.00, Consignment 6.00, Rent 43.33, Card Fees 2.01, Tax 0.55, Adjustments 0.00, **Payout 8.66**. Math: `60.00 − 6.00 − 43.33 − 2.01 = 8.66`.

Payments for item sales (Sep 6): cash **−9.55** (change), card 60.00, cash 10.10, card 0.00. Incoming layaway: cash 10.00 paid / 90.00 remaining. Clerk totals: everythingmall 60.55.

On 2026-09-06 the payout was negative and the UI offered **Send invoice**. After the extra $10.10 cash sale it is positive — same stuffed formula, different sign.

Settings → Vendors & Rent: **Show Prorated Rent in Booth Reports** is ON (checked). Helper (verbatim): “Set this to OFF to use vendors' full monthly rent in booth reports, regardless of the date range used. **WARNING: This may show unexpectedly incorrect payout amounts.**” They already know the date-range lie.

Change-rent copy on the booth is explicit: Quail deducts rent from “total payout” on booth reports, and vendors see it inside “total profit.” If the store writes a sales check and takes a separate rent check, set rent to **$0**.

**Date-range illusion (vendor portal):** same sales, different “payout”:

| Range | Rent shown | Payout shown |
|-------|------------|--------------|
| Sep 1–30 | $43.33 (30 days prorated) | **-$0.34** |
| Sep 6 only | $1.67 (1 day prorated) | **$41.32** |

EMP: rent is `1300` receipts / charges. Payout is `2000` only. Never net rent inside payout.

## Register

URL: `/transaction`. Lands here after store login.

**One empty line:** Booth, inv #, description, qty, price, tax-exempt checkbox, discount. Required fields get a red **MISSING** chip. Calculate Payment stays disabled until the booth is a real booth (typed “A1” is not enough; pick `A1 (Jane Doe)` from the typeahead). Copy under incomplete lines: “one or more items in this sale are incomplete.”

Toolbar (icon row): Vendors, Gift Certificate, Receipts, Refund, Register, Boothless, Quick Grid, Pause Sale.

Clicked on the demo:

- **Booth tag speed:** description + price + booth is the sale. Inventory number is optional (settings can hide it). Empty descriptions can be allowed.
- **$2 off whole sale:** percent off or fixed dollar; overrides per-item one-off discounts. Line showed `2.00 OFF`; tax after discount.
- **Pause:** name-or-anything field, default `everythingmall 14:11 PM`. Resume / keep paused / delete. Not layaway.
- **Checkout:** huge total, cash default, **Split Payment**, Back to items, Finalize. Card reader status: **No card reader available**. Do not start Square/Stripe from here.
- **Cash sale posted (this walkthrough):** Book / A1 / $12 list, $2 off, tax $0.10, cash **$10.10**. Items report that day then showed 4 items, 2 transactions, cash $10.55, card $60.00, total $70.55.
- **Quick Grid:** auto list from frequently sold items (chair A1 25.00, hat A1 10.00); customize freezes auto-update.
- **Boothless:** ad-hoc money that is **not** credited to a booth. Copy says use it for rent or store-side charges; do not use it for vendor items (you cannot reassign later). EMP must not collect rent as a nameless POS line.
- **Gift certificate (issue wizard):** face value, auto issue number, tax at **redeem** not issue, optional expiry, optional memo, issue without payment.
- **Refund:** look up `transaction #` like `50-24408`. Not a browse-the-ticket list.
- **Scan barcode:** “Ready to scan” overlay.
- **Customer on the ticket:** search or create (name, email, phone, note, found-via). All optional.
- **Last transaction** popup: print / email / dismiss; duration is a setting (none / 1 minute / 5 minutes / sticky).
- **Register report:** starting cash per clerk, cash/card/check/certificate totals, add starting cash. Demo starting cash was $0.00 with sales already on the day.

Settings that hide register chrome: hide layaway, hide refunds, hide quick grid, hide inventory numbers, allow empty descriptions.

## Vendors, booths, rent

Vendors list: search booth or vendor, Filters (**All / With booth / Without booth / Deleted**), Email vendors, Today’s discounts, Excel, New vendor. Cap shown as **Active vendors: 1 / 30**.

New vendor: **name is not booth number**, email for portal + weekly performance emails, optional contact details.

Booth editor (A1):

- Booth number = what clerks type / what is on the tag
- Report comments (mail vs pickup)
- **Change booth rent** (amount + effective date + one-year timeline of rent history)
- Custom tax rate (blank = store default; `0` marks items tax-exempt)
- Consignment rate (store % of sales; changing it does not rewrite old sales)
- Credit card rate (pass processor fee to the dealer; or use store customer card fee instead)
- Dated **% off** discounts per booth
- Close booth (pick a date, stop rent; Never mind on the demo)

**Today’s discounts:** empty unless a booth discount is in range; create from the booth, not a global promo calendar.

## Vendor portal

Separate origin. Login is **email + password** (“See your sales”). Grandma-friendly: six icons, huge metrics, orange date buttons.

Nav: **Sales, Barcodes, Referrals, Share, Settings, Logout**.

Sales report (this month on the demo): Items sold 3, Total sales $50.00, Net sales $42.99 (after consignment + card fees), consignment $5.00, card fees $2.01, tax $0.45, rent $43.33, discounted $10.00, payout **-$0.34**. Line table: date, qty, price, discount, tax, gross, CC fee, consign, net sale. Layaway section: Doll $10 paid / $90 remaining, cash, $0 card fee.

Date picker: Today / This week / This month / Yesterday / Last week / Last month, plus a calendar. Export to Excel.

Barcodes: vendor prints their own Avery sheets (4×1 Avery 5161, 2⅝×1 Avery 5160, 1¾×⅔ Avery 5195, 1¾×½ Avery 5167, code list). US Letter or A4. Skip N labels on a partial sheet. Store-side barcodes mention **Sandpiper** for vendor self-print.

Share: invite a friend to the same vendor portal.

Settings: **change password only**.

Referrals (opens quailhq.com/referrals): personal code (letters + numbers), $5 / $10 / $15 off for referred stores by size, first paid month up to $120 to the referrer. Bank details for referral payouts — do not fill on a look-around.

Vendors are shown the haircut. EMP’s vendor home should show **sales since last payout** and **rent owed** as two numbers, never one mixed “payout.”

## Reports and payouts

Store reports: **Items, Totals, Methods, Long View, Opening Hours, Invalidated**, print / Excel.

- **Items:** tenders as big cards, line list with Actions, layaway payments section, Filter, clerk not required on the cashier-facing POS.
- **Totals:** gross, taxable, tax-exempt, tax collected, vendor payout, vendor card fees, consignment, rent collected, incoming vs outgoing layaway, per-vendor payout row + **Send invoice**, payment list (cash can be **negative** = change), clerk totals.
- **Methods:** card vs cash including tax split, vendor totals, daily grid for the month.
- **Long View:** year-over-year monthly sales chart.
- **Opening Hours:** weekday × hour heatmap of sales.
- **Invalidated:** voided sales (empty on the demo).

Booth reports: pick a booth, date range, **Print checks** / **Print all**. Statement is the vendor-facing money story for staff.

Vendor payments: money **from** the vendor (demo showed $0.00 from vendor on Sep 6) — invoice/collection side of negative payout, not EMP rent receipts.

Purchases: house buys / register expenses; show in daily reports in a separate section from sales.

Audit: email a full action trail later (do not fire on a casual look-around).

## Settings, plans, processors

Store hub tiles: Settings, Customers & Marketing, Tax-Exempt Customers, Gift Certificates, Print Barcodes, Quail Subscription, Linked Apps, Clerks & Admins, Vendor Payments, Purchases, Audit, News & Updates.

Settings groups: store, sales, vendors & rent, extra features, receipts, email & password. Plain English under every field.

Notable settings: sales tax as a percent field (demo had **0.997** — treat as their UI, not a model to copy), customer card fee, alternate tax (Canada), penny rounding to 5¢, hide rent (or track in **Rent Robin**), prorate rent in booth reports, sort printed reports by booth number, time tracking, barcodes, auto-print receipts, narrow thermal receipts.

Payment methods (all on in the demo; one settings checkbox group): cash, check, card, gift certificate, Venmo, Cash App, Apple Cash, PayPal, other, Zelle, **vendor credit**. Default payment method combobox: cash / card / Check. Customer card fee helper: “Card fees aren't paid out to vendors, since they're meant to cover an expense that you as the store are paying” — dealer **4%** on the booth is a separate haircut.

Store hub version string (2026-09-10): `2026.9.08 Lago-Vista`. Setup still **90% ready**; remaining step was Add a credit card — do not click Continue Setup.

Clerks: Admin / Manager / Clerk. Demo had one admin.

Linked apps: **Quail Payments** (Payment Pigeon, ~$200 pre-paired reader, vendor invoices they can pay online) and **Square** already in a Linked section. News also talks **Stripe Terminal**. **Do not click Add card, Start setup, Connect, or Disconnect** unless asked.

News also: boothless for rent, resend rent invoices, internal reason on adjustments (hidden from vendors).

Setup checklist: “90% ready”; remaining step was **Add a credit card**. Already done: vendor, booth, vendor emails, pick a plan.

## Feel worth matching

- Large type, dark chrome, one orange primary button.
- Helper sentence under every field (“This is the vendor’s name, not their booth number”).
- **MISSING** instead of a 500 or a silent skip.
- Register is one line you can complete from a handwritten tag.
- Vendor portal is a handful of icons and a date range, not an office suite.
- Pause sale with any memorable label.
- Last-sale change popup so the cashier can make change without a new screen.

EMP already has hold, booth-tag POS, searchable catalog, and a scoped vendor home. Match the *clarity*, not the tile grid.

## Books EMP refuses

- Rent inside vendor payout / “total profit”
- **Send invoice** because payout went negative after rent
- Boothless POS as the rent receipt
- Dealer card fee haircut on `2000`
- Vendor credit as a silent payable wipe
- Payout that changes when you shrink the date range
- Gift expiry (Quail optional; EMP certificates do not expire)
- Square / Stripe / Quail Payments in this product until the operator asks

## What this does *not* change in EMP

Locked later unless asked: Square, Stripe, ACH/Zelle, QZ/Dymo, photos, layaway liability, perpetual inventory `1400`, over/short GL.

Intended next EMP batch (from floor-OS handoff): **card-on-file rent receipts** that credit `1300` and never haircut `2000`, then **vendor notifications** (Quail’s Sunday email / push is the feel to beat, not the copy).

## Leftovers (2026-09-10)

Posted money story is enough to refuse stuffed rent. Still unwalked:

- Post a check / gift / Venmo / vendor-credit tender (methods are enabled; only cash and card have posted rows). Do not start Square / Quail Payments / Payment Pigeon.
- Take another payment on the **Doll** layaway (A1, $100, **$90 remaining**, started Sep 6; Customer / Vendor chips). EMP hold is not layaway.
- Close booth (pick a date; Never mind was used on the first walk).
- Print Checks / Print All on a booth statement without mailing it (selector is a custom combobox).
- Invalidated (void) list with a real void.
- Vendor portal re-login (Sep 6 date-range lie still stands; not re-opened Sep 10).

Do not put the demo password in git. Store login is username **everythingmall** on quailhq.com (credentials live in the operator chat, not here).

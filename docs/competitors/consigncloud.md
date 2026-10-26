# Competitor: ConsignCloud

> Owned by **PC2** (`docs/competitors/`). Phrases that say “EMP” mean PC2’s books posture (two numbers; refuse rent-in-payout). See [../naming.md](../naming.md).

- Site: https://consigncloud.com/ (Next-gen app https://my.consigncloud.com/, Classic https://app.consigncloud.com/)
- Apps walked: store Next-gen (home, accounts, items, POS, sales table, reports, settings). Vendor login was off. Consignor portal not opened as the vendor. Classic `app.` not opened.
- Observed: 2026-09-06 (posted sales + `fee_charged` Rent) and **2026-09-10** (home still **Owed to Consignors $3.11**; Pay Out Accounts preview **$3.11** after optional payout fee).
- Version / plan: Next-gen SPA. Billing `plan: basic`, `status: trialing`. Banner **Your free trial ends in 10 days** (2026-09-10). Vendor login / locations / item-performance reports toast **Pro $189/location/month**. API & Webhooks labeled **Beta**.

Do not clone ConsignCloud. Beat them by keeping Saturday speed **and** posting rent and payout as separate documents. Their API already uses integer cents; their **books still mix rent into payable**, and one real sale already disagrees with itself by a penny.

Do not put trial passwords, Clerk JWTs, print-client passwords, or API keys in git. Do not start their Stripe, Gravity, Checkbook, Shopify, or Square setup from a look-around.

## How they sell

Clothing consignment **and** vendor mall. Pitch: who owns what, automatic splits, auto booth rent from vendor balance, vendor self-login, Shopify/Square, Checkbook ACH, consignor portal.

They are mid-rewrite: Next-gen (`my.`) vs Classic (`app.`). Help is still full of “(Classic)” articles. Settings → Store Data in Next-gen is **“Feature coming soon.”**

They are not a GL. There is no booth map and no booth-code uniqueness. Locations are Pro.

## Money model (balances vs books)

API money is **integer minor units** (`balance: 311` = $3.11, `tag_price: 5000` = $50). Splits and tax rates are floats (`split: 0.9`, tax `value: 0.1`). `cost` and `days_on_shelf` are still JSON numbers (`0.0`). EMP: `@emp/money` `bigint` + currency; rates are not money; never a JSON number for currency.

Append-only **balance entries** on the account. Trial vendor **Annies Boutique** (#000001):

| When | Reason | Details | Delta | Running |
|------|--------|---------|-------|---------|
| Account created | `account_created` | | $0.00 | $0.00 |
| Chair sold | `item_sold` | “$50.00 with a 90% split” | **+$45.01** | $45.01 |
| Oakly sold (10% off) | `item_sold` | “$9.00 with a 90% split” | +$8.10 | $53.11 |
| Recurring **Rent** | `fee_charged` | name `Rent`, day-of-month **6** | **−$50.00** | **$3.11** |

Same Chair **line item**: `consignor_portion: 4500`, `store_portion: 500`, `split: 0.9`, `unit_price: 5000`. Sales table COGS for that sale: **$45.00**. Sales Summary COGS for the day: **$53.10** (= $45.00 + $8.10). Dashboard “Owed to Consignors”: **$3.11** (follows the ledger, not the line).

So in one $50 / 90% sale:

- Line item ≠ payable ledger ($45.00 vs $45.01)
- Report COGS ≠ payable ledger
- After rent, the store still **owes the vendor $3.11** — rent ate payable; it did not become rent AR

EMP: sale line, `2000`, and reports are the same cents. Rent is `1300`. Payout is `2000` only. Named apply-payable-to-rent is debit `2000` / credit `1300`, never a `fee_charged` haircut.

Recurring fee on the API: `amount: 5000`, `frequency_type: "day_of_month"`, `frequency_value: 6`, `last_charged: "2026-09-06"`, `name: "Rent"`. Fired **07:00 UTC** (midnight Pacific). Marketing says weekly / biweekly / monthly; the row we saw is calendar-day-of-month. **Works on Basic** — not Pro-gated.

Payout settings add two more haircuts: **Payout Reduction %** (settlement penalty, extra balance entry) and POS **Store Credit Bonus %** (debit payable less than the credit tender). Help formula for the bonus: `bonus = payment − (payment / (bonus% + 1))`.

Glossary (their words): **Split** is always the consignor **percent**, not the dollars. **Split Price** = store portion + consignor portion. **Tag Price** = split price + inclusive tax + buyer’s fee. COGS = consignor portion + buy-outright/retail CPU.

## Cent-level options (walk these; do not copy the mix-ins)

Operator walked the option lists. Trial sales were posted with rounding **off** (`cash_rounding_adjustment: 0`).

### Cash rounding (POS → Preferences)

Combobox:

| Option | Meaning |
|--------|---------|
| **No Cash Rounding** | Default on the trial |
| **$0.05** | Nickel |
| **$0.10** | Dime |
| **$0.25** | Quarter |

Sale JSON has `cash_rounding_adjustment` in cents. Tenders are `amounts_tendered: { cash: 10000 }` (cents), plus `change`.

EMP: owner **Payments** dropdown (`1` / `5` / `10` / `25`). Cash residual rounds; difference posts to `6150`. Never haircut `2000` / `4000` / `2100`. Till close uses the same `6150` (`Till over/short`).

Quail’s “penny rounding to 5¢” is the same family. ConsignCloud at least names the increment.

### Discount who-pays-the-markdown (Settings → Discounts → Type)

% or $. Rules + days-on-shelf schedule. POS quick-action toggle.

**Type** (this is the cent policy, not the %):

1. **Shared between the store and consignor portion** (`split_type: "shared"`)
2. **Taken from the consignor portion only**
3. **Taken from the store portion only**

Observed **shared** on Oakly: tag $10.00, 10% off $1.00, 90% split → `unit_price: 1000`, `split_price: 900`, `consignor_portion: 810`, `store_portion: 90`. Markdown shared; then split of the reduced split-price. Pennies summed.

EMP: discount is amount **or** percent (`Money.times` half-even), then `Money.allocate` across remaining merchandise so pennies add up. Commission and `2000` use **discounted** merchandise. No “take the markdown only from the vendor” switch — that is another payable haircut. If a mall wants the store to eat a courtesy markdown, that is a **named discount reason** (`courtesy` | `damage` | `promo` | `other`), not a second split engine.

### Price field (Settings → Item Defaults)

| Option | Meaning (their glossary) |
|--------|--------------------------|
| **Tag Price** | What the customer sees (trial default) |
| **Split Price** | Store + consignor portions; tag may then add inclusive tax / buyer’s fee |

Chair on the trial: `tag_price` = `split_price` = 5000 (no surcharge, no inclusive tax).

EMP: one list price in minor units. Tax is basis points **after** discount, allocated with `Money.allocate`. Do not invent a second “what you type is the vendor’s take-home” input unless the operator asks — it is how Classic consign software hides tax and fees inside the tag.

### Surcharges (Settings → Surcharges → Type)

% or $. Rules + schedule. Types:

| Type | Who it hits |
|------|-------------|
| **Buyer’s Fee** | Customer / tag (glossary: part of tag price) |
| **Consignor Fee** | Vendor take |
| **Credit Card Fee** | Dealer card-fee pass-through |

Empty on the trial. EMP refuses dealer card fee on `2000` (same as Quail). Buyer’s fee is a surcharge on the customer, not rent. Consignor fee is another payable haircut — if a mall needs processing cost, name it; do not bury it in split.

### Split remainder on the sale line

90% of $50.00 is **exactly** $45.00 in integer cents (`Money.times` / allocate). ConsignCloud’s line item got that right (`4500` / `500`). The **ledger posted $45.01**. Do not guess a new rounding mode until a second odd-cent sale is captured. Treat it as: **they have a second total**. EMP has one: `Money.allocate` largest remainder so line splits + tax pennies **sum to the document**.

## Register

URL: `/sales/pos`. Tabs: Point of Sale, Sales Table, Gift Cards.

Quick actions: Add Customer, Create Item, Discounts, Gift Cards, Refunds, Taxes. Custom discount. Scan box: “Search or scan to add item…”. Select Register, Parked Sales.

Tenders on one sale: Debit/Credit (Stripe terminal or Gravity — not set up; “Swipe Now to Charge”), Cash, Store Credit (needs a customer), Gift Card (code), Check (number), Manual (memo). After pay: Print Receipt / Print Gift Receipt / Email Receipt / Finish. Receipt PDF from `templatizer.s3.amazonaws.com`.

This walkthrough the POS sat on **Loading Sale…** with pay buttons disabled (2026-09-06 and again 2026-09-10). Native POS is not as tight as the marketing. Tenders listed while stuck: Debit/Credit (“You have not yet set up integrated credit card processing” — do not click through), Cash, Store Credit (needs a customer), Gift Card, Check (number), Manual (memo). After pay: Print Receipt / Print Gift Receipt / Email Receipt / Finish.

Create item at the register exists (help). Layaway is a first-class setting: expiration days, minimum % due, reminder emails. EMP hold is **not** layaway (no money until checkout; expires end of store day).

Cash sale #000001: $50.00, tendered $100.00 cash, change $50.00, note “Test”, register null on the sale even though **Register #1** exists. Sale #000002: $9.00 after Ten Off, tendered $20.00, change $11.00.

Sales table views: All / Finalized / Voided. Columns include Subtotal, Total, **Cost of Goods Sold** (consignor portion, $45.00 / $8.10).

## Vendors, booths, rent

No booth codes. Account = person + optional company. Sequential `#000001`. Soft delete + restore (help + `deleted: null`).

**Vendor Login** (Settings → Vendors): “Allow vendors to log in, manage their own inventory, and view their sales and payouts.” Toggle **off** on Basic; sticky Pro toast. **Allow Print Client Access** on. **Customize Vendor Item Fields**. Invites table: Annie **Invite Pending**.

**Batches** (Items → Batches): All / **Draft** / **Store Review**. That is vendor intake approval. Empty on the trial.

**Recurring fees** live on the **account**, not on a booth assignment. Auto-debit `fee_charged` from payable. Payouts Summary line: **Recurring Fees Charged**.

EMP: booth code unique forever; assignment + monthly/weekly/biweekly **charge documents**; occupancy overlap rejected; restore retired `A1`; never a second `A1`.

## Vendor portal

Two products:

| Surface | Job | Trial |
|---------|-----|--------|
| **Vendor Login** | Add items, price, print, own sales/payouts | Off / Pro |
| **Consignor Portal** | Read items + balance; optional item entry | Subdomain `your-store-name.consigncloud.com`; appearance customize |

Help: vendors cannot see other vendors’ inventory or sales.

EMP: one role `vendor` bound to `party_id`. Portal shows **sales / payable `2000`** and **rent owed `1300` as two numbers**. `vendor_may_add_items` / print start **off**. House never gets a login. Do not ship a second “consignor portal” that only hides rent — mall vs consignment is `USE_CASE_MISMATCH` on rent screens, same `/vendor`.

Staff presets: **Admin, Employee, Clerk, Accountant**. Clerk is POS + basic sales reports. Accountant is read-only tables + accounting reports. EMP: owner / manager / cashier / vendor — cashier bounced off office routes.

## Reports and payouts

Reports tabs: Accounts, Sales, Items, Accounting.

- **Sales:** Today vs previous; Revenue, −COGS, Gross Profit, −Surcharges, Profit, Taxes; payment types. Trial “Yesterday” $59.00 revenue, $53.10 COGS, $5.90 profit (10% of $59 — the **line** math, not the $53.11 ledger).
- **Accounts / Payouts Summary:** Consignor portion, store credit used, refunds to credit, buy-outright credit, balance adjustments, **Recurring Fees Charged**, payouts, **Payout Penalties**, net change to consignor balance. “Today” after the rent job: fees **−$50.00**, net **−$50.00**.
- **Accounting:** **Balance Entries** (4) vs **Item Sales** (2) as two tables — they already know these drift.
- **Items / Item Performance:** Pro wall with **sample** categories (Dresses $82, etc.), not this store’s data.

Bulk **Pay Out Accounts** (opened 2026-09-10, **not confirmed**): Selected vs All, payout type (Check on this trial), register (No Register). Next step: **Payout Fee (Optional)** — “This fee will be deducted from each payout” — plus **Cut Off Date** (“Balance entries that were created after this date will not be included”). Confirm step **Payout Overview**: Subtotal (1 Account) **$3.11**, $0.00 Fee, Total **$3.11**. Generate Payout Receipts toggle. Copy: “To print checks directly from ConsignCloud, visit settings and set up Check Printing.” That $3.11 is leftover payable **after** the $50 `fee_charged` Rent, not the Chair/Oakly consignor portion. Do not click Confirm Payout.

`GET /api/v1/accounts/{id}/stats`: `balance.current` / `lifetime`, item count + total quantity, payouts, purchases (when the consignor is also a customer).

Saved **table views** with live counts (Accounts: All / Store Customers `purchases ≥ 1` / Outstanding `balance ≥ 1¢`; Items: All / Active / Unprinted / Expired / Sold).

## Settings, plans, processors

Next-gen settings tree: My Login; Payouts, Email Notifications, Vendors, Consignor Portal; Item Defaults, Categories, Shelves, Surcharges, Label Printing; Point of Sale, Discounts, Taxes, Receipt Printing; Apps, API & Webhooks; Store Details, Users, Plans & Billing, Store Data, Early Access.

Item defaults on the trial: inventory **Consignment** | Buy Outright | Retail; split **50%** store-default (Annie’s items were **90%**); terms Return to Consignor (Donate is the other glossary term); tax exempt No; no expiration; title `{{Color}} {{Brand}} {{ItemType}}, {{Size}}`; price field **Tag Price**.

Emails: auto Items Received / Returned / Sold, Layaway Reminders, Payout Receipt, Receipt, Welcome; manual Account Status, Portal Invite.

Print **client** (Windows/Mac) with a **generated password** (not the Clerk login). Labels + receipts. Receipt formats: Default / Legacy / **ESC/POS**. Auto-print, width mm, header image, show cashier, show customer balance.

Processors: Stripe Terminal, Gravity (emergepay), Square, Shopify, Checkbook ACH (`ach_ready: false`, usage 0). Marketing ACH **$2.50/payout**. PayPal bulk is a **CSV export**, not a processor.

Auth: **Clerk** → `POST /api/v1/auth/stores/{id}/select`. REST `https://api.consigncloud.com/api/v1`. Leaky bucket 100 / 10 per second. Soft deletes. Cursor pagination. Include/expand query params. Sale includes: `amounts_tendered`, `receipt_url`, cashier, register, notes. Item includes: `split_price`, `quantity`, `days_on_shelf`, tags, images.

Early access (off): improved search, **tiered splits**, portal expiration dates.

Basic $139 (3 users, 1 location). Pro $189/location: vendor access (up to 1,000), portal, automated emails, advanced reporting, unlimited users, API. Enterprise: unlimited locations/vendors/API, Slack.

## Feel worth matching

- Saved views with **counts on the chips** (Outstanding 1, Unprinted 1, Store Review).
- Account drawer: amount owed, last payout, notes, Items / Purchases / Activity.
- Inventory statement in one export.
- Discount/surcharge **rules + schedule** in one object (days on shelf).
- Batches with store review.
- Clerk vs Accountant presets.
- Print client + ESC/POS (QZ/Dymo stay later unless the operator asks).
- Helper (i) next to Cash Rounding / Store Credit Bonus.

## PC2 steals (verified)

| # | Steal | PC2 status |
|---|--------|------------|
| 1 | Cash round steps 1 / 5 / 10 / 25 (not nickel on/off) | **Done** — `cash_round_minor`, Settings dropdown, `041`, C04 |
| 2 | Items filter with count for stickers not yet printed | **Done** — `sticker_printed_at`; Items → All / Available / Need stickers / Qty 0, selected segment + lead copy |
| 3 | Vendor intake **Batches** / Store Review | **Optional todo** — not built; queue on their trial; revisit when a mall asks for vendor self-intake approval |

EMP already has hold, SKU POS, instant add, booth list + map enhancement, scoped vendor home, audit. Match the **clarity of the chips and the statement**, not the rent haircut.

## Books EMP refuses

- Recurring **Rent** as `fee_charged` against payable
- Optional **Payout Fee** deducted from each payout (wizard copy, 2026-09-10)
- Payout Reduction % / store-credit bonus as silent `2000` wipes
- Discount type “consignor portion only” / “store portion only” as a second split engine
- Credit-card surcharge on the vendor
- Line item, ledger, and COGS that disagree by a penny
- Date-range “payout” that includes rent (same sin as Quail, different UI)
- Delete+restore of tenant business rows (EMP: retire / reverse / restore; practice `DROP SCHEMA` only)
- Locations / vendor login locked behind a second SKU of the same product
- Gift expiry (they may; EMP certificates do not expire)
- Starting their Square/Stripe/Checkbook from a recon session

## What this does *not* change in EMP

The 2026-09-10 walk did not copy their books. These later shipped in named EMP plans: ACH payout ([e12-ach-payout.md](../../plans/e12-ach-payout.md)), QZ and WebUSB drawer ([e13-qz-hardware.md](../../plans/e13-qz-hardware.md)), item photos ([e15-chrome.md](../../plans/e15-chrome.md)), layaway `2210` default off ([e14-layaway.md](../../plans/e14-layaway.md)), House `1400` ([e8-statements-inventory.md](../../plans/e8-statements-inventory.md)), over/short `6150` ([e5-over-short.md](../../plans/e5-over-short.md)), cash rounding to `6150` ([cash-rounding.md](../../plans/cash-rounding.md)).

Still out until the operator asks: Stripe, Zelle, Dymo, gift expiry.

Square Terminal is already in this ship ([square.md](../square.md)).

Intended EMP answer to their vendor-mall page: role `vendor`, recurring **charge documents**, named **apply payable to rent**, vendor home with **two numbers**, booth codes that stay unique. Cash rounding posts to `6150`, not a payable dime.

## Leftovers (2026-09-10)

Posted Chair / Oakly / `fee_charged` Rent already prove the books EMP refuses. Pay Out Accounts preview ($3.11 leftover) was opened and closed without Confirm. Still unwalked:

- Next-gen POS unstuck from **Loading Sale…** (pay buttons still disabled 2026-09-10).
- Classic `app.consigncloud.com`.
- Vendor Login (Pro toast; toggle was off) and consignor portal as the vendor.
- Second odd-cent sale to confirm whether Chair **$45.01** vs line **$45.00** is a rounding mode or a one-off.
- Store Data (“Feature coming soon”) and Early Access (tiered splits, portal expiration).
- Locations (Pro). Check Printing setup.

Do not put Clerk JWTs or the trial password in git. Trial email lives in the operator chat, not here.

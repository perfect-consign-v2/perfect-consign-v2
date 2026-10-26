# Competitor: Resell4.me

> Owned by **PC2** (`docs/competitors/`). Phrases that say “EMP” mean PC2’s books posture (two numbers; refuse rent-in-payout). See [../naming.md](../naming.md).

- Site: https://www.resell4.me/ (app https://app.resell4.me/?demo=1, guide https://www.resell4.me/guide.html)
- Apps walked: public marketing, login page, **guide.html** split-screen coach over the Magnolia Consignment Co. demo. No paid signup. No completed live sale (cart math only).
- Observed: 2026-09-08 (build **2026.09.08.2**)
- Version / plan if shown: BCSS (Best Consignment Shop Software, Steve Henning, Dallas, since 2001) rewritten as a React + Supabase browser app. Public prices **$49 Essentials / $89 Pro** per store. LinkedIn ~1 employee.

Do not clone Resell4.me. Beat them by keeping a dense counter **and** integer minor-unit money with a ledger. Their loudest claims (ACH deposit, mall rent automation, omniscient “Q”) collapse when you open the product.

Do not put demo routing/account numbers or a 4-digit manager PIN in git. Do not start Stripe or QZ from a look-around.

## How they sell

This is not a new startup. It is the BCSS installed base being walked off Windows. Free migration that keeps existing barcodes is the close. Homepage price-war table attacks SimpleConsign, ConsignCloud, Ricochet, Liberty, and processing-funded “free” software. Support is the founder on an 800 number and a 903 text line.

Four public surfaces **disagree**:

| Claim | Where it is said | What the demo / code does |
|-------|------------------|---------------------------|
| ACH bank deposit, no per-payout fee, in Essentials | Homepage FAQ | **Record ACH** only. Help: Professional. Guide sidebar: Settlements are a Pro feature. Staff enter routing/account, then initiate at their own bank. Portal is told it happened. |
| Consignor portal + QuickBooks in the $49 plan | Homepage Essentials list | Login page and Settings put portal / QBO / ACH on Professional. Production QBO is IIF/CSV email, not live sync. Auto-email fires only while a browser tab is open. |
| Works offline when the internet is down | Login page, marketing tables | Service worker + IndexedDB op queue exist. Some batches refuse to post offline. Printing needs QZ Tray on the PC. |
| Q knows your shop and every feature | guide.html group 4 | Verified FAQ matcher. Shop-data question from the guide itself (“Who has the most items on the floor right now?”) failed and offered Support. Terms (when they existed) listed Anthropic; this Q answer did not use it. |
| Antique mall booth rent, auto-deduct from sales | Pro list + antique-mall landing | Booths module exists. **Demo has zero booths.** Settings copy: rent schedules, occupancy, vendor statements, rent history, optional rent deductions are a **recommended next expansion**. |
| Software can be free via Tag Bonus | BCSS buy page | Buyer fee: 10¢ added to the tag; split still on base price. Subscription is still billed. Not in the demo seed. |
| Terms of Service / Privacy on resell4.me | Homepage footer | Both URLs 404 on the walk. BCSS privacy page last updated 3 Jan 2020 covers the marketing site, not the SaaS. |
| 5-year price lock, one honest price | Homepage hero | Cannot verify a contract. Plan packaging already contradicts itself across pages. |

`guide.html` is a static HTML/JS coach overlay. Open App hides the landing page and shows a 60/40 split: iframe to `https://app.resell4.me/?demo=1&theme=midnight` plus 15 steps. No login. Themes: Midnight, Light, BCSS, Coastal. Groups: first day (sidebar, consignor, voice drop-off, labels, POS); find anything (global `/` search); day-to-day (settle, daily report, layaway, email/text); power (bulk markdowns, Record ACH, Q).

Magnolia seed (demo): 6 consignors, 10–14 active items, $1,211.98 floor, pending payouts **$186.60** to 3 clients (60% / 70% splits), gift cards $35.00 / 2 cards, all-time revenue $467.66. Sales receipts dated **Feb–Mar 2025** (stale seed). Today’s sales $0.00. Tax combined **8.250%**.

They are a boutique consignment counter (clothing, furniture, jewelry). Vendor mall is a **wording toggle**, not a floor map.

## Money model (balances vs books)

Bundled JS uses JavaScript `Number`, `parseFloat`, `toFixed(2)`, and `Math.round(t * 100)` for remainder allocation. Prices are decimal strings/floats, not integer minor units. There is **no ledger** in the demo UI — balances are row math on sold-unsettled items.

Settlement:

- Per-consignor split % (demo 55–70)
- Settle: **Print Check**, **Record ACH**, or **Record Cash**
- Batch settle all due consignors in one pass
- Optional CC fee deducted from consignor share
- Store-credit payout can add a bonus %
- Negative vendor balance: vendor owes the shop

POS probe: Vintage Leather Jacket SKU-2001 put **$67.49** in the cart. Process Sale showed **$73.06** (8.25% tax). Cash rounding for Canada exists in Settings. Completing a sale was not required to prove the cart math.

EMP: `@emp/money` `bigint` + currency. Never `Number` for currency. Rent is `1300`. Payout is `2000` only.

## Register

Dense BCSS-shaped desktop port: one left nav, no real URLs, auto-saving settings, gold action ribbon.

New Sale: scan/search, unlisted item, gift card, unlisted return, Hold, Layaway, Pop Out. Line qty/price/discount. Cart discount. Backdate sale date. Amount tendered required.

Tenders listed: Amex, Visa, MC, Discover, Debit, Cash, Check, Apple Pay, Google Pay, PayPal, Venmo, Zelle, Cash App, Chime, Gift Card, Store Credit, Consignor Balance, Other, Split.

Three money-out screens: **Disbursements** (drawer), **Daily Settlement** (till), **Settlements** (consignor) — single or batch with print preview.

Also: Reports, Email All, Gift Cards, Weekly Sales Entry, Wish List, loyalty points, store-credit settlement bonus, CC surcharge and payout deduction.

## Vendors, booths, rent

Clients / Customers / Vendors: consignors with per-person split, Portal / Settle / Msg / Delete. Buyers separate from sellers.

Inventory: Drop-Off, Quick Add, Markdown Day, Print Tags/Labels/Jewelry. Statuses: active, sold, returned, deleted, pulled. OVER HOLD aging. Consigned vs Bought.

**Booths:** empty. Occupied / Vacant / Monthly Rent KPIs at zero. Add First Booth. Antique-mall pages claim automatic rent-from-sales; Settings still lists mall ops as future work.

Retention: Inventory Delete is a soft-delete to Deleted with an 8-second undo. Clients list still exposes **Delete client**. Settings auto-save, including tax, fees, and a **4-digit manager PIN**. Expired items can move to Returned or transfer to shop ownership.

## Vendor portal

Marketed on the homepage for Essentials; login page and Settings put it on **Professional**. Not opened as a vendor on this walk.

## Reports and payouts

Daily report and settle screens exist. Record ACH is **not** a bank transfer. Print Check / Record Cash are the honest labels.

## Settings, plans, processors

Stack (public bundle): React SPA, Vite hashed chunks, Sora font, Cloudflare. Supabase `dbdujffyoxgmlnekxvqy.supabase.co`. PWA `/sw.js`; IndexedDB `routed_ops`; `localStorage` `r4_device_id`. QZ Tray 2.2.4 (hang tags, Avery 5160, Dymo, 80mm thermal, cash-drawer kick). Stripe.js Payment Element. Voice-mic chunks for talk-and-touch intake. Q = verified help articles. QBO sandbox labeled testing-only. Analytics `G-D0EZ9P1YYK`. Hosting: pair.com in old terms; app on `app.resell4.me`.

## Feel worth matching

- First-day coach beside a live app.
- Global `/` search.
- Dense counter POS with many tenders.
- Voice drop-off as a story (do not ship a mic unless asked).
- Label catalog (hang tag, jewelry, Avery, Dymo) — QZ/Dymo stay later unless the operator asks.
- Founder answering the phone is their GTM, not a product to copy.

EMP already has hold, SKU POS, and a scoped vendor home. Match **counter density** only if EMP also wants that boutique-consignment register. Their threat is **price + “we already have your BCSS file,”** not accounting correctness.

## Books EMP refuses

- JavaScript `Number` / `toFixed(2)` / `Math.round(t * 100)` for currency
- Record ACH marketed as a bank deposit
- Optional CC fee deducted from consignor share
- Store-credit payout bonus as a silent payable haircut
- 4-digit PIN as staff privacy / discount approval
- Auto-save of fee and tax settings
- Delete-client on a living account (EMP: retire / reverse / restore)
- Claiming mall rent automation while Settings still lists it as future work
- A help FAQ branded as an omniscient shop assistant
- Gift expiry (if they have it; EMP certificates do not expire)
- Starting Stripe / QZ from a recon session

## What this does *not* change in EMP

Locked unless the operator asks: Stripe, ACH/Zelle, QZ/Dymo, photos, layaway liability, perpetual `1400`, over/short GL.

Competitive frame: they sell independent clothing/furniture consignment shops a BCSS-shaped cloud at $49, with booths as a rename. EMP’s mall-native booth map, append-only ledger, and integer money are a different product.

## Leftovers

Complete a live sale on the public demo only if a later session needs posted numbers. Do not enter real bank details. Booths stay empty until they seed mall data — do not treat marketing copy as a rent model.

# Competitor: SimpleConsign

> Owned by **PC2** (`docs/competitors/`). Phrases that say “EMP” mean PC2’s books posture (two numbers; refuse rent-in-payout). See [../naming.md](../naming.md).

- Site: https://www.simpleconsign.com/ (login https://user.traxia.com/#home)
- Apps walked: public marketing + pricing; **Candy’s Consignment** Traxia demo (manager back office + Terminal 1 cashier). Consignor Access not opened (invite is email; password not set; domain/CNAME not configured). Vendor Mall Plus 3D map not on this trial.
- Observed: 2026-09-10 (two passes: store-owned sale, then dealer consignor + fee + sale + payout UI)
- Version / plan if shown: GWT client `scGwtClient` (cache `?version=15699`) plus React/MUI overlay for **New Consignor** and the consignor profile. Trial banner **Choose a Plan**; in-app picker **You will be charged after the trial.** No plan selected. Did not complete billing.

Do not clone SimpleConsign. Beat them by keeping clothing-consignment and mall Saturday speed **and** posting rent and payout as separate documents. Their marketing says “automatically deduct rent from vendor balances.” Their in-app plan picker puts **Rent Collection** on Vendor Mall Plus only. On this trial, a saved **$50 Booth rent** fee **did not debit payable** when the dealer’s item sold.

Do not put demo passwords, Manager IDs, Terminal IDs, pairing codes, or Consignor Access invites in git. Do not click **GET HELP**, **SimpleConsign Remote Support**, **Download Remote Support** (SimpleHelp), **Send Consignor Access invite**, **Reconnect QuickBooks**, **Select Plan**, or **Upgrade to Enable SimpleACH**. Do not start SimplePay / CardConnect / SimpleACH KYC from a look-around.

## How they sell

Traxia-era product (login still `user.traxia.com`). Pitch: “Sell more. Manage less.” ~3,100 shops, since 2008. Compare page names ConsignCloud, Ricochet, GoAntiquing, Quail.

Two intro funnels (public pricing, 2026-09-10):

| Funnel | Intro | Then | Claim |
|--------|-------|------|--------|
| Consignment | **$99/mo** Professional | $359/mo after 6 months **or** $50k gross, whichever first | POS, AI item entry, ACH payouts, Shopify & QuickBooks |
| Vendor mall | **$199/mo** Vendor Mall Plus | $379/mo after the same cap | 3D map, automated rent, vendor portal, managed onboarding |

List prices in the **Choose a Plan** dialog on this demo (no intro overlay in-app):

| Plan | Price | What they put on the card |
|------|-------|---------------------------|
| Basic | $159/mo | Unlimited consignors & inventory, backups, reward points, integrated processing, support |
| Standard | $259/mo | ACH consignor payments, Standard Consignor Access, Simple Price Book |
| Professional | $359/mo | Simple Photo App, dealer remote item entry, cloud printing, Shopify & QuickBooks, Store Insights |
| Vendor Mall Plus | $379/mo | Bulk import, remote vendor printing, **Rent Collection**, space management, interactive 3D map |

Add-on (marketing): Marketing & Reputation. ACH: SimpleACH ($5.01–$10,000, 2–5 days, submit before 3:30 p.m. CT).

Vendor Mall Plus marketing (MappedIn 3D): vacancies, assign/move vendors, list view synced to map, double-booking guard, vendor portal, remote print, bulk CSV, rent from **sales balance or card on file**, ACH. FAQ: not a separate product; bulk upload + rent at launch; AI insights next.

Location types on this demo (all checked): Dealer/Vendor Mall, Consignment, Retail, Thrift, Buy Outright, Auction, Seasonal/Pop Up, Wholesale, Estate Sales, Online.

They are a tagged-inventory consignment suite with a mall module bolted on. They are not a GL. There is no booth entity and no booth-code uniqueness. Rent is a recurring **consignor fee** on the same payable as sales — and on this trial it is a schedule that **does not fire on save or on sale**.

## Money model (balances vs books)

This is the EMP contrast.

**Split language is store-take.** Location default on this demo: **store split 100%** (consignors get 0% unless **Set Split** on the account). Company setting **Only Allow Flat Store Splits** was on. The New Consignor / item **Set Split Schedule** still offered **Default Percentage**, **Flat Split**, and **Tiered Split** (From/To/Store %, last band To = Max). Help copy: split is applied **after** buyer’s / item fees; **credit-card fee is charged to the consignor**.

Three consignor balances on the profile: **Current**, **Pending**, **Available Credit**. After the dealer sale below: Current **$60.00**, Pending **$0.00**, Available Credit **$60.00**, Store Credit **$0.00**, Reward Points **0**. Settings: return waiting period (demo **0 days** = “credited immediately”); default payout type **SimpleACH**; min payout $0; extra store-credit % 0.

Dashboard **Pending Payouts** tooltip (verbatim): “Amount owed to consignors. This number does not include the amount that consignors owe you.” They already know vendors can owe the store (rent / fees / negative balance) and **hide that from the headline**. Reports exist for **Consignor Monthly Fee**, **Consignor Negative Balance**, and **Consignor Negative Balance Summary**.

**Rent is a fee on payable — when it fires.** Marketing: “Automatically deduct rent from vendor balances monthly.” In-app **Add fee** on the consignor: FREQUENCY (Weekly, Biweekly, Monthly, Yearly), WHEN (Monthly → Day of Month **1st–30th** plus **31st (last day)**), amount, description placeholder **“e.g. Shelf space.”** Trial copy: “Your plan supports only one fee. Multiple fees per account are available on **Vendor Mall Plus.**” Choose a Plan lists **Rent Collection** only on Vendor Mall Plus. Settings help: **Accept Consignor Pay-Ins** for monthly fees/rent.

### Posted on this walk

Store-owned retail (not a dealer sale):

| Line | Amount |
|------|--------|
| Demo Chair retail | $25.00 |
| Tax | $0.00 |
| Cash tender (Exact Change) | $25.00 |
| Invoice | **1**, 2026-09-10 ~11:21 AM, Terminal 1, Ariel M. |
| Consignor column | empty (store-owned) |

Dealer sale (Jane Booth, store split **40%** Custom Flat):

| Line | Amount |
|------|--------|
| Vintage Lamp retail | $100.00 |
| Tax | $0.00 |
| Cash tender (Exact Change) | $100.00 |
| Invoice | **2**, 2026-09-10 12:27 PM, Terminal 1, Ariel M. |
| SKU | **C74SA4** (6 alphanumeric, same family as Chair `4ELXG3`) |
| Consignor ID on the ticket | **463550** |
| Store take (40%) | **$40.00** |
| Consignor take (60%) | **$60.00** |
| Recurring fee **Booth rent $50.00** Monthly on the **10th** | **not charged** |

Dashboard after both sales (Today / This Week / This Month all the same on a new trial day):

| Line | Amount |
|------|--------|
| STORE SPLIT | **$65.00** ($25 chair + $40 lamp) |
| CONSIGNOR SPLIT | **$60.00** |
| TOTAL | **$125.00** |
| TAX | $0.00 |
| PENDING PAYOUTS | **$60.00** (full consignor split; rent omitted) |
| STORE CREDIT | $0.00 |
| INVENTORY ITEMS | 0 |
| TRANSACTIONS | 2 |
| CONSIGNORS | 1 |
| CUSTOMERS | 1 (consignors count as customers) |
| ITEMS PER TRANSACTION | **0** (help: average items in a transaction — two one-item tickets should be 1) |
| AVG INVOICE SUB-TOTAL | **$62.50** ($125 / 2) |

Cashier Transaction footer: **Sells 2 - $125 Total $125**.

Jane grid balance **$60.00**. Account activity (after the row is selected): **9/10/2026 Vintage Lamp $60.00** — the line is the **consignor portion**, not the $100 ticket. Recurring fee is **not** a second activity row.

Dashboard *Net Sales* help (bubble charts): “Net Sales are the Total Sales less the Cost of Inventory or Consignor Split.”

EMP: rent is `1300`. Payout is `2000` only. Never net rent, CC/check/ACH fees, or “monthly fee” inside payable. Vendor home shows **sales since last payout** and **rent owed** as two numbers.

## Register

Hash `#cashier;tab=Checkout`. Manager **Log into Terminal** / **SWITCH TO Terminal 1**. Manager session jumped straight into cashier; no PIN prompt on that path. Wiki still documents a **4-digit Cashier PIN**. New User form has a **Cashier PIN** label plus **Create Back-Office Manager Login**. Cashier role copy: **Cannot give access to cashier** for Settings and Store Insights. Hidden heading **TERMINAL CLOSED FOR TRANSACTIONS** stays in the a11y tree while Checkout is usable.

Checkout is two scan boxes on one row: **Sell** (green) and **Buy** (red). Grid: Consignor, SKU, Item Name, Category, Discount, Quantity, Price, Total Price. Right rail: customer search, **Layaway** checkbox, **Current Transaction** (Discount %, Discount Total, Buy Total, Sell Total, Gift Card Total, Sub Total, Sales Tax, Grand Total), **Online Sale (No Tax)**. Buttons: Complete / Cancel / **Save Transaction** (hold), **Sell Gift Card**, **SEARCH SAVED TRANSACTIONS**. Last Transaction after a post: Total, Payment, Print Receipt Copy.

Column kebab: **Bulk Price Override**, **Show Line Entry Area**, **Hide SKU**, **Hide Consignor**.

**Store-owned walk:** typed SKU `4ELXG3` + Enter → Demo Chair $25.00, consignor empty. Complete → **Payment Information**: customer search, CASH + CHECK fields, **Add Credit Card**, **Redeem Gift Card**, $1/$5/$10/$20/$50/$100, **Exact Change**, TOTAL DUE / TENDERED / BALANCE. Receipt radios: **NO RECEIPTS**, CREDIT CARD RECEIPT ONLY, CREDIT CARD + CUSTOMER COPY. Hidden labels also list Gift Card, Store Credit, **custom payment types**. Exact Change filled cash **25.00**; Complete posted. Browser toast: **Failed to print** (popup blocker).

**Dealer walk:** SKU `C74SA4` on the ticket showed consignor **463550**, Vintage Lamp $100.00, Grand Total $100.00. Payment: Exact Change **100.00**; Add Credit Card and Redeem Gift Card **disabled** once cash covered the due. Processing overlay, then Last Transaction Total/Payment **$100.00**. Customer on the payment dialog: Available Credit, Reward Points, Marketing / Account contact prefs (email/text).

**Qty-1 oversell warning:** filling the sell box twice put **two** $100 lines (Sell Items **2**, Grand Total **$200.00**) on a qty-1 SKU. Second line had a red triangle and a truncated consignor id. Cancelled before post. They warn; they still let the ticket total $200.

Cashier tabs: Checkout, Consignment, Inventory, Customer, Returns, Transaction, Layaway, Admin.

- **Returns:** scan barcode or enter **transaction invoice ID**; Assign Customer.
- **Layaway:** customer search + New Customer; columns Layaway date, Expire date, Quantity, Total, Payments, Balance, Status.
- **Transaction:** filters Buy / Sell / Service / Returns / Voided / Layaway / Has Gift Cards; Lookup Invoice #. Cashier filters looked disabled until the grid loaded, then enabled. Invoices **1** ($25) and **2** ($100) on 9/10/2026.
- **Admin:** Close Terminal, Add Cash To Drawer, Withdraw Cash, Run End Of Day, gift-card lookup, **EDNA** print services (Not Installed / Download), **cardconnect** EMV (No EMV Devices), **SimpleHelp** remote support (do not download), Pair Price Display.

Inventory add (cashier or manager batch): **Add Store Inventory** / **Add Consignor Inventory**. Default vs Category First entry. Ctrl+I / New Item. Columns Name, Category, Condition (**New** / **Used**), Quantity, Cost/Retail. Family Group / Brand / Color / Size / UPCs. Clothing dept combobox (Women, Men, Maternity, Children, Infant, Juniors, Plus, Unisex, Girls, Boys). **Price Book Enabled**, **Do not discount**, **Memo Pricing**, Print Label, **E-Commerce Item**, drop-ship, Compare Price, MSRP. **AI-Automated Item Entry** via QR (batch window must stay open; footer **Connected**). Expire days **0** on this demo defaulted Start and Expire both to **9/10/2026**; filling Expire **12/31/2026** did **not** stick on save. Item-level **Store Split by Consignor** link opens the same Set Split Schedule (Default / Flat / Tiered). Batch footer: Total Item, Total Price, Category. SKU assigned at save.

POS is scan-the-tag, not Quail’s one empty booth line. A mall Saturday without printed SKUs is slower here unless the clerk already knows the code.

## Vendors, booths, rent

There is no booth entity. The consignor **is** the vendor. Business name is a field (**A1 Antiques** next to person name Jane Booth). Lookup code **A1**. Sequential consignor ID **463550**. **Edit consignor ID** / **Regenerate**. Portal Disabled until enabled. Pay by on the form was **Check** even though location default payout type was SimpleACH.

Saved consignor (this walk):

| Field | Value |
|-------|--------|
| Name | Jane Booth |
| Business | A1 Antiques |
| Address | 100 Main Street, Springfield, IL 62701 |
| Lookup | A1 |
| Consignor ID / portal username | **463550** |
| Portal password | Not set |
| Store split | **40% Custom Flat** |
| Pay by | Check |
| Recurring fee | **Booth rent $50.00**, Monthly, **10th**, Est. monthly total $50.00 |
| Balances after save | Current / Pending / Available Credit **$0.00** (fee did not auto-charge) |
| Balances after $100 sale | Current **$60.00**, Pending **$0.00**, Available Credit **$60.00** |

**Identification Information** (opened, closed without PII): ID Type/Number/Issuer/Expiration; personal (name, DOB, Gender); address; physical (hair, eye, race, height, weight). Pawn-style ID capture.

Profile **Simple / Detailed**. Detailed adds: mailing address, Loyalty ID, Contract picker (**-- No contract selected --**, Print Contract disabled until a contract exists), **Mark Inventory as Do Not Discount / Never Expire**, **Bulk Update Inventory Splits**, Tax Exempt + Tax ID, portal permissions **Allowed to Add / Edit / Withdraw / Print Labels**, Created date, Archive, Account Notes, Print. Multi-location: “Consignor has accounts in 1 of 1 locations” / Expand Locations / Status **Active**.

**Account Notes:** titled **Notes for A1 Antiques** (business name). Search notes, Show archived, New Note, “Add a note to track preferences, alerts, and account history.”

Inventory statuses: ACTIVE, SOLD, EXPIRED, INGRACE, DONATED, CONVERTED, RETURNED, LAYAWAY, MISSING, DAMAGED, UNAVAILABLE, INCOMING, TRANS, WITHDRAWN, ITEMS NOT SOLD, NO LABEL PRINTED. After sale: C74SA4 **SOLD**, Qty **0**, Start/Expires still **9/10/2026**.

Consignment actions: Add Consignor Inventory, Adjust Account, Pay Out, Delete Entries, Payout History, Pay Consignors With Pending Payouts, Print Labels, Print Contract.

**Adjust Consignor Account** (opened, not submitted): Amount, Reason, Date, **Submit Account Adjustment**. That is the manual payable haircut / credit.

**Pay Out** (opened, not submitted):

- Radios: **Pay out a specific amount** / **Pay out on items** (default)
- Payment Type: **SimpleACH**, **Check**, **Store Credit**
- SimpleACH on this trial: Initial/Total **0.00**, **Upgrade to enable ACH payouts/direct deposit**, copy “Optionally monetize payouts. Create new revenue streams.” Did not click Upgrade.
- Check: Initial/Total Payout Amount **60.00**, Check Date, Check Number, Item Activity row **Vintage Lamp $60.00**, Cutoff date, Print Detailed Report / Print Check, **Submit Payout at Candy's Consignment**. Rent is **not** a payout line.
- Facilitator Fee / Bank Withdrawal Amount shown on the SimpleACH layout.

Manager actions also: Inventory Audit, Dropoff Scheduling, Run End Of Day, SimpleConnect (hidden toolbar), Choose a Plan.

**3D floor / space management** was not on this trial. Marketing only (MappedIn). EMP already has a booth list plus an optional floor map; do not copy MappedIn.

## Vendor portal

**Consignor Access** subdomain `*.consignoraccess.com` (empty on this demo). Send-invite button tooltip: “Set a ConsignorAccess domain or CNAME in Settings / Options / ConsignorAccess before sending invites.” Per-consignor: Allowed to Add / Edit / Withdraw / Print Labels (all off until enabled). **Send Consignor Access invite** and **Log into Consignor Access** — did not send mail; password not set.

Marketing: vendors see balance + store credit, inventory, sales, contracts, ACH; mall vendors can print / add / edit / withdraw. Store-configured rent “flows into the portal” and is deducted from balances if enabled.

EMP’s vendor home should not show one mixed “payout after rent.”

## Reports and payouts

Manager Reports list (date-based / EOD / company-wide / custom), observed:

Cash Ins and Outs, Category Sell-Through, **Consignor Monthly Fee**, **Consignor Negative Balance**, Consignor Payout Totals, Consignor Sales, Consignor Sales Leaderboard, Account History By Consignor / By Employee, **Credit Card Surcharge**, Donated Items, Employee Sales, Expiring Items, Gift Card Balances, Hourly Sales, Inventory Adjustments, Inventory By Category, Online Sales, **Outstanding Balances By Day**, Payout Activity, Sales By Category, Sales Report, Sales Transaction Count, **Sold not in System**, End Of Day / Month, Layaway, Terminal, Terminal Payments, Transaction, **Consignor Negative Balance Summary**, Consignor, Consignor Account Activity, **Consignor Invoice**, Custom Transaction, Customer, Customer Store Credit History, Customer Notes, Inventory, Inventory Item Family, Custom Payout Activity, Sold Inventory, Sold Online Inventory, Supplier, Supplier Inventory.

The Monthly Fee / Negative Balance reports were **not executed** after the dealer sale (fee never posted, so they would be empty until a charge fires or Adjust Account posts a negative). Pay Out / SimpleACH not completed (KYC + “strong SimpleACH password” on the user wizard; Upgrade wall on this trial). Default payout type SimpleACH on a trial that has not chosen Standard+ is a settings leftover.

## Settings, plans, processors

Settings groups walked: Company, Location, Options (General, Printing, Reward Points, Consignment, Receipt, Fees, Consignor Access, Cash Rounding, Cashier/Terminal, Layaway, Resale, Payment Types, Scheduling Dropoff). Tabs seen but not fully walked: Overrides, Taxes, Terminals, Users, Labels, Categories, Tag Colors, Discounts, Data, QuickBooks (failed reconnect toast — left it), Brands, Price Book (Setup/Grading/Pricing/Reports), EMV.

Observed defaults on this demo (do not copy):

- Store split **100%**, flat, expire days 0, grace 0, after grace **Expire** (not convert to store)
- Tax store-owned inventory **on**; 0-qty store alert **on**; AI item photos **on**
- $0 retail checkout **Alert**; inventory entry **Warn**
- Buyer’s fee / item fee off; CC fee 0; check fee $0; SimpleACH fee $0; online fee 0
- Payment types: Cash, VISA, MC, AMEX, DISC, Check, StoreCredit, GiftCard, Debit + up to 5 custom
- Services off; show cost at POS off; tax override at POS off
- Photo App / Shopify pairing **None**; SimplePay not set up (red banner — did not click through)

Users: Admin / Manager / Cashier. One Admin on the demo (Ariel). Manager Login ID vs Cashier PIN. Location Access checkboxes. SimpleACH tab: verified email + strong SimpleACH password.

Stack: GWT `include.js` + `scGwtClient.nocache.js`; React/MUI dialogs for new consignor and profile (duplicate dialog nodes in the DOM — two Set Split / two Close). Print: SignalR `edna-direct.com:3362`, `ChromeTraxiaPrint`. Analytics: HubSpot, Featurebase, Zendesk, Facebook/GA. Hash tabs `#manager;tab=Dashboard|Consignment|…` and `#cashier;tab=Checkout`. Manager IDs are 6 digits; terminals `T` + 5 digits.

Wiki `wiki.traxia.com` often 500 during this walk. Help: https://help.simpleconsign.com/hc/en-us.

## Feel worth matching

- One SKU box that posts a sale in two keystrokes once the tag exists.
- Exact Change + last-transaction Total/Payment so the cashier can make change without a new screen.
- Save Transaction as named hold.
- Buy and Sell on the same ticket (buy-outright next to consignment).
- Inventory batch with keyboard (Ctrl+I) and a QR for phone photos.
- Dashboard STORE SPLIT vs CONSIGNOR SPLIT as two numbers — that split is the right *shape*; mixing rent into the consignor number is the wrong *book*.
- Consignor profile Simple/Detailed with Current vs Pending vs Available Credit as three labeled amounts.

EMP already has hold, booth-tag POS, and a scoped vendor home. Match the *scan speed* and the *two money numbers*, not Traxia chrome or a 100% store split.

## Books EMP refuses

- Rent as a recurring consignor **fee** that haircuts payable (`2000`) when it fires
- **Rent Collection** gated behind a $379 mall SKU while still selling “auto rent” on the public site
- One-fee cap on cheaper plans as a product wedge
- A saved Booth rent schedule that **does not post** on the 10th (or on save/sale) while Pending Payouts still shows the full consignor split
- Pending Payouts that **omit what consignors owe you**, plus a Negative Balance report / invoice to collect it
- Credit-card / check / SimpleACH **fees on the consignor**
- Default **100% store split**
- **Memo Pricing** as a second split story
- Dealer card fee / surcharge as a payable haircut (Credit Card Surcharge report)
- Consignor Pay-Ins as the rent receipt
- **Adjust Account** as the rent document (Amount + Reason against the same payable)
- Pay Out that lists **item activity only** (Vintage Lamp $60) with no rent line — stuffing happens later as a fee, not as a named apply-payable-to-rent
- “Optionally monetize payouts” as a product feature
- Date-range “payout” that mixes rent with sales (same family as Quail/ConsignCloud; fee did not post here so the mix was not replayed)
- Short numeric **Cashier PIN** as a second factor
- Qty-1 SKU allowed twice on the ticket (warns, still totals)
- Expire date that silently snaps back to start-date when expire days is 0
- Square / CardConnect / SimplePay / SimpleACH / Shopify / QuickBooks from this look-around

## What this does *not* change in EMP

Locked later unless asked: Stripe, ACH/Zelle, QZ/Dymo, photos, layaway liability, perpetual inventory `1400`, over/short GL, MappedIn-style 3D.

Intended contrast to keep: booth codes unique forever, two vendor numbers, rent `1300` vs payout `2000`, `@emp/money`, named apply-payable-to-rent (debit `2000` / credit `1300`) instead of `fee_charged`, card-on-file rent that credits `1300` and never haircuts payable.

## Leftovers (not required to keep the money story)

Cashier: Buy box, gift sell/redeem, Save Transaction with money, discount, layaway with a tender, return/void invoices 1–2, split tenders, Online Sale (No Tax) posted, customer on the ticket, PIN vs manager jump. Manager: Customers, Suppliers, Service, Inventory Audit, Dropoff, EOD, drawer in/out, bulk edit, Print Contract with a contract selected, Log into Consignor Access (will fail until password + CNAME). Settings: Overrides, Taxes, Terminals, Labels, Categories, Tag Colors, Discounts, Data, Brands, Price Book, EMV, cash rounding. Run Monthly Fee / Negative Balance / Payout Activity / Consignor Invoice after a fee actually posts. VMP 3D only if a later trial has that plan.

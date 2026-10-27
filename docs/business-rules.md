# Business rules (PC2)

Rules this mall (**PC2** / `perfect-consign`) enforces. Live Perfect Consign is **PC1**. EMP is archived — [naming.md](naming.md), [emp-to-pc2.md](emp-to-pc2.md).

Add a rule here when it is decided. A rule stays in this list after the code that enforces it changes.

## Vendors

A vendor is Active or Inactive. That is the vendor's own standing. It is not the same fact as which booth they hold.

A vendor with no booth is still Active. The booth column says Unassigned.

A vendor's name is a first name and a last name. A business name may be empty. When it is present, the list and the receipt show the business name. The payable id does not change.

Phone, email, and address are the vendor's contact. Each one may be empty. An email that is present has to be an email address.

Store take is a percent of the sale kept by the mall. Settings holds the mall default. Anyone who can add or edit a vendor can save a different amount. A saved amount that differs from the mall default is marked overridden. A saved amount equal to the mall default is the default, and it follows a later change to that default. The sale still posts the commission amount entered on that sale. Agreement dates, tax id, and notes may be empty. An agreement end cannot be before its start.

A vendor is tax exempt only with a resale or tax exempt number on file. That number is required when tax exempt is enabled. When tax exempt is off, the number may be empty.

A vendor with open rent cannot be made Inactive. Open rent is a positive rent receivable on a booth that vendor held. A positive vendor payable means the store owes the vendor, and that balance does not by itself block Inactive.

Booth rent can be turned off. While it is off, the mall does not post a rent charge, pay-vendors does not take rent from the check, and the booth, rent, wait list, and map pages stay closed. Turning it off is refused while a booth still owes rent.

Consignment and store stock are separate switches. At least one of booth rent, consignment, and store stock stays on. Turning consignment off is refused while a consigned item is still on hand. Turning store stock off is refused while a store item is still on hand. Days on the floor save only while consignment is on. Empty days still mean consigned items do not expire.

An item sits in a booth, on consignment, or in store stock. A booth piece and a consigned piece name a vendor. A store piece does not. A new booth piece needs booth rent on and an open assignment, and it does not expire. A consigned piece needs consignment on. A store piece needs store stock on, has no store-take override, and does not expire. A sale of a store piece credits store sales for the full net and posts no vendor payable. Tax still follows the mall rate. A cost, when present, posts cost of goods and relieves inventory in that same sale.

Buying a consigned piece is one store purchase. The item becomes store stock, the expire date clears, and the cost is the amount paid. A second buy, a buy of a booth piece, a buy of a store piece, and a buy after the item has sold are refused.

A markdown schedule is days on the floor and a percent off the original asking price. Percents increase as the days increase. Applying a step writes the prior price and the new price, then updates the asking price. Skipping a step, repeating a step, or applying a step before its day is refused. The schedule is for consigned items.

An expired consigned item that is still on hand cannot be sold until it is returned, donated, or bought. A return sets quantity to zero. A donation makes it store stock with no cost. A booth piece does not reach that gate.

The rent period starts on the cycle day, a whole number from 1 to 28. Day 1 is a calendar month. Any other day runs from that day through the day before it next month. The cycle day cannot change after a rent charge is posted.

A rent charge for the period a vendor moves in counts the days from the start date through the end of that period, and the amount truncates toward zero. Collect rent names those dates on that charge. A full period shows its charge date. A rent charge for a period the vendor leaves is the full period. Sales can pay that period's rent only up to what the vendor earned. Anything sales do not cover stays receivable and is collected at the register.

## Payouts

A payout no longer clears payable and rent against cash. Close posts a settlement: the smaller of the open payable and the open rent, with no cash. A check, a drawer payment, or a payment from the safe is a later disbursement. A vendor with nothing open gets a report and no journal. Before settlement, close applies open vendor receivable (clawback) against open payable for that vendor — the smaller of the two — with no cash.

A settled or paid run refuses a new journal dated inside its sales window. A reversal is dated after that window. Settlement, rent charged, checks already written, and rent paid in a later window are the net of those journals, reversals included. Two check disbursements cannot share a check number.

A vendor is pay, credit only, or hold. Credit only and hold are left out of a run. Rent for a credit-only vendor is paid at the desk.

A vendor memo adds to what the mall owes that vendor, or subtracts from it, with a reason. It is dated before the next payout and shows on the statement. A debit may leave the payable below zero. Later sales pay that balance down before a check. While it is below zero the check is zero. The memo does not charge or pay booth rent. A draw, a check, and any other journal still cannot leave the payable below zero.

Vendor receivable (clawback) is what that vendor owes the mall after a return or void when payable was already paid out. It is a control account by vendor. It cannot go below zero. A return after payout debits receivable for the shortfall (and payable only for what is still open). A void after payout first reclasses the shortfall onto receivable, then posts the exact reverse of the sale. The vendor screen shows “They owe us (returns)” when receivable is open.

## Inventory

An item is one item. It has a tag, a barcode, a vendor, a description, an asking price, a quantity, and an intake date. The brand may be empty. The expire date may be empty. Quantity is how many are on hand. A new item defaults to 1. An edit may be 0, and then it cannot be sold.

The tag is unique in the mall, ignoring case. A tag is at most 40 characters. The barcode is unique, ignoring case, and at most 100 characters. A blank barcode is filled with BC, the mall date, and a four-digit sequence. A description is at most 200 characters. The asking price is greater than zero.

Stickers print that barcode. The mall remembers which stickers it uses: the 30-up sheet, the 50-up sheet, a hang tag, two hang tags across, or a jewelry tag. A print can pick another. A new sheet starts at row 1, column 1. A job stops at 240 stickers. The first sticker preview for an item marks it printed. The items list shows All, Available (quantity on hand), Need stickers (never printed), and Qty 0 — each with a count. The selected filter is visible, and the page lead names what you are looking at.

The asking price is the price on the item. It is not a journal. A sale still posts the amount entered on the sale. After a sale, that asking price and that consignor stay. A return pays the consignor on the item.

The store take on an item follows the vendor. Saving the vendor's percent, or leaving the field empty, stores no override. A different percent is stored on the item and marked overridden.

The expire date is empty, or on or after the intake date. Settings can set days on the floor, from 1 to 365, while consignment is on. A new consigned item then expires that many days after intake. An empty setting means consigned items do not expire. A booth piece and a store piece do not expire.

After an item is created, the add form stays open with the same vendor and the same intake date. The next item gets a fresh expire date from days on the floor.

The consignor is chosen by custom id or name. The custom id is shown in front of the name. A brand is chosen by name. A name that is not in the mall can be added before the item is saved.

The tag, barcode, brand, description, quantity, store take, intake date, and expire date can be changed. The price and the consignor can be changed until a sale is posted. An item can be deleted only while nothing else points at it. The list says In use, and there is no delete step.

A vendor or brand that an item still names cannot be deleted. An inactive vendor can still own an item. The booth stays on the vendor’s assignment, not on the item.

## Wait list

A wait list row is a person who wants a booth when none are open. It is not a vendor and it is not a journal. Staff still create the vendor and the assignment by hand when a booth opens.

First name, last name, email, and phone are required. An email has to be an email address. Phone is at most 40 characters. Notes may be empty. Notes that are present are at most 2000 characters.

Preferred booth size is a width and a depth in whole feet, or both empty. One side without the other is refused. A size of zero is refused.

Status is Waiting, Contacted, Placed, or Declined. A new row starts at Waiting. The open list is Waiting and Contacted, oldest first. The same email cannot be Waiting or Contacted twice. After the row is Placed or Declined, that email can be added again.

A row can be edited or deleted. Deleting it does not change a vendor or a booth.

## Registers

A mall starts with one register named Register. The name is unique, ignoring case, and at most 40 characters. The last register cannot be deleted. A register that a shift points at cannot be deleted. Rename is allowed.

One shift can be open on a register. The cashier name is required. The signed-in owner is the default. Opening cash is the amount entered. A bill and coin count replaces that amount. The bills and coins are $100, $50, $20, $10, $5, $1, 25¢, 10¢, 5¢, and 1¢. Zero is allowed. An amount entered with no count is stored as the largest bills and coins that add up to it. The opening count cannot be changed. A closed shift cannot be changed or deleted.

A sale, a cash rent payment, a payout, a stored-value issue, a cash drop, a register over/short, and a void of any of those must name the open shift. A sales-tax payment credits the bank. It does not take cash from the drawer. No open register refuses the post. A sale debits cash, card, checks, a gift certificate, a gift card, or store credit. Each tender is used at most once, at least one is required, and they add up to the sale. A check sale stores the check number. Gift certificates, gift cards, and store credit are redeemed by number, and the open credit cannot go below zero.

Expected drawer cash is the opening count, plus cash sales, cash rent, and stored value sold for cash, minus cash reversals, cash payouts, and cash drops. A cash drop moves drawer cash to cash in the safe. It is not a payout. The drop date is a mall day from the day the register was opened through today. If that day is not already in an open year, the year is opened for it. A day that was closed stays closed. The Book does not ask anyone to add a period.

Close takes the counted cash you type. A bill and coin count replaces that amount. Card and checks are entered as counted amounts next to the posted totals. Store credit, gift cards, gift certificates, and cash drops are shown and are not counted again. When counted cash matches the expected drawer, close posts no difference. When it does not, close waits until the cashier confirms both amounts, then one journal moves the difference between cash and cash over/short. Card and check differences stay on the shift. Vendor payable and sales tax are never the plug.

Each closed shift has a Z-tape: both counts, the expected-cash lines, the drops, card and checks counted versus posted, and the gift certificate, gift card, and store credit totals.

## Sales

A sale is one or more items on one journal. The customer pays the final sold prices plus sales tax. Store take is the percent of that final price. Tax does not reduce what a vendor is owed.

The sold price starts at the asking price and can differ. A discount lowers it. Store take is the item's percent of the final price, otherwise the vendor's, otherwise the mall default, rounded half up to the cent. Sales tax is the mall rate on that final price, rounded the same way. A tax-exempt vendor's items have no tax.

A discount lowers the sold price. Store take is the item's percent of that final price, otherwise the vendor's, otherwise the mall default, rounded half up to the cent. The vendor is owed the rest of that final price. Percent is half up on each sold price after that line's discount. A flat amount is split across those prices, half up, and the last line takes the remainder. A line discount is that same rule on one line, stored on the line. The ticket discount applies after the line discounts. A discount cannot be more than the price. A zero discount clears it. Tax-free is this ticket only. It stores a reason and every line's tax is zero. Restore tax clears it.

A buyer on the ticket is a vendor. From their sales draws that vendor, still capped at the payable that existed before this sale.

Pay leaves the finished sale on the register: the lines, the tenders, the total, and the change. Scan, Instant Add, and the pad do nothing until New sale. The receipt is that same ticket for a later lookup, a return, or a void.

A layaway is a short payment on the sale tender screen. Cash, card, or a check under the amount due, with a customer on the ticket, debits that tender and credits layaway deposits. The items stay on the ticket and stay unsold. Each deposit and each refund is listed on the layaway receipt, and that receipt prints when the payment is taken. Gift certificates, store credit, and a draw on sales cannot hold a ticket. The sale journal, vendor payable, tax, and store take post only when the deposits and any later tenders cover the amount due. A deposit above the amount due is a sale. Cancel refunds the deposits, prints that refund, and frees the ticket.

Each tender is stored on the open ticket and used once. Cash, card, check, gift certificate, gift card, and store credit are those tenders. Complete posts when they add up to the amount due. The journal debits each tender once. Those tenders add up to the sold prices, minus the discount, plus tax, and the cash-round difference when cash is rounded. From their sales debits that vendor's payable, once, and only up to what the store already owed before this sale. The payable this sale is about to credit does not raise that cap. It credits store take once when that amount is not zero, sales tax once when that total is not zero, and one payable for each vendor who is owed at least one cent. A vendor owed nothing has no payable line. Vendor payable and sales tax are still never the plug.

Pay takes the sold quantity off the item. A void puts back the units that are still sold. A return puts its units back. Zero on hand cannot be sold. The asking price stays on the item.

A gift certificate is sold for cash, card, or a check. That journal debits the tender and credits the certificate. The balance left on a number is its credits minus its redemptions. A charge cannot take more than that remainder.

A ticket is the open cart on one register shift. It is not a journal. The shift has one open ticket. Scanning an item that is already on the open ticket adds one. Open, parked, and layaway tickets together cannot hold more than what is on hand. The sold price can change until pay. Pay posts the sale and freezes the ticket. Discard removes a ticket only before pay. Close is refused while that shift still has an open ticket.

When the mall rounds cash to a chosen step (cent, nickel, dime, or quarter), that round happens only when the sale is paid entirely in cash. The difference posts to cash rounding. It shows on the register once cash is the only payment, and on the receipt. Card, checks, stored value, and any mix of those with cash stay on the cent. Cash handed over above the rounded cash due is change. It is not a second tender, and the journal debits only the amount due.

Void from the receipt reverses the whole sale and frees every item. A second void is refused. When the payable for that sale was already paid out, void first reclasses the shortfall to vendor receivable, then posts the exact reverse. A return is its own journal for one item. It reverses that item's discount share, store take, and tax, credits one tender, and reduces vendor payable only up to what is still open — any shortfall posts to vendor receivable. That item can be sold again. The rest of the ticket stays sold. The shopper is not blocked because payable was already cleared.

Park takes the open ticket off the shift so close can finish. Recall puts it on the active shift. A parked ticket is not drawer cash. Two shifts cannot hold the same item. A parked ticket can be discarded. Its lines cannot be changed until it is recalled.

## Sales tax and reports

The monthly sales-tax worksheet reads journals and sale lines for a calendar month in the mall time zone, using posting date. Exempt sales follow the waiver stored on the ticket and a vendor who is tax exempt. Returns and voids posted in the month are subtracted. Booth rent collected, and gift certificates, gift cards, and store credit issued, are listed as non-taxable. Redemptions stay inside the sale they paid. The filing strip is gross, exempt, returns, taxable base, and tax collected. Each posted rate in the month is shown with its base. The liability strip is the opening balance, the tax collected, the tax paid, and the closing balance of sales tax payable. The worksheet is refused when collected tax disagrees with that account by one cent. The screen, the print view, and the CSV use that same query.

Sales by day, tender, vendor, and cashier, the rent roll, the payout register, and stored-value liability are named queries. Each CSV is that same query. A nightly job compares those money totals with the account balances and fails when they differ by one cent.

A saved report is a name plus a catalog choice: columns come from sales, rent, payouts, or stored value, with filters, a date range, and one grouping. It does not accept SQL. The name sales tax is reserved. A saved report cannot replace the worksheet or the nightly tie-out.

## Floor, sales, and checks

The day an item sells, the vendor gets a notice from the same net as the vendor check. The Sunday letter stays a summary. A bounce marks that email address.

The owner can file one plain-text letter to every active consignor with a usable email, or to the ones they check. A missing address and a bounced address are left out. An inactive consignor is left off the list. The letter goes out on the mail run through the mailbox in Settings.

A mall-wide sale has a start, an end, a percent, and who absorbs it. The percent cannot exceed 50. Two mall sales cannot overlap on dates. The register applies the event that covers today. Mall adds a store discount. Vendor adds a discount that reduces the vendor's price. Booth, check, and mall-sale posts record who posted them.

A floor finding is a missing tag or an item that is not where the system says. A pickup records the person and the day the goods went back to the vendor.

A booth charge beyond rent debits booth rent receivable and credits booth extras. A deposit collected at the desk debits cash and credits booth deposits. A deposit release cannot exceed the deposit still held for that booth. Year-to-date disbursements sit next to the vendor's tax id. The 1099 file waits for an accountant's field list.

The assignment is the lease: the contracted rent is locked when the stay starts and holds until that stay ends. The rent-charge posting key stays one booth and one period. A mid-period move-in prorates. The deposit stays on the booth deposits account.

The vendor's accepted terms are who may discount, how long goods stay (`keep_days`, a day count from 1 to 3650 when set), and what happens at expiry.

A cashier's discount cannot pass the stored percent ceiling, and a tax-free sale cannot pass the stored tax-free ceiling, unless a second staff member signs as themselves and their ceiling covers it. A cashier void, waive, or payout needs that second signature. The audit row names that person. An owner does not need the second signature.

A bounced check is a dated journal that debits returned checks and credits checks.

## Late fees

Late fees are a mall setting: on or off, grace days (0–28), a first fee amount, and an amount for each later fee. Turning them on records the effective date as that day. Fees start from that effective date, not from older unpaid history before the feature was enabled.

A late fee posts as its own journal: debit that booth's rent receivable, credit late-fee income. It does not reduce vendor payable. A job posts due late fees. A waive is a reversal of that fee journal. A late fee that tries to hit vendor payable is refused.

## Store use and missed sale

A store use is the mall buying a consigned piece for the business, not for resale. It posts its own journal: expense the agreed price and credit that vendor's payable. Quantity on hand falls. It is not a sale and it does not take drawer cash.

A missed sale pays the vendor their share and expenses that cost to the mall when goods left without a proper sale ticket. It posts through the ledger as `missed_sale`, not as a normal register sale.

## Commission rules

Beyond the item / vendor / mall default store-take path, commission rules can win by specificity, then priority, then price bounds. A posted sale line keeps the commission from the rule that was in force. An equal tie between rules is refused. An offline register snap may send a client commission; the post still uses the rule engine's take, not the client's figure.

## Markdown queue

A scheduled markdown can wait in a queue. The asking price changes when the re-tag is confirmed, not only when the day threshold is crossed. A parked ticket keeps the price it already captured. Changing a floor price needs an audit actor.

## Vendor portal and offline sync

A vendor portal session sees only the vendor named by `stall.vendor_id`. A missing setting, or another vendor's id, returns no rows.

An offline register snap posts the captured sold price and collected tax when the drawer is open and the item still has quantity. Duplicate snaps, a closed period, a missing item, a closed drawer, and a second register taking the last unit are refused. `journal_no` stays empty until the post commits. A poster cannot rewrite or delete a posted snap.

## Sign-in

The site root `/` is email and password. The directory maps each recovery email to one mall and one username. Owner emails and staff recovery emails share that directory. The same email cannot appear twice across malls or account kinds. A missing or wrong password fails the same way as an unknown email. Five failed tries for the same email and client address within fifteen minutes lock that pair until the window passes.

`/{slug}/login` still accepts username and password for that mall. Forgot password at `/forgot` looks up the email in the directory and, when found, starts the same reset path as the mall forgot form. The success message is the same whether the email is known or not.

An owner or staff recovery email is required for email sign-in. Setting or changing that email is refused when another sign-in already holds it. `php bin/set-owner.php` may set `STALL_OWNER_EMAIL` when planting the owner.

Password-reset and consignor magic links use `STALL_PUBLIC_ORIGIN` (not the request `Host`). Mutating POSTs need a session CSRF token. Secure cookies and HSTS follow trusted TLS only — see `docs/http-edge.md`.

## Platform admin

Platform `/admin` is for operators, not mall cashiers. An admin can sign in, reset a password, enter a store as owner or cashier, wipe a tenant, and read an append-only admin audit. Password-reset rows and admin audit events are append-only. A bad admin email is refused.

## Loyalty

Loyalty is not scheduled. Points, an earn rate, and a redemption journal stay unbuilt until the tenant says whether the mall, the vendor, or a split absorbs a redemption. Customers can already be named on a ticket.

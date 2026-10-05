# Business rules

Rules the mall enforces. Add a rule here when it is decided. A rule stays in this list after the code that enforces it changes.

## Vendors

A vendor is Active or Inactive. That is the vendor's own standing. It is not the same fact as which booth they hold.

A vendor with no booth is still Active. The booth column says Unassigned.

A vendor's name is a first name and a last name. The list shows them together.

Phone, email, and address are the vendor's contact. Each one may be empty. An email that is present has to be an email address.

Store take is a percent of the sale kept by the mall. Settings holds the mall default. Anyone who can add or edit a vendor can save a different amount. A saved amount that differs from the mall default is marked overridden. A saved amount equal to the mall default is the default, and it follows a later change to that default. The sale still posts the commission amount entered on that sale. Agreement dates, tax id, and notes may be empty. An agreement end cannot be before its start.

A vendor is tax exempt only with a resale or tax exempt number on file. That number is required when tax exempt is enabled. When tax exempt is off, the number may be empty.

A vendor with open rent cannot be made Inactive. Open rent is a positive rent receivable on a booth that vendor held. A positive vendor payable means the store owes the vendor, and that balance does not by itself block Inactive.

A rent charge for the month a vendor moves in counts the days from the start date through the end of that month, and the amount truncates toward zero. A rent charge for a month the vendor leaves is the full month.

## Payouts

A payout no longer clears payable and rent against cash. Close posts a settlement: the smaller of the open payable and the open rent, with no cash. A check, a drawer payment, or a payment from the safe is a later disbursement. A vendor with nothing open gets a report and no journal.

A settled or paid run refuses a new journal dated inside its sales window. A reversal is dated after that window. Settlement, rent charged, checks already written, and rent paid in a later window are the net of those journals, reversals included. Two check disbursements cannot share a check number.

A vendor is pay, credit only, or hold. Credit only and hold are left out of a run. Rent for a credit-only vendor is paid at the desk.

## Inventory

An item is one piece. It has a tag, a barcode, a vendor, a description, an asking price, a quantity, and an intake date. The brand may be empty. The expire date may be empty. Quantity is how many are on hand. A new piece defaults to 1. An edit may be 0, and then it cannot be sold.

The tag is unique in the mall, ignoring case. A tag is at most 40 characters. The barcode is unique, ignoring case, and at most 100 characters. A blank barcode is filled with BC, the mall date, and a four-digit sequence. A description is at most 200 characters. The asking price is greater than zero.

The asking price is the price on the piece. It is not a journal. A sale still posts the amount entered on the sale. After a sale, that asking price and that consignor stay. A return pays the consignor on the piece.

The store take on a piece follows the vendor. Saving the vendor's percent, or leaving the field empty, stores no override. A different percent is stored on the piece and marked overridden.

The expire date is empty, or on or after the intake date. Settings can set days on the floor, from 1 to 365. A new piece then expires that many days after intake. An empty setting means pieces do not expire. That setting is for a consignment store. A vendor mall leaves it empty.

After a piece is created, the add form stays open with the same vendor and the same intake date. The next piece gets a fresh expire date from days on the floor.

The consignor is chosen by custom id or name. The custom id is shown in front of the name. A brand is chosen by name. A name that is not in the mall can be added before the piece is saved.

The tag, barcode, brand, description, quantity, store take, intake date, and expire date can be changed. The price and the consignor can be changed until a sale is posted. An item can be deleted only while nothing else points at it. The list says In use, and there is no delete step.

A vendor or brand that an item still names cannot be deleted. An inactive vendor can still own a piece. The booth stays on the vendor’s assignment, not on the piece.

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

A sale is one or more pieces on one journal. The customer pays the final sold prices plus sales tax. Store take is the percent of that final price. Tax does not reduce what a vendor is owed.

The sold price starts at the asking price and can differ. A discount lowers it. Store take is the piece's percent of the final price, otherwise the vendor's, otherwise the mall default, rounded half up to the cent. Sales tax is the mall rate on that final price, rounded the same way. A tax-exempt vendor's pieces have no tax.

A discount lowers the sold price. Store take is the piece's percent of that final price, otherwise the vendor's, otherwise the mall default, rounded half up to the cent. The vendor is owed the rest of that final price. Percent is half up on each sold price after that line's discount. A flat amount is split across those prices, half up, and the last line takes the remainder. A line discount is that same rule on one line, stored on the line. The ticket discount applies after the line discounts. A discount cannot be more than the price. A zero discount clears it. Tax-free is this ticket only. It stores a reason and every line's tax is zero. Restore tax clears it.

A buyer on the ticket is a vendor. From their sales draws that vendor, still capped at the payable that existed before this sale.

Pay leaves the finished sale on the register: the lines, the tenders, the total, and the change. Scan, Instant Add, and the pad do nothing until New sale. The receipt is that same ticket for a later lookup, a return, or a void.

A layaway is a short payment on the sale tender screen. Cash, card, or a check under the amount due, with a customer on the ticket, debits that tender and credits layaway deposits. The pieces stay on the ticket and stay unsold. Each deposit and each refund is listed on the layaway receipt, and that receipt prints when the payment is taken. Gift certificates, store credit, and a draw on sales cannot hold a ticket. The sale journal, vendor payable, tax, and store take post only when the deposits and any later tenders cover the amount due. A deposit above the amount due is a sale. Cancel refunds the deposits, prints that refund, and frees the ticket.

Each tender is stored on the open ticket and used once. Cash, card, check, gift certificate, gift card, and store credit are those tenders. Complete posts when they add up to the amount due. The journal debits each tender once. Those tenders add up to the sold prices, minus the discount, plus tax, and the cash-round difference when cash is rounded. From their sales debits that vendor's payable, once, and only up to what the store already owed before this sale. The payable this sale is about to credit does not raise that cap. It credits store take once when that amount is not zero, sales tax once when that total is not zero, and one payable for each vendor who is owed at least one cent. A vendor owed nothing has no payable line. Vendor payable and sales tax are still never the plug.

Pay takes the sold quantity off the piece. A void puts back the units that are still sold. A return puts its units back. Zero on hand cannot be sold. The asking price stays on the piece.

A gift certificate is sold for cash, card, or a check. That journal debits the tender and credits the certificate. The balance left on a number is its credits minus its redemptions. A charge cannot take more than that remainder.

A ticket is the open cart on one register shift. It is not a journal. The shift has one open ticket. Scanning a piece that is already on the open ticket adds one. Open, parked, and layaway tickets together cannot hold more than what is on hand. The sold price can change until pay. Pay posts the sale and freezes the ticket. Discard removes a ticket only before pay. Close is refused while that shift still has an open ticket.

When the mall rounds cash to the nearest nickel, that round happens only when the sale is paid entirely in cash. The difference posts to cash rounding. It shows on the register once cash is the only payment, and on the receipt. Card, checks, stored value, and any mix of those with cash stay on the cent. Cash handed over above the rounded cash due is change. It is not a second tender, and the journal debits only the amount due.

Void from the receipt reverses the whole sale and frees every piece. A second void is refused. A return is its own journal for one piece. It reverses that piece's discount share, store take, tax, and vendor payable, and credits one tender. That piece can be sold again. The rest of the ticket stays sold.

Park takes the open ticket off the shift so close can finish. Recall puts it on the active shift. A parked ticket is not drawer cash. Two shifts cannot hold the same piece. A parked ticket can be discarded. Its lines cannot be changed until it is recalled.

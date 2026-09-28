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

## Inventory

An item is one piece. It has a tag, a barcode, a vendor, a description, an asking price, and an intake date. The brand may be empty. The expire date may be empty. There is no quantity.

The tag is unique in the mall, ignoring case. A tag is at most 40 characters. The barcode is unique, ignoring case, and at most 100 characters. A blank barcode is filled with BC, the mall date, and a four-digit sequence. A description is at most 200 characters. The asking price is greater than zero.

The asking price is the price on the piece. It is not a journal. A sale still posts the amount entered on the sale.

The store take on a piece follows the vendor. Saving the vendor's percent, or leaving the field empty, stores no override. A different percent is stored on the piece and marked overridden.

The expire date is empty, or on or after the intake date. Settings can set days on the floor, from 1 to 365. A new piece then expires that many days after intake. An empty setting means pieces do not expire. That setting is for a consignment store. A vendor mall leaves it empty.

After a piece is created, the add form stays open with the same vendor and the same intake date. The next piece gets a fresh expire date from days on the floor.

The consignor is chosen by custom id or name. The custom id is shown in front of the name. A brand is chosen by name. A name that is not in the mall can be added before the piece is saved.

The tag, barcode, vendor, brand, description, price, store take, intake date, and expire date can be changed. An item can be deleted only while nothing else points at it.

A vendor or brand that an item still names cannot be deleted. An inactive vendor can still own a piece. The booth stays on the vendor’s assignment, not on the piece.

## Wait list

A wait list row is a person who wants a booth when none are open. It is not a vendor and it is not a journal. Staff still create the vendor and the assignment by hand when a booth opens.

First name, last name, email, and phone are required. An email has to be an email address. Phone is at most 40 characters. Notes may be empty. Notes that are present are at most 2000 characters.

Preferred booth size is a width and a depth in whole feet, or both empty. One side without the other is refused. A size of zero is refused.

Status is Waiting, Contacted, Placed, or Declined. A new row starts at Waiting. The open list is Waiting and Contacted, oldest first. The same email cannot be Waiting or Contacted twice. After the row is Placed or Declined, that email can be added again.

A row can be edited or deleted. Deleting it does not change a vendor or a booth.

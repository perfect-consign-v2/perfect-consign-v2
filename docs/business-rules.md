# Business rules

Rules the mall enforces. Add a rule here when it is decided. A rule stays in this list after the code that enforces it changes.

## Vendors

A vendor is Active or Inactive. That is the vendor's own standing. It is not the same fact as which booth they hold.

A vendor with no booth is still Active. The booth column says Unassigned.

A vendor's name is a first name and a last name. The list shows them together.

Phone, email, and address are the vendor's contact. Each one may be empty. An email that is present has to be an email address.

Store take is a percent of the sale kept by the mall. Settings holds the mall default. Anyone who can add or edit a vendor can save a different amount. A saved amount that differs from the mall default is marked overridden. Agreement dates, tax id, and notes may be empty. An agreement end cannot be before its start.

A vendor is tax exempt only with a resale or tax exempt number on file. That number is required when tax exempt is enabled. When tax exempt is off, the number may be empty.

A vendor who owes the store cannot be made Inactive. The vendor owes the store when the vendor payable balance is negative, or when a booth that vendor held still has a positive open rent receivable. A positive vendor payable means the store owes the vendor, and that balance does not by itself block Inactive.

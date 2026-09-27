# Business rules

Rules the mall enforces. Add a rule here when it is decided. A rule stays in this list after the code that enforces it changes.

## Vendors

A vendor is Active or Inactive. That is the vendor's own standing. It is not the same fact as which booth they hold.

A vendor with no booth is still Active. The booth column says Unassigned.

A vendor who owes the store cannot be made Inactive. The vendor owes the store when the vendor payable balance is negative, or when a booth that vendor held still has a positive open rent receivable. A positive vendor payable means the store owes the vendor, and that balance does not by itself block Inactive.

Inactive is not stored yet. Marking a vendor Inactive waits until that fact is part of the mall schema. Until then every vendor on the vendors page is Active.

---
title: Party identity, customers, and the wait list
category: Floor and ops
hesk: published 2026-11-12
as_of: 2026-11-10
---

# Party identity, customers, and the wait list

## As of

2026-11-10 — Contact (phones, emails, physical and mailing addresses) lives on the party, not on the person or organization name row.

## In one sentence

Staff open identity at `/{slug}/parties`; the seller roster is `/{slug}/vendors` or `/{slug}/consignors`, a customer is `/{slug}/customers/{party id}`, and a wait-list person is `/{slug}/wait-list/{party id}`.

## When it happens

Adding a seller, adding a customer from the list or naming one on a ticket, capturing a booth wait-list person, or giving one person both the vendor role and the customer role.

## What the books / screens do

- A party is one person or one organization, not both. Roles are vendor, customer, or wait list.
- The money key stays the vendor id. The vendor row is that role's books and points at the party. Person and organization rows hold names only.
- Phones and emails are party children. A party may hold many of each; one phone and one email are primary. Labels are primary, mobile, home, work, or other. Ten-digit phones display as (xxx) xxx-xxxx.
- Addresses are party children: one physical and one mailing (street, city, state, ZIP). Mailing may be empty or the same as physical.
- `/{slug}/parties` is identity (person or organization, and which roles they hold). It is not the seller roster.
- The seller roster is `/{slug}/vendors` in a mall and `/{slug}/consignors` in a consignment-only store.
- A customer is a party with the customer role. There is no customers table. A ticket stores that party's id. Staff open `/{slug}/customers`, add at `/{slug}/customers/new`, and open one customer at `/{slug}/customers/{party id}`.
- New customer from the list, or naming someone on a sale, saves the person and the customer role when that name, primary phone, and primary email are new. The same contact opens the existing party.
- A wait-list row is keyed by the person's party. Name is on the person; primary email and phone are on the party. Staff open `/{slug}/wait-list` and `/{slug}/wait-list/{party id}`.
- A vendor (a person) can also be given the customer role. A customer who is a person can be given the vendor role. The vendor id does not change.
- Staff at `/{slug}/staff` (People → Staff) is store sign-in, not this list.

## What is refused

- An organization as a vendor, or as a wait-list person.
- An organization customer given the vendor role.
- Editing an organization customer as a first and last name.
- Treating `/{slug}/parties` as the seller roster.
- A second primary phone or primary email on the same party.

## How to check

Open Parties and confirm the title is Parties, with people and organizations and their roles, and no seller-roster heading. Open Vendors (or Consignors on a consignment-only store) and confirm that list is who sells here. On a seller edit form, add a second phone and a mailing address. On Customers, use New customer, then open that party. Open a wait-list row by party id.

## Source

`docs/business-rules.md` — Vendors; Wait list; Customers.

## Related

- Wait list (not a vendor, not a journal)
- People and mall roles
- Vendor Active vs booth assignment vs Unassigned

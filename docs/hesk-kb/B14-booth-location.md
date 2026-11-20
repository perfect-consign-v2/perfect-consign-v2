---
title: Booth location
category: Vendors and booths
hesk: published 2026-11-20
as_of: 2026-11-19
---

# Booth location

## As of

2026-11-19

## In one sentence

A booth can name where it sits, and that line shows on the booth list.

## When it happens

Adding a booth, editing a booth, or reading the booth list.

## What the books / screens do

- Location is on the new-booth form and the edit form.
- It may be empty. An empty location shows as a blank on the booth list.
- When it is present, it is one line of at most 200 characters, with no leading or trailing space and no line break.
- The booth list has a Location column between Name and Size.

## What is refused

A blank location that is only spaces, a location with a line break, a location with leading or trailing space, and a location longer than 200 characters.

## How to check

Open New booth. Location is there and is not required. Save a booth with a location: the booth list shows that text in the Location column, and Edit shows the same text. Clear it and save: the list shows a blank. A location of 201 characters stays on the form and says location is one line of at most 200 characters.

## Source

`docs/business-rules.md` — Vendors.

## Related

- Booth Active vs Inactive
- Vendor Active vs booth assignment vs Unassigned

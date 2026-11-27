---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-11-26
as_of: 2026-11-25
---

# Self-serve store signup

## As of

2026-11-25 — Nothing is built until the owner opens the emailed link and presses Set up my store. The worker then builds the store and emails when it is ready. Email confirmation can no longer be switched off. The browser solves a small check before Create store turns on. Every limit is one row in `platform.signup_rules`. Admin → Signups lists the signups with Resend link and Discard.

## In one sentence

Someone asks for a store, opens the link we email, presses one button, and gets an email a minute later saying the store is ready.

## When it happens

When Admin → Signups has "Allow public store signup" on. An operator can also create a store from Admin → Tenants → Create store, which goes through the same build queue with the owner email already confirmed, even when public signup is off.

## What the books / screens do

- Root shows Create your store only when public signup is on.
- `/signup` asks for the store name, email, and password. A rejected form keeps those fields filled and shows the error in red. Create store stays grey until the browser finishes its check (a second or two). Without JavaScript the page asks to turn it on.
- After Create store the page always says "Check your email for a link to finish setting up your store." A new email gets the link. An email that already has a store gets a note with the sign-in and forgot-password links instead. Nothing is built yet.
- The link shows a Set up my store button. Opening the link alone changes nothing.
- After the button, the page says the store is being set up and checks again by itself every five seconds. The worker builds the store, then emails "Your store is ready" with a sign-in link that fills in the email. The link page then goes to sign in.
- If the build fails, nothing is left half-built. The page says it is taking longer than it should and that an email will follow; the worker tries again.
- Admin → Signups shows each signup and where it stands, Resend link for one still waiting, Discard for one waiting, confirmed, or failed, how many unconfirmed signups were removed in the last 30 days, and any older store whose owner never confirmed the email, with Delete store.
- Admin audit records `store.signup_toggle`, `store.email_verified`, `store.ready`, `store.admin_create`, `store.signup_resend`, and `store.signup_discard`.

## What is refused

- Public signup when the switch is off.
- A link that ran out (24 hours), was replaced by a newer request, or was discarded: "That link is invalid or expired. Start again at Create your store."
- A form whose browser check is missing or already used: "Your browser did not finish its check. Try again."
- More than five requests from one address in an hour: "Too many signup attempts. Try again later."
- More than 200 requests across the site in an hour: "Signups are busy right now. Try again in an hour."
- More than three requests for one email in an hour: no more mail is sent, and the page says the same sentence.
- The web login cannot read password or link hashes, cannot write a signup, and cannot build a store.

## How to check

Turn signup on in Admin → Signups; ask for a store at `/signup`; open the mailed link and press the button; watch the page change to sign-in after the worker runs. `sql/prove_store_signup.sql` proves the rules, the state machine, and who may touch what. `tests/Integration/Feature/StoreSignupFlowTest` builds a store as the worker and checks it has the same grants as a store built by hand.

## Source

`docs/business-rules.md` (Self-serve store signup).

## Related

- Email sign-in finds the mall
- Platform admin portal
- When mail sends (web vs worker)

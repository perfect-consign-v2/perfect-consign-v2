-- Mall setting: require a second staff signature for discounts, voids, waives, payouts, and booth corrections.
-- Default false for family-run stores; turn on for multi-staff malls that use ceilings.

ALTER TABLE mall
    ADD COLUMN IF NOT EXISTS require_second_signature boolean NOT NULL DEFAULT false;

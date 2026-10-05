-- Rent collected at the counter is a receipt.
-- The tender rule, the checks source rule, and journals_rent_payment
-- live in sql/043_rent_receipt.sql, which runs after the checks account exists.
-- A mall that already applied this file still has journals_rent_payment.
-- Do not drop that trigger.

DO $$
BEGIN
    NULL;
END $$;

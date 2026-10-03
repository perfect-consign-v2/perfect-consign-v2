-- The payout source is retired. A close is a settlement. A check is a disbursement.
-- sql/041_payout_run.sql defines those journals.

CREATE OR REPLACE FUNCTION stall_assert_payout()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.source_type = 'payout' AND NOT NEW.is_reversal THEN
        RAISE EXCEPTION 'a payout journal is retired'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_payout
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_payout();

-- People lifecycle: suspend staff without deleting the row.

ALTER TABLE staff
    ADD COLUMN IF NOT EXISTS can_sign_in boolean NOT NULL DEFAULT true;

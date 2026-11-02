-- People lifecycle: suspend staff without deleting the row.

ALTER TABLE staff
    ADD COLUMN can_sign_in boolean NOT NULL DEFAULT true;

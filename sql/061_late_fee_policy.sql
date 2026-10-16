-- Late fees are off until the mall turns them on and names both amounts.
-- Grace is counted from the rent period start. Apply after 059. The provisioner rewrites stall_poster.

ALTER TABLE mall
    ADD COLUMN late_fees_enabled boolean NOT NULL DEFAULT false;

ALTER TABLE mall
    ADD COLUMN late_fee_grace_days smallint NOT NULL DEFAULT 0;

ALTER TABLE mall
    ADD COLUMN late_fee_first_minor bigint NOT NULL DEFAULT 0;

ALTER TABLE mall
    ADD COLUMN late_fee_next_minor bigint NOT NULL DEFAULT 0;

ALTER TABLE mall
    ADD CONSTRAINT mall_late_fee_grace_chk CHECK (late_fee_grace_days >= 0 AND late_fee_grace_days <= 28);

ALTER TABLE mall
    ADD CONSTRAINT mall_late_fee_first_chk CHECK (late_fee_first_minor >= 0);

ALTER TABLE mall
    ADD CONSTRAINT mall_late_fee_next_chk CHECK (late_fee_next_minor >= 0);

ALTER TABLE mall
    ADD CONSTRAINT mall_late_fee_amounts_chk CHECK (
        NOT late_fees_enabled OR (late_fee_first_minor > 0 AND late_fee_next_minor > 0)
    );

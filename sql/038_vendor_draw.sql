-- A sale may debit vendor payable once, up to what the store already owed that vendor.
-- Pending tenders sit on the open ticket. Apply after 037. The provisioner rewrites stall_poster.

CREATE TABLE IF NOT EXISTS ticket_tenders (
    ticket_id uuid NOT NULL REFERENCES tickets (id) ON DELETE CASCADE,
    role text NOT NULL,
    amount_minor bigint NOT NULL,
    reference text,
    PRIMARY KEY (ticket_id, role),
    CONSTRAINT ticket_tenders_role_chk CHECK (
        role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'vendor_draw')
    ),
    CONSTRAINT ticket_tenders_amount_chk CHECK (amount_minor > 0),
    CONSTRAINT ticket_tenders_reference_chk CHECK (
        reference IS NULL OR (reference = btrim(reference) AND char_length(reference) <= 40)
    )
);

GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_tenders TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON ticket_tenders FROM stall_poster;







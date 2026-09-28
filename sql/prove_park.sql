-- Proof that a parked ticket lets the shift close and is not drawer cash.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000009a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    2500,
    'BC-PARK',
    DATE '2092-03-01',
    0
);

INSERT INTO registers (id, name)
VALUES
    ('01900000-0000-7000-8000-000000000d98', 'Park proof'),
    ('01900000-0000-7000-8000-000000000d99', 'Recall proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d2c',
    '01900000-0000-7000-8000-000000000d98',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000009b1', '01900000-0000-7000-8000-000000000d2c');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-0000000009b1', 1, '01900000-0000-7000-8000-0000000009a1', 2500);

DO $$
BEGIN
    UPDATE register_sessions
    SET closed_at = clock_timestamp(),
        closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        counted_cash_minor = 0,
        counted_card_minor = 0,
        counted_checks_minor = 0
    WHERE id = '01900000-0000-7000-8000-000000000d2c';
    RAISE EXCEPTION 'close with an open ticket was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%close is refused while a ticket is open%' THEN
            RAISE;
        END IF;
END $$;

UPDATE tickets SET status = 'parked' WHERE id = '01900000-0000-7000-8000-0000000009b1';

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000009a2',
    'Hat',
    '01900000-0000-7000-8000-000000000101',
    'Felt hat',
    800,
    'BC-PARK-HAT',
    DATE '2092-03-01',
    0
);
INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000009b4', '01900000-0000-7000-8000-000000000d2c');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-0000000009b4', 1, '01900000-0000-7000-8000-0000000009a2', 800);
UPDATE tickets SET status = 'parked' WHERE id = '01900000-0000-7000-8000-0000000009b4';
DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-0000000009b4';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM tickets WHERE id = '01900000-0000-7000-8000-0000000009b4') THEN
        RAISE EXCEPTION 'a parked ticket was not discarded';
    END IF;
END $$;

DO $$
BEGIN
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d2c') <> 0 THEN
        RAISE EXCEPTION 'a parked ticket changed the drawer';
    END IF;
END $$;

UPDATE register_sessions
SET closed_at = clock_timestamp(),
    closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 0,
    counted_card_minor = 0,
    counted_checks_minor = 0
WHERE id = '01900000-0000-7000-8000-000000000d2c';

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d2d',
    '01900000-0000-7000-8000-000000000d99',
    'Bea',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

UPDATE tickets
SET status = 'open', session_id = '01900000-0000-7000-8000-000000000d2d'
WHERE id = '01900000-0000-7000-8000-0000000009b1';

DO $$
BEGIN
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000009b2', '01900000-0000-7000-8000-000000000d2c');
    RAISE EXCEPTION 'a closed shift accepted a ticket';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a ticket belongs on an open register%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d2e',
        '01900000-0000-7000-8000-000000000d98',
        'Cara',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000009b3', '01900000-0000-7000-8000-000000000d2e');
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES ('01900000-0000-7000-8000-0000000009b3', 1, '01900000-0000-7000-8000-0000000009a1', 2500);
    RAISE EXCEPTION 'two shifts held the same piece';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a piece is already on a ticket%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;

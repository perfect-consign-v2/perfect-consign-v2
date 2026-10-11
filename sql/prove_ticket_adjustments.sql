-- Proof that a discount lowers the sold price and store take is the percent of that price.
-- One transaction, then ROLLBACK. A second register session cannot take the same item.

BEGIN;

UPDATE mall SET tax_bps = 10000, commission_bps = 0, cash_round_nickel = false;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps, tax_exempt, tax_exempt_number)
VALUES
    ('01900000-0000-7000-8000-000000000191', 'ada-adj', 'Ada', 'Lane', 5000, false, NULL),
    ('01900000-0000-7000-8000-000000000192', 'bea-adj', 'Bea', 'Cole', 5000, true, 'EX-ADJ');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES
    ('01900000-0000-7000-8000-0000000009a1', 'Hat', '01900000-0000-7000-8000-000000000191', 'Wool hat', 1000, 'BC-ADJ-HAT', DATE '2096-03-01'),
    ('01900000-0000-7000-8000-0000000009a2', 'Coat', '01900000-0000-7000-8000-000000000191', 'Wool coat', 3000, 'BC-ADJ-COAT', DATE '2096-03-01'),
    ('01900000-0000-7000-8000-0000000009a3', 'Scarf', '01900000-0000-7000-8000-000000000191', 'Silk scarf', 1000, 'BC-ADJ-SCARF', DATE '2096-03-01'),
    ('01900000-0000-7000-8000-0000000009a4', 'Bag', '01900000-0000-7000-8000-000000000191', 'Leather bag', 3000, 'BC-ADJ-BAG', DATE '2096-03-01'),
    ('01900000-0000-7000-8000-0000000009a5', 'Mug', '01900000-0000-7000-8000-000000000191', 'Clay mug', 2000, 'BC-ADJ-MUG', DATE '2096-03-01'),
    ('01900000-0000-7000-8000-0000000009a6', 'Lamp', '01900000-0000-7000-8000-000000000192', 'Brass lamp', 2000, 'BC-ADJ-LAMP', DATE '2096-03-01');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-0000000009a7',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01', '2096-12-31', 'open'
);

INSERT INTO registers (id, name)
VALUES
    ('01900000-0000-7000-8000-000000000d98', 'Adjust proof'),
    ('01900000-0000-7000-8000-000000000d99', 'Adjust other');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES
    (
        '01900000-0000-7000-8000-000000000d2c',
        '01900000-0000-7000-8000-000000000d98',
        'Ada',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    ),
    (
        '01900000-0000-7000-8000-000000000d2d',
        '01900000-0000-7000-8000-000000000d99',
        'Bea',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000009b1', '01900000-0000-7000-8000-000000000d2c');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009b1', 1, '01900000-0000-7000-8000-0000000009a1', 1000),
    ('01900000-0000-7000-8000-0000000009b1', 2, '01900000-0000-7000-8000-0000000009a2', 3000);

DO $$
BEGIN
    UPDATE tickets
    SET discount_kind = 'amount', discount_value = 5000, discount_reason = 'manager approval'
    WHERE id = '01900000-0000-7000-8000-0000000009b1';
    RAISE EXCEPTION 'a discount above the price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the discount is more than the price%' THEN
            RAISE;
        END IF;
END $$;

UPDATE tickets
SET discount_kind = 'percent', discount_value = 10000, discount_reason = 'manager approval'
WHERE id = '01900000-0000-7000-8000-0000000009b1';

DO $$
BEGIN
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b1',
        '01900000-0000-7000-8000-0000000009a1',
        'percent',
        10000
    ) <> 1000 THEN
        RAISE EXCEPTION 'a full discount did not take the hat price';
    END IF;
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b1',
        '01900000-0000-7000-8000-0000000009a2',
        'percent',
        10000
    ) <> 3000 THEN
        RAISE EXCEPTION 'a full discount did not take the coat price';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE tickets
    SET discount_kind = 'percent', discount_value = 0, discount_reason = 'other'
    WHERE id = '01900000-0000-7000-8000-0000000009b1';
    RAISE EXCEPTION 'a zero discount was stored';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

UPDATE tickets
SET discount_kind = 'percent', discount_value = 1000, discount_reason = 'promotion'
WHERE id = '01900000-0000-7000-8000-0000000009b1';

DO $$
BEGIN
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b1',
        '01900000-0000-7000-8000-0000000009a1',
        'percent',
        1000
    ) <> 100 THEN
        RAISE EXCEPTION 'percent share on the hat was not 100';
    END IF;
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b1',
        '01900000-0000-7000-8000-0000000009a2',
        'percent',
        1000
    ) <> 300 THEN
        RAISE EXCEPTION 'percent share on the coat was not 300';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000009b5', '01900000-0000-7000-8000-000000000d2d');
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000009b5',
        1,
        '01900000-0000-7000-8000-0000000009a1',
        1000
    );
    RAISE EXCEPTION 'a second session took the hat';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an item is already on a ticket%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-000000000001',
    'sale:adjust-percent', '2096-03-01', '2096-03-01 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'sale', 'adjust-percent', 'Percent discount', 3960, 3960
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c1', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 3960, 0),
    ('01900000-0000-7000-8000-0000000009c1', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1800),
    ('01900000-0000-7000-8000-0000000009c1', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 360);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 1800, 'vendor', '01900000-0000-7000-8000-000000000191'
);

DO $$
BEGIN
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
    VALUES ('01900000-0000-7000-8000-0000000009c1', 1, '01900000-0000-7000-8000-0000000009a1', 1000, 500, 90, 0);
    RAISE EXCEPTION 'a missing discount share was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale discount does not match the ticket%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c1', 1, '01900000-0000-7000-8000-0000000009a1', 1000, 450, 90, 100),
    ('01900000-0000-7000-8000-0000000009c1', 2, '01900000-0000-7000-8000-0000000009a2', 3000, 1350, 270, 300);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c1');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000009c1'
WHERE id = '01900000-0000-7000-8000-0000000009b1';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    UPDATE tickets
    SET discount_reason = 'other'
    WHERE id = '01900000-0000-7000-8000-0000000009b1';
    RAISE EXCEPTION 'a posted discount was changed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a posted ticket cannot change%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000009c8',
        '01900000-0000-7000-8000-000000000001',
        'return:hat-full', '2096-03-02', '2096-03-02 12:00:00+00',
        '01900000-0000-7000-8000-0000000009a7',
        'USD', 'item_return', 'return-hat-full', 'Return the hat at full price', 1090, 1090
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-0000000009c8', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 500, 0),
        ('01900000-0000-7000-8000-0000000009c8', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 90, 0),
        ('01900000-0000-7000-8000-0000000009c8', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000010', 0, 1090);
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000009c8',
        '01900000-0000-7000-8000-000000000001',
        3, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-000000000191'
    );
    INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor, discount_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000009c8',
        '01900000-0000-7000-8000-0000000009c1',
        '01900000-0000-7000-8000-0000000009a1',
        1000, 500, 90, 100
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c8');
    SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'a return ignored the discount share';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a return reverses that item%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-000000000001',
    'return:hat', '2096-03-02', '2096-03-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'item_return', 'return-hat', 'Return the hat', 990, 990
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
        ('01900000-0000-7000-8000-0000000009c2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 450, 0),
    ('01900000-0000-7000-8000-0000000009c2', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 90, 0),
    ('01900000-0000-7000-8000-0000000009c2', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000010', 0, 990);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 450, 0, 'vendor', '01900000-0000-7000-8000-000000000191'
);

INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-0000000009a1',
    1000, 450, 90, 100
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c2');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000009b2', '01900000-0000-7000-8000-000000000d2c');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009b2', 1, '01900000-0000-7000-8000-0000000009a3', 1000),
    ('01900000-0000-7000-8000-0000000009b2', 2, '01900000-0000-7000-8000-0000000009a4', 3000);

UPDATE tickets
SET discount_kind = 'amount', discount_value = 100, discount_reason = 'damaged'
WHERE id = '01900000-0000-7000-8000-0000000009b2';

DO $$
BEGIN
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b2',
        '01900000-0000-7000-8000-0000000009a3',
        'amount',
        100
    ) <> 25 THEN
        RAISE EXCEPTION 'flat share on the scarf was not 25';
    END IF;
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b2',
        '01900000-0000-7000-8000-0000000009a4',
        'amount',
        100
    ) <> 75 THEN
        RAISE EXCEPTION 'flat share on the bag was not 75';
    END IF;
END $$;

UPDATE tickets
SET discount_kind = NULL, discount_value = NULL, discount_reason = NULL
WHERE id = '01900000-0000-7000-8000-0000000009b2';

DO $$
BEGIN
    IF stall_line_discount(
        '01900000-0000-7000-8000-0000000009b2',
        '01900000-0000-7000-8000-0000000009a3',
        NULL,
        NULL
    ) <> 0 THEN
        RAISE EXCEPTION 'a cleared discount still had a share';
    END IF;
END $$;

UPDATE tickets
SET discount_kind = 'amount', discount_value = 100, discount_reason = 'loyalty'
WHERE id = '01900000-0000-7000-8000-0000000009b2';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:adjust-flat', '2096-03-03', '2096-03-03 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'sale', 'adjust-flat', 'Flat discount', 4291, 4291
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c3', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 4291, 0),
    ('01900000-0000-7000-8000-0000000009c3', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1951),
    ('01900000-0000-7000-8000-0000000009c3', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 391);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c3',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 1949, 'vendor', '01900000-0000-7000-8000-000000000191'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c3', 1, '01900000-0000-7000-8000-0000000009a3', 1000, 488, 98, 25),
    ('01900000-0000-7000-8000-0000000009c3', 2, '01900000-0000-7000-8000-0000000009a4', 3000, 1463, 293, 75);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c3');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000009c3'
WHERE id = '01900000-0000-7000-8000-0000000009b2';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-000000000001',
    'sale:adjust-flat:void', '2096-03-04', '2096-03-04 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'sale', 'adjust-flat-void', 'Void the flat ticket', true,
    '01900000-0000-7000-8000-0000000009c3',
    4291, 4291
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c4', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 1951, 0),
    ('01900000-0000-7000-8000-0000000009c4', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 391, 0),
    ('01900000-0000-7000-8000-0000000009c4', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000010', 0, 4291);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 1949, 0, 'vendor', '01900000-0000-7000-8000-000000000191'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c4');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO tickets (id, session_id, tax_waiver_reason)
VALUES (
    '01900000-0000-7000-8000-0000000009b3',
    '01900000-0000-7000-8000-000000000d2c',
    'resale'
);

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-0000000009b3', 1, '01900000-0000-7000-8000-0000000009a5', 2000);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c5',
    '01900000-0000-7000-8000-000000000001',
    'sale:adjust-waiver', '2096-03-05', '2096-03-05 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'sale', 'adjust-waiver', 'Tax waived', 2000, 2000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c5', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2000, 0),
    ('01900000-0000-7000-8000-0000000009c5', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c5',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000191'
);

DO $$
BEGIN
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
    VALUES ('01900000-0000-7000-8000-0000000009c5', 1, '01900000-0000-7000-8000-0000000009a5', 2000, 1000, 200, 0);
    RAISE EXCEPTION 'tax was charged on a waived ticket';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale tax does not match the mall rate%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-0000000009c5', 1, '01900000-0000-7000-8000-0000000009a5', 2000, 1000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c5');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000009c5'
WHERE id = '01900000-0000-7000-8000-0000000009b3';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000009b4', '01900000-0000-7000-8000-000000000d2c');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-0000000009b4', 1, '01900000-0000-7000-8000-0000000009a6', 2000);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c6',
    '01900000-0000-7000-8000-000000000001',
    'sale:adjust-exempt', '2096-03-06', '2096-03-06 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a7',
    'USD', 'sale', 'adjust-exempt', 'Exempt vendor', 2000, 2000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000009c6', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2000, 0),
    ('01900000-0000-7000-8000-0000000009c6', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c6',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000192'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-0000000009c6', 1, '01900000-0000-7000-8000-0000000009a6', 2000, 1000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c6');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000009c6'
WHERE id = '01900000-0000-7000-8000-0000000009b4';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (
        SELECT tax_waiver_reason FROM tickets WHERE id = '01900000-0000-7000-8000-0000000009b4'
    ) IS NOT NULL THEN
        RAISE EXCEPTION 'an exempt vendor was treated as a waiver';
    END IF;
    IF (SELECT tax_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000009c6') <> 0 THEN
        RAISE EXCEPTION 'an exempt vendor was charged tax';
    END IF;
    IF (SELECT tax_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000009c5') <> 0 THEN
        RAISE EXCEPTION 'a waived ticket was charged tax';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE sale_lines DISABLE TRIGGER sale_lines_append_only;
    RAISE EXCEPTION 'disabling the sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE sale_lines ENABLE REPLICA TRIGGER sale_lines_append_only;
    RAISE EXCEPTION 'replica-only sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER sale_lines_append_only ON sale_lines;
    RAISE EXCEPTION 'dropping the sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the sale shape function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    BEGIN
        DELETE FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000009c1';
        RAISE EXCEPTION 'replica sale line delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a sale line cannot be changed%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        ALTER TABLE sale_lines DISABLE TRIGGER sale_lines_append_only;
        RAISE EXCEPTION 'replica disable was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF (SELECT count(*) FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000009c1') <> 2 THEN
        RAISE EXCEPTION 'a replica attack removed a sale line';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE sale_lines;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $body$
        BEGIN
            RETURN NEW;
        END;
        $body$;
        RAISE EXCEPTION 'poster replaced the sale shape function';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'poster replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'poster turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;

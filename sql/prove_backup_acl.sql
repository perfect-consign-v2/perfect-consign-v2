-- stall_backup can read a tenant table and cannot run mutators.
-- The poster role can still call stall_confirm_markdown.
-- The script rolls back.

BEGIN;

DO $$
BEGIN
    EXECUTE 'SET LOCAL ROLE stall_backup';
    PERFORM count(*) FROM public.items;

    BEGIN
        PERFORM public.stall_confirm_markdown(
            '01900000-0000-7000-8000-00000000b0a1'::uuid,
            '00000000-0000-0000-0000-000000000000'::uuid,
            'staff',
            'ada',
            'Ada',
            false
        );
        RAISE EXCEPTION 'stall_backup confirmed a markdown';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    BEGIN
        PERFORM public.stall_propose_markdowns(CURRENT_DATE);
        RAISE EXCEPTION 'stall_backup proposed markdowns';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    BEGIN
        PERFORM public.stall_vendor_open(repeat('ab', 32));
        RAISE EXCEPTION 'stall_backup opened a vendor link';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    BEGIN
        PERFORM public.stall_vendor_prefer('hold');
        RAISE EXCEPTION 'stall_backup changed a payout preference';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    BEGIN
        INSERT INTO public.markdown_proposals (item_id, step_id, proposed_minor, proposed_on)
        VALUES (
            '01900000-0000-7000-8000-00000000b0a1'::uuid,
            '01900000-0000-7000-8000-00000000b0a2'::uuid,
            100,
            CURRENT_DATE
        );
        RAISE EXCEPTION 'stall_backup inserted a markdown proposal';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
END $$;

DO $$
BEGIN
    EXECUTE 'SET LOCAL ROLE stall_poster';
    BEGIN
        PERFORM public.stall_confirm_markdown(
            '01900000-0000-7000-8000-00000000b0a1'::uuid,
            '00000000-0000-0000-0000-000000000000'::uuid,
            'staff',
            'ada',
            'Ada',
            false
        );
        RAISE EXCEPTION 'a missing markdown was confirmed';
    EXCEPTION
        WHEN insufficient_privilege THEN
            RAISE EXCEPTION 'the poster cannot confirm a markdown';
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM IS DISTINCT FROM 'that markdown is not waiting' THEN
                RAISE;
            END IF;
    END;
END $$;

ROLLBACK;

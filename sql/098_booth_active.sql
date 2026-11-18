-- A booth can be hidden from the default list, the assignment choices,
-- and the map without deleting it. A current or scheduled assignment
-- still refuses Inactive.

ALTER TABLE public.booths
    ADD COLUMN active boolean DEFAULT true NOT NULL;

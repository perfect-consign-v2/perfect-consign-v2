-- Where a booth sits in the mall. Empty stays null. A value is one
-- trimmed line of at most 200 characters.

ALTER TABLE public.booths
    ADD COLUMN location text;

ALTER TABLE public.booths
    ADD CONSTRAINT booths_location_chk CHECK ((
        (location IS NULL)
        OR (
            (location = btrim(location))
            AND (location <> ''::text)
            AND (char_length(location) <= 200)
            AND (location !~ '[\n\r]'::text)
        )
    ));

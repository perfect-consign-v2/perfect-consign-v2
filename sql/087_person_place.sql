-- City / state / ZIP on the person (street stays in address).

ALTER TABLE public.persons
    ADD COLUMN IF NOT EXISTS city text,
    ADD COLUMN IF NOT EXISTS state text,
    ADD COLUMN IF NOT EXISTS postal text;

ALTER TABLE public.persons
    DROP CONSTRAINT IF EXISTS persons_city_chk,
    DROP CONSTRAINT IF EXISTS persons_state_chk,
    DROP CONSTRAINT IF EXISTS persons_postal_chk;

ALTER TABLE public.persons
    ADD CONSTRAINT persons_city_chk CHECK (
        (city IS NULL) OR ((city = btrim(city)) AND (city <> ''::text) AND (char_length(city) <= 80))
    ),
    ADD CONSTRAINT persons_state_chk CHECK (
        (state IS NULL) OR ((state = btrim(state)) AND (state <> ''::text) AND (char_length(state) <= 40))
    ),
    ADD CONSTRAINT persons_postal_chk CHECK (
        (postal IS NULL) OR ((postal = btrim(postal)) AND (postal <> ''::text) AND (char_length(postal) <= 20))
    );

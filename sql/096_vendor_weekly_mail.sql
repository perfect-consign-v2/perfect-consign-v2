-- The Sunday vendor letter is a store switch. It starts off.
-- Sale-day notices and the rent reminder are separate and stay on.

ALTER TABLE public.mall
    ADD COLUMN vendor_weekly_mail_enabled boolean DEFAULT false NOT NULL;

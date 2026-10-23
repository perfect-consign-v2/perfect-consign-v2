-- Mall-sale dates cannot overlap. A sale percent cannot exceed 50.
-- Late fees start on the day the feature is turned on.

ALTER TABLE public.mall_sales DROP CONSTRAINT mall_sales_percent_chk;

ALTER TABLE public.mall_sales
    ADD CONSTRAINT mall_sales_percent_chk CHECK (((percent_bps > 0) AND (percent_bps <= 5000)));

ALTER TABLE public.mall_sales
    ADD CONSTRAINT mall_sales_no_overlap
    EXCLUDE USING gist (daterange(starts_on, ends_on, '[]') WITH &&);

ALTER TABLE public.mall
    ADD COLUMN late_fee_effective_on date;

UPDATE public.mall
SET late_fee_effective_on = CURRENT_DATE
WHERE late_fees_enabled;

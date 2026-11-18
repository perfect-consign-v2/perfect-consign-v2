-- A brand, color, category, size, condition, or department can be hidden
-- from the next item without taking the name off items that already use it.

ALTER TABLE public.brands
    ADD COLUMN active boolean DEFAULT true NOT NULL;

ALTER TABLE public.categories
    ADD COLUMN active boolean DEFAULT true NOT NULL;

ALTER TABLE public.colors
    ADD COLUMN active boolean DEFAULT true NOT NULL;

ALTER TABLE public.sizes
    ADD COLUMN active boolean DEFAULT true NOT NULL;

ALTER TABLE public.conditions
    ADD COLUMN active boolean DEFAULT true NOT NULL;

ALTER TABLE public.departments
    ADD COLUMN active boolean DEFAULT true NOT NULL;

-- Consignor portal runs as stall_vendor. Poster loses the vendor views.

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        GRANT stall_vendor TO stall_app;
    END IF;
    EXECUTE format('GRANT stall_vendor TO %I', current_user);
END $$;

REVOKE SELECT ON public.stall_vendor_items FROM stall_poster;
REVOKE SELECT ON public.stall_vendor_sales FROM stall_poster;
REVOKE SELECT ON public.stall_vendor_payable FROM stall_poster;
REVOKE SELECT ON public.stall_vendor_expiring FROM stall_poster;
REVOKE SELECT ON public.stall_vendor_agreement FROM stall_poster;

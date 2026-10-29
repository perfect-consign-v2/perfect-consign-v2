-- Allow Admin → App log clear to be recorded on platform.admin_audit.

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.password_change'::text,
        'admin.password_reset_request'::text,
        'admin.password_reset'::text,
        'tenant.enter_as'::text,
        'tenant.enter_as_refused'::text,
        'tenant.delete'::text,
        'tenant.delete_refused'::text,
        'store.signup_toggle'::text,
        'store.signup'::text,
        'store.admin_create'::text,
        'store.email_verified'::text,
        'app.log_clear'::text
    ]));

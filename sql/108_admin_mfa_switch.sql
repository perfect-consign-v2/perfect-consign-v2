-- A signed-in admin can turn the authenticator requirement on or off.
-- The phone secret stays. Clearing it is still owner-only.

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.mfa_enrolled'::text,
        'admin.mfa_refused'::text,
        'admin.mfa_cleared'::text,
        'admin.mfa_enabled'::text,
        'admin.mfa_disabled'::text,
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
        'app.log_clear'::text,
        'store.load'::text,
        'probe_ban_lift'::text
    ]));

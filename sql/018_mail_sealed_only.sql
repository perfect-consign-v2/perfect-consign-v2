-- A mailbox password is sealed. Plaintext is no longer a valid stored value.

ALTER TABLE public.mall DROP CONSTRAINT mall_receipt_mail_chk;

ALTER TABLE public.mall
    ADD CONSTRAINT mall_receipt_mail_chk CHECK ((
        (receipt_mail_via = ANY (ARRAY['server'::text, 'gmail'::text]))
        AND (
            (gmail_address IS NULL)
            OR (
                (gmail_address = btrim(gmail_address))
                AND (char_length(gmail_address) <= 200)
                AND (gmail_address ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text)
            )
        )
        AND (
            (gmail_app_password IS NULL)
            OR (
                (gmail_app_password = btrim(gmail_app_password))
                AND (char_length(gmail_app_password) >= 20)
                AND (char_length(gmail_app_password) <= 200)
                AND (gmail_app_password ~ '^v1:[A-Za-z0-9+/]+=*$'::text)
            )
        )
        AND (
            (receipt_mail_via = 'server'::text)
            OR ((gmail_address IS NOT NULL) AND (gmail_app_password IS NOT NULL))
        )
    ));

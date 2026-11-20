-- Permanent bans for scanner clients, and the short streak that leads there.
-- stall_app checks and writes these rows before a session starts.

CREATE TABLE platform.probe_bans (
    client_address text NOT NULL,
    banned_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    reason text NOT NULL,
    user_agent text DEFAULT ''::text NOT NULL,
    path text DEFAULT ''::text NOT NULL,
    CONSTRAINT probe_bans_address_chk CHECK (((char_length(client_address) >= 1) AND (char_length(client_address) <= 64))),
    CONSTRAINT probe_bans_agent_chk CHECK ((char_length(user_agent) <= 200)),
    CONSTRAINT probe_bans_path_chk CHECK ((char_length(path) <= 200)),
    CONSTRAINT probe_bans_reason_chk CHECK ((reason = ANY (ARRAY['scanner'::text, 'streak'::text])))
);

CREATE TABLE platform.probe_streaks (
    client_address text NOT NULL,
    streak integer NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT probe_streaks_address_chk CHECK (((char_length(client_address) >= 1) AND (char_length(client_address) <= 64))),
    CONSTRAINT probe_streaks_streak_chk CHECK ((streak >= 1))
);

ALTER TABLE ONLY platform.probe_bans
    ADD CONSTRAINT probe_bans_pkey PRIMARY KEY (client_address);

ALTER TABLE ONLY platform.probe_streaks
    ADD CONSTRAINT probe_streaks_pkey PRIMARY KEY (client_address);

GRANT SELECT, INSERT, DELETE ON TABLE platform.probe_bans TO stall_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE platform.probe_streaks TO stall_app;

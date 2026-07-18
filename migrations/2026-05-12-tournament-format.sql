-- Migration 2026-05-12
-- Adds tournament-format settings:
--   balls_per_over    — 5 or 6 (default 6 for backward compat)
--   single_group      — 1 = one big flat standings table; 0 = grouped A/B/C/…
--   hide_fixtures_tab — 1 = hide the public Fixtures tab
--
-- SAFE TO RUN ON EXISTING DATA.

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS balls_per_over    TINYINT UNSIGNED NOT NULL DEFAULT 6  AFTER overs_per_side,
    ADD COLUMN IF NOT EXISTS single_group      TINYINT(1)       NOT NULL DEFAULT 0  AFTER team_size,
    ADD COLUMN IF NOT EXISTS hide_fixtures_tab TINYINT(1)       NOT NULL DEFAULT 0  AFTER single_group;

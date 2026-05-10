-- Migration 2026-05-10
-- Adds tournament_date / subtitle / organizer to tournaments,
-- adds round_number + home_batted_first to matches,
-- renames the seeded tournament,
-- removes the placeholder teams (admin will add real teams on the day).
--
-- SAFE TO RUN ON EXISTING DATA. Run via phpMyAdmin → scorecan_db → SQL tab.

-- ----- tournaments ---------------------------------------------------------
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS subtitle        VARCHAR(180) NULL  AFTER name,
    ADD COLUMN IF NOT EXISTS organizer       VARCHAR(180) NULL  AFTER subtitle,
    ADD COLUMN IF NOT EXISTS tournament_date DATE         NULL  AFTER year;

UPDATE tournaments
SET name      = 'Peterite Cricket Carnival 2026',
    subtitle  = 'Cecil Perera Memorial Trophy',
    organizer = 'SPCOBA East Coast USA'
WHERE id = 1;

-- ----- matches -------------------------------------------------------------
ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS round_number      TINYINT UNSIGNED NULL  AFTER ground,
    ADD COLUMN IF NOT EXISTS home_batted_first TINYINT(1)  NOT NULL DEFAULT 1
        AFTER away_source_match;

-- Index for fixture-map sort.
CREATE INDEX IF NOT EXISTS idx_ground_round ON matches (ground, round_number);

-- ----- clear placeholder teams + scheduled matches -------------------------
-- (Only deletes scheduled/in-progress matches; preserves anything already 'complete'.)
DELETE FROM matches
WHERE tournament_id = 1
  AND status IN ('scheduled','in_progress');

DELETE FROM teams WHERE tournament_id = 1;

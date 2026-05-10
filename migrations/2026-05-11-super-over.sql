-- Migration 2026-05-11
-- Adds the super-over flag to matches so we can record matches that ended tied
-- in regulation but were decided by a super over (winner gets the points; main
-- match scores still count toward NRR exactly as a tie would).

ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS decided_by_super_over TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_tie;

-- Convert any 'in_progress' matches to 'scheduled' since the UI no longer offers
-- in_progress as a status.
UPDATE matches SET status = 'scheduled' WHERE status = 'in_progress';

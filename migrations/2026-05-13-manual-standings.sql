-- Migration 2026-05-13
-- Adds "manual standings" mode: the site can either calculate standings from
-- match scores (default) OR display an admin-uploaded standings snapshot.
--
-- SAFE TO RUN ON EXISTING DATA.

-- ----- tournaments ---------------------------------------------------------
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS standings_source ENUM('calculated','manual')
        NOT NULL DEFAULT 'calculated' AFTER hide_fixtures_tab;

-- ----- manual_standings ----------------------------------------------------
CREATE TABLE IF NOT EXISTS manual_standings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id INT UNSIGNED NOT NULL,
    position      SMALLINT UNSIGNED NULL,        -- rank (1 = top). NULL means "sort by points/NRR"
    team_name     VARCHAR(120) NOT NULL,
    played        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    wins          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    losses        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ties          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    points        SMALLINT NOT NULL DEFAULT 0,
    nrr           DECIMAL(6,3) NULL,
    arpw          DECIMAL(6,2) NULL,
    runs_for      INT UNSIGNED NULL,
    wickets_lost  SMALLINT UNSIGNED NULL,
    runs_against  INT UNSIGNED NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tournament_pos (tournament_id, position),
    CONSTRAINT fk_manual_tourn FOREIGN KEY (tournament_id)
        REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

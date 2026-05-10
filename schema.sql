-- scorecan.com — Peterite Cricket Carnival 2026
-- MySQL 8 / MariaDB 10.4+ compatible.
-- Idempotent: drops & recreates everything. DON'T run on production once data exists
-- (use migrations/ instead for incremental upgrades).

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS knockout_slots;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS tournaments;
DROP TABLE IF EXISTS admins;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- tournaments
-- ----------------------------------------------------------
CREATE TABLE tournaments (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(180) NOT NULL,
    subtitle        VARCHAR(180) NULL,                 -- e.g. "Cecil Perera Memorial Trophy"
    organizer       VARCHAR(180) NULL,                 -- e.g. "SPCOBA East Coast USA"
    year            SMALLINT UNSIGNED NOT NULL,
    tournament_date DATE NULL,                         -- single-day tournament date; auto-fills score-entry form
    overs_per_side  TINYINT UNSIGNED NOT NULL DEFAULT 5,
    team_size       TINYINT UNSIGNED NOT NULL DEFAULT 6,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- teams
-- ----------------------------------------------------------
CREATE TABLE teams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id   INT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    short_code      VARCHAR(8) NULL,
    group_letter    CHAR(1) NOT NULL,                     -- A..F
    seed            TINYINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_tournament_name (tournament_id, name),
    KEY idx_tournament_group (tournament_id, group_letter),
    CONSTRAINT fk_teams_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- matches
-- ----------------------------------------------------------
-- Naming: schema keeps "home"/"away" for backward compat; UI displays "Team 1"/"Team 2".
-- "team1_batted_first" tracks innings order (which side opened the batting).
CREATE TABLE matches (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id       INT UNSIGNED NOT NULL,
    stage               ENUM('group','QF','SF','F','3P') NOT NULL DEFAULT 'group',
    bracket_position    TINYINT UNSIGNED NULL,
    match_date          DATE NULL,
    ground              TINYINT UNSIGNED NULL,
    round_number        TINYINT UNSIGNED NULL,            -- 1..N within a ground (replaces time_slot)
    time_slot           VARCHAR(16) NULL,                 -- legacy; kept for backward compat

    home_team_id        INT UNSIGNED NULL,                -- aka "Team 1" in UI
    away_team_id        INT UNSIGNED NULL,                -- aka "Team 2" in UI
    home_source_match   INT UNSIGNED NULL,
    away_source_match   INT UNSIGNED NULL,

    home_batted_first   TINYINT(1) NOT NULL DEFAULT 1,    -- 1 = home (Team 1) batted first; 0 = away batted first

    home_runs           SMALLINT UNSIGNED NULL,
    home_wickets        TINYINT UNSIGNED NULL,
    home_balls_faced    SMALLINT UNSIGNED NULL,
    home_all_out        TINYINT(1) NOT NULL DEFAULT 0,

    away_runs           SMALLINT UNSIGNED NULL,
    away_wickets        TINYINT UNSIGNED NULL,
    away_balls_faced    SMALLINT UNSIGNED NULL,
    away_all_out        TINYINT(1) NOT NULL DEFAULT 0,

    winner_team_id      INT UNSIGNED NULL,
    is_tie              TINYINT(1) NOT NULL DEFAULT 0,
    status              ENUM('scheduled','in_progress','complete','no_result') NOT NULL DEFAULT 'scheduled',
    notes               TEXT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_tournament (tournament_id),
    KEY idx_match_date (match_date),
    KEY idx_stage (stage),
    KEY idx_status (status),
    KEY idx_ground_round (ground, round_number),
    CONSTRAINT fk_matches_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_home FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_away FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_winner FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- admins
-- ----------------------------------------------------------
CREATE TABLE admins (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(64) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(120) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- audit_log
-- ----------------------------------------------------------
CREATE TABLE audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id        INT UNSIGNED NULL,
    action          VARCHAR(64) NOT NULL,
    target_type     VARCHAR(32) NULL,
    target_id       INT UNSIGNED NULL,
    payload_json    JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin (admin_id),
    KEY idx_target (target_type, target_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- SEED DATA
-- ==========================================================

INSERT INTO tournaments (id, name, subtitle, organizer, year, tournament_date, overs_per_side, team_size, is_active)
VALUES (1, 'Peterite Cricket Carnival 2026', 'Cecil Perera Memorial Trophy', 'SPCOBA East Coast USA',
        2026, NULL, 5, 6, 1);

-- No teams seeded — admin adds them manually on tournament day.

-- Default admin: username `admin`, password `changeme`. CHANGE ON FIRST LOGIN via /admin/change-password.php.
INSERT INTO admins (username, password_hash, display_name) VALUES
    ('admin', '$2b$12$3YgmCoJUi0DIhNVdzunOru1rnEt93RK93PKCX7HVQoLPq0Ni07gnS', 'Default Admin');

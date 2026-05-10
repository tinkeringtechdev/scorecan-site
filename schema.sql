-- scorecan.com — St. Peter's Cricket Carnival 2026
-- MySQL 8 / MariaDB 10.4+ compatible.
-- Idempotent: drops & recreates everything. DON'T run on production once data exists.

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
    year            SMALLINT UNSIGNED NOT NULL,
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
    seed            TINYINT UNSIGNED NULL,                -- optional manual seed
    PRIMARY KEY (id),
    UNIQUE KEY ux_tournament_name (tournament_id, name),
    KEY idx_tournament_group (tournament_id, group_letter),
    CONSTRAINT fk_teams_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- matches
-- ----------------------------------------------------------
CREATE TABLE matches (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id       INT UNSIGNED NOT NULL,
    stage               ENUM('group','QF','SF','F','3P') NOT NULL DEFAULT 'group',
    bracket_position    TINYINT UNSIGNED NULL,           -- 1..4 for QF, 1..2 for SF, 1 for F
    match_date          DATE NULL,
    ground              TINYINT UNSIGNED NULL,           -- 1..N
    time_slot           VARCHAR(16) NULL,                -- e.g. "08:00"

    home_team_id        INT UNSIGNED NULL,
    away_team_id        INT UNSIGNED NULL,
    -- Knockouts: instead of fixed team IDs, reference winners of earlier matches
    home_source_match   INT UNSIGNED NULL,
    away_source_match   INT UNSIGNED NULL,

    home_runs           SMALLINT UNSIGNED NULL,
    home_wickets        TINYINT UNSIGNED NULL,
    home_balls_faced    SMALLINT UNSIGNED NULL,           -- overs * 6 + extra
    home_all_out        TINYINT(1) NOT NULL DEFAULT 0,    -- if 1, balls treated as full quota for NRR

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
-- audit_log — every match save (and a few other ops) gets a row here
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

-- One tournament: St. Peter's Cricket Carnival 2026
INSERT INTO tournaments (id, name, year, overs_per_side, team_size, is_active)
VALUES (1, "St. Peter's Cricket Carnival 2026", 2026, 5, 6, 1);

-- 16 placeholder teams across groups A–D (rename via admin UI when team list is finalised).
INSERT INTO teams (tournament_id, name, group_letter, seed) VALUES
    (1, 'Team A1', 'A', 1), (1, 'Team A2', 'A', 2), (1, 'Team A3', 'A', 3), (1, 'Team A4', 'A', 4),
    (1, 'Team B1', 'B', 1), (1, 'Team B2', 'B', 2), (1, 'Team B3', 'B', 3), (1, 'Team B4', 'B', 4),
    (1, 'Team C1', 'C', 1), (1, 'Team C2', 'C', 2), (1, 'Team C3', 'C', 3), (1, 'Team C4', 'C', 4),
    (1, 'Team D1', 'D', 1), (1, 'Team D2', 'D', 2), (1, 'Team D3', 'D', 3), (1, 'Team D4', 'D', 4);

-- Default admin: username `admin`, password `changeme`. CHANGE ON FIRST LOGIN.
INSERT INTO admins (username, password_hash, display_name) VALUES
    ('admin', '$2b$12$3YgmCoJUi0DIhNVdzunOru1rnEt93RK93PKCX7HVQoLPq0Ni07gnS', 'Default Admin');

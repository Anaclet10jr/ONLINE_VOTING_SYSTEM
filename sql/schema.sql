-- ============================================================================
-- Secure Online Voting Management System (SOVMS)
-- Database schema v1.0
-- Target: MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4)
-- Usage (XAMPP):
--   1. Open phpMyAdmin -> Import -> select this file, OR
--   2. mysql -u root -p < sql/schema.sql
-- WARNING: Drops existing database 'sovms'. Use only on dev/empty systems.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS sovms;
CREATE DATABASE sovms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sovms;

-- ----------------------------------------------------------------------------
-- 1. roles : lookup table for RBAC role names
-- ----------------------------------------------------------------------------
CREATE TABLE roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(30)  NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. users : authentication identity + role + status
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id            INT UNSIGNED NOT NULL,
    full_name          VARCHAR(120) NOT NULL,
    email              VARCHAR(190) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NOT NULL,
    status             ENUM('pending','active','suspended','locked') NOT NULL DEFAULT 'pending',
    mfa_enabled        TINYINT(1)   NOT NULL DEFAULT 0,
    mfa_secret         VARCHAR(128) NULL,
    avatar             VARCHAR(255) NULL,
    last_login_at      DATETIME     NULL,
    last_login_ip      VARCHAR(45)  NULL,
    failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until       DATETIME     NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_users_role   (role_id),
    INDEX idx_users_status (status),
    INDEX idx_users_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. voters : voter profile linked to a user account
-- ----------------------------------------------------------------------------
CREATE TABLE voters (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL UNIQUE,
    voter_card_no    VARCHAR(40)  NOT NULL UNIQUE,
    national_id_hash VARCHAR(64)  NULL,
    date_of_birth    DATE         NULL,
    phone            VARCHAR(30)  NULL,
    address          TEXT         NULL,
    is_verified      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_voters_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_voters_card (voter_card_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. elections : election definition, schedule and state machine
-- ----------------------------------------------------------------------------
CREATE TABLE elections (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(150) NOT NULL,
    description   TEXT         NULL,
    start_at      DATETIME     NOT NULL,
    end_at        DATETIME     NOT NULL,
    status        ENUM('draft','scheduled','active','closed','published','cancelled')
                  NOT NULL DEFAULT 'draft',
    eligibility_mode ENUM('all_voters','selected') NOT NULL DEFAULT 'all_voters',
    created_by    INT UNSIGNED NOT NULL,
    published_by  INT UNSIGNED NULL,
    published_at  DATETIME     NULL,
    results_public TINYINT(1)  NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_elections_creator  FOREIGN KEY (created_by)   REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_elections_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_elections_status (status),
    INDEX idx_elections_start  (start_at),
    INDEX idx_elections_end    (end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. candidates : candidate master record (person)
-- ----------------------------------------------------------------------------
CREATE TABLE candidates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(120) NOT NULL,
    bio        TEXT         NULL,
    photo      VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_candidates_creator FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_candidates_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ----------------------------------------------------------------------------
-- 6. election_candidates : candidate participation in an election (approval workflow)
-- ----------------------------------------------------------------------------
CREATE TABLE election_candidates (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    election_id          INT UNSIGNED NOT NULL,
    candidate_id         INT UNSIGNED NOT NULL,
    manifesto            TEXT         NULL,
    position_order       INT UNSIGNED NOT NULL DEFAULT 0,
    status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by          INT UNSIGNED NULL,
    approved_at          DATETIME     NULL,
    rejection_reason     VARCHAR(255) NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ec_election   FOREIGN KEY (election_id)  REFERENCES elections(id)  ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ec_candidate  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ec_approver   FOREIGN KEY (approved_by)  REFERENCES users(id)     ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_ec (election_id, candidate_id),
    INDEX idx_ec_election (election_id),
    INDEX idx_ec_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. election_managers : scope which managers may access which elections
-- ----------------------------------------------------------------------------
CREATE TABLE election_managers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    election_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_em_user     FOREIGN KEY (user_id)     REFERENCES users(id)      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_em_election FOREIGN KEY (election_id) REFERENCES elections(id)  ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_em_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)      ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_em (user_id, election_id),
    INDEX idx_em_election (election_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. voter_elections : eligibility + "has voted" flag. NO candidate reference.
-- ----------------------------------------------------------------------------
CREATE TABLE voter_elections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voter_id        INT UNSIGNED NOT NULL,
    election_id     INT UNSIGNED NOT NULL,
    is_eligible     TINYINT(1)   NOT NULL DEFAULT 1,
    eligibility_note VARCHAR(255) NULL,
    voted_at        DATETIME     NULL,
    voted_ip_hash   VARCHAR(64)  NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ve_voter    FOREIGN KEY (voter_id)    REFERENCES voters(id)    ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ve_election FOREIGN KEY (election_id) REFERENCES elections(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_ve (voter_id, election_id),
    INDEX idx_ve_election (election_id),
    INDEX idx_ve_voted    (voted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. ballots : ANONYMOUS vote. No voter/user foreign key (ballot secrecy).
-- ----------------------------------------------------------------------------
CREATE TABLE ballots (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    election_id           INT UNSIGNED    NOT NULL,
    election_candidate_id INT UNSIGNED    NOT NULL,
    receipt_code          VARCHAR(64)     NOT NULL UNIQUE,
    cast_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cast_ip_hash          VARCHAR(64)     NULL,
    CONSTRAINT fk_b_election  FOREIGN KEY (election_id)           REFERENCES elections(id)           ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_b_candidate FOREIGN KEY (election_candidate_id) REFERENCES election_candidates(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_b_election  (election_id),
    INDEX idx_b_candidate (election_candidate_id),
    INDEX idx_b_cast_at   (cast_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. audit_logs : tamper-evident action log (no voter<->candidate linkage)
-- ----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80)  NOT NULL,
    entity_type VARCHAR(40)  NULL,
    entity_id   INT UNSIGNED NULL,
    details     TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_al_user    (user_id),
    INDEX idx_al_action  (action),
    INDEX idx_al_entity  (entity_type, entity_id),
    INDEX idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. login_attempts : brute-force / rate-limit tracking
-- ----------------------------------------------------------------------------
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NULL,
    ip_address VARCHAR(45)  NOT NULL,
    success    TINYINT(1)   NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_email   (email),
    INDEX idx_la_ip      (ip_address),
    INDEX idx_la_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. notifications : in-app notification queue
-- ----------------------------------------------------------------------------
CREATE TABLE notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(150) NOT NULL,
    body       TEXT         NOT NULL,
    type       ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. password_resets : single-use, time-limited reset tokens (stored hashed)
-- ----------------------------------------------------------------------------
CREATE TABLE password_resets (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_pr_user    (user_id),
    INDEX idx_pr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. email_verifications : email verification tokens (stored hashed)
-- ----------------------------------------------------------------------------
CREATE TABLE email_verifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    verified_at DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_ev_user    (user_id),
    INDEX idx_ev_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. system_settings : key/value configuration store
-- ----------------------------------------------------------------------------
CREATE TABLE system_settings (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`      VARCHAR(80) NOT NULL UNIQUE,
    `value`    TEXT        NULL,
    type       ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ss_user FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 16. backup_records : backup audit trail
-- ----------------------------------------------------------------------------
CREATE TABLE backup_records (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename        VARCHAR(255) NOT NULL,
    filepath        VARCHAR(500) NOT NULL,
    size_bytes      BIGINT UNSIGNED NULL,
    checksum_sha256 CHAR(64)     NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at     DATETIME     NULL,
    notes           TEXT         NULL,
    CONSTRAINT fk_br_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_br_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 17. mfa_recovery_codes : one-time recovery codes for privileged accounts
-- ----------------------------------------------------------------------------
CREATE TABLE mfa_recovery_codes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mrc_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mrc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA
-- ============================================================================

INSERT INTO roles (name, description) VALUES
  ('admin',   'System administrator with full privileges'),
  ('manager', 'Election manager scoped to assigned elections'),
  ('voter',   'Registered voter');

-- Default admin account.
-- Email: admin@sovms.local
-- Password: admin@123 
-- !! CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN !!
INSERT INTO users (role_id, full_name, email, password_hash, status, mfa_enabled)
SELECT r.id, 'System Administrator', 'admin@sovms.local',
       '$2y$10$KDzlw7P/BIoBS9kPZSAtHum/20nwUQWnSosSUY3BKP1cu737gHEoe',
       'active', 0
FROM roles r WHERE r.name = 'admin';

INSERT INTO system_settings (`key`, `value`, type) VALUES
  ('site_name',               'Secure Online Voting Management System', 'string'),
  ('app_timezone',            'Asia/Manila',                            'string'),
  ('maintenance_mode',        '0',                                      'bool'),
  ('max_upload_bytes',        '2097152',                                'int'),
  ('election_auto_transition','1',                                      'bool'),
  ('session_timeout_minutes', '30',                                      'int');

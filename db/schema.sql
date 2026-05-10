-- Serenity Spaces — Database Schema
-- Fresh install: mysql -u root serenityspaces < db/schema.sql

CREATE TABLE IF NOT EXISTS practitioners (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(255) DEFAULT NULL,
    avatar_path     VARCHAR(512) DEFAULT NULL,
    is_admin        TINYINT(1)   NOT NULL DEFAULT 0,
    hl_color_1      VARCHAR(7)   NOT NULL DEFAULT '#f5e24a',
    hl_color_2      VARCHAR(7)   NOT NULL DEFAULT '#f48fb1',
    hl_color_3      VARCHAR(7)   NOT NULL DEFAULT '#64b5f6',
    about_work      TEXT         DEFAULT NULL,
    practice_types  JSON         DEFAULT NULL,
    timezone        VARCHAR(64)  NOT NULL DEFAULT 'UTC',
    gender          VARCHAR(32)  DEFAULT NULL,
    sexuality       VARCHAR(32)  DEFAULT NULL,
    date_of_birth   DATE         DEFAULT NULL,
    location        VARCHAR(64)  DEFAULT NULL,          -- ISO 3166-1 alpha-2 country code
    show_gender     TINYINT(1)   NOT NULL DEFAULT 0,
    show_sexuality  TINYINT(1)   NOT NULL DEFAULT 0,
    show_age        TINYINT(1)   NOT NULL DEFAULT 0,
    show_location   TINYINT(1)   NOT NULL DEFAULT 0,
    inclusivity_tags JSON        DEFAULT NULL,
    languages       JSON         DEFAULT NULL,
    service_type           VARCHAR(20)   NOT NULL DEFAULT 'non_clinical',
    mfa_secret             VARCHAR(32)   DEFAULT NULL COMMENT 'Base32 TOTP secret — NULL means MFA not enabled',
    license_status         ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    role_icon              VARCHAR(64)   DEFAULT NULL,
    account_status         ENUM('active','pending','rejected') NOT NULL DEFAULT 'active',
    accepting_new_clients  TINYINT(1)    NOT NULL DEFAULT 1,
    cancellation_policy    TEXT          DEFAULT NULL,    -- shown to clients on book.php before they confirm
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
    `value` TEXT         NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rooms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    background_path VARCHAR(512) DEFAULT NULL,
    room_token      VARCHAR(64) NOT NULL UNIQUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    INDEX idx_practitioner (practitioner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id               INT UNSIGNED NOT NULL,
    started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at              DATETIME DEFAULT NULL,
    transcript_exported_at DATETIME DEFAULT NULL,
    data_purged_at        DATETIME DEFAULT NULL,
    series_id             INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- transcript_exported_at and data_purged_at now included in CREATE TABLE above.

CREATE TABLE IF NOT EXISTS participants (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              INT UNSIGNED NOT NULL,
    display_name            VARCHAR(255) NOT NULL,
    avatar_path             VARCHAR(512) DEFAULT NULL,
    join_token              VARCHAR(64) NOT NULL UNIQUE,
    is_host                 TINYINT(1) NOT NULL DEFAULT 0,
    joined_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    position_x              FLOAT NOT NULL DEFAULT 0.1,
    position_y              FLOAT NOT NULL DEFAULT 0.1,
    typing_at               DATETIME DEFAULT NULL,
    noting_at               DATETIME DEFAULT NULL,
    last_seen_at            DATETIME DEFAULT NULL,
    linked_to_participant_id INT UNSIGNED DEFAULT NULL,
    is_ai                   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = virtual AI assistant participant',
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_to_participant_id) REFERENCES participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    participant_id  INT UNSIGNED DEFAULT NULL,
    content         TEXT NOT NULL,
    message_type    ENUM('text','voice_note','file','crisis') NOT NULL DEFAULT 'text',
    caption         TEXT DEFAULT NULL,
    file_size       INT UNSIGNED DEFAULT NULL,
    mime_type       VARCHAR(128) DEFAULT NULL,
    original_name   VARCHAR(255) DEFAULT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_practitioner TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    edited_at       DATETIME DEFAULT NULL,
    INDEX idx_session_sent (session_id, sent_at),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS highlights (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id   INT UNSIGNED NOT NULL,
    start_offset INT UNSIGNED NOT NULL,
    end_offset   INT UNSIGNED NOT NULL,
    color        ENUM('yellow','pink','blue','crimson') NOT NULL DEFAULT 'yellow',
    annotation   TEXT DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pins (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL UNIQUE,
    annotation  TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS practitioner_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    participant_id  INT UNSIGNED NOT NULL,
    note_content    TEXT NOT NULL DEFAULT '',
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_participant (session_id, participant_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    type       VARCHAR(32) NOT NULL,
    payload    JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX      idx_session_events (session_id, id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backgrounds (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    file_path       VARCHAR(512) NOT NULL,
    mime_type       VARCHAR(128) NOT NULL DEFAULT 'image/jpeg',
    thumb_path      VARCHAR(512) DEFAULT NULL,
    original_name   VARCHAR(255) NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Activities ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_sessions (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_session_id          INT UNSIGNED NOT NULL,
    activity_type            VARCHAR(32)  NOT NULL,
    lobby_code               VARCHAR(64)  NOT NULL UNIQUE,
    started_by_participant_id INT UNSIGNED NOT NULL,
    started_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at                 DATETIME NULL,
    INDEX idx_room_session (room_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_moves (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lobby_code VARCHAR(64) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    payload    JSON NOT NULL,
    sequence   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_lobby_seq (lobby_code, sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_state (
    lobby_code VARCHAR(64)  NOT NULL PRIMARY KEY,
    state_json JSON         NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_lobbies (
    lobby_code VARCHAR(64)  NOT NULL PRIMARY KEY,
    game_id    INT UNSIGNED NOT NULL DEFAULT 0,
    user1_id   INT UNSIGNED NULL,
    user2_id   INT UNSIGNED NULL,
    status     ENUM('waiting','active','ended') NOT NULL DEFAULT 'waiting',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Voice Chat ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS voice_sessions (
    participant_id INT UNSIGNED NOT NULL PRIMARY KEY,
    session_id     INT UNSIGNED NOT NULL,
    joined_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS voice_signals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          INT UNSIGNED NOT NULL,
    from_participant_id INT UNSIGNED NOT NULL,
    to_participant_id   INT UNSIGNED NOT NULL,
    type                VARCHAR(16) NOT NULL,
    data                JSON NOT NULL,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_to (session_id, to_participant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If upgrading, add:
--   (run the CREATE TABLE statements above)

-- ── Presence detection (reconnect / disconnect awareness) ────────
-- If upgrading an existing install, run:
--   ALTER TABLE participants ADD COLUMN last_seen_at DATETIME DEFAULT NULL AFTER typing_at;

-- ── Admin / settings (added for setup wizard) ────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE practitioners ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_path;
--   CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_reactions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id     INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    emoji          VARCHAR(10)  NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_participant_message (message_id, participant_id),
    KEY idx_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Highlight colors (added) ─────────────────────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE practitioners
--     ADD COLUMN hl_color_1 VARCHAR(7) NOT NULL DEFAULT '#f5e24a' AFTER is_admin,
--     ADD COLUMN hl_color_2 VARCHAR(7) NOT NULL DEFAULT '#f48fb1' AFTER hl_color_1,
--     ADD COLUMN hl_color_3 VARCHAR(7) NOT NULL DEFAULT '#64b5f6' AFTER hl_color_2;

-- ── Reactions (added) ──────────────────────────────────────────
-- If upgrading an existing install, run:
--   (run the CREATE TABLE message_reactions statement above)

-- ── Rich message types (voice notes, file sharing) ─────────────
-- If upgrading an existing install, run:
--   ALTER TABLE messages
--     ADD COLUMN message_type ENUM('text','voice_note','file') NOT NULL DEFAULT 'text' AFTER content,
--     ADD COLUMN caption TEXT DEFAULT NULL AFTER message_type,
--     ADD COLUMN file_size INT UNSIGNED DEFAULT NULL AFTER caption,
--     ADD COLUMN mime_type VARCHAR(128) DEFAULT NULL AFTER file_size,
--     ADD COLUMN original_name VARCHAR(255) DEFAULT NULL AFTER mime_type;
--
-- Also for backgrounds table if upgrading:
--   ALTER TABLE backgrounds ADD COLUMN mime_type VARCHAR(128) NOT NULL DEFAULT 'image/jpeg' AFTER file_path;

-- ── Message edit/delete (added) ───────────────────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE messages
--     ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pinned,
--     ADD COLUMN edited_at DATETIME DEFAULT NULL AFTER is_deleted;

-- ── Video thumbnail (added) ───────────────────────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE backgrounds ADD COLUMN thumb_path VARCHAR(512) DEFAULT NULL AFTER mime_type;

-- ── Scheduling, directory & bookings (added) ─────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE practitioners
--     ADD COLUMN about_work TEXT DEFAULT NULL AFTER hl_color_3,
--     ADD COLUMN practice_types JSON DEFAULT NULL AFTER about_work,
--     ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER practice_types;
--   (then run the four CREATE TABLE statements below)

CREATE TABLE IF NOT EXISTS practitioner_availability (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL,   -- 0=Sun … 6=Sat
    start_time      TIME NOT NULL,               -- in practitioner's local timezone
    end_time        TIME NOT NULL,
    INDEX idx_pract_day (practitioner_id, day_of_week),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS practitioner_exclusions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    excluded_date   DATE NOT NULL,               -- in practitioner's local timezone
    UNIQUE KEY uq_pract_date (practitioner_id, excluded_date),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS end_users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                VARCHAR(255) NOT NULL UNIQUE,
    password_hash        VARCHAR(255) DEFAULT NULL,
    display_name         VARCHAR(255) NOT NULL,
    avatar_preset        VARCHAR(64)  DEFAULT NULL,
    avatar_path          VARCHAR(512) DEFAULT NULL,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    gender               VARCHAR(512) DEFAULT NULL,            -- stored encrypted (phi_encrypt)
    sexuality            VARCHAR(512) DEFAULT NULL,            -- stored encrypted (phi_encrypt)
    date_of_birth        VARCHAR(512) DEFAULT NULL,            -- stored encrypted (phi_encrypt); plaintext is YYYY-MM-DD
    location             VARCHAR(64)  DEFAULT NULL,            -- ISO 3166-1 alpha-2 country code (not encrypted)
    mfa_secret           VARCHAR(32)  DEFAULT NULL,
    preferred_dc         INT UNSIGNED DEFAULT NULL COMMENT 'References locations.id — NULL means Primary',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id       INT UNSIGNED NOT NULL,
    room_id               INT UNSIGNED DEFAULT NULL,
    end_user_id           INT UNSIGNED DEFAULT NULL,
    guest_name            VARCHAR(255) DEFAULT NULL,
    guest_email           VARCHAR(255) DEFAULT NULL,
    guest_avatar          VARCHAR(64)  DEFAULT NULL,   -- preset key
    scheduled_at          DATETIME NOT NULL,           -- stored UTC
    duration_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    status                ENUM('pending','scheduled','cancelled','completed') NOT NULL DEFAULT 'pending',
    booking_token         VARCHAR(64) NOT NULL UNIQUE,
    participant_id        INT UNSIGNED DEFAULT NULL,
    intake_form_id        INT UNSIGNED DEFAULT NULL,
    intake_token          VARCHAR(64)  DEFAULT NULL UNIQUE,
    discount_code_id      INT UNSIGNED DEFAULT NULL,
    reminder_24h_sent_at  DATETIME     DEFAULT NULL,   -- set when 24h reminder emails are dispatched
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pract_scheduled (practitioner_id, scheduled_at),
    INDEX idx_pract_status (practitioner_id, status),
    INDEX idx_enduser_scheduled (end_user_id, scheduled_at),
    INDEX idx_reminder_window (status, scheduled_at, reminder_24h_sent_at),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id)         REFERENCES rooms(id)         ON DELETE SET NULL,
    FOREIGN KEY (end_user_id)     REFERENCES end_users(id)     ON DELETE SET NULL,
    FOREIGN KEY (participant_id)  REFERENCES participants(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Session Series (#14) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS session_series (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    client_email    VARCHAR(255) DEFAULT NULL,
    end_user_id     INT UNSIGNED DEFAULT NULL,
    shared_notes    TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (end_user_id)     REFERENCES end_users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sessions.series_id column now in CREATE TABLE above; FK added here after session_series exists:
ALTER TABLE sessions ADD CONSTRAINT fk_sessions_series
    FOREIGN KEY (series_id) REFERENCES session_series(id) ON DELETE SET NULL;

-- ── Action Items (#15) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS action_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id    INT UNSIGNED NOT NULL,
    series_id     INT UNSIGNED DEFAULT NULL,
    text          TEXT NOT NULL,
    assigned_to   VARCHAR(255) DEFAULT NULL,
    due_date      DATE DEFAULT NULL,
    completed_at  DATETIME DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id)  REFERENCES session_series(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Intake Forms (#16) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS intake_forms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    fields          JSON NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intake_responses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT UNSIGNED NOT NULL,
    form_id      INT UNSIGNED NOT NULL,
    responses    JSON NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (form_id)    REFERENCES intake_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bookings.intake_form_id and intake_token now in CREATE TABLE above.

-- ── Identity, inclusivity & language fields (added) ────────────
-- If upgrading an existing install, run:
--   ALTER TABLE practitioners
--     ADD COLUMN gender          VARCHAR(32)  DEFAULT NULL AFTER timezone,
--     ADD COLUMN sexuality       VARCHAR(32)  DEFAULT NULL AFTER gender,
--     ADD COLUMN date_of_birth   DATE         DEFAULT NULL AFTER sexuality,
--     ADD COLUMN location        VARCHAR(64)  DEFAULT NULL AFTER date_of_birth,
--     ADD COLUMN show_gender     TINYINT(1)   NOT NULL DEFAULT 0 AFTER location,
--     ADD COLUMN show_sexuality  TINYINT(1)   NOT NULL DEFAULT 0 AFTER show_gender,
--     ADD COLUMN show_age        TINYINT(1)   NOT NULL DEFAULT 0 AFTER show_sexuality,
--     ADD COLUMN show_location   TINYINT(1)   NOT NULL DEFAULT 0 AFTER show_age,
--     ADD COLUMN inclusivity_tags JSON        DEFAULT NULL AFTER show_location,
--     ADD COLUMN languages       JSON         DEFAULT NULL AFTER inclusivity_tags;
--
--   ALTER TABLE end_users
--     ADD COLUMN gender        VARCHAR(512) DEFAULT NULL AFTER avatar_preset,   -- encrypted
--     ADD COLUMN sexuality     VARCHAR(512) DEFAULT NULL AFTER gender,            -- encrypted
--     ADD COLUMN date_of_birth VARCHAR(512) DEFAULT NULL AFTER sexuality,         -- encrypted
--     ADD COLUMN location      VARCHAR(64)  DEFAULT NULL AFTER date_of_birth;

-- ── Service type field (added) ──────────────────────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE practitioners
--     ADD COLUMN service_type VARCHAR(20) NOT NULL DEFAULT 'non_clinical' AFTER languages;

-- ── Client account fields (added) ───────────────────────────────
-- If upgrading an existing install, run:
--   ALTER TABLE end_users
--     ADD COLUMN avatar_path          VARCHAR(512) DEFAULT NULL AFTER avatar_preset,
--     ADD COLUMN must_change_password TINYINT(1)   NOT NULL DEFAULT 0 AFTER avatar_path;

-- ── Brute-force firewall tables ──────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,
    email        VARCHAR(255) NOT NULL DEFAULT '',
    attempted_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_ip_time    (ip_address, attempted_at),
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ip_blocks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address  VARCHAR(45) NOT NULL UNIQUE,
    block_type  ENUM('temp','perm') NOT NULL DEFAULT 'temp',
    expires_at  DATETIME DEFAULT NULL,          -- NULL = permanent
    reason      VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Session ratings (client → session) ──────────────────────────
CREATE TABLE IF NOT EXISTS session_ratings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    end_user_id INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,      -- 1-5
    comment     TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_user (session_id, end_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── GDPR data deletion requests ──────────────────────────────────
CREATE TABLE IF NOT EXISTS gdpr_deletion_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    end_user_id     INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NOT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at     DATETIME NOT NULL,                -- 30 days from request
    status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at    DATETIME DEFAULT NULL,
    completed_by    INT UNSIGNED DEFAULT NULL,         -- practitioner_id who processed it
    notes           TEXT DEFAULT NULL,
    INDEX idx_status (status, deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PHI audit log ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED    DEFAULT NULL,
    participant_id  INT UNSIGNED    DEFAULT NULL,
    session_id      INT UNSIGNED    DEFAULT NULL,
    action          VARCHAR(64)     NOT NULL,
    entity_type     VARCHAR(32)     DEFAULT NULL,
    entity_id       INT UNSIGNED    DEFAULT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    user_agent      TEXT            DEFAULT NULL,
    row_hash        VARCHAR(64)     DEFAULT NULL COMMENT 'HMAC-SHA256 tamper-evident chain hash',
    created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_practitioner (practitioner_id, created_at),
    INDEX idx_session (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Practitioner ↔ Client inbox messaging (outside sessions) ──────
CREATE TABLE IF NOT EXISTS inbox_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    end_user_id     INT UNSIGNED NOT NULL,
    sender_type     ENUM('practitioner','client') NOT NULL,
    body            TEXT DEFAULT NULL,
    message_type    ENUM('text','voice_note','image') NOT NULL DEFAULT 'text',
    file_path       VARCHAR(500) DEFAULT NULL,
    file_name       VARCHAR(255) DEFAULT NULL,
    mime_type       VARCHAR(100) DEFAULT NULL,
    read_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_convo   (practitioner_id, end_user_id, created_at),
    INDEX idx_unread_p (practitioner_id, sender_type, read_at),
    INDEX idx_unread_c (end_user_id, sender_type, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Editable email templates ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_templates (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(64)  NOT NULL UNIQUE,
    name         VARCHAR(128) NOT NULL,
    subject      VARCHAR(255) NOT NULL,
    body_html    TEXT         NOT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Session metadata snapshot (captured at session end) ──────────
CREATE TABLE IF NOT EXISTS session_metadata (
    session_id        INT UNSIGNED NOT NULL PRIMARY KEY,
    practitioner_name VARCHAR(255) NOT NULL DEFAULT '',
    room_bg_path      VARCHAR(512) DEFAULT NULL,
    captured_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payment system ─────────────────────────────────────────────────
-- If upgrading an existing install, run the four CREATE TABLE statements below.

CREATE TABLE IF NOT EXISTS practitioner_rates (
    practitioner_id INT UNSIGNED  NOT NULL PRIMARY KEY,
    currency        CHAR(3)       NOT NULL DEFAULT 'USD',
    amount          DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    is_free         TINYINT(1)    NOT NULL DEFAULT 0,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS practitioner_payment_gateways (
    practitioner_id    INT UNSIGNED  NOT NULL PRIMARY KEY,
    -- ── PayPal ──
    paypal_enabled     TINYINT(1)    NOT NULL DEFAULT 0,
    paypal_mode        ENUM('basic','advanced') NOT NULL DEFAULT 'basic',
    paypal_email       VARCHAR(255)  DEFAULT NULL,
    paypal_me_username VARCHAR(128)  DEFAULT NULL,
    paypal_environment ENUM('live','sandbox') NOT NULL DEFAULT 'live',
    paypal_client_id   VARCHAR(255)  DEFAULT NULL,
    paypal_client_secret_enc TEXT    DEFAULT NULL,    -- AES-256 encrypted
    paypal_webhook_id  VARCHAR(128)  DEFAULT NULL,    -- PayPal webhook ID from REST API setup
    -- ── Stripe ──
    stripe_enabled     TINYINT(1)    NOT NULL DEFAULT 0,
    stripe_mode        ENUM('basic','advanced') NOT NULL DEFAULT 'basic',
    stripe_pub_key     VARCHAR(255)  DEFAULT NULL,
    stripe_secret_enc  TEXT          DEFAULT NULL,    -- AES-256 encrypted
    stripe_webhook_secret_enc TEXT   DEFAULT NULL,    -- whsec_… signing secret
    -- ── Bitcoin ──
    btc_enabled        TINYINT(1)    NOT NULL DEFAULT 0,
    btc_mode           ENUM('basic','advanced') NOT NULL DEFAULT 'basic',
    btc_address        VARCHAR(128)  DEFAULT NULL,
    btcpay_server_url  VARCHAR(255)  DEFAULT NULL,    -- BTCPay Server base URL
    btcpay_api_key_enc TEXT          DEFAULT NULL,    -- AES-256 encrypted
    btcpay_store_id    VARCHAR(128)  DEFAULT NULL,
    btcpay_webhook_secret_enc TEXT   DEFAULT NULL,    -- AES-256 encrypted
    -- ── Square ──
    square_enabled     TINYINT(1)    NOT NULL DEFAULT 0,
    square_mode        ENUM('basic','advanced') NOT NULL DEFAULT 'basic',
    square_environment ENUM('production','sandbox') NOT NULL DEFAULT 'production',
    square_app_id      VARCHAR(100)  DEFAULT NULL,
    square_token_enc   TEXT          DEFAULT NULL,
    square_location_id VARCHAR(60)   DEFAULT NULL,
    square_webhook_signature_key_enc TEXT DEFAULT NULL, -- AES-256 encrypted
    updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Webhook event log ────────────────────────────────────────────────
-- Records every inbound webhook event so we have an audit trail of automated
-- payment confirmations. Idempotency: payload_id is the provider's unique event
-- ID (Stripe event.id, PayPal event.id, etc.) — duplicate deliveries are no-ops.
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED  DEFAULT NULL,
    gateway         ENUM('stripe','paypal','square','btcpay') NOT NULL,
    payload_id      VARCHAR(255)  NOT NULL,         -- provider event id
    event_type      VARCHAR(128)  DEFAULT NULL,
    booking_token   VARCHAR(64)   DEFAULT NULL,
    booking_payment_id INT UNSIGNED DEFAULT NULL,
    signature_ok    TINYINT(1)    NOT NULL DEFAULT 0,
    processed       TINYINT(1)    NOT NULL DEFAULT 0,
    error_message   TEXT          DEFAULT NULL,
    received_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gw_payload (gateway, payload_id),
    INDEX idx_pract_received (practitioner_id, received_at),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE SET NULL,
    FOREIGN KEY (booking_payment_id) REFERENCES booking_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discount_campaigns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED  NOT NULL,
    label           VARCHAR(128)  NOT NULL DEFAULT '',
    discount_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- 0-100
    valid_from      DATE          NOT NULL,
    valid_until     DATE          DEFAULT NULL,             -- NULL = ongoing
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pract_active (practitioner_id, is_active, valid_from),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT UNSIGNED  NOT NULL UNIQUE,
    practitioner_id INT UNSIGNED  NOT NULL,
    amount_due      DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    amount_paid     DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    currency        CHAR(3)       NOT NULL DEFAULT 'USD',
    discount_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    campaign_id     INT UNSIGNED  DEFAULT NULL,
    gateway         ENUM('paypal','stripe','bitcoin','square','free','manual','waived') NOT NULL DEFAULT 'manual',
    status          ENUM('pending','paid','waived','failed') NOT NULL DEFAULT 'pending',
    gateway_ref     VARCHAR(255)  DEFAULT NULL,
    paid_at         DATETIME      DEFAULT NULL,
    notes           TEXT          DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id)      REFERENCES bookings(id)           ON DELETE CASCADE,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)      ON DELETE CASCADE,
    FOREIGN KEY (campaign_id)     REFERENCES discount_campaigns(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Client private notes ────────────────────────────────────────────
-- Per-booking reflection notes, visible to client only — never exposed to practitioner.
CREATE TABLE IF NOT EXISTS client_notes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT UNSIGNED  NOT NULL UNIQUE,
    end_user_id INT UNSIGNED  DEFAULT NULL,   -- NULL for guest-only bookings
    note_text   TEXT          DEFAULT NULL,   -- AES-256-CBC encrypted (phi_encrypt)
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id)  REFERENCES bookings(id)    ON DELETE CASCADE,
    FOREIGN KEY (end_user_id) REFERENCES end_users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── GDPR consent records ────────────────────────────────────────────
-- Audit trail for explicit Art. 9(2)(a) consent to process special category data.
CREATE TABLE IF NOT EXISTS consent_records (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT UNSIGNED  NOT NULL,
    end_user_id     INT UNSIGNED  DEFAULT NULL,
    guest_email_enc TEXT          DEFAULT NULL,   -- encrypted for guest identification
    consent_type    VARCHAR(64)   NOT NULL DEFAULT 'special_category_data',
    consent_version VARCHAR(16)   NOT NULL DEFAULT '1.0',
    consent_text    TEXT          NOT NULL,       -- snapshot of exact wording shown
    ip_address      VARCHAR(45)   DEFAULT NULL,
    user_agent      VARCHAR(512)  DEFAULT NULL,
    consented_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    withdrawn_at    DATETIME      DEFAULT NULL,
    INDEX idx_booking  (booking_id),
    INDEX idx_end_user (end_user_id),
    FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE CASCADE,
    FOREIGN KEY (end_user_id) REFERENCES end_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Session Recap (#4) ──────────────────────────────────────────
-- Structured end-of-session summary filled by practitioner before export.
-- Neutral field names work for all four practitioner roles.
CREATE TABLE IF NOT EXISTS session_recap (
    session_id      INT UNSIGNED NOT NULL PRIMARY KEY,
    key_summary     TEXT DEFAULT NULL,    -- What was explored / covered today
    key_insights    TEXT DEFAULT NULL,    -- Insights, moments, breakthroughs
    agreed_actions  TEXT DEFAULT NULL,    -- Commitments and next steps
    next_focus      TEXT DEFAULT NULL,    -- Intended focus for next session
    series_id       INT UNSIGNED DEFAULT NULL,  -- Optional arc assignment
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id)  REFERENCES session_series(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Practitioner License Submissions (#6) ───────────────────────
-- Practitioners request licensed status by uploading credentials.
-- Admin reviews and approves/rejects. Approval unlocks clinical fields.
-- practitioners.license_status now in CREATE TABLE above.

CREATE TABLE IF NOT EXISTS license_submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    document_path   VARCHAR(512) NOT NULL,    -- uploaded file path
    document_name   VARCHAR(255) NOT NULL,    -- original filename
    notes           TEXT DEFAULT NULL,        -- practitioner's notes
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED DEFAULT NULL,    -- admin practitioner_id
    reviewer_notes  TEXT DEFAULT NULL,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME DEFAULT NULL,
    INDEX idx_status (status, submitted_at),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)     REFERENCES practitioners(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Discount Codes ──────────────────────────────────────────────
-- Practitioner-created codes clients enter at booking time.
-- Separate from discount_campaigns (which are automatic/public).
CREATE TABLE IF NOT EXISTS discount_codes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id     INT UNSIGNED     NOT NULL,
    code                VARCHAR(64)      NOT NULL,              -- stored uppercase
    discount_pct        TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- 0-100
    valid_from          DATE             NOT NULL,
    valid_until         DATE             DEFAULT NULL,          -- NULL = no expiry
    max_total_uses      INT UNSIGNED     DEFAULT NULL,          -- NULL = unrestricted
    max_uses_per_client INT UNSIGNED     DEFAULT NULL,          -- NULL = unrestricted per account
    is_active           TINYINT(1)       NOT NULL DEFAULT 1,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pract_code (practitioner_id, code),
    INDEX idx_pract_active (practitioner_id, is_active),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discount_code_uses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_id     INT UNSIGNED NOT NULL,
    booking_id  INT UNSIGNED NOT NULL,
    end_user_id INT UNSIGNED DEFAULT NULL,   -- NULL for fully-anonymous guests
    used_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (code_id)     REFERENCES discount_codes(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id)  REFERENCES bookings(id)       ON DELETE CASCADE,
    FOREIGN KEY (end_user_id) REFERENCES end_users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bookings.discount_code_id column now in CREATE TABLE above; FK added here after discount_codes exists:
ALTER TABLE bookings ADD CONSTRAINT fk_bookings_discount_code
    FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE SET NULL;

-- ── Client Goals & Progress Tracking ────────────────────────────
CREATE TABLE IF NOT EXISTS client_goals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    end_user_id     INT UNSIGNED DEFAULT NULL,    -- NULL = non-portal client
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    target_date     DATE DEFAULT NULL,
    status          ENUM('active','completed','paused','abandoned') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pract_user (practitioner_id, end_user_id, status),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (end_user_id)     REFERENCES end_users(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goal_milestones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(255) NOT NULL,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id) REFERENCES client_goals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goal_updates (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id      INT UNSIGNED NOT NULL,
    session_id   INT UNSIGNED DEFAULT NULL,     -- optional session link
    note         TEXT DEFAULT NULL,
    progress_pct TINYINT UNSIGNED DEFAULT NULL, -- 0-100, nullable
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id)    REFERENCES client_goals(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Existing installation migration ─────────────────────────────
-- ALTER TABLE messages MODIFY COLUMN message_type ENUM('text','voice_note','file','crisis') NOT NULL DEFAULT 'text';

-- ── Crisis Resources ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crisis_resources (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label        VARCHAR(255) NOT NULL,
    message_body TEXT NOT NULL,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO crisis_resources (id, label, message_body, sort_order) VALUES
(1, 'National Suicide & Crisis Lifeline', '🆘 National Suicide & Crisis Lifeline: Call or text 988', 10),
(2, 'Crisis Text Line', '🆘 Crisis Text Line: Text HOME to 741741', 20),
(3, 'International Suicide Prevention (IASP)', '🆘 International Association for Suicide Prevention — Crisis Centres: https://www.iasp.info/resources/Crisis_Centres/', 30),
(4, 'SAMHSA National Helpline', '🆘 SAMHSA National Helpline: 1-800-662-4357', 40),
(5, 'Emergency Services', '🚨 Emergency Services: Please call 911 or your local emergency number immediately.', 50);

-- ── Concept / Theme Tags ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS concept_tags (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    color           VARCHAR(7) NOT NULL DEFAULT '#7c6af7',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pract_tag (practitioner_id, name),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Message ↔ concept tag junction
CREATE TABLE IF NOT EXISTS message_concept_tags (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_id     INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_msg_tag (tag_id, message_id),
    FOREIGN KEY (tag_id)     REFERENCES concept_tags(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Note ↔ concept tag junction (keyed by session + participant, not note row id)
CREATE TABLE IF NOT EXISTS note_concept_tags (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_id         INT UNSIGNED NOT NULL,
    session_id     INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_note_tag (tag_id, session_id, participant_id),
    FOREIGN KEY (tag_id)         REFERENCES concept_tags(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id)     REFERENCES sessions(id)     ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Practitioner-to-practitioner referrals ──────────────────────────
-- Practitioner A sends a referral to practitioner B for a client. The
-- sender chooses what to share via permission flags; nothing is copied
-- until B accepts. B can Accept (data flows over), Respond (send a
-- message back without accepting), or Reject. A can Withdraw before B
-- responds. The receiving client is automatically emailed on Accept so
-- they know a new practitioner has been brought in.
--
-- Note on consent: this is a TPO-style provider-to-provider referral on
-- the same platform. The permission flags give the sender granular
-- control; the auto-email on Accept gives the client immediate visibility.
-- Operators with stricter consent requirements can wire client_consent_at
-- into their own workflow if needed (default null = use platform default).
CREATE TABLE IF NOT EXISTS practitioner_referrals (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_practitioner_id     INT UNSIGNED NOT NULL,
    to_practitioner_id       INT UNSIGNED NOT NULL,
    -- Client identity — either an existing portal user or a guest by email
    end_user_id              INT UNSIGNED DEFAULT NULL,
    guest_name               VARCHAR(255) DEFAULT NULL,
    guest_email              VARCHAR(255) DEFAULT NULL,
    -- Sender's notes at time of referral (encrypted at rest via phi_encrypt
    -- with context referral:{id} after insert; we use a placeholder string
    -- on insert and re-encrypt with the row id once we have it).
    referral_notes_enc       TEXT          DEFAULT NULL,
    -- Permission flags — what the sender authorises to copy on Accept.
    -- Defaults intentionally narrow: only basic identity, nothing more.
    share_basic_info         TINYINT(1)    NOT NULL DEFAULT 1,
    share_intake_history     TINYINT(1)    NOT NULL DEFAULT 0,
    share_session_notes      TINYINT(1)    NOT NULL DEFAULT 0,
    share_goals              TINYINT(1)    NOT NULL DEFAULT 0,
    share_themes             TINYINT(1)    NOT NULL DEFAULT 0,
    -- Workflow state
    status                   ENUM('pending','accepted','responded','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    response_message_enc     TEXT          DEFAULT NULL,    -- recipient's reply if Respond / optional reason if Reject
    -- Snapshots — what was actually copied at Accept time, kept for audit
    accepted_snapshot        JSON          DEFAULT NULL,
    sent_at                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at             DATETIME      DEFAULT NULL,
    INDEX idx_to_status (to_practitioner_id, status, sent_at),
    INDEX idx_from (from_practitioner_id, sent_at),
    FOREIGN KEY (from_practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (to_practitioner_id)   REFERENCES practitioners(id) ON DELETE CASCADE,
    FOREIGN KEY (end_user_id)          REFERENCES end_users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Practitioner reference / quote / favorites library ──────────────
-- Unified store for: custom quotes, library-starred quotes, starred media
-- recommendations (books/films/TV from media_search), and free-form
-- passages. Practitioners build this out of session and drop items into
-- chat or notes during sessions.
CREATE TABLE IF NOT EXISTS practitioner_references (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practitioner_id INT UNSIGNED NOT NULL,
    ref_type        ENUM('quote','passage','book','film','tv','article','other') NOT NULL DEFAULT 'quote',
    -- Top-level taxonomy. Values: Christian, Jewish, Islamic, Buddhist, Hindu,
    -- Taoist, Interfaith, Philosophy, Mindfulness, Recovery, Grief, Practitioner.
    -- "Practitioner" is the catch-all for items the practitioner created themselves.
    -- Stored as VARCHAR rather than ENUM so operators can extend the list without
    -- a schema migration.
    category        VARCHAR(64)   NOT NULL DEFAULT 'Practitioner',
    title           VARCHAR(512) NOT NULL DEFAULT '',
    body            TEXT          DEFAULT NULL,         -- full quote/passage text
    author          VARCHAR(255)  DEFAULT NULL,         -- speaker / writer / director
    year            VARCHAR(32)   DEFAULT NULL,         -- approximate ok ('c. 165 CE', '1946')
    source          VARCHAR(512)  DEFAULT NULL,         -- book / publication / film / speech
    source_url      VARCHAR(512)  DEFAULT NULL,
    cover_url       VARCHAR(512)  DEFAULT NULL,         -- thumbnail for books/films
    external_kind   VARCHAR(32)   DEFAULT NULL,         -- tmdb|openlibrary|google_books|archive_org|library|null
    external_id     VARCHAR(128)  DEFAULT NULL,         -- provider id (or library quote id)
    tags            JSON          DEFAULT NULL,         -- list of practitioner-defined tag names (themes)
    notes           TEXT          DEFAULT NULL,         -- private — why I saved this; never sent to client
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pract_cat (practitioner_id, category),
    INDEX idx_pract_type (practitioner_id, ref_type),
    INDEX idx_external (practitioner_id, external_kind, external_id),
    UNIQUE KEY uq_pract_external (practitioner_id, external_kind, external_id),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- practitioners.role_icon, account_status, accepting_new_clients now in CREATE TABLE above.

-- ── Site Banners ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS banners (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content        TEXT NOT NULL,
    bg_color       VARCHAR(20)  NOT NULL DEFAULT '#2a2060',
    text_color     VARCHAR(20)  NOT NULL DEFAULT '#ffffff',
    show_public    TINYINT(1)   NOT NULL DEFAULT 0,
    show_dashboard TINYINT(1)   NOT NULL DEFAULT 0,
    show_profile   TINYINT(1)   NOT NULL DEFAULT 0,
    show_client    TINYINT(1)   NOT NULL DEFAULT 0,
    show_admin     TINYINT(1)   NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── v2.7.0 Security Hardening ─────────────────────────────────────
-- practitioners.mfa_secret now included in CREATE TABLE above.

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── v2.8.5 Security Hardening ─────────────────────────────────────
-- audit_log.row_hash now included in CREATE TABLE above.

-- ── v2.9.0 Multi-DC / Linked Locations ────────────────────────────
-- Primary server: tracks connected Alternative Location servers.
CREATE TABLE IF NOT EXISTS locations (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label              VARCHAR(255) NOT NULL,
    country            VARCHAR(2)   NOT NULL,     -- ISO 3166-1 alpha-2
    url                VARCHAR(512) NOT NULL,
    auth_token_hash    VARCHAR(255) NOT NULL,     -- hash of the shared secret
    last_authenticated DATETIME     DEFAULT NULL,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- end_users.preferred_dc now in CREATE TABLE above.

-- Alternative Location servers: lightweight tables to track synced sessions/participants.
-- On primary these exist but are unused. On alt-DC they are the auth source.
CREATE TABLE IF NOT EXISTS dc_sessions (
    session_id  INT UNSIGNED NOT NULL PRIMARY KEY,
    room_id     INT UNSIGNED NOT NULL,
    synced_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_participants (
    participant_id  INT UNSIGNED  NOT NULL PRIMARY KEY,
    session_id      INT UNSIGNED  NOT NULL,
    join_token      VARCHAR(255)  NOT NULL UNIQUE,
    is_practitioner TINYINT(1)    NOT NULL DEFAULT 0,
    synced_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dc_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── v2.9.0 Intake Form Template Library ───────────────────────────
CREATE TABLE IF NOT EXISTS intake_form_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    role_type   VARCHAR(64)  NOT NULL DEFAULT 'any',  -- therapist|counselor|coach|philosophical_counselor|peer_support|any
    fields      JSON         NOT NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = system-seeded template
    created_by  INT UNSIGNED DEFAULT NULL,         -- NULL = system template
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES practitioners(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed three role-appropriate default templates so a new practitioner has a
-- starting point instead of a blank builder. Operators may translate or remove
-- these freely. INSERT IGNORE so re-running the schema is safe.
INSERT IGNORE INTO intake_form_templates (id, name, role_type, fields, is_default, created_by) VALUES
(1, 'New Client — General',     'any',          '[{"id":"goals","label":"What brings you in today?","type":"textarea","required":true},{"id":"history","label":"Have you worked with a practitioner before?","type":"radio","options":["Yes","No","Prefer not to say"],"required":false},{"id":"focus","label":"What would feel like a good outcome?","type":"textarea","required":false},{"id":"contact","label":"Best way to reach you between sessions","type":"text","required":false}]', 1, NULL),
(2, 'Coaching Intake',           'coach',        '[{"id":"life_area","label":"Which area of life are we working on?","type":"select","options":["Career","Relationships","Health","Purpose","Other"],"required":true},{"id":"goal","label":"Specific goal for our work together","type":"textarea","required":true},{"id":"timeline","label":"Timeline for this goal","type":"select","options":["1 month","3 months","6 months","Open-ended"],"required":false},{"id":"obstacles","label":"What has been getting in the way?","type":"textarea","required":false}]', 1, NULL),
(3, 'Peer Support Check-in',     'peer_support', '[{"id":"how_today","label":"How are you doing today, on a scale of 1-10?","type":"select","options":["1","2","3","4","5","6","7","8","9","10"],"required":true},{"id":"focus","label":"What would you like to talk about?","type":"textarea","required":true},{"id":"safety","label":"Are you safe right now?","type":"radio","options":["Yes","Mostly","No — please call me first"],"required":true},{"id":"support","label":"Who else is in your support system right now?","type":"text","required":false}]', 1, NULL);

-- ── 1.0.0-291 AI Integration ──────────────────────────────────────

-- participants.is_ai now in CREATE TABLE above.

-- Per-practitioner AI configuration
CREATE TABLE IF NOT EXISTS practitioner_ai_config (
    practitioner_id     INT UNSIGNED  NOT NULL PRIMARY KEY,
    enabled             TINYINT(1)    NOT NULL DEFAULT 0,
    vendor              VARCHAR(32)   NOT NULL DEFAULT 'openai',  -- openai|anthropic|google|cohere
    api_key_enc         TEXT          DEFAULT NULL,               -- phi_encrypt with context ai_config:{practitionerId}
    model               VARCHAR(128)  NOT NULL DEFAULT '',
    scope_summarization TINYINT(1)    NOT NULL DEFAULT 0,
    scope_notes         TINYINT(1)    NOT NULL DEFAULT 0,
    scope_post_session  TINYINT(1)    NOT NULL DEFAULT 0,
    scope_in_session    TINYINT(1)    NOT NULL DEFAULT 0,
    assistant_name      VARCHAR(100)  NOT NULL DEFAULT 'Assistant',
    assistant_avatar    VARCHAR(512)  DEFAULT NULL,               -- path or NULL = use ai.png
    assistant_prompt    TEXT          DEFAULT NULL,               -- system prompt
    dpa_verified        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Set to 1 when a valid DPA document is on file for the active vendor',
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Client AI consent per practitioner relationship
CREATE TABLE IF NOT EXISTS ai_consent (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    end_user_id          INT UNSIGNED NOT NULL,
    practitioner_id      INT UNSIGNED NOT NULL,
    booking_id           INT UNSIGNED DEFAULT NULL,  -- initial consent captured at booking
    ai_allowed           TINYINT(1)   NOT NULL DEFAULT 0,
    scope_summarization  TINYINT(1)   NOT NULL DEFAULT 0,
    scope_notes          TINYINT(1)   NOT NULL DEFAULT 0,
    scope_post_session   TINYINT(1)   NOT NULL DEFAULT 0,
    scope_in_session     TINYINT(1)   NOT NULL DEFAULT 0,
    consented_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_pract (end_user_id, practitioner_id),
    INDEX idx_practitioner (practitioner_id),
    FOREIGN KEY (end_user_id)     REFERENCES end_users(id)      ON DELETE CASCADE,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)  ON DELETE CASCADE,
    FOREIGN KEY (booking_id)      REFERENCES bookings(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Platform-level AI defaults (OFF by default on fresh install)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('ai_enabled',          '0'),
    ('ai_allowed_vendors',  '["openai","anthropic","google","cohere"]'),
    ('ai_allowed_scopes',   '["summarization","notes","post_session","in_session"]');

-- ── 1.0.0-292 Media Recommendations ─────────────────────────────

-- Per-practitioner media source configuration
CREATE TABLE IF NOT EXISTS practitioner_media_config (
    practitioner_id         INT UNSIGNED  NOT NULL PRIMARY KEY,
    open_library            TINYINT(1)    NOT NULL DEFAULT 1,   -- free, no key
    google_books_enabled    TINYINT(1)    NOT NULL DEFAULT 0,
    google_books_key_enc    TEXT          DEFAULT NULL,         -- phi_encrypt context media_config:{id}
    tmdb_enabled            TINYINT(1)    NOT NULL DEFAULT 0,
    tmdb_key_enc            TEXT          DEFAULT NULL,
    amazon_enabled          TINYINT(1)    NOT NULL DEFAULT 0,
    amazon_tag              VARCHAR(100)  DEFAULT NULL,
    amazon_access_key_enc   TEXT          DEFAULT NULL,
    amazon_secret_key_enc   TEXT          DEFAULT NULL,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add media_rec to in-session message types
ALTER TABLE messages MODIFY COLUMN message_type ENUM('text','voice_note','file','crisis','media_rec') NOT NULL DEFAULT 'text';

-- Add media_rec to inbox message types
ALTER TABLE inbox_messages MODIFY COLUMN message_type ENUM('text','voice_note','image','media_rec') NOT NULL DEFAULT 'text';

-- ── 1.0.0-293 Security Hardening ────────────────────────────────

-- TOTP used-code store: prevents 90-second replay attacks (RFC 6238 best practice)
CREATE TABLE IF NOT EXISTS totp_used_codes (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id   INT UNSIGNED NOT NULL,
    user_type VARCHAR(6)   NOT NULL DEFAULT 'pract',  -- 'pract' or 'client'
    code      CHAR(6)      NOT NULL,
    used_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_type_code (user_type, user_id, code),
    INDEX     idx_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 1.0.0-294 Client TOTP MFA ────────────────────────────────────
-- end_users.mfa_secret and totp_used_codes.user_type now included in CREATE TABLE above.

-- ── 1.0.0-294 DPA Document Management ────────────────────────────

-- DPA documents store: uploaded Data Processing Agreements for all processors
-- scope='platform'      — uploaded by admin; covers hosting, SMTP
-- scope='practitioner'  — uploaded by practitioner; covers AI vendors, payment processors
CREATE TABLE IF NOT EXISTS dpa_documents (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    scope               ENUM('platform','practitioner') NOT NULL,
    type                VARCHAR(60)   NOT NULL,             -- 'hosting','smtp','ai_openai','payment_stripe', etc.
    practitioner_id     INT UNSIGNED  DEFAULT NULL,         -- NULL for platform-scope docs
    vendor_name         VARCHAR(100)  NOT NULL,
    file_name           VARCHAR(255)  NOT NULL,             -- original uploaded filename
    file_path           VARCHAR(255)  NOT NULL,             -- storage-relative path (storage/dpa/xxx.pdf)
    file_size           INT UNSIGNED  NOT NULL DEFAULT 0,
    uploaded_by_type    ENUM('admin','practitioner') NOT NULL,
    uploaded_by_id      INT UNSIGNED  NOT NULL,
    notes               TEXT          DEFAULT NULL,
    uploaded_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scope_type (scope, type),
    INDEX idx_practitioner (practitioner_id),
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- practitioner_ai_config.dpa_verified and Square gateway columns now included in CREATE TABLE above.

-- ── Cron Job Logging ─────────────────────────────────────────────
-- Records every cron run with status, output, and timing for the admin Cron Manager.

CREATE TABLE IF NOT EXISTS cron_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_name     VARCHAR(64)  NOT NULL,
    started_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    completed_at  DATETIME(3)  DEFAULT NULL,
    duration_ms   INT UNSIGNED DEFAULT NULL,
    status        ENUM('running','success','error') NOT NULL DEFAULT 'running',
    rows_affected INT UNSIGNED DEFAULT NULL,
    output        TEXT         DEFAULT NULL,
    INDEX idx_cron_recent (cron_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing installs: add reminder tracking column to bookings
-- ALTER TABLE bookings ADD COLUMN reminder_24h_sent_at DATETIME DEFAULT NULL COMMENT 'Set when 24h reminder emails are dispatched';

CREATE TABLE IF NOT EXISTS dpia_reviews (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewed_by  VARCHAR(255) NOT NULL,
    role         VARCHAR(255) DEFAULT NULL,
    dpia_version VARCHAR(20)  NOT NULL DEFAULT '1.0',
    reviewed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes        TEXT         DEFAULT NULL,
    INDEX idx_dpia_reviewed (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='DPIA sign-off history — UK GDPR Art. 35 compliance record';

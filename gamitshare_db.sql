-- =============================================================
--  GamitShare Database Schema
--  Web-Based Shared Equipment Inventory and Borrowing
--  Management System for Community Organizations
--
--  Generated to match the front-end prototype structure
--  found in: views/assets/js/dashboard.js, auth.js, dashboard.html,
--             login.html
-- =============================================================

-- ───────────────────────────────────────────────────────────
--  0. DATABASE
-- ───────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS gamitshare
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE gamitshare;

-- ───────────────────────────────────────────────────────────
--  1. ORGANIZATIONS
--     Supports multi-tenant communities (e.g. "Barangay Central")
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS organizations (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)    NOT NULL,
  description   TEXT,
  avatar_text   VARCHAR(5),          -- e.g. "BC" shown in the sidebar workspace switch
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  2. USERS / ACCOUNTS
--     Maps to: auth.js  (salt, passwordHash, name, email, joined)
--              dashboard.js  people[]  (id, name, email, role, joined)
--  Roles: Administrator | Organization officer | Custodian | Member
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED    NOT NULL,
  name            VARCHAR(70)     NOT NULL,
  email           VARCHAR(254)    NOT NULL,
  -- PBKDF2-SHA256 hash stored as a 64-char hex string (matches auth.js)
  password_hash   CHAR(64)        NOT NULL,
  -- Base-64 encoded 16-byte salt (matches auth.js)
  salt            VARCHAR(32)     NOT NULL,
  role            ENUM(
                    'Member',
                    'Organization officer',
                    'Custodian',
                    'Administrator'
                  ) NOT NULL DEFAULT 'Member',
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  joined_date     DATE            NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT fk_users_org
    FOREIGN KEY (organization_id) REFERENCES organizations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  3. EQUIPMENT CATEGORIES
--     Extracted from: categoryIcons in dashboard.js
--     (Tools, Events, Electronics, Garden, Sports, Other)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment_categories (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)     NOT NULL,
  emoji       VARCHAR(8)      NOT NULL DEFAULT '📦',
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  4. EQUIPMENT
--     Maps to: dashboard.js  equipment[]
--     Fields: id, name, category, emoji, description,
--             quantity, available, condition, status, photo
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED    NOT NULL,
  category_id     INT UNSIGNED    NOT NULL,
  name            VARCHAR(60)     NOT NULL,
  emoji           VARCHAR(8)      NOT NULL DEFAULT '📦',
  description     VARCHAR(180),
  photo_url       VARCHAR(2048),
  quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  available       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  condition_state ENUM(
                    'Good',
                    'Fair',
                    'Needs repair',
                    'Damaged'
                  ) NOT NULL DEFAULT 'Good',
  status          ENUM(
                    'Available',
                    'Borrowed',
                    'Reserved',
                    'Under maintenance',
                    'Damaged'
                  ) NOT NULL DEFAULT 'Available',
  added_by        INT UNSIGNED,       -- user who added this item
  created_at      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_equipment_org (organization_id),
  KEY idx_equipment_status (status),
  KEY idx_equipment_category (category_id),
  CONSTRAINT fk_equipment_org
    FOREIGN KEY (organization_id) REFERENCES organizations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_equipment_category
    FOREIGN KEY (category_id) REFERENCES equipment_categories (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_equipment_added_by
    FOREIGN KEY (added_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_available_lte_quantity
    CHECK (available <= quantity)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  5. BORROWING REQUESTS
--     Maps to: dashboard.js  requests[]
--     Fields: id, equipmentId, borrower, email, quantity,
--             startDate, dueDate, purpose, status,
--             conditionOut, createdAt,
--             returnCondition, returnNotes, returnedAt
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS borrow_requests (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  organization_id     INT UNSIGNED    NOT NULL,
  equipment_id        INT UNSIGNED    NOT NULL,
  user_id             INT UNSIGNED    NOT NULL,     -- the borrower
  reviewed_by         INT UNSIGNED,                -- admin/officer/custodian who acted
  quantity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_date          DATE            NOT NULL,
  due_date            DATE            NOT NULL,
  purpose             VARCHAR(255)    NOT NULL,
  status              ENUM(
                        'Pending',
                        'Approved',
                        'Rejected',
                        'Returned',
                        'Overdue'
                      ) NOT NULL DEFAULT 'Pending',
  -- Condition of the item when it left (copied from equipment.condition_state at approval)
  condition_out       ENUM(
                        'Good',
                        'Fair',
                        'Needs repair',
                        'Damaged'
                      ),
  -- Return check-in fields
  return_condition    ENUM(
                        'Good',
                        'Fair',
                        'Needs repair',
                        'Damaged',
                        'Missing',
                        'Incomplete'
                      ),
  return_notes        VARCHAR(180),
  returned_at         DATE,
  reviewed_at         DATETIME,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_requests_org (organization_id),
  KEY idx_requests_user (user_id),
  KEY idx_requests_equipment (equipment_id),
  KEY idx_requests_status (status),
  KEY idx_requests_due (due_date),
  CONSTRAINT fk_requests_org
    FOREIGN KEY (organization_id) REFERENCES organizations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_requests_equipment
    FOREIGN KEY (equipment_id) REFERENCES equipment (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_requests_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_requests_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_due_after_start
    CHECK (due_date >= start_date)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  6. ACTIVITY LOG
--     Maps to: dashboard.js  activity[]  (addActivity function)
--     Fields: id, text, icon, date
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED    NOT NULL,
  performed_by    INT UNSIGNED,                   -- user who triggered the action
  icon            VARCHAR(4)      NOT NULL DEFAULT '✳',
  message         VARCHAR(255)    NOT NULL,
  -- Optional references for context
  related_request_id   INT UNSIGNED,
  related_equipment_id INT UNSIGNED,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_org (organization_id),
  KEY idx_activity_created (created_at),
  CONSTRAINT fk_activity_org
    FOREIGN KEY (organization_id) REFERENCES organizations (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_activity_user
    FOREIGN KEY (performed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_activity_request
    FOREIGN KEY (related_request_id) REFERENCES borrow_requests (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_activity_equipment
    FOREIGN KEY (related_equipment_id) REFERENCES equipment (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  7. SESSIONS  (server-side session store)
--     Replaces the browser localStorage sessions in auth.js /
--     dashboard.js for a production deployment
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_sessions (
  id          CHAR(36)        NOT NULL,   -- UUID
  user_id     INT UNSIGNED    NOT NULL,
  ip_address  VARCHAR(45),
  user_agent  VARCHAR(512),
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  8. EQUIPMENT MAINTENANCE LOG
--     Tracks history when condition/status is updated
--     (supports the "Under maintenance" and "Damaged" statuses
--      visible in admin.html and dashboard.js)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment_maintenance (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  equipment_id    INT UNSIGNED    NOT NULL,
  reported_by     INT UNSIGNED,
  issue_notes     VARCHAR(500)    NOT NULL,
  status_before   ENUM('Available','Borrowed','Reserved','Under maintenance','Damaged'),
  status_after    ENUM('Available','Borrowed','Reserved','Under maintenance','Damaged'),
  condition_before ENUM('Good','Fair','Needs repair','Damaged'),
  condition_after  ENUM('Good','Fair','Needs repair','Damaged'),
  resolved_at     DATETIME,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_maintenance_equipment (equipment_id),
  CONSTRAINT fk_maintenance_equipment
    FOREIGN KEY (equipment_id) REFERENCES equipment (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_maintenance_reported_by
    FOREIGN KEY (reported_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  9. EQUIPMENT SUGGESTIONS / DONATIONS
--     Allows members to suggest or donate items to the community pool,
--     which stewards review and approve before adding to equipment.
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment_suggestions (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  organization_id  INT UNSIGNED    NOT NULL DEFAULT 1,
  member_id        INT UNSIGNED    NOT NULL,
  item_name        VARCHAR(120)    NOT NULL,
  category         VARCHAR(60)     NOT NULL DEFAULT 'Other',
  description      TEXT,
  quantity         INT             NOT NULL DEFAULT 1,
  condition_state  VARCHAR(40)     NOT NULL DEFAULT 'Good',
  image_url        VARCHAR(2048)   NULL,
  status           ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  rejection_reason TEXT            NULL,
  reviewed_by      INT UNSIGNED    NULL,
  reviewed_at      DATETIME        NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_suggestions_org (organization_id),
  KEY idx_suggestions_member (member_id),
  KEY idx_suggestions_status (status),
  CONSTRAINT fk_suggestions_org
    FOREIGN KEY (organization_id) REFERENCES organizations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_suggestions_member
    FOREIGN KEY (member_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_suggestions_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  10. LOGIN ATTEMPTS (audit and rate limiting)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(254) NOT NULL DEFAULT '',
  user_id      INT UNSIGNED NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  successful   TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_address, attempted_at),
  KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────
--  SEED DATA — matches dashboard.js defaultData()
-- ───────────────────────────────────────────────────────────

-- Organization
INSERT INTO organizations (name, description, avatar_text)
VALUES ('Barangay Central', 'Community workspace for shared equipment borrowing.', 'BC');

-- Equipment categories (from categoryIcons in dashboard.js)
INSERT INTO equipment_categories (name, emoji) VALUES
  ('Tools',       '🧰'),
  ('Events',      '🎪'),
  ('Electronics', '🔌'),
  ('Garden',      '🌱'),
  ('Sports',      '⚽'),
  ('Other',       '📦');

-- Demo users (PBKDF2-SHA256, 120,000 iterations):
--   Administrator:        maya.santos@example.org   / admin12345
--   Organization officer: noah.cruz@example.org     / officer12345
--   Custodian:            rafi.dc@example.org       / custodian12345
--   Members:              lina.reyes@example.org    / member12345
--                         ivo.m@example.org         / member12345
INSERT INTO users (organization_id, name, email, password_hash, salt, role, joined_date) VALUES
  (1, 'Maya Santos',    'maya.santos@example.org', '16a4e4c6fe32d4858c44fba3390eccf49e2530599885ce95cdd2bf49430fc28c', 'IQ5OHOwvMIi5kkBYsmqQZA==', 'Administrator',       '2025-03-12'),
  (1, 'Noah Cruz',      'noah.cruz@example.org',   '4f54f536d4d0fa7023b34364e92e7e2b886aaf9ced833d36ba8f80a915f47b85', 'BEHXt7x4N/9xy/HquL61jg==', 'Organization officer','2025-04-20'),
  (1, 'Rafi Dela Cruz', 'rafi.dc@example.org',     'c262813cad4bf4a348658d5deacd495f7a50048dd56591f684109a55a8b16702', 'xfmUtGfOpD2GxFNLMBnBrQ==', 'Custodian',           '2025-05-02'),
  (1, 'Lina Reyes',     'lina.reyes@example.org',  'a57bd3d4a121acce61d7ee16a020d83c925e8d4f28fda0cdad61ed33e58b4c58', 'gnssI08SrNfK7qwVMzIy6A==', 'Member',              '2025-07-08'),
  (1, 'Ivo Mercado',    'ivo.m@example.org',       '265773eedd02878342ab6b8ffeeee43f2f4e13b04c29aceeae4225385653c514', 'KbMIOf4VYff0gaL4AxFYWw==', 'Member',              '2025-08-14');

-- Demo equipment (matches equipment[] in dashboard.js defaultData)
INSERT INTO equipment
  (organization_id, category_id, name, emoji, description, quantity, available, condition_state, status, added_by)
VALUES
  (1, (SELECT id FROM equipment_categories WHERE name='Tools'),
      'Cordless drill set', '🛠️',
      'For those little fixes and big DIY dreams.', 3, 2, 'Good', 'Available', 1),
  (1, (SELECT id FROM equipment_categories WHERE name='Events'),
      'Folding event tent', '⛺',
      'A little shade for the next neighborhood gathering.', 2, 0, 'Good', 'Borrowed', 1),
  (1, (SELECT id FROM equipment_categories WHERE name='Electronics'),
      'Portable speaker', '🔊',
      'Bring people together, one playlist at a time.', 4, 3, 'Good', 'Available', 1),
  (1, (SELECT id FROM equipment_categories WHERE name='Garden'),
      'Community garden kit', '🪴',
      'Gloves, hand trowels, and room to grow.', 8, 6, 'Good', 'Available', 1),
  (1, (SELECT id FROM equipment_categories WHERE name='Electronics'),
      'Projector & screen', '📽️',
      'Movie nights, workshops, and bright ideas.', 1, 0, 'Fair', 'Under maintenance', 1),
  (1, (SELECT id FROM equipment_categories WHERE name='Events'),
      'Folding tables', '🪑',
      'Sturdy tables ready for a crowd.', 6, 4, 'Good', 'Available', 1);

-- Demo borrow requests (matches requests[] in dashboard.js defaultData)
-- Using DATE_ADD(CURDATE(), INTERVAL n DAY) to mirror the offsetDate() calls
INSERT INTO borrow_requests
  (organization_id, equipment_id, user_id, reviewed_by, quantity,
   start_date, due_date, purpose, status, condition_out,
   return_condition, returned_at, created_at)
VALUES
  -- r1: Lina Reyes borrowed Folding event tent, Approved
  (1, 2, 4, 1, 1,
   DATE_ADD(CURDATE(), INTERVAL -2 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY),
   'Weekend community market', 'Approved', 'Good',
   NULL, NULL,
   DATE_ADD(CURDATE(), INTERVAL -5 DAY)),

  -- r2: Noah Cruz requested Cordless drill set, Pending
  (1, 1, 2, NULL, 1,
   DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY),
   'Repairing the reading nook shelves', 'Pending', 'Good',
   NULL, NULL,
   DATE_ADD(CURDATE(), INTERVAL -1 DAY)),

  -- r3: Maya Santos borrowed Portable speaker, Overdue
  (1, 3, 1, 1, 1,
   DATE_ADD(CURDATE(), INTERVAL -12 DAY), DATE_ADD(CURDATE(), INTERVAL -5 DAY),
   'Youth group film afternoon', 'Overdue', 'Good',
   NULL, NULL,
   DATE_ADD(CURDATE(), INTERVAL -15 DAY)),

  -- r4: Rafi Dela Cruz borrowed Community garden kit, Approved
  (1, 4, 3, 1, 2,
   DATE_ADD(CURDATE(), INTERVAL -1 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY),
   'Saturday garden workday', 'Approved', 'Good',
   NULL, NULL,
   DATE_ADD(CURDATE(), INTERVAL -4 DAY)),

  -- r5: Lina Reyes returned Folding tables
  (1, 6, 4, 3, 2,
   DATE_ADD(CURDATE(), INTERVAL -21 DAY), DATE_ADD(CURDATE(), INTERVAL -17 DAY),
   'Community potluck', 'Returned', 'Good',
   'Good', DATE_ADD(CURDATE(), INTERVAL -17 DAY),
   DATE_ADD(CURDATE(), INTERVAL -25 DAY)),

  -- r6: Ivo Mercado requested Cordless drill set, Pending
  (1, 1, 5, NULL, 1,
   DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 8 DAY),
   'Fixing a broken fence', 'Pending', 'Good',
   NULL, NULL,
   DATE_ADD(CURDATE(), INTERVAL -1 DAY));

-- ───────────────────────────────────────────────────────────
--  USEFUL VIEWS  (mirror what dashboard.js computes in JS)
-- ───────────────────────────────────────────────────────────

-- v_pending_requests  — "Needs your attention" overview card
CREATE OR REPLACE VIEW v_pending_requests AS
SELECT
  br.id,
  e.name        AS equipment_name,
  e.emoji,
  ec.name       AS category,
  u.name        AS borrower_name,
  u.email       AS borrower_email,
  br.quantity,
  br.start_date,
  br.due_date,
  br.purpose,
  br.status,
  br.created_at
FROM borrow_requests br
JOIN equipment e  ON e.id  = br.equipment_id
JOIN equipment_categories ec ON ec.id = e.category_id
JOIN users     u  ON u.id  = br.user_id
WHERE br.status = 'Pending'
ORDER BY br.created_at ASC;

-- v_active_borrowings  — items currently out in the community
CREATE OR REPLACE VIEW v_active_borrowings AS
SELECT
  br.id,
  e.name        AS equipment_name,
  e.emoji,
  u.name        AS borrower_name,
  u.email       AS borrower_email,
  br.quantity,
  br.start_date,
  br.due_date,
  br.status,
  DATEDIFF(CURDATE(), br.due_date) AS days_overdue
FROM borrow_requests br
JOIN equipment e ON e.id = br.equipment_id
JOIN users     u ON u.id = br.user_id
WHERE br.status IN ('Approved', 'Overdue')
ORDER BY br.due_date ASC;

-- v_overdue_requests  — "A gentle nudge" report table
CREATE OR REPLACE VIEW v_overdue_requests AS
SELECT
  br.id,
  e.name        AS equipment_name,
  e.emoji,
  u.name        AS borrower_name,
  u.email       AS borrower_email,
  br.due_date,
  DATEDIFF(CURDATE(), br.due_date) AS days_late
FROM borrow_requests br
JOIN equipment e ON e.id = br.equipment_id
JOIN users     u ON u.id = br.user_id
WHERE br.status = 'Overdue'
   OR (br.status = 'Approved' AND br.due_date < CURDATE())
ORDER BY days_late DESC;

-- v_equipment_usage  — "Most loved" usage chart in reports
CREATE OR REPLACE VIEW v_equipment_usage AS
SELECT
  e.id,
  e.name,
  e.emoji,
  ec.name       AS category,
  COUNT(br.id)  AS borrow_count
FROM equipment e
LEFT JOIN equipment_categories ec ON ec.id = e.category_id
LEFT JOIN borrow_requests br ON br.equipment_id = e.id
GROUP BY e.id, e.name, e.emoji, ec.name
ORDER BY borrow_count DESC;

-- v_inventory_health  — "Collection health" / status breakdown
CREATE OR REPLACE VIEW v_inventory_health AS
SELECT
  e.status,
  COUNT(*)                              AS equipment_types,
  SUM(e.quantity)                       AS total_units,
  SUM(e.available)                      AS available_units
FROM equipment e
GROUP BY e.status;

-- v_member_requests  — member dashboard "My requests" table
CREATE OR REPLACE VIEW v_member_requests AS
SELECT
  br.id,
  u.name        AS borrower_name,
  u.email,
  e.name        AS equipment_name,
  e.emoji,
  ec.name       AS category,
  br.quantity,
  br.start_date,
  br.due_date,
  br.purpose,
  br.status,
  br.return_condition,
  br.returned_at,
  br.created_at
FROM borrow_requests br
JOIN equipment          e  ON e.id  = br.equipment_id
JOIN equipment_categories ec ON ec.id = e.category_id
JOIN users              u  ON u.id  = br.user_id
ORDER BY br.created_at DESC;

-- ───────────────────────────────────────────────────────────
--  STORED PROCEDURES  (encapsulate business logic from
--  dashboard.js:  reviewRequest(), recordReturn(), etc.)
-- ───────────────────────────────────────────────────────────
DELIMITER $$

-- sp_approve_request  — mirrors reviewRequest(id, 'Approved')
CREATE PROCEDURE sp_approve_request(
  IN p_request_id   INT UNSIGNED,
  IN p_reviewer_id  INT UNSIGNED
)
BEGIN
  DECLARE v_equipment_id  INT UNSIGNED;
  DECLARE v_quantity       SMALLINT UNSIGNED;
  DECLARE v_available      SMALLINT UNSIGNED;
  DECLARE v_condition      VARCHAR(20);

  START TRANSACTION;

  SELECT equipment_id, quantity INTO v_equipment_id, v_quantity
  FROM borrow_requests
  WHERE id = p_request_id AND status = 'Pending'
  FOR UPDATE;

  SELECT available, condition_state INTO v_available, v_condition
  FROM equipment
  WHERE id = v_equipment_id
  FOR UPDATE;

  IF v_available < v_quantity THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Not enough units available to approve this request.';
  END IF;

  UPDATE borrow_requests
  SET status       = 'Approved',
      reviewed_by  = p_reviewer_id,
      condition_out = v_condition,
      reviewed_at  = NOW()
  WHERE id = p_request_id;

  UPDATE equipment
  SET available = available - v_quantity,
      status    = IF(available - v_quantity = 0, 'Borrowed', status)
  WHERE id = v_equipment_id;

  INSERT INTO activity_log (organization_id, performed_by, icon, message,
                            related_request_id, related_equipment_id)
  SELECT br.organization_id, p_reviewer_id, '✓',
         CONCAT((SELECT name FROM users WHERE id = p_reviewer_id),
                ' approved a borrowing request for ',
                (SELECT name FROM equipment WHERE id = v_equipment_id)),
         p_request_id, v_equipment_id
  FROM borrow_requests br WHERE br.id = p_request_id;

  COMMIT;
END$$

-- sp_reject_request  — mirrors reviewRequest(id, 'Rejected')
CREATE PROCEDURE sp_reject_request(
  IN p_request_id   INT UNSIGNED,
  IN p_reviewer_id  INT UNSIGNED
)
BEGIN
  UPDATE borrow_requests
  SET status      = 'Rejected',
      reviewed_by = p_reviewer_id,
      reviewed_at = NOW()
  WHERE id = p_request_id AND status = 'Pending';

  INSERT INTO activity_log (organization_id, performed_by, icon, message,
                            related_request_id)
  SELECT organization_id, p_reviewer_id, '×',
         CONCAT((SELECT name FROM users WHERE id = p_reviewer_id),
                ' declined a borrowing request'),
         p_request_id
  FROM borrow_requests WHERE id = p_request_id;
END$$

-- sp_record_return  — mirrors recordReturn() in dashboard.js
CREATE PROCEDURE sp_record_return(
  IN p_request_id       INT UNSIGNED,
  IN p_reviewer_id      INT UNSIGNED,
  IN p_return_condition VARCHAR(20),
  IN p_return_notes     VARCHAR(180)
)
BEGIN
  DECLARE v_equipment_id INT UNSIGNED;
  DECLARE v_quantity      SMALLINT UNSIGNED;

  START TRANSACTION;

  SELECT equipment_id, quantity INTO v_equipment_id, v_quantity
  FROM borrow_requests
  WHERE id = p_request_id AND status IN ('Approved', 'Overdue')
  FOR UPDATE;

  UPDATE borrow_requests
  SET status           = 'Returned',
      return_condition  = p_return_condition,
      return_notes      = p_return_notes,
      returned_at       = CURDATE(),
      reviewed_by       = p_reviewer_id,
      reviewed_at       = NOW()
  WHERE id = p_request_id;

  -- Adjust equipment state just as dashboard.js recordReturn() does
  IF p_return_condition = 'Missing' THEN
    UPDATE equipment
    SET quantity = GREATEST(0, quantity - v_quantity)
    WHERE id = v_equipment_id;
  ELSE
    UPDATE equipment
    SET available = LEAST(quantity, available + v_quantity)
    WHERE id = v_equipment_id;
  END IF;

  IF p_return_condition IN ('Damaged', 'Needs repair') THEN
    UPDATE equipment
    SET condition_state = p_return_condition,
        status          = 'Damaged'
    WHERE id = v_equipment_id;
  ELSEIF p_return_condition = 'Incomplete' THEN
    UPDATE equipment
    SET condition_state = 'Fair',
        status = IF(available > 0, 'Available', 'Borrowed')
    WHERE id = v_equipment_id;
  ELSE
    UPDATE equipment
    SET status = IF(available > 0, 'Available', 'Borrowed')
    WHERE id = v_equipment_id;
  END IF;

  INSERT INTO activity_log (organization_id, performed_by, icon, message,
                            related_request_id, related_equipment_id)
  SELECT br.organization_id, p_reviewer_id,
         IF(p_return_condition = 'Good', '↩', '!'),
         CONCAT((SELECT name FROM users WHERE id = br.user_id),
                ' returned ',
                (SELECT name FROM equipment WHERE id = v_equipment_id),
                ' · ', LOWER(p_return_condition)),
         p_request_id, v_equipment_id
  FROM borrow_requests br WHERE br.id = p_request_id;

  COMMIT;
END$$

-- sp_create_borrow_request  — mirrors createRequest() in dashboard.js
CREATE PROCEDURE sp_create_borrow_request(
  IN p_equipment_id INT UNSIGNED,
  IN p_user_id      INT UNSIGNED,
  IN p_quantity     SMALLINT UNSIGNED,
  IN p_start_date   DATE,
  IN p_due_date     DATE,
  IN p_purpose      VARCHAR(255),
  OUT p_new_id      INT UNSIGNED
)
BEGIN
  DECLARE v_available      SMALLINT UNSIGNED;
  DECLARE v_status         VARCHAR(20);
  DECLARE v_org_id         INT UNSIGNED;
  DECLARE v_condition      VARCHAR(20);
  DECLARE v_equipment_name VARCHAR(60);

  SELECT available, status, organization_id, condition_state, name
    INTO v_available, v_status, v_org_id, v_condition, v_equipment_name
  FROM equipment WHERE id = p_equipment_id;

  IF v_available < p_quantity OR v_status != 'Available' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Equipment is not available in the requested quantity.';
  END IF;

  IF p_start_date < CURDATE() OR p_due_date < p_start_date THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Please choose valid borrowing dates.';
  END IF;

  INSERT INTO borrow_requests
    (organization_id, equipment_id, user_id, quantity,
     start_date, due_date, purpose, status, condition_out)
  VALUES
    (v_org_id, p_equipment_id, p_user_id, p_quantity,
     p_start_date, p_due_date, p_purpose, 'Pending', v_condition);

  SET p_new_id = LAST_INSERT_ID();

  INSERT INTO activity_log (organization_id, performed_by, icon, message,
                            related_request_id, related_equipment_id)
  VALUES (v_org_id, p_user_id, '↗',
          CONCAT((SELECT name FROM users WHERE id = p_user_id),
                 ' requested ', v_equipment_name),
          p_new_id, p_equipment_id);
END$$

DELIMITER ;

-- ───────────────────────────────────────────────────────────
--  END OF SCHEMA
-- ───────────────────────────────────────────────────────────


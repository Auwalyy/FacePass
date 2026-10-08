-- =====================================================================
-- Facial Recognition-Based Student Examination Verification System
-- Database schema (MySQL 5.7+ / MariaDB 10.4+ as shipped with XAMPP)
--
-- Import order:
--   1. database/schema.sql   (this file - creates the database + tables)
--   2. database/seed.sql     (demo data)
--
-- NOTE ON NAMING:
--   users.student_id is the human-readable matriculation number
--   (e.g. "SWE/2021/001").  In every other table, the column
--   `student_id` is a FOREIGN KEY to users.id (the numeric primary key).
-- =====================================================================

DROP DATABASE IF EXISTS exam_verification_system;
CREATE DATABASE exam_verification_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE exam_verification_system;

-- ---------------------------------------------------------------------
-- 1. users  (administrators and students share one table)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id       VARCHAR(30)  NULL,                -- matric number; NULL for admins
    first_name       VARCHAR(60)  NOT NULL,
    last_name        VARCHAR(60)  NOT NULL,
    email            VARCHAR(120) NOT NULL,
    phone            VARCHAR(20)  NULL,
    password         VARCHAR(255) NOT NULL,            -- password_hash() output
    role             ENUM('admin','student') NOT NULL DEFAULT 'student',
    department       VARCHAR(100) NULL,
    level            VARCHAR(10)  NULL,                -- e.g. 100, 200, 300, 400
    faculty          VARCHAR(100) NULL,
    face_registered  TINYINT(1)   NOT NULL DEFAULT 0,
    account_status   ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_student_id (student_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_dept_level (department, level),
    KEY idx_users_status (account_status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. student_faces  (enrollment samples - files live in uploads/faces/)
-- ---------------------------------------------------------------------
CREATE TABLE student_faces (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id     INT UNSIGNED NOT NULL,              -- FK users.id
    face_image     VARCHAR(255) NOT NULL,              -- randomised file name only (no path)
    face_encoding  TEXT         NULL,                  -- JSON metadata from OpenCV (bbox, size, method)
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faces_image (face_image),
    KEY idx_faces_student (student_id),
    CONSTRAINT fk_faces_student FOREIGN KEY (student_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. examinations
-- ---------------------------------------------------------------------
CREATE TABLE examinations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_code   VARCHAR(20)  NOT NULL,
    course_title  VARCHAR(150) NOT NULL,
    department    VARCHAR(100) NOT NULL,
    level         VARCHAR(10)  NOT NULL,
    exam_date     DATE         NOT NULL,
    start_time    TIME         NOT NULL,
    end_time      TIME         NOT NULL,
    venue         VARCHAR(120) NOT NULL,
    status        ENUM('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exams_date (exam_date),
    KEY idx_exams_status (status),
    KEY idx_exams_dept_level (department, level)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. exam_eligibility  (payment / clearance per student per exam)
-- ---------------------------------------------------------------------
CREATE TABLE exam_eligibility (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id        INT UNSIGNED NOT NULL,           -- FK users.id
    exam_id           INT UNSIGNED NOT NULL,           -- FK examinations.id
    school_fees_paid  TINYINT(1)   NOT NULL DEFAULT 0,
    eligible          TINYINT(1)   NOT NULL DEFAULT 0,
    reason            VARCHAR(255) NULL,               -- why a student is not eligible
    verified_at       DATETIME     NULL,               -- last time eligibility was confirmed at the hall
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eligibility_student_exam (student_id, exam_id),
    KEY idx_eligibility_exam (exam_id),
    CONSTRAINT fk_eligibility_student FOREIGN KEY (student_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eligibility_exam FOREIGN KEY (exam_id)
        REFERENCES examinations (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. attendance
-- ---------------------------------------------------------------------
CREATE TABLE attendance (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id           INT UNSIGNED NOT NULL,        -- FK users.id
    exam_id              INT UNSIGNED NOT NULL,        -- FK examinations.id
    verification_method  ENUM('facial_recognition','manual') NOT NULL DEFAULT 'facial_recognition',
    verification_time    DATETIME     NULL,
    status               ENUM('present','absent','rejected') NOT NULL DEFAULT 'present',
    confidence_score     DECIMAL(5,4) NULL,            -- 0.0000 - 1.0000 (NULL for manual)
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_student_exam (student_id, exam_id),   -- no duplicates
    KEY idx_attendance_exam (exam_id),
    KEY idx_attendance_time (verification_time),
    KEY idx_attendance_status (status),
    CONSTRAINT fk_attendance_student FOREIGN KEY (student_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_exam FOREIGN KEY (exam_id)
        REFERENCES examinations (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. complaints
-- ---------------------------------------------------------------------
CREATE TABLE complaints (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id      INT UNSIGNED NOT NULL,             -- FK users.id
    exam_id         INT UNSIGNED NULL,                 -- FK examinations.id (optional)
    subject         VARCHAR(150) NOT NULL,
    description     TEXT         NOT NULL,
    status          ENUM('pending','reviewed','resolved','rejected') NOT NULL DEFAULT 'pending',
    admin_response  TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_complaints_student (student_id),
    KEY idx_complaints_status (status),
    CONSTRAINT fk_complaints_student FOREIGN KEY (student_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_complaints_exam FOREIGN KEY (exam_id)
        REFERENCES examinations (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,                    -- who performed the action (NULL = system/anonymous)
    action       VARCHAR(50)  NOT NULL,                -- e.g. FACE_VERIFY_SUCCESS
    description  TEXT         NULL,
    ip_address   VARCHAR(45)  NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),
    KEY idx_audit_ip_action (ip_address, action, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. settings  (key/value store: active kiosk exam, recognition threshold)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
    setting_key    VARCHAR(60)  NOT NULL,
    setting_value  VARCHAR(255) NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('active_exam_id', NULL),
    ('recognition_threshold', '70'),
    ('allow_student_self_enrollment', '1');

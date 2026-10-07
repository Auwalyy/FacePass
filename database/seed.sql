-- =====================================================================
-- Demo / seed data
-- Run AFTER database/schema.sql
--
-- Demo credentials (change them after the demo!):
--   Admin    : admin@university.edu.ng           / Admin@123
--   Students : CSC/2021/001 ... CSC/2021/005      / Student@123
--              (students may also log in with their e-mail)
--
-- Examination dates are relative to the day you import this file, so the
-- "ongoing" demo examination is always TODAY.
--
-- No faces are seeded: biometric samples must be captured with a real
-- camera through the Face Enrollment page.
-- =====================================================================

USE exam_verification_system;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE complaints;
TRUNCATE TABLE attendance;
TRUNCATE TABLE exam_eligibility;
TRUNCATE TABLE student_faces;
TRUNCATE TABLE examinations;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Users  (password hashes generated with bcrypt, cost 10 - PHP password_hash compatible)
--   Admin@123   -> __ADMIN_HASH__
--   Student@123 -> __STUDENT_HASH__
-- ---------------------------------------------------------------------
INSERT INTO users (id, student_id, first_name, last_name, email, phone, password, role, department, level, faculty, face_registered, account_status) VALUES
(1, NULL,           'System',  'Administrator', 'admin@university.edu.ng',                    '08030000000', '__ADMIN_HASH__',   'admin',   'Examinations Office', NULL,  NULL,                      0, 'active'),
(2, 'CSC/2021/001', 'Auwal',   'Muhammad',      'auwal.muhammad@student.university.edu.ng',   '08031111111', '__STUDENT_HASH__', 'student', 'Computer Science',    '400', 'Physical Sciences', 0, 'active'),
(3, 'CSC/2021/002', 'Aisha',   'Bello',         'aisha.bello@student.university.edu.ng',      '08032222222', '__STUDENT_HASH__', 'student', 'Computer Science',    '400', 'Physical Sciences', 0, 'active'),
(4, 'CSC/2021/003', 'Ibrahim', 'Musa',          'ibrahim.musa@student.university.edu.ng',     '08033333333', '__STUDENT_HASH__', 'student', 'Computer Science',    '400', 'Physical Sciences', 0, 'active'),
(5, 'CSC/2021/004', 'Fatima',  'Abdullahi',     'fatima.abdullahi@student.university.edu.ng', '08034444444', '__STUDENT_HASH__', 'student', 'Computer Science',    '400', 'Physical Sciences', 0, 'active'),
(6, 'CSC/2021/005', 'Chinedu', 'Okafor',        'chinedu.okafor@student.university.edu.ng',   '08035555555', '__STUDENT_HASH__', 'student', 'Computer Science',    '400', 'Physical Sciences', 0, 'active');

-- ---------------------------------------------------------------------
-- Examinations
--   1  CSC401  TODAY            ongoing    (selected on the kiosk)
--   2  CSC403  TODAY + 3 days   scheduled
--   3  CSC405  TODAY - 5 days   completed
-- ---------------------------------------------------------------------
INSERT INTO examinations (id, course_code, course_title, department, level, exam_date, start_time, end_time, venue, status) VALUES
(1, 'CSC401', 'Software Engineering',    'Computer Science', '400', CURDATE(),                           '09:00:00', '12:00:00', 'Main Examination Hall A', 'ongoing'),
(2, 'CSC403', 'Computer Networks',       'Computer Science', '400', DATE_ADD(CURDATE(), INTERVAL 3 DAY), '13:00:00', '16:00:00', 'Lecture Theatre 2',       'scheduled'),
(3, 'CSC405', 'Artificial Intelligence', 'Computer Science', '400', DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:00:00', '11:00:00', 'Main Examination Hall B', 'completed');

-- ---------------------------------------------------------------------
-- Eligibility / payment
--   Ibrahim Musa (id 4) has NOT paid school fees for CSC401 -> NOT ELIGIBLE demo
-- ---------------------------------------------------------------------
INSERT INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason, verified_at) VALUES
(2, 1, 1, 1, NULL, NULL),
(3, 1, 1, 1, NULL, NULL),
(4, 1, 0, 0, 'School fees/payment requirement has not been satisfied.', NULL),
(5, 1, 1, 1, NULL, NOW()),
(6, 1, 1, 1, NULL, NULL),
(2, 2, 1, 1, NULL, NULL),
(3, 2, 1, 1, NULL, NULL),
(4, 2, 0, 0, 'School fees/payment requirement has not been satisfied.', NULL),
(5, 2, 1, 1, NULL, NULL),
(6, 2, 1, 1, NULL, NULL),
(2, 3, 1, 1, NULL, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(3, 3, 1, 1, NULL, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(4, 3, 1, 1, NULL, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(5, 3, 1, 1, NULL, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(6, 3, 1, 1, NULL, NULL);

-- ---------------------------------------------------------------------
-- Attendance
-- ---------------------------------------------------------------------
INSERT INTO attendance (student_id, exam_id, verification_method, verification_time, status, confidence_score) VALUES
-- CSC405 (completed, 5 days ago)
(2, 3, 'facial_recognition', CONCAT(DATE_SUB(CURDATE(), INTERVAL 5 DAY), ' 08:41:12'), 'present', 0.8132),
(3, 3, 'facial_recognition', CONCAT(DATE_SUB(CURDATE(), INTERVAL 5 DAY), ' 08:43:55'), 'present', 0.7764),
(4, 3, 'manual',             CONCAT(DATE_SUB(CURDATE(), INTERVAL 5 DAY), ' 08:50:03'), 'present', NULL),
(5, 3, 'facial_recognition', CONCAT(DATE_SUB(CURDATE(), INTERVAL 5 DAY), ' 08:52:40'), 'present', 0.8411),
(6, 3, 'manual',             NULL,                                                      'absent',  NULL),
-- CSC401 (today) - Fatima already verified
(5, 1, 'facial_recognition', CONCAT(CURDATE(), ' 08:47:19'), 'present', 0.8025);

-- ---------------------------------------------------------------------
-- Complaints
-- ---------------------------------------------------------------------
INSERT INTO complaints (student_id, exam_id, subject, description, status, admin_response) VALUES
(6, 3, 'Attendance not recorded for CSC405',
 'I attended the CSC405 Artificial Intelligence examination but my attendance is showing as absent. I sat in row D, seat 14.',
 'pending', NULL),
(3, 3, 'Wrong verification time on CSC405',
 'My verification time for CSC405 looks wrong, please confirm my attendance was recorded.',
 'resolved', 'Your attendance was recorded correctly at 08:43. No further action required.');

-- ---------------------------------------------------------------------
-- Kiosk configuration: CSC401 is the examination currently being conducted
-- ---------------------------------------------------------------------
UPDATE settings SET setting_value = '1' WHERE setting_key = 'active_exam_id';

-- ---------------------------------------------------------------------
-- A few audit entries so the dashboard is not empty
-- ---------------------------------------------------------------------
INSERT INTO audit_logs (user_id, action, description, ip_address, created_at) VALUES
(1, 'EXAM_CREATE',         'Created examination CSC401 - Software Engineering', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'MANUAL_VERIFICATION', 'Manually verified CSC/2021/003 for CSC405 (camera unavailable)', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'FACE_VERIFY_SUCCESS', 'CSC/2021/004 verified for CSC401 (confidence 0.8025)', '127.0.0.1', CONCAT(CURDATE(), ' 08:47:19'));

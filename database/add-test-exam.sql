-- =====================================================================
-- Add a TEST examination that is running right now, for kiosk testing.
--
-- Safe to run on a database that already has enrolled faces: it only
-- ADDS rows (it does not truncate anything). Run it as many times as you
-- like; each run creates a new test exam and selects it on the kiosk.
--
--   C:\xampp\mysql\bin\mysql.exe -u root < database\add-test-exam.sql
--   (or phpMyAdmin > Import)
--
-- Result:
--   * SWE407 "Software Engineering Project Defence (Test)"
--     today, from 30 minutes ago until 3 hours from now, status ONGOING
--   * every active Software Engineering 400-level student is registered:
--       - paid & eligible           (all students except .../003)
--       - school fees NOT paid      (SWE/2026/003 - for the "not eligible" test)
--   * the exam becomes the verification kiosk examination
-- =====================================================================

USE exam_verification_system;

INSERT INTO examinations (course_code, course_title, department, level, exam_date, start_time, end_time, venue, status)
VALUES (
    'SWE407',
    'Software Engineering Project Defence (Test)',
    'Software Engineering',
    '400',
    CURDATE(),
    IF(DATE(NOW() - INTERVAL 30 MINUTE) < CURDATE(), '00:00:00', TIME_FORMAT(NOW() - INTERVAL 30 MINUTE, '%H:%i:00')),
    IF(DATE(NOW() + INTERVAL 3 HOUR) > CURDATE(), '23:59:00', TIME_FORMAT(NOW() + INTERVAL 3 HOUR, '%H:%i:00')),
    'Software Engineering Lab, New Campus',
    'ongoing'
);

SET @test_exam = LAST_INSERT_ID();

INSERT INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
SELECT id,
       @test_exam,
       IF(student_id LIKE '%/003', 0, 1),
       IF(student_id LIKE '%/003', 0, 1),
       IF(student_id LIKE '%/003', 'School fees/payment requirement has not been satisfied.', NULL)
FROM users
WHERE role = 'student'
  AND account_status = 'active'
  AND department = 'Software Engineering'
  AND level = '400';

UPDATE settings SET setting_value = @test_exam WHERE setting_key = 'active_exam_id';

INSERT INTO audit_logs (user_id, action, description, ip_address)
VALUES (NULL, 'EXAM_CREATE', CONCAT('Test examination SWE407 created by add-test-exam.sql (exam #', @test_exam, ') and selected on the kiosk'), '127.0.0.1');

SELECT e.id, e.course_code, e.course_title, e.exam_date, e.start_time, e.end_time, e.venue, e.status,
       (SELECT COUNT(*) FROM exam_eligibility el WHERE el.exam_id = e.id) AS registered_students
FROM examinations e WHERE e.id = @test_exam;

<?php
/**
 * Complaints API.
 *
 * Student: GET -> own complaints with status and admin response
 * Admin:   GET -> counts per status (+ ?status= list)
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireLogin();
$pdo = db();

if ($user['role'] === 'student') {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.subject, c.status, c.admin_response, c.created_at, c.updated_at, e.course_code
         FROM complaints c LEFT JOIN examinations e ON e.id = c.exam_id
         WHERE c.student_id = ? ORDER BY c.created_at DESC"
    );
    $stmt->execute([$user['id']]);
    json_response(['success' => true, 'complaints' => $stmt->fetchAll()]);
}

$counts = $pdo->query('SELECT status, COUNT(*) AS n FROM complaints GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$response = ['success' => true, 'counts' => array_map('intval', $counts)];

$status = get_str('status', 20);
if (in_array($status, COMPLAINT_STATUSES, true)) {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.subject, c.status, c.created_at, u.student_id, e.course_code
         FROM complaints c JOIN users u ON u.id = c.student_id LEFT JOIN examinations e ON e.id = c.exam_id
         WHERE c.status = ? ORDER BY c.created_at DESC LIMIT 50"
    );
    $stmt->execute([$status]);
    $response['complaints'] = $stmt->fetchAll();
}

json_response($response);

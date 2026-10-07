<?php
/**
 * Streams a stored face sample to an administrator.
 * uploads/faces/ is blocked from direct web access (.htaccess), so this
 * authenticated endpoint is the only way to view biometric images.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();

$stmt = db()->prepare('SELECT face_image FROM student_faces WHERE id = ?');
$stmt->execute([get_int('id')]);
$file = $stmt->fetchColumn();

$path = $file ? FACES_PATH . DIRECTORY_SEPARATOR . basename($file) : '';
if (!$file || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ALLOWED_IMAGE_MIME, true)) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);

<?php
/**
 * General helper functions shared by every page.
 */

defined('APP_INIT') or exit('No direct access.');

const LEVELS = ['100', '200', '300', '400', '500'];
const EXAM_STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled'];
const ATTENDANCE_STATUSES = ['present', 'absent', 'rejected'];
const COMPLAINT_STATUSES = ['pending', 'reviewed', 'resolved', 'rejected'];
const ACCOUNT_STATUSES = ['active', 'inactive', 'suspended'];

// Audit actions that represent a verification attempt at the examination hall.
const VERIFY_ACTIONS = [
    'FACE_VERIFY_SUCCESS', 'FACE_NOT_RECOGNIZED', 'FACE_VERIFY_ERROR', 'ELIGIBILITY_REJECTED',
    'VERIFY_DUPLICATE', 'VERIFY_ACCOUNT_INACTIVE', 'MANUAL_VERIFICATION',
];

// ---------------------------------------------------------------------
// Output / URLs
// ---------------------------------------------------------------------

/** Escape a value for safe HTML output (XSS protection). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base URL of the app, e.g. "/exam-verification-system" (no trailing slash). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (BASE_URL_OVERRIDE !== null) {
        return $base = rtrim(BASE_URL_OVERRIDE, '/');
    }
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = realpath(ROOT_PATH) ?: ROOT_PATH;
    $docRoot = str_replace('\\', '/', $docRoot);
    $root = str_replace('\\', '/', $root);
    $relative = '';
    if ($docRoot !== '' && stripos($root, $docRoot) === 0) {
        $relative = trim(substr($root, strlen($docRoot)), '/');
    }
    $base = $relative === '' ? '' : '/' . $relative;
    return $base;
}

/** Build an application URL from a project-relative path. */
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Send a JSON response and stop. */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// Flash messages (Bootstrap alerts shown on the next page load)
// ---------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------------------------------------------------------------------
// Input helpers (always validate - these only trim/cast)
// ---------------------------------------------------------------------

function post_str(string $key, int $maxLen = 255): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $maxLen) : '';
}

function get_str(string $key, int $maxLen = 100): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $maxLen) : '';
}

function get_int(string $key, int $default = 0): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : $value;
}

function post_int(string $key, int $default = 0): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : $value;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function valid_time(string $value): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value);
}

// ---------------------------------------------------------------------
// Logging / auditing
// ---------------------------------------------------------------------

function client_ip(): string
{
    // REMOTE_ADDR only - forwarded headers are trivially spoofable.
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function log_error(string $message): void
{
    error_log('[' . date('c') . '] ' . $message);
}

/** Write an entry to the audit_logs table. Never throws. */
function audit_log(string $action, string $description = '', ?int $userId = null): void
{
    try {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, mb_substr($description, 0, 2000), client_ip()]);
    } catch (Throwable $e) {
        log_error('Audit log failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Settings (key/value table)
// ---------------------------------------------------------------------

function get_setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return ($value === false || $value === null) ? $default : (string) $value;
}

function set_setting(string $key, ?string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function recognition_threshold(): float
{
    $value = (float) get_setting('recognition_threshold', (string) DEFAULT_RECOGNITION_THRESHOLD);
    return $value > 0 ? $value : (float) DEFAULT_RECOGNITION_THRESHOLD;
}

/** The examination currently selected for the verification kiosk (or null). */
function active_exam(): ?array
{
    $id = (int) get_setting('active_exam_id', '0');
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM examinations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_exam(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM examinations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_student(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Distinct departments (for filter dropdowns). */
function departments(): array
{
    return db()->query(
        "SELECT department FROM users WHERE role = 'student' AND department IS NOT NULL
         UNION SELECT department FROM examinations ORDER BY department"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------

function full_name(array $row): string
{
    return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}

function fmt_date(?string $date): string
{
    return $date ? date('D, d M Y', strtotime($date)) : '—';
}

function fmt_time(?string $time): string
{
    return $time ? date('g:i A', strtotime($time)) : '—';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('d M Y, g:i A', strtotime($dt)) : '—';
}

function fmt_confidence($score): string
{
    return $score === null || $score === '' ? '—' : number_format((float) $score * 100, 1) . '%';
}

/** Bootstrap badge HTML for any known status value. */
function status_badge(?string $status): string
{
    $map = [
        'scheduled' => 'primary', 'ongoing' => 'warning text-dark', 'completed' => 'success',
        'cancelled' => 'secondary', 'present' => 'success', 'absent' => 'danger',
        'rejected' => 'danger', 'pending' => 'warning text-dark', 'reviewed' => 'info text-dark',
        'resolved' => 'success', 'active' => 'success', 'inactive' => 'secondary',
        'suspended' => 'danger', 'facial_recognition' => 'primary', 'manual' => 'dark',
    ];
    $status = (string) $status;
    $class = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge bg-' . $class . '">' . e($label) . '</span>';
}

function yes_no_badge($value, string $yes = 'Yes', string $no = 'No'): string
{
    return $value
        ? '<span class="badge bg-success">' . e($yes) . '</span>'
        : '<span class="badge bg-danger">' . e($no) . '</span>';
}

// ---------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------

/** Returns [page, perPage, offset, totalPages]. */
function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, get_int('page', 1)), $pages);
    return [$page, $perPage, ($page - 1) * $perPage, $pages];
}

function render_pagination(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $params = $_GET;
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm justify-content-end mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $params['page'] = $i;
        $active = $i === $page ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="?'
            . e(http_build_query($params)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}

// ---------------------------------------------------------------------
// File uploads
// ---------------------------------------------------------------------

function random_filename(string $extension): string
{
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

/**
 * Validate an uploaded image from $_FILES.
 * Checks upload errors, size, real MIME type (finfo) and that it decodes as an image.
 *
 * @return array{ok:bool, error?:string, ext?:string}
 */
function validate_image_upload(?array $file): array
{
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'No image received.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed (code ' . (int) $file['error'] . ').'];
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_IMAGE_BYTES) {
        return ['ok' => false, 'error' => 'Image must be smaller than ' . (MAX_IMAGE_BYTES / 1048576) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_MIME, true)) {
        return ['ok' => false, 'error' => 'Only JPEG or PNG images are accepted.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 100 || $info[1] < 100 || $info[0] > 4000 || $info[1] > 4000) {
        return ['ok' => false, 'error' => 'The image is not valid or has unusable dimensions.'];
    }
    return ['ok' => true, 'ext' => $mime === 'image/png' ? 'png' : 'jpg'];
}

/** Move a validated upload into uploads/tmp with a random name. Returns the full path. */
function store_temp_upload(array $file, string $ext): string
{
    if (!is_dir(TMP_UPLOAD_PATH)) {
        mkdir(TMP_UPLOAD_PATH, 0750, true);
    }
    $path = TMP_UPLOAD_PATH . DIRECTORY_SEPARATOR . random_filename($ext);
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Could not store uploaded image.');
    }
    return $path;
}

/**
 * Normalise $_FILES['images'] (multiple input) into a list of single-file arrays.
 */
function normalize_files(?array $files): array
{
    if (!$files || !isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $_) {
        $out[] = [
            'name' => $files['name'][$i], 'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
    }
    return $out;
}

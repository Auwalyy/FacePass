<?php
/**
 * Application configuration.
 *
 * Edit the values in this file to match your XAMPP installation.
 * Every value can also be overridden with an environment variable of the
 * same name (useful when Apache is configured with SetEnv).
 */

defined('APP_INIT') or exit('No direct access.');

/** Read an environment override, falling back to a default. */
function env_or(string $key, $default)
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

// ---------------------------------------------------------------------
// General
// ---------------------------------------------------------------------
define('APP_NAME', 'FacePass Exam Verification');
define('INSTITUTION_NAME', env_or('INSTITUTION_NAME', 'Federal University'));
define('APP_TIMEZONE', env_or('APP_TIMEZONE', 'Africa/Lagos'));

// Set to true ONLY while developing. Shows raw errors in the browser.
define('APP_DEBUG', filter_var(env_or('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

// ---------------------------------------------------------------------
// Database (XAMPP default: user "root" with an empty password)
// ---------------------------------------------------------------------
define('DB_HOST', env_or('DB_HOST', '127.0.0.1'));
define('DB_PORT', env_or('DB_PORT', '3306'));
define('DB_NAME', env_or('DB_NAME', 'exam_verification_system'));
define('DB_USER', env_or('DB_USER', 'root'));
define('DB_PASS', env_or('DB_PASS', ''));

// ---------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('FACES_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'faces');
define('TMP_UPLOAD_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tmp');
define('LOG_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'logs');
define('FR_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'facial_recognition');
define('FR_MODEL_PATH', FR_PATH . DIRECTORY_SEPARATOR . 'models');

/*
 * Base URL of the application, e.g. "/exam-verification-system".
 * Leave as null to auto-detect it from the folder inside htdocs.
 */
define('BASE_URL_OVERRIDE', env_or('BASE_URL', null));

// ---------------------------------------------------------------------
// Python / OpenCV
// ---------------------------------------------------------------------
/*
 * PYTHON_PATH: the Python interpreter PHP will execute.
 *
 * Resolution order:
 *   1. PYTHON_PATH environment variable / the value you type below
 *   2. <project>/venv/Scripts/python.exe   (Windows virtual environment)
 *   3. <project>/venv/bin/python           (Linux/macOS virtual environment)
 *   4. "python" from the system PATH
 *
 * IMPORTANT (Windows/XAMPP): Apache often does not see your user PATH,
 * so a full path to venv\Scripts\python.exe is the most reliable choice.
 * Example: 'C:\\xampp\\htdocs\\exam-verification-system\\venv\\Scripts\\python.exe'
 */
$__python = env_or('PYTHON_PATH', '');
if ($__python === '') {
    $candidates = [
        ROOT_PATH . '\\venv\\Scripts\\python.exe',
        ROOT_PATH . '/venv/bin/python',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $__python = $candidate;
            break;
        }
    }
}
define('PYTHON_PATH', $__python !== '' ? $__python : 'python');
unset($__python);

// Maximum seconds PHP waits for a Python script.
define('PYTHON_TIMEOUT', (int) env_or('PYTHON_TIMEOUT', 60));

/*
 * Default LBPH distance threshold (lower distance = closer match).
 * A face is accepted only when distance <= threshold.
 * Typical useful range: 50 (strict) - 90 (lenient). Default 70.
 * The value saved on Admin > Settings overrides this default.
 */
define('DEFAULT_RECOGNITION_THRESHOLD', 70);

// Number of webcam frames captured during enrollment.
define('ENROLL_FRAMES', 8);
// Minimum number of frames in which a face must be detected.
define('ENROLL_MIN_FACES', 5);

// ---------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------
define('MAX_IMAGE_BYTES', 2 * 1024 * 1024);          // 2 MB per frame
define('ALLOWED_IMAGE_MIME', ['image/jpeg', 'image/png']);

// ---------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------
define('SESSION_NAME', 'EVS_SESSION');
define('SESSION_IDLE_TIMEOUT', 30 * 60);              // 30 minutes
define('LOGIN_MAX_ATTEMPTS', 5);                      // per IP ...
define('LOGIN_LOCKOUT_MINUTES', 15);                  // ... within this window

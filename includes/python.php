<?php
/**
 * PHP -> Python bridge.
 *
 * Runs a script in facial_recognition/ with PYTHON_PATH, WITHOUT a shell
 * (arguments are passed as an array, so nothing can be injected), and
 * parses the JSON object the script prints on stdout.
 *
 * stdout/stderr are redirected to temporary files instead of pipes because
 * non-blocking pipes are unreliable on Windows; this lets us enforce a timeout.
 */

defined('APP_INIT') or exit('No direct access.');

/**
 * @param string   $script  file name inside facial_recognition/ (e.g. "recognize.py")
 * @param string[] $args    command-line arguments
 * @return array{ok:bool, data:array, error:string}
 */
function run_python(string $script, array $args = [], ?int $timeout = null): array
{
    $timeout = $timeout ?? PYTHON_TIMEOUT;
    $scriptPath = FR_PATH . DIRECTORY_SEPARATOR . $script;
    if (!is_file($scriptPath)) {
        return ['ok' => false, 'data' => [], 'error' => "Python script not found: $script"];
    }

    if (!is_dir(TMP_UPLOAD_PATH)) {
        mkdir(TMP_UPLOAD_PATH, 0750, true);
    }
    $outFile = tempnam(TMP_UPLOAD_PATH, 'py_out_');
    $errFile = tempnam(TMP_UPLOAD_PATH, 'py_err_');

    // Array form = no shell involved, so arguments cannot be used for command injection.
    $command = array_merge([PYTHON_PATH, $scriptPath], array_map('strval', $args));

    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']],
        $pipes,
        FR_PATH
    );

    if (!is_resource($process)) {
        @unlink($outFile);
        @unlink($errFile);
        return ['ok' => false, 'data' => [],
            'error' => 'Could not start Python. Check PYTHON_PATH in config/config.php (currently "' . PYTHON_PATH . '").'];
    }
    fclose($pipes[0]);

    $start = microtime(true);
    $timedOut = false;
    $exitCode = -1;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(50000);
    }
    proc_close($process);

    $stdout = (string) @file_get_contents($outFile);
    $stderr = (string) @file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);

    if ($timedOut) {
        log_error("Python timeout ($script) after {$timeout}s");
        return ['ok' => false, 'data' => [], 'error' => 'Face processing timed out.'];
    }

    // The script prints exactly one JSON object; take the last non-empty line to be safe.
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $stdout))));
    $json = $lines ? json_decode(end($lines), true) : null;

    if (!is_array($json)) {
        log_error("Python ($script) exit $exitCode, invalid output. stdout: "
            . mb_substr($stdout, 0, 500) . ' stderr: ' . mb_substr($stderr, 0, 1500));
        $hint = stripos($stderr, 'No module named') !== false
            ? ' Required Python packages are missing - run: pip install -r facial_recognition/requirements.txt'
            : ($exitCode === 9009 || stripos($stderr, 'not recognized') !== false
                ? ' Python was not found - set PYTHON_PATH in config/config.php.' : '');
        return ['ok' => false, 'data' => [], 'error' => 'The face recognition engine failed.' . $hint];
    }

    return ['ok' => true, 'data' => $json, 'error' => ''];
}

/**
 * Re-train the LBPH model from every enrolled face of every ACTIVE student.
 * The database is the source of truth: PHP writes a manifest, Python trains.
 *
 * @return array{ok:bool, message:string}
 */
function retrain_face_model(): array
{
    $rows = db()->query(
        "SELECT f.face_image, u.id AS user_id, u.student_id
         FROM student_faces f
         JOIN users u ON u.id = f.student_id
         WHERE u.role = 'student' AND u.account_status = 'active'"
    )->fetchAll();

    $samples = [];
    foreach ($rows as $row) {
        $path = FACES_PATH . DIRECTORY_SEPARATOR . basename($row['face_image']);
        if (is_file($path)) {
            $samples[] = ['label' => (int) $row['user_id'], 'student_id' => $row['student_id'], 'path' => $path];
        }
    }

    $manifest = tempnam(TMP_UPLOAD_PATH, 'manifest_');
    file_put_contents($manifest, json_encode(['samples' => $samples]));

    $result = run_python('enroll.py', ['train', '--manifest', $manifest, '--model-dir', FR_MODEL_PATH]);
    @unlink($manifest);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error']];
    }
    $data = $result['data'];
    return [
        'ok' => !empty($data['success']),
        'message' => $data['message'] ?? 'Model training finished.',
    ];
}

<?php
/**
 * Administrator dashboard - all figures come from MySQL.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

$stats = [
    'students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    'faces' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND face_registered = 1")->fetchColumn(),
    'today_exams' => (int) $pdo->query("SELECT COUNT(*) FROM examinations WHERE exam_date = CURDATE() AND status <> 'cancelled'")->fetchColumn(),
    'today_attendance' => (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'present' AND DATE(verification_time) = CURDATE()")->fetchColumn(),
    'eligible' => (int) $pdo->query(
        "SELECT COUNT(DISTINCT el.student_id) FROM exam_eligibility el
         JOIN examinations e ON e.id = el.exam_id
         WHERE el.school_fees_paid = 1 AND el.eligible = 1 AND e.status IN ('scheduled','ongoing')"
    )->fetchColumn(),
    'pending_complaints' => (int) $pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'pending'")->fetchColumn(),
];

$activeExam = active_exam();

$todayExams = $pdo->query(
    "SELECT e.*,
        (SELECT COUNT(*) FROM exam_eligibility el WHERE el.exam_id = e.id) AS registered,
        (SELECT COUNT(*) FROM attendance a WHERE a.exam_id = e.id AND a.status = 'present') AS present
     FROM examinations e
     WHERE e.exam_date = CURDATE()
     ORDER BY e.start_time"
)->fetchAll();

$recentAttendance = $pdo->query(
    "SELECT a.*, u.student_id AS matric, u.first_name, u.last_name, e.course_code
     FROM attendance a
     JOIN users u ON u.id = a.student_id
     JOIN examinations e ON e.id = a.exam_id
     WHERE a.verification_time IS NOT NULL
     ORDER BY a.verification_time DESC LIMIT 8"
)->fetchAll();

$pendingComplaints = $pdo->query(
    "SELECT c.*, u.student_id AS matric, u.first_name, u.last_name, e.course_code
     FROM complaints c
     JOIN users u ON u.id = c.student_id
     LEFT JOIN examinations e ON e.id = c.exam_id
     WHERE c.status = 'pending'
     ORDER BY c.created_at DESC LIMIT 5"
)->fetchAll();

$placeholders = implode(',', array_fill(0, count(VERIFY_ACTIONS), '?'));
$stmt = $pdo->prepare(
    "SELECT action, description, created_at FROM audit_logs
     WHERE action IN ($placeholders) ORDER BY created_at DESC, id DESC LIMIT 8"
);
$stmt->execute(VERIFY_ACTIONS);
$attempts = $stmt->fetchAll();

$cards = [
    ['Total Students', $stats['students'], 'bi-people', 'primary', 'admin/students.php'],
    ['Registered Faces', $stats['faces'], 'bi-person-bounding-box', 'info', 'admin/students.php?face=1'],
    ["Today's Examinations", $stats['today_exams'], 'bi-calendar-event', 'warning', 'admin/examinations.php'],
    ["Today's Attendance", $stats['today_attendance'], 'bi-check2-square', 'success', 'admin/attendance.php?date=' . date('Y-m-d')],
    ['Eligible Students', $stats['eligible'], 'bi-patch-check', 'dark', 'admin/payments.php'],
    ['Pending Complaints', $stats['pending_complaints'], 'bi-chat-left-text', 'danger', 'admin/complaints.php?status=pending'],
];

$pageTitle = 'Dashboard';
$breadcrumbs = ['Dashboard' => null];
$headerActions = '<a href="' . e(url('verification/index.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-person-bounding-box me-1"></i>Open Verification Kiosk</a>';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($activeExam): ?>
    <div class="alert alert-<?= $activeExam['status'] === 'ongoing' ? 'primary' : 'warning' ?> d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-broadcast"></i>
        <div>Kiosk examination: <strong><?= e($activeExam['course_code'] . ' – ' . $activeExam['course_title']) ?></strong>
            (<?= e(fmt_date($activeExam['exam_date'])) ?>, <?= e($activeExam['venue']) ?>) <?= status_badge($activeExam['status']) ?>
            <?php if ($activeExam['status'] !== 'ongoing'): ?>
                <span class="small d-block">Verification only works while the exam status is <strong>ongoing</strong>.</span>
            <?php endif; ?>
        </div>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('admin/settings.php')) ?>">Change</a>
    </div>
<?php else: ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        No examination is selected for the verification kiosk.
        <a class="btn btn-sm btn-warning ms-auto" href="<?= e(url('admin/settings.php')) ?>">Select examination</a>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$label, $value, $icon, $color, $link]): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= e(url($link)) ?>" class="text-decoration-none text-reset">
                <div class="card stat-card">
                    <div class="stat-icon bg-<?= e($color) ?>-soft"><i class="bi <?= e($icon) ?>"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($value) ?></div>
                        <div class="stat-label"><?= e($label) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-day me-2"></i>Today's Examinations</span>
                <a href="<?= e(url('admin/examination-create.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> New</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-card">
                    <thead><tr><th>Course</th><th>Time</th><th>Venue</th><th>Present</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($todayExams as $exam): ?>
                        <tr>
                            <td><strong><?= e($exam['course_code']) ?></strong><br><span class="small text-muted"><?= e($exam['course_title']) ?></span>
                                <?php if ($activeExam && (int) $activeExam['id'] === (int) $exam['id']): ?>
                                    <span class="badge bg-primary-subtle text-primary-emphasis">Kiosk</span>
                                <?php endif; ?></td>
                            <td class="text-nowrap"><?= e(fmt_time($exam['start_time'])) ?> – <?= e(fmt_time($exam['end_time'])) ?></td>
                            <td><?= e($exam['venue']) ?></td>
                            <td><?= (int) $exam['present'] ?> / <?= (int) $exam['registered'] ?></td>
                            <td><?= status_badge($exam['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$todayExams): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-calendar-x"></i>No examinations scheduled for today.</div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recent Attendance</span>
                <a href="<?= e(url('admin/attendance.php')) ?>" class="small">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-card">
                    <thead><tr><th>Student</th><th>Exam</th><th>Method</th><th>Time</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentAttendance as $row): ?>
                        <tr>
                            <td><?= e(full_name($row)) ?><br><span class="small text-muted"><?= e($row['matric']) ?></span></td>
                            <td><?= e($row['course_code']) ?></td>
                            <td><?= status_badge($row['verification_method']) ?></td>
                            <td class="small text-nowrap"><?= e(fmt_datetime($row['verification_time'])) ?></td>
                            <td><?= status_badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentAttendance): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No attendance recorded yet.</div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-left-text me-2"></i>Pending Complaints</span>
                <a href="<?= e(url('admin/complaints.php')) ?>" class="small">Manage</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($pendingComplaints as $c): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong class="small"><?= e($c['subject']) ?></strong>
                            <span class="small text-muted text-nowrap ms-2"><?= e(date('d M', strtotime($c['created_at']))) ?></span>
                        </div>
                        <div class="small text-muted"><?= e(full_name($c)) ?> (<?= e($c['matric']) ?>)<?= $c['course_code'] ? ' · ' . e($c['course_code']) : '' ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$pendingComplaints): ?>
                    <li class="list-group-item"><div class="empty-state py-3"><i class="bi bi-emoji-smile"></i>No pending complaints.</div></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-check me-2"></i>Recent Verification Attempts</span>
                <a href="<?= e(url('admin/audit-logs.php?type=verification')) ?>" class="small">Audit log</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($attempts as $a):
                    $ok = in_array($a['action'], ['FACE_VERIFY_SUCCESS', 'MANUAL_VERIFICATION'], true);
                    $warn = $a['action'] === 'VERIFY_DUPLICATE'; ?>
                    <li class="list-group-item d-flex gap-2">
                        <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : ($warn ? 'bi-exclamation-circle-fill text-warning' : 'bi-x-circle-fill text-danger') ?> mt-1"></i>
                        <div class="small">
                            <span class="audit-action"><?= e($a['action']) ?></span>
                            <div><?= e($a['description']) ?></div>
                            <div class="text-muted"><?= e(fmt_datetime($a['created_at'])) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$attempts): ?>
                    <li class="list-group-item"><div class="empty-state py-3"><i class="bi bi-shield"></i>No verification attempts yet.</div></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
include('dbconfig.php');

function lecture_column_exists(mysqli $conn, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecattendance' AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function ensure_lecture_attendance_columns(mysqli $conn): void {
    if (!lecture_column_exists($conn, 'absentNo')) {
        $conn->query("ALTER TABLE lecattendance ADD COLUMN absentNo TEXT NULL AFTER presentNo");
    }
    if (!lecture_column_exists($conn, 'description')) {
        $conn->query("ALTER TABLE lecattendance ADD COLUMN description VARCHAR(255) NULL AFTER absentNo");
    }
}

ensure_lecture_attendance_columns($conn);

function compare_terms_desc($left, $right) {
    return strnatcmp((string)$right, (string)$left);
}

// ── Auto-create lecmapping table if missing ───────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `lecmapping` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `faculty`     VARCHAR(50)  NOT NULL,
    `term`        VARCHAR(20)  NOT NULL,
    `sem`         VARCHAR(10)  NOT NULL,
    `subject`     VARCHAR(100) NOT NULL,
    `class`       VARCHAR(5)   NOT NULL,
    `slot`        VARCHAR(50)  NOT NULL,
    `start_date`  DATE         NOT NULL,
    `end_date`    DATE         NOT NULL,
    `repeat_days` VARCHAR(20)  NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Auto-create exceptions table (holiday/skip slots) ─────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `lecmapping_exceptions` (
    `id`         INT  NOT NULL AUTO_INCREMENT,
    `mapping_id` INT  NOT NULL,
    `date`       DATE NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mapping_date` (`mapping_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$session_faculty_name = $_SESSION['Name'] ?? '';

// Get logged-in faculty id
$fac_id_stmt = $conn->prepare("SELECT id FROM faculty WHERE Name = ?");
$fac_id_stmt->bind_param('s', $session_faculty_name);
$fac_id_stmt->execute();
$fac_row = $fac_id_stmt->get_result()->fetch_assoc();
$fac_id_stmt->close();
$logged_faculty_id = $fac_row ? (string)$fac_row['id'] : '0';

$success_msg = trim((string)($_GET['msg'] ?? ''));
$error_msg = trim((string)($_GET['err'] ?? ''));

// ── Filters from GET ──────────────────────────────────────────────────────────
$filter_term    = trim((string)($_GET['term'] ?? ''));
$filter_status  = $_GET['status']  ?? 'unfilled';   // all | filled | unfilled | skipped
$filter_mapping = (int)($_GET['mapping'] ?? 0); // specific mapping id, 0 = all

// ── Load all mappings for this faculty ───────────────────────────────────────
$mappings_stmt = $conn->prepare("SELECT * FROM lecmapping WHERE faculty = ? ORDER BY start_date, id");
$mappings_stmt->bind_param('s', $logged_faculty_id);
$mappings_stmt->execute();
$mappings_rows = $mappings_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mappings_stmt->close();

$available_terms = [];
foreach ($mappings_rows as $mapping_row) {
    $term_value = trim((string)($mapping_row['term'] ?? ''));
    if ($term_value !== '') {
        $available_terms[$term_value] = true;
    }
}
$available_terms = array_keys($available_terms);
usort($available_terms, 'compare_terms_desc');

if ($filter_term === '') {
    header('Location: myAttendanceSelect.php');
    exit();
}

// ── Load exceptions for this faculty's mappings ───────────────────────────────
$exceptions_set = []; // "mapping_id|date" => true
if (!empty($mappings_rows)) {
    $mapping_ids = array_column($mappings_rows, 'id');
    $exc_placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
    $exc_types = str_repeat('i', count($mapping_ids));
    $exc_stmt = $conn->prepare("SELECT mapping_id, date FROM lecmapping_exceptions WHERE mapping_id IN ($exc_placeholders)");
    $exc_stmt->bind_param($exc_types, ...$mapping_ids);
    $exc_stmt->execute();
    $exc_res = $exc_stmt->get_result();
    while ($er = $exc_res->fetch_assoc()) {
        $exceptions_set[$er['mapping_id'] . '|' . $er['date']] = true;
    }
    $exc_stmt->close();
}

// ── Expand each mapping into individual date slots ───────────────────────────
// slot_list: array of [mapping_id, date, faculty, term, sem, subject, class, slot, skipped]
$slot_list = [];
if ($filter_term !== '') {
    foreach ($mappings_rows as $m) {
    $mapping_term = trim((string)($m['term'] ?? ''));
    if ($filter_mapping > 0 && $m['id'] !== $filter_mapping) continue;
        if (strcasecmp($mapping_term, $filter_term) !== 0) continue;

        $repeat_days = array_map('intval', explode(',', $m['repeat_days']));
        $cur = new DateTime($m['start_date']);
        $end = new DateTime($m['end_date']);
        $today = new DateTime('today');
        if ($end > $today) {
            $end = $today;
        }
        if ($cur > $end) {
            continue;
        }
        $end->modify('+1 day'); // make end inclusive

        while ($cur < $end) {
        $dow = (int)$cur->format('w'); // 0=Sun … 6=Sat
        if (in_array($dow, $repeat_days, true)) {
            $date_str = $cur->format('Y-m-d');
            $slot_list[] = [
                'mapping_id' => $m['id'],
                'date'       => $date_str,
                'faculty'    => $m['faculty'],
                'term'       => $mapping_term,
                'sem'        => $m['sem'],
                'subject'    => $m['subject'],
                'class'      => $m['class'],
                'slot'       => $m['slot'],
                'skipped'    => isset($exceptions_set[$m['id'] . '|' . $date_str]),
            ];
        }
        $cur->modify('+1 day');
    }
    }
}

// Sort by date descending (newest first)
usort($slot_list, fn($a, $b) => strcmp($b['date'], $a['date']));

// ── Check which slots are already filled ─────────────────────────────────────
// Build a lookup: "term|sem|subject|class|date|slot" => attendance_id
$filled_lookup = [];
if (!empty($slot_list)) {
    // Collect unique term/sem combos to query efficiently
    $unique_terms = array_values(array_unique(array_column($slot_list, 'term')));
    $unique_sems  = array_values(array_unique(array_column($slot_list, 'sem')));

    if (!empty($unique_terms) && !empty($unique_sems)) {
        $t_placeholders = implode(',', array_fill(0, count($unique_terms), '?'));
        $s_placeholders = implode(',', array_fill(0, count($unique_sems),  '?'));
        $types = str_repeat('s', count($unique_terms) + count($unique_sems));
        $params = array_merge($unique_terms, $unique_sems);

        $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, class FROM lecattendance WHERE term IN ($t_placeholders) AND sem IN ($s_placeholders)");
        $att_stmt->bind_param($types, ...$params);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $key = $ar['term'] . '|' . $ar['sem'] . '|' . $ar['subject'] . '|' . $ar['class'] . '|' . $ar['date'] . '|' . $ar['time'];
            $filled_lookup[$key] = (int)$ar['id'];
        }
        $att_stmt->close();
    }
}

// ── Annotate each slot with filled status ─────────────────────────────────────
foreach ($slot_list as &$slot) {
    $key = $slot['term'] . '|' . $slot['sem'] . '|' . $slot['subject'] . '|' . $slot['class'] . '|' . $slot['date'] . '|' . $slot['slot'];
    $slot['filled']        = isset($filled_lookup[$key]);
    $slot['attendance_id'] = $filled_lookup[$key] ?? null;
}
unset($slot);

$bulk_candidates = array_values(array_filter($slot_list, fn($s) => !$s['filled'] && !$s['skipped']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autofill_pending_max'])) {
    $redirect_params = [
        'status' => $filter_status,
        'mapping' => $filter_mapping,
        'term' => $filter_term,
    ];

    if (empty($bulk_candidates)) {
        $redirect_params['err'] = 'No pending lecture slots found for autofill.';
        header('Location: myAttendance.php?' . http_build_query($redirect_params));
        exit();
    }

    $class_students_stmt = $conn->prepare("SELECT enrollmentNo FROM students WHERE term = ? AND sem = ? AND class = ? AND enrollmentNo IS NOT NULL AND TRIM(enrollmentNo) <> ''");
    $lec_auto_stmt = $conn->prepare("SELECT presentNo FROM lecattendance WHERE term = ? AND sem = ? AND class = ? AND date = ?");
    $lab_auto_stmt = $conn->prepare("SELECT presentNo FROM labattendance WHERE term = ? AND sem = ? AND date = ? AND COALESCE(TRIM(labNo), '') <> ''");
    $tut_auto_stmt = $conn->prepare("SELECT presentNo FROM tutattendance WHERE term = ? AND sem = ? AND date = ?");
    $exists_stmt = $conn->prepare("SELECT id FROM lecattendance WHERE date = ? AND time = ? AND term = ? AND sem = ? AND subject = ? AND class = ? LIMIT 1");
    $insert_stmt = $conn->prepare("INSERT INTO lecattendance (date, logdate, time, term, faculty, sem, subject, class, presentNo, absentNo, description) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$class_students_stmt || !$lec_auto_stmt || !$lab_auto_stmt || !$tut_auto_stmt || !$exists_stmt || !$insert_stmt) {
        $redirect_params['err'] = 'Bulk autofill is unavailable right now. Please try again.';
        header('Location: myAttendance.php?' . http_build_query($redirect_params));
        exit();
    }

    $parse_present_tokens = static function (string $csv): array {
        $tokens = [];
        foreach (explode(',', $csv) as $raw) {
            $token = trim($raw);
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
        return array_keys($tokens);
    };

    $class_cache = [];
    $best_cache = [];
    $processed_slot_keys = [];

    $created = 0;
    $autofilled = 0;
    $skipped_no_autofill = 0;
    $skipped_existing = 0;
    $skipped_duplicate = 0;
    $failed = 0;

    foreach ($bulk_candidates as $slot) {
        $slot_key = $slot['term'] . '|' . $slot['sem'] . '|' . $slot['subject'] . '|' . $slot['class'] . '|' . $slot['date'] . '|' . $slot['slot'];
        if (isset($processed_slot_keys[$slot_key])) {
            $skipped_duplicate++;
            continue;
        }
        $processed_slot_keys[$slot_key] = true;

        $date = (string)$slot['date'];
        $time = (string)$slot['slot'];
        $term = (string)$slot['term'];
        $faculty = (string)$slot['faculty'];
        $sem = (string)$slot['sem'];
        $subject = (string)$slot['subject'];
        $class = (string)$slot['class'];

        $exists_stmt->bind_param('ssssss', $date, $time, $term, $sem, $subject, $class);
        $exists_stmt->execute();
        $existing_row = $exists_stmt->get_result()->fetch_assoc();
        if ($existing_row) {
            $skipped_existing++;
            continue;
        }

        $class_key = $term . '|' . $sem . '|' . $class;
        if (!isset($class_cache[$class_key])) {
            $class_students_stmt->bind_param('sss', $term, $sem, $class);
            $class_students_stmt->execute();
            $student_res = $class_students_stmt->get_result();
            $enrollment_set = [];
            while ($sr = $student_res->fetch_assoc()) {
                $enrollment = trim((string)($sr['enrollmentNo'] ?? ''));
                if ($enrollment !== '') {
                    $enrollment_set[$enrollment] = true;
                }
            }
            $class_cache[$class_key] = $enrollment_set;
        }

        $best_key = $term . '|' . $sem . '|' . $class . '|' . $date;
        if (!isset($best_cache[$best_key])) {
            $class_set = $class_cache[$class_key];
            $best_present = [];
            $best_count = 0;

            $consider_present = static function (string $csv, array $class_set, callable $parser): array {
                $tokens = $parser($csv);
                if (empty($tokens)) {
                    return [];
                }
                $filtered = [];
                foreach ($tokens as $token) {
                    if (isset($class_set[$token])) {
                        $filtered[$token] = true;
                    }
                }
                return array_keys($filtered);
            };

            if (!empty($class_set)) {
                $lec_auto_stmt->bind_param('ssss', $term, $sem, $class, $date);
                $lec_auto_stmt->execute();
                $lec_res = $lec_auto_stmt->get_result();
                while ($row = $lec_res->fetch_assoc()) {
                    $present = $consider_present((string)($row['presentNo'] ?? ''), $class_set, $parse_present_tokens);
                    if (count($present) > $best_count) {
                        $best_count = count($present);
                        $best_present = $present;
                    }
                }

                $lab_auto_stmt->bind_param('sss', $term, $sem, $date);
                $lab_auto_stmt->execute();
                $lab_res = $lab_auto_stmt->get_result();
                while ($row = $lab_res->fetch_assoc()) {
                    $present = $consider_present((string)($row['presentNo'] ?? ''), $class_set, $parse_present_tokens);
                    if (count($present) > $best_count) {
                        $best_count = count($present);
                        $best_present = $present;
                    }
                }

                $tut_auto_stmt->bind_param('sss', $term, $sem, $date);
                $tut_auto_stmt->execute();
                $tut_res = $tut_auto_stmt->get_result();
                while ($row = $tut_res->fetch_assoc()) {
                    $present = $consider_present((string)($row['presentNo'] ?? ''), $class_set, $parse_present_tokens);
                    if (count($present) > $best_count) {
                        $best_count = count($present);
                        $best_present = $present;
                    }
                }
            }

            $best_cache[$best_key] = $best_present;
        }

        $present_list = $best_cache[$best_key];
        if (empty($present_list)) {
            $skipped_no_autofill++;
            continue;
        }

        $class_set = $class_cache[$class_key];
        $present_set = [];
        foreach ($present_list as $enrollment_no) {
            $present_set[$enrollment_no] = true;
        }

        $absent_list = [];
        foreach ($class_set as $enrollment_no => $_exists) {
            if (!isset($present_set[$enrollment_no])) {
                $absent_list[] = $enrollment_no;
            }
        }

        $present_csv = implode(',', $present_list);
        $absent_csv = implode(',', $absent_list);
        $description = null;
        $insert_stmt->bind_param('ssssssssss', $date, $time, $term, $faculty, $sem, $subject, $class, $present_csv, $absent_csv, $description);
        if ($insert_stmt->execute()) {
            $created++;
            $autofilled++;
        } else {
            $failed++;
        }
    }

    $class_students_stmt->close();
    $lec_auto_stmt->close();
    $lab_auto_stmt->close();
    $tut_auto_stmt->close();
    $exists_stmt->close();
    $insert_stmt->close();

    if ($created === 0 && $failed === 0) {
        $redirect_params['err'] = 'No pending slots were inserted. Existing entries may already be present or no autofill source had students.';
    } else {
        $summary = "Autofill complete: created {$created}, autofilled {$autofilled}, skipped no source {$skipped_no_autofill}, skipped existing {$skipped_existing}";
        if ($skipped_duplicate > 0) {
            $summary .= ", skipped duplicate {$skipped_duplicate}";
        }
        if ($failed > 0) {
            $summary .= ", failed {$failed}";
        }
        $summary .= '.';
        $redirect_params['msg'] = $summary;
    }

    header('Location: myAttendance.php?' . http_build_query($redirect_params));
    exit();
}

// ── Handle skip (add exception) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_slot'])) {
    $skip_mapping_id = (int)($_POST['skip_mapping_id'] ?? 0);
    $skip_date       = trim((string)($_POST['skip_date'] ?? ''));
    $redirect_params = ['status' => $filter_status, 'mapping' => $filter_mapping, 'term' => $filter_term];

    if ($skip_mapping_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $skip_date)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO lecmapping_exceptions (mapping_id, date) VALUES (?, ?)");
        $stmt->bind_param('is', $skip_mapping_id, $skip_date);
        $stmt->execute();
        $stmt->close();
        $redirect_params['msg'] = "Slot on {$skip_date} removed (marked as holiday/skip).";
    } else {
        $redirect_params['err'] = 'Invalid skip request.';
    }
    header('Location: myAttendance.php?' . http_build_query($redirect_params));
    exit();
}

// ── Handle restore (remove exception) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_slot'])) {
    $restore_mapping_id = (int)($_POST['restore_mapping_id'] ?? 0);
    $restore_date       = trim((string)($_POST['restore_date'] ?? ''));
    $redirect_params = ['status' => $filter_status, 'mapping' => $filter_mapping, 'term' => $filter_term];

    if ($restore_mapping_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $restore_date)) {
        $stmt = $conn->prepare("DELETE FROM lecmapping_exceptions WHERE mapping_id = ? AND date = ?");
        $stmt->bind_param('is', $restore_mapping_id, $restore_date);
        $stmt->execute();
        $stmt->close();
        $redirect_params['msg'] = "Slot on {$restore_date} restored.";
    } else {
        $redirect_params['err'] = 'Invalid restore request.';
    }
    header('Location: myAttendance.php?' . http_build_query($redirect_params));
    exit();
}

// ── Apply status filter ───────────────────────────────────────────────────────
// Stats computed before filter (on all slots including skipped)
$stats_slot_list = $slot_list;
$total_skipped = count(array_filter($stats_slot_list, fn($s) => $s['skipped']));

if ($filter_status === 'filled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['filled']));
} elseif ($filter_status === 'unfilled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => !$s['filled'] && !$s['skipped']));
} elseif ($filter_status === 'skipped') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['skipped']));
}
// 'all' shows everything including skipped

// ── Faculty name lookup ───────────────────────────────────────────────────────
$faculty_map = [];
$fres = $conn->query("SELECT id, Name FROM faculty");
while ($fr = $fres->fetch_assoc()) {
    $faculty_map[(string)$fr['id']] = $fr['Name'];
}

// Stats (computed on the full unfiltered list)
$total    = count($stats_slot_list);
$filled   = count(array_filter($stats_slot_list, fn($s) => $s['filled']));
$skipped  = count(array_filter($stats_slot_list, fn($s) => $s['skipped']));
$unfilled = $total - $filled - $skipped;

$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">

            <?php if ($success_msg !== ''): ?>
                <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <?php if (empty($mappings_rows)): ?>
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>No lecture mappings found for your account.
                    <a href="addLectureMapping.php" class="alert-link">Create a mapping</a> to get started.
                </div>
            <?php else: ?>

            <?php if ($filter_term === ''): ?>
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body py-5">
                    <div class="attendance-term-picker text-center">
                        <div class="term-picker-icon mb-3"><i class="bi bi-calendar2-check"></i></div>
                        <h3 class="mb-2 fw-bold">Select Term</h3>
                        <p class="text-muted mb-4">Choose a term to open its attendance page. Pending will be selected by default.</p>
                        <div class="attendance-term-badges">
                            <?php foreach ($available_terms as $term_option): ?>
                                <a href="myAttendance.php?<?= htmlspecialchars(http_build_query(['term' => $term_option, 'status' => 'unfilled'])) ?>" class="attendance-term-badge">
                                    <i class="bi bi-calendar3 me-2"></i><?= htmlspecialchars($term_option) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>

            <!-- Page Header -->
            <div class="page-header-card mb-3">
                <div class="page-header-content">
                    <div class="page-header-text">
                        <h1 class="page-header-title"><i class="bi bi-calendar2-check"></i>My Attendance</h1>
                        <div class="page-header-meta">
                            <span class="page-header-meta-item"><i class="bi bi-mortarboard"></i>Term <?= htmlspecialchars($filter_term) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="app-card shadow-sm mb-3 attendance-table-card">
                <div class="attendance-table-toolbar">
                    <div class="attendance-table-toolbar-left">
                        <div class="attendance-filter-pills" role="group" aria-label="Attendance filters">
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'all', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill <?= $filter_status === 'all' ? 'filter-pill-active' : '' ?>" title="All slots">
                                All <span class="filter-pill-count"><?= $total ?></span>
                            </a>
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'unfilled', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill filter-pill-danger <?= $filter_status === 'unfilled' ? 'filter-pill-active' : '' ?>" title="Pending slots">
                                Pending <span class="filter-pill-count"><?= $unfilled ?></span>
                            </a>
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'filled', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill filter-pill-success <?= $filter_status === 'filled' ? 'filter-pill-active' : '' ?>" title="Filled slots">
                                Filled <span class="filter-pill-count"><?= $filled ?></span>
                            </a>
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'skipped', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill filter-pill-muted <?= $filter_status === 'skipped' ? 'filter-pill-active' : '' ?>" title="Skipped slots">
                                Skipped <span class="filter-pill-count"><?= $skipped ?></span>
                            </a>
                        </div>
                    </div>
                    <?php if (!empty($bulk_candidates)): ?>
                    <div class="attendance-table-toolbar-right">
                        <form method="POST" action="myAttendance.php?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => $filter_status, 'mapping' => $filter_mapping])) ?>" class="attendance-bulk-form m-0">
                            <button type="submit" name="autofill_pending_max" class="btn bulk-autofill-btn" title="Autofill all pending slots" onclick="return confirm('Autofill all pending slots using maximum available attendance on each day? Slots without autofill source will be skipped.');">
                                <i class="bi bi-stars"></i><span>Autofill <?= count($bulk_candidates) ?> Pending</span>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="app-card-body p-0">
                    <?php if (empty($slot_list)): ?>
                        <div class="attendance-empty-state">
                            <div class="attendance-empty-icon"><i class="bi bi-calendar-x"></i></div>
                            <h5>No slots found</h5>
                            <p>No slots match the current filter for term <strong><?= htmlspecialchars($filter_term) ?></strong>.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive attendance-table-wrap">
                            <table id="attendanceDataTable" class="table attendance-data-table mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-center col-num">#</th>
                                        <th class="text-center col-type">Type</th>
                                        <th class="col-date">Date</th>
                                        <th class="text-center col-day">Day</th>
                                        <th class="col-subject">Subject</th>
                                        <th class="text-center col-class">Class</th>
                                        <th class="text-center col-slot">Slot</th>
                                        <th class="text-center col-status">Status</th>
                                        <th class="text-center col-action">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($slot_list as $i => $slot):
                                    $date_obj = new DateTime($slot['date']);
                                    $dow_name = $day_names[(int)$date_obj->format('w')];
                                    $is_today = ($slot['date'] === date('Y-m-d'));
                                    $params = http_build_query([
                                        'faculty' => $slot['faculty'],
                                        'term'    => $slot['term'],
                                        'sem'     => $slot['sem'],
                                        'subject' => $slot['subject'],
                                        'class'   => $slot['class'],
                                        'date'    => $slot['date'],
                                        'slot'    => $slot['slot'],
                                    ]);
                                    $take_url = 'takelecatt.php?' . $params;
                                    $edit_url = $slot['filled'] ? 'editlecatt.php?id=' . $slot['attendance_id'] : null;
                                    $summary_url = $slot['filled'] ? 'attendanceSummary.php?type=lecture&id=' . $slot['attendance_id'] : null;
                                    $row_class = '';
                                    if ($slot['skipped']) $row_class = 'row-skipped';
                                    elseif (!$slot['filled'] && $is_today) $row_class = 'row-today';
                                    elseif (!$slot['filled']) $row_class = 'row-pending';
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td class="text-center col-num"><span class="row-num"><?= $i + 1 ?></span></td>
                                    <td class="text-center col-type">
                                        <span class="type-pill type-lec" title="Lecture"><i class="bi bi-easel2"></i>Lec</span>
                                    </td>
                                    <td class="col-date">
                                        <div class="date-cell">
                                            <span class="date-cell-day"><?= date('d', strtotime($slot['date'])) ?></span>
                                            <div class="date-cell-month">
                                                <?= date('M', strtotime($slot['date'])) ?>
                                                <small><?= date('Y', strtotime($slot['date'])) ?></small>
                                            </div>
                                            <?php if ($is_today): ?>
                                                <span class="today-tag">Today</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center col-day"><span class="day-pill"><?= $dow_name ?></span></td>
                                    <td class="col-subject">
                                        <div class="subject-cell">
                                            <span class="subject-name"><?= htmlspecialchars($slot['subject']) ?></span>
                                            <span class="subject-sem">Sem <?= htmlspecialchars($slot['sem']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center col-class"><span class="class-badge"><?= htmlspecialchars($slot['class']) ?></span></td>
                                    <td class="text-center col-slot"><span class="slot-text"><?= htmlspecialchars($slot['slot']) ?></span></td>
                                    <td class="text-center col-status">
                                        <?php if ($slot['skipped']): ?>
                                            <span class="status-pill status-skipped"><i class="bi bi-slash-circle"></i>Skipped</span>
                                        <?php elseif ($slot['filled']): ?>
                                            <span class="status-pill status-filled"><i class="bi bi-check-circle-fill"></i>Filled</span>
                                        <?php else: ?>
                                            <span class="status-pill status-pending"><i class="bi bi-hourglass-split"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center col-action">
                                        <div class="action-buttons">
                                        <?php if ($slot['skipped']): ?>
                                            <form method="POST" action="myAttendance.php?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => $filter_status, 'mapping' => $filter_mapping])) ?>" class="d-inline-flex m-0">
                                                <input type="hidden" name="restore_mapping_id" value="<?= (int)$slot['mapping_id'] ?>">
                                                <input type="hidden" name="restore_date" value="<?= htmlspecialchars($slot['date']) ?>">
                                                <button type="submit" name="restore_slot" class="action-btn action-btn-secondary" title="Restore slot" onclick="return confirm('Restore this slot on <?= htmlspecialchars($slot['date']) ?>?')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        <?php elseif ($slot['filled']): ?>
                                            <a href="<?= htmlspecialchars($summary_url) ?>" class="action-btn action-btn-success" title="View Summary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($edit_url) ?>" class="action-btn action-btn-primary" title="Edit Attendance">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($take_url) ?>" class="action-btn action-btn-warning" title="Take Attendance">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <form method="POST" action="myAttendance.php?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => $filter_status, 'mapping' => $filter_mapping])) ?>" class="d-inline-flex m-0">
                                                <input type="hidden" name="skip_mapping_id" value="<?= (int)$slot['mapping_id'] ?>">
                                                <input type="hidden" name="skip_date" value="<?= htmlspecialchars($slot['date']) ?>">
                                                <button type="submit" name="skip_slot" class="action-btn action-btn-secondary" title="Skip slot (holiday/no class)" onclick="return confirm('Skip slot on <?= htmlspecialchars($slot['date']) ?>? It will be removed from pending.')">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* ============================================================
   My Attendance - Professional Design
   ============================================================ */

/* ---------- Page Header (Hero) ---------- */
.page-header-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 1rem;
    padding: 1.5rem 1.75rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
    border: 1px solid #e2e8f0;
    position: relative;
    overflow: hidden;
}

.page-header-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
}

.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.page-header-text {
    flex: 1 1 auto;
    min-width: 0;
}

.page-header-title {
    font-size: 1.75rem !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    margin: 0 0 0.5rem 0 !important;
    padding: 0 !important;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    letter-spacing: -0.025em;
}

.page-header-title i {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 0.6rem;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    flex-shrink: 0;
}

.page-header-title::after {
    display: none;
}

.page-header-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    font-size: 0.875rem;
    color: #64748b;
    padding-left: 56px;
}

.page-header-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.75rem;
    background: #eef2ff;
    color: #4338ca;
    border-radius: 999px;
    font-weight: 600;
    font-size: 0.825rem;
}

.page-header-meta-item i {
    font-size: 0.85rem;
}

.page-header-meta-divider {
    width: 4px;
    height: 4px;
    background: #cbd5e1;
    border-radius: 50%;
}

.page-header-change-term {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #64748b !important;
    text-decoration: none !important;
    font-size: 0.825rem;
    font-weight: 600;
    padding: 0.3rem 0.65rem;
    border-radius: 0.4rem;
    transition: all 0.2s ease;
}

.page-header-change-term:hover {
    background: #f1f5f9;
    color: #475569 !important;
}

.page-header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-header-btn {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
    border: 0 !important;
    color: #fff !important;
    font-weight: 600 !important;
    padding: 0.65rem 1.25rem !important;
    border-radius: 0.55rem !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28) !important;
    transition: all 0.2s ease !important;
    display: inline-flex !important;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem !important;
}

.page-header-btn:hover,
.page-header-btn:focus {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.4) !important;
    color: #fff !important;
    filter: brightness(1.05);
}

.page-header-btn i {
    font-size: 1rem;
}

/* ---------- Stat Cards ---------- */
.stat-card {
    background: #fff;
    border-radius: 0.85rem;
    padding: 1.25rem 1.25rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.06);
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    height: 100%;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    transition: height 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08), 0 4px 8px rgba(15, 23, 42, 0.04);
}

.stat-card:hover::before {
    height: 4px;
}

.stat-card-total::before { background: linear-gradient(90deg, #64748b, #475569); }
.stat-card-filled::before { background: linear-gradient(90deg, #10b981, #059669); }
.stat-card-pending::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
.stat-card-skipped::before { background: linear-gradient(90deg, #94a3b8, #64748b); }

.stat-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.5rem;
}

.stat-card-total .stat-card-icon { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); color: #475569; }
.stat-card-filled .stat-card-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
.stat-card-pending .stat-card-icon { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #b91c1c; }
.stat-card-skipped .stat-card-icon { background: linear-gradient(135deg, #e2e8f0, #cbd5e1); color: #475569; }

.stat-card-body {
    flex: 1 1 auto;
    min-width: 0;
}

.stat-card-value {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
    letter-spacing: -0.025em;
}

.stat-card-label {
    margin-top: 0.35rem;
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

/* ---------- Term Picker (initial screen) ---------- */
.attendance-term-picker {
    max-width: 720px;
    margin: 0 auto;
    padding: 1rem 0;
}

.term-picker-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, #eef4ff, #dbeafe);
    color: #4338ca;
    border-radius: 1.25rem;
    font-size: 2rem;
    margin: 0 auto;
    box-shadow: 0 8px 20px rgba(67, 56, 202, 0.12);
}

.attendance-term-picker h3 {
    font-size: 1.6rem !important;
    color: #0f172a !important;
    margin-bottom: 0.5rem !important;
    padding-bottom: 0 !important;
    border-bottom: 0 !important;
    font-weight: 700 !important;
    letter-spacing: -0.02em;
}

.attendance-term-picker p {
    font-size: 0.95rem !important;
    color: #64748b !important;
}

.attendance-term-badges {
    display: flex;
    justify-content: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1.5rem;
}

.attendance-term-badge {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    min-width: 150px;
    padding: 0.9rem 1.5rem !important;
    border-radius: 0.75rem !important;
    background: linear-gradient(135deg, #eef4ff, #dbeafe) !important;
    border: 1.5px solid #93c5fd !important;
    color: #1e3a8a !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    font-size: 1rem !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.08);
}

.attendance-term-badge:hover,
.attendance-term-badge:focus {
    color: #1e3a8a !important;
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
    background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
}

/* ---------- Table Card ---------- */
.attendance-table-card {
    border-radius: 0.85rem !important;
    overflow: hidden;
    border: 1px solid #e2e8f0 !important;
}

/* Toolbar above table */
.attendance-table-toolbar {
    padding: 1rem 1.25rem;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.attendance-table-toolbar-left {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
    flex: 1 1 auto;
    min-width: 0;
}

.attendance-table-title {
    font-size: 1.05rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.attendance-table-title i {
    color: #667eea;
    font-size: 1.15rem;
}

/* Filter pills (clean, with counts) */
.attendance-filter-pills {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.filter-pill {
    display: inline-flex !important;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.85rem !important;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    color: #475569 !important;
    border-radius: 999px !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    line-height: 1.2;
    white-space: nowrap;
}

.filter-pill:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #1e293b !important;
    transform: translateY(-1px);
}

.filter-pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 0.4rem;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    line-height: 1;
}

.filter-pill-active {
    background: linear-gradient(135deg, #5c6bc0 0%, #3949ab 100%) !important;
    border-color: transparent !important;
    color: #fff !important;
    box-shadow: 0 4px 10px rgba(92, 107, 192, 0.3);
}

.filter-pill-active:hover {
    background: linear-gradient(135deg, #4a5ab8 0%, #2c3a99 100%) !important;
    color: #fff !important;
}

.filter-pill-active .filter-pill-count {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}

.filter-pill-danger.filter-pill-active {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
    box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);
}

.filter-pill-success.filter-pill-active {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
}

.filter-pill-muted.filter-pill-active {
    background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important;
    box-shadow: 0 4px 10px rgba(100, 116, 139, 0.3);
}

/* Bulk autofill button */
.attendance-table-toolbar-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.bulk-autofill-btn {
    background: linear-gradient(135deg, #fb8c00 0%, #ef6c00 100%) !important;
    border: 0 !important;
    color: #fff !important;
    font-weight: 600 !important;
    padding: 0.55rem 1.1rem !important;
    border-radius: 0.55rem !important;
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3) !important;
    transition: all 0.2s ease !important;
    display: inline-flex !important;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem !important;
    white-space: nowrap;
}

.bulk-autofill-btn:hover,
.bulk-autofill-btn:focus {
    color: #fff !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(245, 158, 11, 0.4) !important;
    filter: brightness(1.05);
}

.bulk-autofill-btn i {
    font-size: 0.95rem;
}

/* ---------- Data Table ---------- */
.attendance-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.attendance-data-table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.attendance-data-table thead th {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #475569;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 0.85rem 1rem;
    border-bottom: 2px solid #cbd5e1;
    vertical-align: middle;
    white-space: nowrap;
}

.attendance-data-table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    font-size: 0.875rem;
    background: #fff;
}

.attendance-data-table tbody tr {
    transition: background-color 0.15s ease;
}

.attendance-data-table tbody tr:hover td {
    background: #f8fafc;
}

/* Column widths */
.col-num { width: 56px; }
.col-type { width: 80px; }
.col-date { width: 120px; }
.col-day { width: 70px; }
.col-subject { width: auto; min-width: 180px; }
.col-class { width: 70px; }
.col-slot { width: 110px; }
.col-status { width: 130px; }
.col-action { width: 110px; }

/* Number cell */
.row-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 0.5rem;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 0.4rem;
    font-size: 0.8rem;
    font-weight: 700;
}

/* Date cell - calendar style */
.date-cell {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.date-cell-day {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #4338ca;
    border-radius: 0.55rem;
    font-size: 1.1rem;
    font-weight: 800;
    line-height: 1;
    flex-shrink: 0;
}

.date-cell-month {
    display: flex;
    flex-direction: column;
    font-size: 0.75rem;
    font-weight: 700;
    color: #1e293b;
    text-transform: uppercase;
    line-height: 1.1;
}

.date-cell-month small {
    font-size: 0.65rem;
    color: #94a3b8;
    font-weight: 600;
}

.today-tag {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: #78350f;
    border-radius: 0.35rem;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-left: 0.4rem;
}

/* Day pill */
.day-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.3rem 0.6rem;
    background: #f1f5f9;
    color: #475569;
    border-radius: 0.4rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    min-width: 42px;
}

/* Subject cell */
.subject-cell {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    line-height: 1.3;
}

.subject-name {
    font-weight: 700;
    color: #0f172a;
    font-size: 0.9rem;
}

.subject-sem {
    font-size: 0.7rem;
    color: #64748b;
    font-weight: 600;
}

/* Class badge */
.class-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 28px;
    padding: 0 0.6rem;
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    color: #1e3a8a;
    border-radius: 0.4rem;
    font-size: 0.85rem;
    font-weight: 800;
    border: 1px solid #a5b4fc;
}

/* Type pill (Lec / Lab / Tut) */
.type-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    min-width: 56px;
    height: 26px;
    padding: 0 0.55rem;
    border-radius: 0.4rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    line-height: 1;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.type-pill i {
    font-size: 0.8rem;
    line-height: 1;
}

.type-lec {
    background: linear-gradient(135deg, #ddd6fe, #c4b5fd);
    color: #5b21b6;
    border: 1px solid #a78bfa;
}

.type-lab {
    background: linear-gradient(135deg, #fed7aa, #fdba74);
    color: #9a3412;
    border: 1px solid #fb923c;
}

.type-tut {
    background: linear-gradient(135deg, #a7f3d0, #6ee7b7);
    color: #065f46;
    border: 1px solid #34d399;
}

/* Slot text */
.slot-text {
    font-size: 0.8rem;
    color: #475569;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    background: #f8fafc;
    border-radius: 0.35rem;
    border: 1px solid #e2e8f0;
    display: inline-block;
    white-space: nowrap;
}

/* Status pills */
.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    min-width: 105px;
    white-space: nowrap;
}

.status-pill i {
    font-size: 0.85rem;
}

.status-filled {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.status-pending {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.status-skipped {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    color: #475569;
    border: 1px solid #94a3b8;
}

/* Row state variants */
.row-pending td {
    background: linear-gradient(90deg, rgba(254, 226, 226, 0.25) 0%, rgba(254, 226, 226, 0.05) 100%) !important;
}

.row-pending:hover td {
    background: linear-gradient(90deg, rgba(254, 226, 226, 0.4) 0%, rgba(254, 226, 226, 0.1) 100%) !important;
}

.row-today td {
    background: linear-gradient(90deg, rgba(254, 243, 199, 0.4) 0%, rgba(254, 243, 199, 0.1) 100%) !important;
}

.row-today:hover td {
    background: linear-gradient(90deg, rgba(254, 243, 199, 0.55) 0%, rgba(254, 243, 199, 0.15) 100%) !important;
}

.row-skipped td {
    opacity: 0.55;
}

.row-skipped:hover td {
    opacity: 0.75;
    background: #f8fafc !important;
}

/* ---------- Action Buttons ---------- */
.action-buttons {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
}

.action-btn {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: 34px !important;
    height: 34px !important;
    min-height: 34px !important;
    padding: 0 !important;
    border-radius: 0.45rem !important;
    border-width: 1.5px !important;
    transition: all 0.2s ease !important;
    flex-shrink: 0;
}

.action-btn i {
    font-size: 0.95rem !important;
    margin: 0 !important;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.action-btn-warning {
    background: linear-gradient(135deg, #fb8c00 0%, #ef6c00 100%) !important;
    border: 0 !important;
    color: #fff !important;
    box-shadow: 0 3px 8px rgba(251, 140, 0, 0.3);
}

.action-btn-warning:hover {
    color: #fff !important;
    box-shadow: 0 6px 14px rgba(251, 140, 0, 0.45) !important;
    filter: brightness(1.05);
}

.action-btn-warning i {
    color: #fff !important;
}

.action-btn-success {
    background: #fff !important;
    border-color: #10b981 !important;
    color: #059669 !important;
}

.action-btn-success:hover {
    background: #10b981 !important;
    color: #fff !important;
    box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
}

.action-btn-primary {
    background: #fff !important;
    border-color: #5c6bc0 !important;
    color: #5c6bc0 !important;
}

.action-btn-primary:hover {
    background: #5c6bc0 !important;
    color: #fff !important;
    box-shadow: 0 4px 10px rgba(92, 107, 192, 0.3);
}

.action-btn-secondary {
    background: #fff !important;
    border-color: #cbd5e1 !important;
    color: #475569 !important;
}

.action-btn-secondary:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    color: #1e293b !important;
}

/* ---------- DataTables Overrides ---------- */
.attendance-table-wrap .dataTables_wrapper {
    padding: 1rem 1.25rem !important;
}

.attendance-table-wrap .dataTables_filter {
    float: right;
    margin-bottom: 1rem;
}

.attendance-table-wrap .dataTables_filter input {
    margin-left: 0.5rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 0.45rem;
    padding: 0.45rem 0.75rem;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    background: #fff;
}

.attendance-table-wrap .dataTables_filter input:focus {
    border-color: #5c6bc0;
    box-shadow: 0 0 0 3px rgba(92, 107, 192, 0.15);
    outline: none;
}

.attendance-table-wrap .dataTables_filter label {
    color: #475569;
    font-weight: 600;
    font-size: 0.85rem;
}

.attendance-table-wrap .dataTables_length {
    float: left;
    margin-bottom: 1rem;
}

.attendance-table-wrap .dataTables_length select {
    border: 1.5px solid #e2e8f0;
    border-radius: 0.45rem;
    padding: 0.35rem 0.6rem;
    margin: 0 0.35rem;
    font-size: 0.85rem;
    background: #fff;
}

.attendance-table-wrap .dataTables_length label {
    color: #475569;
    font-weight: 600;
    font-size: 0.85rem;
}

.attendance-table-wrap .dataTables_info {
    font-size: 0.825rem !important;
    padding-top: 1rem !important;
    color: #64748b;
    font-weight: 500;
}

.attendance-table-wrap .dataTables_paginate {
    padding-top: 0.75rem !important;
    float: right;
}

.attendance-table-wrap .dataTables_paginate .paginate_button {
    border-radius: 0.45rem !important;
    padding: 0.4rem 0.8rem !important;
    border: 1.5px solid #e2e8f0 !important;
    margin: 0 0.15rem !important;
    font-size: 0.85rem !important;
    color: #475569 !important;
    background: #fff !important;
    transition: all 0.2s ease !important;
    font-weight: 600 !important;
}

.attendance-table-wrap .dataTables_paginate .paginate_button:hover {
    background: #f1f5f9 !important;
    border-color: #cbd5e1 !important;
    color: #1e293b !important;
}

.attendance-table-wrap .dataTables_paginate .paginate_button.current {
    background: linear-gradient(135deg, #5c6bc0 0%, #3949ab 100%) !important;
    border-color: transparent !important;
    color: #fff !important;
    box-shadow: 0 3px 8px rgba(92, 107, 192, 0.3);
}

.attendance-table-wrap .dataTables_paginate .paginate_button.current:hover {
    background: linear-gradient(135deg, #4a5ab8 0%, #2c3a99 100%) !important;
    color: #fff !important;
}

.attendance-table-wrap .dataTables_paginate .paginate_button.disabled {
    color: #cbd5e1 !important;
    cursor: not-allowed;
}

.attendance-table-wrap .dataTables_paginate .paginate_button.disabled:hover {
    background: #fff !important;
    border-color: #e2e8f0 !important;
}

/* ---------- Empty State ---------- */
.attendance-empty-state {
    padding: 4rem 2rem;
    text-align: center;
}

.attendance-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #94a3b8;
    border-radius: 1.5rem;
    font-size: 2.5rem;
    margin-bottom: 1.25rem;
}

.attendance-empty-state h5 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.4rem;
}

.attendance-empty-state p {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0;
}

/* ---------- Responsive ---------- */
@media (max-width: 1199.98px) {
    .col-num { width: 50px; }
    .col-type { width: 70px; }
    .col-date { width: 100px; }
    .col-day { width: 60px; }
    .col-class { width: 60px; }
    .col-slot { width: 100px; }
    .col-status { width: 120px; }
    .col-action { width: 100px; }
}

@media (max-width: 991.98px) {
    .page-header-content {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    .page-header-meta {
        padding-left: 0;
        justify-content: center;
    }
    .page-header-actions {
        justify-content: center;
    }
    .page-header-title {
        justify-content: center;
    }
    .page-header-card {
        padding: 1.25rem 1rem;
    }
    .page-header-card::before {
        width: 100%;
        height: 5px;
        bottom: auto;
    }
    .stat-card {
        padding: 1rem;
    }
    .stat-card-icon {
        width: 44px;
        height: 44px;
        font-size: 1.3rem;
    }
    .stat-card-value {
        font-size: 1.6rem;
    }
    .attendance-table-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .attendance-table-toolbar-left {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
    }
    .attendance-filter-pills {
        justify-content: center;
    }
    .attendance-table-toolbar-right {
        justify-content: center;
    }
}

@media (max-width: 767.98px) {
    .page-header-title {
        font-size: 1.35rem !important;
    }
    .page-header-title i {
        width: 36px;
        height: 36px;
        font-size: 1rem;
    }
    .page-header-meta {
        font-size: 0.8rem;
    }
    .page-header-meta-item {
        font-size: 0.75rem;
        padding: 0.25rem 0.6rem;
    }
    .page-header-btn {
        width: 100%;
        justify-content: center;
        padding: 0.65rem 1rem !important;
    }
    .stat-card {
        padding: 0.85rem;
        gap: 0.75rem;
    }
    .stat-card-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
    .stat-card-value {
        font-size: 1.5rem;
    }
    .stat-card-label {
        font-size: 0.7rem;
    }
    .attendance-table-title {
        font-size: 0.95rem !important;
    }
    .attendance-filter-pills {
        gap: 0.35rem;
    }
    .filter-pill {
        padding: 0.4rem 0.7rem !important;
        font-size: 0.75rem !important;
    }
    .filter-pill-count {
        min-width: 18px;
        height: 18px;
        font-size: 0.65rem;
    }
    .bulk-autofill-btn {
        width: 100%;
        justify-content: center;
    }
    .attendance-data-table thead th {
        padding: 0.65rem 0.5rem;
        font-size: 0.65rem;
    }
    .attendance-data-table tbody td {
        padding: 0.65rem 0.5rem;
    }
    .date-cell-day {
        width: 34px;
        height: 34px;
        font-size: 0.95rem;
    }
    .date-cell-month {
        font-size: 0.65rem;
    }
    .today-tag {
        font-size: 0.6rem;
        padding: 0.15rem 0.35rem;
    }
    .status-pill {
        min-width: 90px;
        font-size: 0.65rem;
        padding: 0.3rem 0.5rem;
    }
    .action-btn {
        width: 30px !important;
        height: 30px !important;
        min-height: 30px !important;
    }
    .action-btn i {
        font-size: 0.85rem !important;
    }
    .class-badge {
        width: 28px;
        height: 24px;
        font-size: 0.75rem;
        min-width: 28px;
    }
    .type-pill {
        min-width: 50px;
        height: 22px;
        font-size: 0.65rem;
        padding: 0 0.4rem;
    }
    .type-pill i {
        font-size: 0.7rem;
    }
    .day-pill {
        min-width: 36px;
        padding: 0.25rem 0.4rem;
        font-size: 0.7rem;
    }
    .subject-name {
        font-size: 0.85rem;
    }
}

@media (max-width: 575.98px) {
    .attendance-data-table {
        min-width: 720px;
    }
    .attendance-table-wrap .dataTables_length,
    .attendance-table-wrap .dataTables_filter {
        text-align: left;
        float: none;
        width: 100%;
    }
}
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableElement = document.getElementById('attendanceDataTable');
    if (!tableElement || typeof window.jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') {
        return;
    }

    const dataTable = jQuery(tableElement).DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        autoWidth: true,
        dom: '<"row g-2 mb-2"<"col-sm-6 col-md-6 d-flex align-items-center"l><"col-sm-6 col-md-6 d-flex justify-content-end align-items-center"f>>t<"row mt-2"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6 d-flex justify-content-end"p>>',
        columnDefs: [
            { targets: 0, orderable: false, searchable: false, className: 'text-center' },
            { targets: 1, className: 'text-start' },
            { targets: 2, className: 'text-center' },
            { targets: 3, className: 'text-start' },
            { targets: 4, className: 'text-center' },
            { targets: 5, className: 'text-center' },
            { targets: 6, className: 'text-center' },
            { targets: 7, orderable: false, searchable: false, className: 'text-center' }
        ],
        language: {
            search: 'Search slots:',
            lengthMenu: 'Show _MENU_ slots',
            info: 'Showing _START_ to _END_ of _TOTAL_ slots',
            emptyTable: 'No slots available for the selected term.'
        }
    });

    dataTable.on('order.dt search.dt draw.dt', function () {
        dataTable.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, index) {
            cell.textContent = index + 1;
        });
    });

    dataTable.draw();
});
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

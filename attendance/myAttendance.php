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

// ── Load all mappings (lecture, lab, tutorial) for this faculty ─────────────
$mappings_rows = [];
// Lecture mappings
$lec_stmt = $conn->prepare("SELECT 'lecture' AS mapping_type, id, faculty, term, sem, subject, class AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM lecmapping WHERE faculty = ?");
$lec_stmt->bind_param('s', $logged_faculty_id);
$lec_stmt->execute();
$lec_res = $lec_stmt->get_result();
while ($r = $lec_res->fetch_assoc()) {
    $mappings_rows[] = $r;
}
$lec_stmt->close();
// Lab mappings
$lab_stmt = $conn->prepare("SELECT 'lab' AS mapping_type, id, faculty, term, sem, subject, batch AS class_or_batch, labNo, slot, start_date, end_date, repeat_days FROM labmapping WHERE faculty = ?");
$lab_stmt->bind_param('s', $logged_faculty_id);
$lab_stmt->execute();
$lab_res = $lab_stmt->get_result();
while ($r = $lab_res->fetch_assoc()) {
    $mappings_rows[] = $r;
}
$lab_stmt->close();
// Tutorial mappings
$tut_stmt = $conn->prepare("SELECT 'tutorial' AS mapping_type, id, faculty, term, sem, subject, tutBatch AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM tutmapping WHERE faculty = ?");
$tut_stmt->bind_param('s', $logged_faculty_id);
$tut_stmt->execute();
$tut_res = $tut_stmt->get_result();
while ($r = $tut_res->fetch_assoc()) {
    $mappings_rows[] = $r;
}
$tut_stmt->close();

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

// ── Current week bounds (Mon..Sun) ───────────────────────────────────────────
// Slots are expanded up to the END of the current week (not just today) so the
// "This Week" view can show upcoming slots. Slots after today carry
// 'future' => true and are excluded from every other view and from stats,
// preserving the original behavior outside the week view.
$today_str = date('Y-m-d');
$week_start_dt = new DateTime('monday this week');
$week_end_dt = (clone $week_start_dt)->modify('+6 days');
$week_start_str = $week_start_dt->format('Y-m-d');
$week_end_str = $week_end_dt->format('Y-m-d');
$expand_cap = new DateTime($today_str > $week_end_str ? $today_str : $week_end_str);

// ── Load exceptions for this faculty's mappings ───────────────────────────────
$exceptions_set = []; // "type:mapping_id|date" => true
if (!empty($mappings_rows)) {
    $lec_mapping_ids = array_column(array_filter($mappings_rows, fn($r) => ($r['mapping_type'] ?? '') === 'lecture'), 'id');
    if (!empty($lec_mapping_ids)) {
        $exc_placeholders = implode(',', array_fill(0, count($lec_mapping_ids), '?'));
        $exc_types = str_repeat('i', count($lec_mapping_ids));
        $exc_stmt = $conn->prepare("SELECT mapping_id, date FROM lecmapping_exceptions WHERE mapping_id IN ($exc_placeholders)");
        $exc_stmt->bind_param($exc_types, ...$lec_mapping_ids);
        $exc_stmt->execute();
        $exc_res = $exc_stmt->get_result();
        while ($er = $exc_res->fetch_assoc()) {
            $exceptions_set['lecture:' . $er['mapping_id'] . '|' . $er['date']] = true;
        }
        $exc_stmt->close();
    }
}

// ── Expand each mapping into individual date slots ───────────────────────────
// slot_list: array of [mapping_id, mapping_type, date, faculty, term, sem, subject, class_or_batch, slot, batch_label, lab_label, skipped]
$slot_list = [];
if ($filter_term !== '') {
    foreach ($mappings_rows as $m) {
    $mapping_term = trim((string)($m['term'] ?? ''));
    $mapping_type = (string)($m['mapping_type'] ?? 'lecture');
    if ($filter_mapping > 0 && ((int)$m['id']) !== $filter_mapping) continue;
        if (strcasecmp($mapping_term, $filter_term) !== 0) continue;

        $repeat_days = array_map('intval', explode(',', (string)$m['repeat_days']));
        $cur = new DateTime((string)$m['start_date']);
        $end = new DateTime((string)$m['end_date']);
        if ($end > $expand_cap) {
            $end = clone $expand_cap;
        }
        if ($cur > $end) {
            continue;
        }
        $end->modify('+1 day'); // make end inclusive

        // Pre-decode JSON storage fields
        $stored_slot = (string)($m['slot'] ?? '');
        $stored_batch = (string)($m['class_or_batch'] ?? '');
        $stored_lab = (string)($m['labNo'] ?? '');
        $parsed_slots = null;
        $parsed_batches = null;
        $parsed_labs = null;
        if ($stored_slot !== '' && $stored_slot[0] === '{') {
            $parsed_slots = json_decode($stored_slot, true) ?: [];
        }
        if ($stored_batch !== '' && $stored_batch[0] === '{') {
            $parsed_batches = json_decode($stored_batch, true) ?: [];
        }
        if ($stored_lab !== '' && $stored_lab[0] === '{') {
            $parsed_labs = json_decode($stored_lab, true) ?: [];
        }

        while ($cur < $end) {
        $dow = (int)$cur->format('w'); // 0=Sun … 6=Sat
        if (in_array($dow, $repeat_days, true)) {
            $date_str = $cur->format('Y-m-d');
            $dow_str = (string)$dow;
            $is_future_date = $date_str > $today_str;
            // Resolve per-day slot from JSON if stored
            if ($parsed_slots !== null) {
                $slot_value = (string)($parsed_slots[$dow] ?? $parsed_slots[$dow_str] ?? '');
            } else {
                $slot_value = $stored_slot;
            }
            if ($slot_value === '') { $cur->modify('+1 day'); continue; }

            // For lab mappings, expand into one entry per batch
            if ($mapping_type === 'lab' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                $batches_day = (array)($parsed_batches[$dow] ?? $parsed_batches[$dow_str] ?? []);
                $labs_day = (array)($parsed_labs[$dow] ?? $parsed_labs[$dow_str] ?? []);
                foreach ($batches_day as $bi => $batch_val) {
                    $batch_label = (string)$batch_val;
                    $lab_label = (string)($labs_day[$bi] ?? '');
                    if ($batch_label === '' || $lab_label === '') continue;
                    $slot_list[] = [
                        'mapping_id'   => (int)$m['id'],
                        'mapping_type' => $mapping_type,
                        'date'         => $date_str,
                        'faculty'      => (string)$m['faculty'],
                        'term'         => $mapping_term,
                        'sem'          => (string)$m['sem'],
                        'subject'      => (string)$m['subject'],
                        'class'        => $batch_label,
                        'slot'         => $slot_value,
                        'lab_no'       => $lab_label,
                        'skipped'      => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                        'future'       => $is_future_date,
                    ];
                }
            } elseif ($mapping_type === 'tutorial' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                $batches_day = (array)($parsed_batches[$dow] ?? $parsed_batches[$dow_str] ?? []);
                foreach ($batches_day as $batch_val) {
                    $batch_label = (string)$batch_val;
                    if ($batch_label === '') continue;
                    $slot_list[] = [
                        'mapping_id'   => (int)$m['id'],
                        'mapping_type' => $mapping_type,
                        'date'         => $date_str,
                        'faculty'      => (string)$m['faculty'],
                        'term'         => $mapping_term,
                        'sem'          => (string)$m['sem'],
                        'subject'      => (string)$m['subject'],
                        'class'        => $batch_label,
                        'slot'         => $slot_value,
                        'lab_no'       => '',
                        'skipped'      => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                        'future'       => $is_future_date,
                    ];
                }
            } else {
                $slot_list[] = [
                    'mapping_id'   => (int)$m['id'],
                    'mapping_type' => $mapping_type,
                    'date'         => $date_str,
                    'faculty'      => (string)$m['faculty'],
                    'term'         => $mapping_term,
                    'sem'          => (string)$m['sem'],
                    'subject'      => (string)$m['subject'],
                    'class'        => (string)$stored_batch,
                    'slot'         => $slot_value,
                    'lab_no'       => '',
                    'skipped'      => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                    'future'       => $is_future_date,
                ];
            }
        }
        $cur->modify('+1 day');
    }
    }
}

// Sort by date descending (newest first)
usort($slot_list, fn($a, $b) => strcmp($b['date'], $a['date']));

// ── Check which slots are already filled ─────────────────────────────────────
// Build a lookup: "type|term|sem|subject|class_or_batch|date|slot" => attendance_id.
//
// Key normalization handles common data-entry variations:
//   - Case-insensitive batch/class ("A1" == "a1")
//   - All whitespace stripped, including NBSP and full-width spaces
//   - All common dash variants collapsed to a single '-'
//   - Full-width colon (：) normalized to ASCII (:)
//   - Trailing/leading punctuation removed
//   - Subject terms collapse internal whitespace runs
//   - Time strings stripped of anything except digits, colons, and dashes
//     (so "10:30 - 11:30", "10:30-11:30", "10：30–11：30" all match)
//
// We populate the lookup TWICE per attendance row:
//   1. With the strict normalized key.
//   2. With a "loose" key that strips ALL non-alphanumeric chars from each
//      field, so even extreme variations (mixed case, smart quotes, BOM,
//      CRLF, ZWJ, etc.) still collide. This is the safety net for
//      hosted environments where the source data may have been touched
//      by Excel, Google Sheets, or migration scripts.
if (!function_exists('attendance_norm_text')) {
    function attendance_norm_text($value) {
        $s = (string)$value;
        if ($s === '') return '';
        $s = strtr($s, [
            "\xE3\x80\x82" => '.',
            "\xEF\xBC\x9A" => ':',
            "\xEF\xBC\x8C" => ',',
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xC2\xA0"      => ' ',
            "\xE3\x80\x80" => ' ',
            "\xE2\x80\x82" => ' ',
            "\xE2\x80\x83" => ' ',
        ]);
        $s = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}\x{2060}]/u', '', $s);
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
    function attendance_norm_time($value) {
        $s = attendance_norm_text($value);
        if ($s === '') return '';
        $s = preg_replace('/[^0-9:\-]/', '', $s);
        $s = preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        return $s;
    }
    // Strict key: full normalization (whitespace-preserving for text fields).
    function attendance_norm_key($type, $term, $sem, $subject, $class_or_batch, $date, $time) {
        return strtolower(trim((string)$type)) . '|'
             . attendance_norm_text($term) . '|'
             . attendance_norm_text($sem) . '|'
             . attendance_norm_text($subject) . '|'
             . attendance_norm_text($class_or_batch) . '|'
             . attendance_norm_text($date) . '|'
             . attendance_norm_time($time);
    }
    // Loose key: strip ALL non-alphanumeric chars from every field. Two
    // attendance rows that only differ by invisible characters will collide
    // here even when their strict keys differ.
    function attendance_norm_loose($value) {
        $s = (string)$value;
        $s = preg_replace('/[^a-z0-9]/i', '', $s);
        return strtolower($s);
    }
    function attendance_loose_key($type, $term, $sem, $subject, $class_or_batch, $date, $time) {
        return attendance_norm_loose($type) . '|'
             . attendance_norm_loose($term) . '|'
             . attendance_norm_loose($sem) . '|'
             . attendance_norm_loose($subject) . '|'
             . attendance_norm_loose($class_or_batch) . '|'
             . attendance_norm_loose($date) . '|'
             . attendance_norm_loose($time);
    }
    function attendance_mark_filled(&$lookup, $key_loose, $id) {
        // Store under BOTH keys: the strict key is computed by the caller
        // and the loose key here. We use the loose key for collision
        // detection because it's the more permissive of the two.
        $lookup[$key_loose] = $id;
    }
}

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

        // Lecture attendance
        $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, class FROM lecattendance WHERE term IN ($t_placeholders) AND sem IN ($s_placeholders)");
        $att_stmt->bind_param($types, ...$params);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $key_strict = attendance_norm_key('lecture', $ar['term'], $ar['sem'], $ar['subject'], $ar['class'], $ar['date'], $ar['time']);
            $key_loose  = attendance_loose_key('lecture', $ar['term'], $ar['sem'], $ar['subject'], $ar['class'], $ar['date'], $ar['time']);
            $filled_lookup[$key_strict] = (int)$ar['id'];
            $filled_lookup[$key_loose]  = (int)$ar['id'];
        }
        $att_stmt->close();

        // Lab attendance
        $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, batch FROM labattendance WHERE term IN ($t_placeholders) AND sem IN ($s_placeholders)");
        $att_stmt->bind_param($types, ...$params);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $key_strict = attendance_norm_key('lab', $ar['term'], $ar['sem'], $ar['subject'], $ar['batch'], $ar['date'], $ar['time']);
            $key_loose  = attendance_loose_key('lab', $ar['term'], $ar['sem'], $ar['subject'], $ar['batch'], $ar['date'], $ar['time']);
            $filled_lookup[$key_strict] = (int)$ar['id'];
            $filled_lookup[$key_loose]  = (int)$ar['id'];
        }
        $att_stmt->close();

        // Tutorial attendance
        $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, batch FROM tutattendance WHERE term IN ($t_placeholders) AND sem IN ($s_placeholders)");
        $att_stmt->bind_param($types, ...$params);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $key_strict = attendance_norm_key('tutorial', $ar['term'], $ar['sem'], $ar['subject'], $ar['batch'], $ar['date'], $ar['time']);
            $key_loose  = attendance_loose_key('tutorial', $ar['term'], $ar['sem'], $ar['subject'], $ar['batch'], $ar['date'], $ar['time']);
            $filled_lookup[$key_strict] = (int)$ar['id'];
            $filled_lookup[$key_loose]  = (int)$ar['id'];
        }
        $att_stmt->close();
    }
}

// ── Annotate each slot with filled status ─────────────────────────────────────
foreach ($slot_list as &$slot) {
    $type = $slot['mapping_type'];
    $key_strict = attendance_norm_key(
        $type,
        $slot['term'],
        $slot['sem'],
        $slot['subject'],
        $slot['class'],
        $slot['date'],
        $slot['slot']
    );
    $key_loose = attendance_loose_key(
        $type,
        $slot['term'],
        $slot['sem'],
        $slot['subject'],
        $slot['class'],
        $slot['date'],
        $slot['slot']
    );
    // Try strict match first; fall back to loose match (which strips ALL
    // non-alphanumeric chars from every field) so even severe data quality
    // issues don't break the filled-detection.
    $matched_key = null;
    if (isset($filled_lookup[$key_strict])) {
        $matched_key = $key_strict;
    } elseif (isset($filled_lookup[$key_loose])) {
        $matched_key = $key_loose;
    }
    $slot['filled']        = $matched_key !== null;
    $slot['attendance_id'] = $matched_key !== null ? $filled_lookup[$matched_key] : null;
}
unset($slot);

// ── Optional diagnostic ────────────────────────────────────────────────────
// Append `?diag=1` to the page URL to see exactly what is stored for each
// pending slot and each filled attendance row. This helps diagnose why a
// filled slot still appears in the Pending list when there is a hidden
// data-quality mismatch (e.g. NBSP, full-width punctuation, casing).
$diag_rows = [];
if (isset($_GET['diag']) && $_GET['diag'] === '1') {
    foreach ($slot_list as $s) {
        $diag_rows[] = [
            'when'          => $s['date'] . ' ' . $s['slot'],
            'type'          => $s['mapping_type'],
            'term'          => $s['term'],
            'sem'           => $s['sem'],
            'subject_raw'   => $s['subject'],
            'class_raw'     => $s['class'],
            'slot_raw'      => $s['slot'],
            'subject_norm'  => attendance_norm_text($s['subject']),
            'class_norm'    => attendance_norm_text($s['class']),
            'slot_norm'     => attendance_norm_time($s['slot']),
            'key_strict'    => attendance_norm_key($s['mapping_type'], $s['term'], $s['sem'], $s['subject'], $s['class'], $s['date'], $s['slot']),
            'key_loose'     => attendance_loose_key($s['mapping_type'], $s['term'], $s['sem'], $s['subject'], $s['class'], $s['date'], $s['slot']),
            'filled'        => !empty($s['filled']),
        ];
    }
    $diag_filled_keys = array_keys($filled_lookup);
}

$bulk_candidates = array_values(array_filter($slot_list, fn($s) => !$s['filled'] && !$s['skipped'] && empty($s['future'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autofill_pending_max'])) {
    $redirect_params = [
        'status' => $filter_status,
        'mapping' => $filter_mapping,
        'term' => $filter_term,
    ];

    if (empty($bulk_candidates)) {
        $redirect_params['err'] = 'No pending slots found for autofill.';
        header('Location: myAttendance.php?' . http_build_query($redirect_params));
        exit();
    }

    $class_students_stmt = $conn->prepare("SELECT enrollmentNo FROM students WHERE term = ? AND sem = ? AND class = ? AND enrollmentNo IS NOT NULL AND TRIM(enrollmentNo) <> ''");
    // Two-stage source lookup:
    //   (a) Same-class same-date attendance for the slot's date (preferred,
    //       catches "today" attendance that another faculty already filed).
    //   (b) Same-class attendance from the most recent PAST date within the
    //       last 14 days (fallback when the slot's date has no source yet,
    //       which is the typical case when filling tomorrow's slot today).
    $lec_auto_stmt = $conn->prepare("SELECT presentNo, date FROM lecattendance WHERE term = ? AND sem = ? AND class = ? AND date <= ? ORDER BY date DESC, id DESC LIMIT 50");
    $lab_auto_stmt = $conn->prepare("SELECT presentNo, date FROM labattendance WHERE term = ? AND sem = ? AND date <= ? AND COALESCE(TRIM(labNo), '') <> '' ORDER BY date DESC, id DESC LIMIT 50");
    $tut_auto_stmt = $conn->prepare("SELECT presentNo, date FROM tutattendance WHERE term = ? AND sem = ? AND date <= ? ORDER BY date DESC, id DESC LIMIT 50");
    // Three exists/insert pairs, one per attendance table
    $lec_exists = $conn->prepare("SELECT id FROM lecattendance WHERE date = ? AND time = ? AND term = ? AND sem = ? AND subject = ? AND class = ? LIMIT 1");
    $lec_insert = $conn->prepare("INSERT INTO lecattendance (date, logdate, time, term, faculty, sem, subject, class, presentNo, absentNo, description) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $lab_exists = $conn->prepare("SELECT id FROM labattendance WHERE date = ? AND time = ? AND term = ? AND sem = ? AND subject = ? AND batch = ? LIMIT 1");
    $lab_insert = $conn->prepare("INSERT INTO labattendance (date, logdate, time, term, faculty, sem, subject, batch, presentNo, labNo) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)");
    $tut_exists = $conn->prepare("SELECT id FROM tutattendance WHERE date = ? AND time = ? AND term = ? AND sem = ? AND subject = ? AND batch = ? LIMIT 1");
    $tut_insert = $conn->prepare("INSERT INTO tutattendance (date, logdate, time, term, faculty, sem, subject, batch, presentNo) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");

    if (!$class_students_stmt || !$lec_auto_stmt || !$lab_auto_stmt || !$tut_auto_stmt || !$lec_exists || !$lec_insert || !$lab_exists || !$lab_insert || !$tut_exists || !$tut_insert) {
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
    $skipped_no_autofill = 0;
    $skipped_existing = 0;
    $skipped_duplicate = 0;
    $failed = 0;
    $first_failure_reason = '';
    $source_dates_used = [];

    foreach ($bulk_candidates as $slot) {
        $slot_type = (string)($slot['mapping_type'] ?? 'lecture');
        $slot_key = attendance_norm_key(
            $slot_type,
            $slot['term'],
            $slot['sem'],
            $slot['subject'],
            $slot['class'],
            $slot['date'],
            $slot['slot']
        );
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
        $lab_no = (string)($slot['lab_no'] ?? '');

        // Pick the right exists/insert based on type
        if ($slot_type === 'lecture') {
            $exists_stmt = $lec_exists;
            $insert_stmt = $lec_insert;
        } elseif ($slot_type === 'lab') {
            $exists_stmt = $lab_exists;
            $insert_stmt = $lab_insert;
        } else {
            $exists_stmt = $tut_exists;
            $insert_stmt = $tut_insert;
        }

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
                // Source-attendance lookup with explicit preference order:
                //   1. Same-class, same-date (preferred — usually another
                //      faculty filed today's attendance for this class).
                //   2. Same-class, most-recent PAST date (skipping dates
                //      that have no data — handles holidays etc. naturally
                //      by just not having rows for that date).
                //   3. Same-class, most-recent FUTURE date (last-resort when
                //      the user is filling the FIRST lecture of the term
                //      and there's no past history yet).
                // Each row is scored by recency (lower days-apart wins)
                // and roster size (more students is slightly better when
                // recency is equal). Same-date rows always beat any other
                // date regardless of roster size.
                $slot_ts = strtotime($date);
                $candidate_rows = [];
                // Same-date row (highest priority).
                $lec_auto_stmt->bind_param('ssss', $term, $sem, $class, $date);
                $lec_auto_stmt->execute();
                $lec_res = $lec_auto_stmt->get_result();
                while ($row = $lec_res->fetch_assoc()) {
                    $candidate_rows[] = $row;
                }

                $lab_auto_stmt->bind_param('sss', $term, $sem, $date);
                $lab_auto_stmt->execute();
                $lab_res = $lab_auto_stmt->get_result();
                while ($row = $lab_res->fetch_assoc()) {
                    $candidate_rows[] = $row;
                }

                $tut_auto_stmt->bind_param('sss', $term, $sem, $date);
                $tut_auto_stmt->execute();
                $tut_res = $tut_auto_stmt->get_result();
                while ($row = $tut_res->fetch_assoc()) {
                    $candidate_rows[] = $row;
                }

                // Walk-back lookup: find the most-recent past date that
                // has any same-class attendance. Done by stepping back one
                // day at a time (up to 30 days) and querying the same-class
                // attendance for that day. The first non-empty result is
                // the closest past source.
                $walkback_rows = [];
                $walkback_found_date = '';
                $walk_dt = new DateTime($date);
                for ($i = 1; $i <= 30; $i++) {
                    $walk_dt->modify('-1 day');
                    $walk_date = $walk_dt->format('Y-m-d');
                    $has_any = false;

                    $lec_auto_stmt->bind_param('ssss', $term, $sem, $class, $walk_date);
                    $lec_auto_stmt->execute();
                    $wr = $lec_auto_stmt->get_result();
                    while ($row = $wr->fetch_assoc()) {
                        $row['_walk_date'] = $walk_date;
                        $walkback_rows[] = $row;
                        $has_any = true;
                    }

                    $lab_auto_stmt->bind_param('sss', $term, $sem, $walk_date);
                    $lab_auto_stmt->execute();
                    $wr = $lab_auto_stmt->get_result();
                    while ($row = $wr->fetch_assoc()) {
                        $row['_walk_date'] = $walk_date;
                        $walkback_rows[] = $row;
                        $has_any = true;
                    }

                    $tut_auto_stmt->bind_param('sss', $term, $sem, $walk_date);
                    $tut_auto_stmt->execute();
                    $wr = $tut_auto_stmt->get_result();
                    while ($row = $wr->fetch_assoc()) {
                        $row['_walk_date'] = $walk_date;
                        $walkback_rows[] = $row;
                        $has_any = true;
                    }

                    if ($has_any) {
                        $walkback_found_date = $walk_date;
                        break;  // closest past date with data wins
                    }
                }

                // Walk-forward fallback: only when no past data exists. Used
                // for the very first lecture of the term. Step forward up
                // to 14 days looking for a same-class entry.
                $walkforward_rows = [];
                $walkforward_found_date = '';
                if (empty($walkback_rows)) {
                    $wf_dt = new DateTime($date);
                    for ($i = 1; $i <= 14; $i++) {
                        $wf_dt->modify('+1 day');
                        $wf_date = $wf_dt->format('Y-m-d');
                        $has_any = false;

                        $lec_auto_stmt->bind_param('ssss', $term, $sem, $class, $wf_date);
                        $lec_auto_stmt->execute();
                        $wr = $lec_auto_stmt->get_result();
                        while ($row = $wr->fetch_assoc()) {
                            $row['_walk_date'] = $wf_date;
                            $walkforward_rows[] = $row;
                            $has_any = true;
                        }

                        $lab_auto_stmt->bind_param('sss', $term, $sem, $wf_date);
                        $lab_auto_stmt->execute();
                        $wr = $lab_auto_stmt->get_result();
                        while ($row = $wr->fetch_assoc()) {
                            $row['_walk_date'] = $wf_date;
                            $walkforward_rows[] = $row;
                            $has_any = true;
                        }

                        $tut_auto_stmt->bind_param('sss', $term, $sem, $wf_date);
                        $tut_auto_stmt->execute();
                        $wr = $tut_auto_stmt->get_result();
                        while ($row = $wr->fetch_assoc()) {
                            $row['_walk_date'] = $wf_date;
                            $walkforward_rows[] = $row;
                            $has_any = true;
                        }

                        if ($has_any) {
                            $walkforward_found_date = $wf_date;
                            break;
                        }
                    }
                }

                // Combine all candidate rows and pick the best one.
                // Tie-breaks:
                //   - Same-date rows always win (highest priority).
                //   - Among same-date rows, more students is better.
                //   - For past/future rows, fewer days-apart is better;
                //     ties broken by larger roster.
                $winning_date = '';
                $rank_candidate = static function (array $row) use (&$best_present, &$best_count, &$winning_date, $class_set, $parse_present_tokens, $consider_present, $slot_ts, $date) {
                    $present = $consider_present((string)($row['presentNo'] ?? ''), $class_set, $parse_present_tokens);
                    if (empty($present)) {
                        return;
                    }
                    $row_date = (string)($row['_walk_date'] ?? $row['date'] ?? '');
                    $row_ts = strtotime($row_date);
                    $days_apart = (int)(abs($row_ts - $slot_ts) / 86400);
                    $same_day_bonus = ($row_ts === $slot_ts) ? 1_000_000 : 0;
                    $recency_bonus  = max(0, 200 - $days_apart);
                    $score = (count($present) * 100) + $same_day_bonus + $recency_bonus;
                    if ($score > $best_count) {
                        $best_count = $score;
                        $best_present = $present;
                        $winning_date = $row_date;
                    }
                };

                foreach ($candidate_rows as $row) {
                    $rank_candidate($row);
                }
                foreach ($walkback_rows as $row) {
                    $rank_candidate($row);
                }
                foreach ($walkforward_rows as $row) {
                    $rank_candidate($row);
                }

                // Remember the source date this slot used so we can surface
                // it in the redirect message.
                if ($winning_date !== '' && $winning_date !== $date) {
                    $source_dates_used[$slot_key] = $winning_date;
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

        if ($slot_type === 'lecture') {
            $description = null;
            $insert_stmt->bind_param('ssssssssss', $date, $time, $term, $faculty, $sem, $subject, $class, $present_csv, $absent_csv, $description);
        } elseif ($slot_type === 'lab') {
            // Lab attendance columns: date, logdate, time, term, faculty, sem, subject, batch, presentNo, labNo
            $insert_stmt->bind_param('sssssssss', $date, $time, $term, $faculty, $sem, $subject, $class, $present_csv, $lab_no);
        } else {
            // Tutorial: date, logdate, time, term, faculty, sem, subject, batch, presentNo
            $insert_stmt->bind_param('ssssssss', $date, $time, $term, $faculty, $sem, $subject, $class, $present_csv);
        }

        if ($insert_stmt->execute()) {
            $created++;
        } else {
            $failed++;
            if ($first_failure_reason === '') {
                $first_failure_reason = sprintf(
                    '[%s] %s on %s — %s',
                    $slot_type,
                    $subject,
                    $date,
                    $conn->error ?: 'unknown error'
                );
            }
        }
    }

    $class_students_stmt->close();
    $lec_auto_stmt->close();
    $lab_auto_stmt->close();
    $tut_auto_stmt->close();
    $lec_exists->close();
    $lec_insert->close();
    $lab_exists->close();
    $lab_insert->close();
    $tut_exists->close();
    $tut_insert->close();

    if ($created === 0 && $failed === 0) {
        $redirect_params['err'] = 'No pending slots were inserted. Existing entries may already be present or no autofill source had students.';
    } else {
        $summary = "Autofill complete: created {$created}, skipped no source {$skipped_no_autofill}, skipped existing {$skipped_existing}";
        if ($skipped_duplicate > 0) {
            $summary .= ", skipped duplicate {$skipped_duplicate}";
        }
        if ($failed > 0) {
            $summary .= ", failed {$failed}";
            if ($first_failure_reason !== '') {
                $summary .= " (first error: " . $first_failure_reason . ")";
            }
        }
        // Surface up to 3 distinct source dates used (most common case).
        $unique_sources = array_values(array_unique($source_dates_used));
        if (!empty($unique_sources)) {
            $shown = array_slice($unique_sources, 0, 3);
            $summary .= " Source dates: " . implode(', ', $shown);
            if (count($unique_sources) > 3) {
                $summary .= ' (+' . (count($unique_sources) - 3) . ' more)';
            }
            $summary .= '.';
        }
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
// Stats computed before filter, on non-future slots only, so counts match the
// classic views. The week view is the only view that includes future slots.
$stats_slot_list = array_values(array_filter($slot_list, fn($s) => empty($s['future'])));
$total_skipped = count(array_filter($stats_slot_list, fn($s) => $s['skipped']));

// Current-week slots (all statuses, incl. upcoming days)
$week_slot_count = count(array_filter($slot_list, fn($s) => $s['date'] >= $week_start_str && $s['date'] <= $week_end_str));

// Sort key for chronological ordering within a day: first time in the slot
// string, mapped to minutes; hours below 7 are treated as PM (college slots
// run 10:30 AM - 5:10 PM, so "1:00 - 2:00" means 13:00).
if (!function_exists('myatt_slot_minutes')) {
    function myatt_slot_minutes(string $slot): int {
        if (!preg_match('/(\d{1,2}):(\d{2})/', $slot, $m)) return 9999;
        $h = (int)$m[1];
        if ($h < 7) $h += 12;
        return $h * 60 + (int)$m[2];
    }
}

if ($filter_status === 'filled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['filled'] && empty($s['future'])));
} elseif ($filter_status === 'unfilled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => !$s['filled'] && !$s['skipped'] && empty($s['future'])));
} elseif ($filter_status === 'skipped') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['skipped'] && empty($s['future'])));
} elseif ($filter_status === 'week') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['date'] >= $week_start_str && $s['date'] <= $week_end_str));
    usort($slot_list, fn($a, $b) => strcmp($a['date'], $b['date']) ?: (myatt_slot_minutes($a['slot']) <=> myatt_slot_minutes($b['slot'])));
} else {
    // 'all' shows everything including skipped (but not upcoming days)
    $slot_list = array_values(array_filter($slot_list, fn($s) => empty($s['future'])));
}

// Per-day pending counts for the week view day dividers
$week_day_pending = [];
if ($filter_status === 'week') {
    foreach ($slot_list as $s) {
        if (!$s['filled'] && !$s['skipped']) {
            $week_day_pending[$s['date']] = ($week_day_pending[$s['date']] ?? 0) + 1;
        }
    }
}

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

            <?php if (!empty($diag_rows)): ?>
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <h5 class="mb-2"><i class="bi bi-bug me-1"></i>Diagnostic — Pending Slot Keys vs Filled Lookup</h5>
                    <p class="text-muted small mb-2">Remove <code>?diag=1</code> from the URL to hide this block. The <b>Strict</b> and <b>Loose</b> keys are what the page compares against the filled-attendance lookup.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-3" style="font-size:0.78rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>Type</th>
                                    <th>Filled?</th>
                                    <th>Subject (raw → norm)</th>
                                    <th>Class (raw → norm)</th>
                                    <th>Slot (raw → norm)</th>
                                    <th>Strict key</th>
                                    <th>Loose key</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diag_rows as $d): ?>
                                    <tr class="<?= !empty($d['filled']) ? 'table-success' : 'table-warning' ?>">
                                        <td><?= htmlspecialchars($d['when']) ?></td>
                                        <td><?= htmlspecialchars($d['type']) ?></td>
                                        <td class="text-center"><?= !empty($d['filled']) ? '✓' : '✗' ?></td>
                                        <td><code class="text-muted">[<?= htmlspecialchars($d['subject_raw']) ?>]</code><br><?= htmlspecialchars($d['subject_norm']) ?></td>
                                        <td><code class="text-muted">[<?= htmlspecialchars($d['class_raw']) ?>]</code><br><?= htmlspecialchars($d['class_norm']) ?></td>
                                        <td><code class="text-muted">[<?= htmlspecialchars($d['slot_raw']) ?>]</code><br><?= htmlspecialchars($d['slot_norm']) ?></td>
                                        <td><code style="word-break:break-all;font-size:0.7rem;"><?= htmlspecialchars($d['key_strict']) ?></code></td>
                                        <td><code style="word-break:break-all;font-size:0.7rem;"><?= htmlspecialchars($d['key_loose']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <h6 class="mt-3 mb-1">Filled-attendance lookup keys (<?= count($diag_filled_keys) ?>)</h6>
                    <p class="text-muted small mb-1">These are the keys from <code>lecattendance</code>, <code>labattendance</code>, and <code>tutattendance</code> rows. If you see a row above marked <b>not filled</b> whose key appears here, the match should succeed — if it doesn't, the loose-key table above will reveal the difference.</p>
                    <div style="max-height:200px;overflow:auto;font-size:0.72rem;background:#1e1e1e;color:#d4d4d4;padding:0.5rem;border-radius:0.3rem;">
                        <?php foreach ($diag_filled_keys as $k): ?>
                            <div><code style="color:#ce9178;"><?= htmlspecialchars($k) ?></code></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($mappings_rows)): ?>
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>No mappings (lecture / lab / tutorial) found for your account.
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
                        <?php
                        $filter_options = [
                            'all'      => ['label' => 'All',       'icon' => 'bi-grid',           'count' => $total],
                            'week'     => ['label' => 'This Week', 'icon' => 'bi-calendar-week',  'count' => $week_slot_count],
                            'unfilled' => ['label' => 'Pending',   'icon' => 'bi-hourglass-split','count' => $unfilled],
                            'filled'   => ['label' => 'Filled',    'icon' => 'bi-check-circle',   'count' => $filled],
                            'skipped'  => ['label' => 'Skipped',   'icon' => 'bi-slash-circle',   'count' => $skipped],
                        ];
                        $active_filter = isset($filter_options[$filter_status]) ? $filter_status : 'unfilled';
                        ?>
                        <div class="attendance-filter-select-wrap d-md-none" data-filter="<?= htmlspecialchars($active_filter) ?>">
                            <i class="bi <?= htmlspecialchars($filter_options[$active_filter]['icon']) ?> attendance-filter-select-icon"></i>
                            <select class="attendance-filter-select" aria-label="Attendance filter" onchange="if (this.value) window.location.href = this.value;">
                                <?php foreach ($filter_options as $status_key => $opt): ?>
                                    <option value="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => $status_key, 'mapping' => $filter_mapping])) ?>" <?= $status_key === $active_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt['label']) ?> (<?= (int)$opt['count'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="bi bi-chevron-down attendance-filter-select-chevron"></i>
                        </div>
                        <div class="attendance-filter-pills d-none d-md-inline-flex" role="group" aria-label="Attendance filters">
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'all', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill <?= $filter_status === 'all' ? 'filter-pill-active' : '' ?>" title="All slots">
                                All <span class="filter-pill-count"><?= $total ?></span>
                            </a>
                            <a href="?<?= htmlspecialchars(http_build_query(['term' => $filter_term, 'status' => 'week', 'mapping' => $filter_mapping])) ?>"
                               class="filter-pill filter-pill-week <?= $filter_status === 'week' ? 'filter-pill-active' : '' ?>" title="All slots in the current week (<?= htmlspecialchars(date('d M', strtotime($week_start_str))) ?> - <?= htmlspecialchars(date('d M', strtotime($week_end_str))) ?>)">
                                <i class="bi bi-calendar-week"></i>This Week <span class="filter-pill-count"><?= $week_slot_count ?></span>
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
                                        <th class="text-center col-class">Class / Batch</th>
                                        <th class="text-center col-slot">Slot</th>
                                        <th class="text-center col-status">Status</th>
                                        <th class="text-center col-action">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $prev_week_date = null; ?>
                                <?php foreach ($slot_list as $i => $slot):
                                    $date_obj = new DateTime($slot['date']);
                                    $dow_name = $day_names[(int)$date_obj->format('w')];
                                    $is_today = ($slot['date'] === date('Y-m-d'));
                                    if ($filter_status === 'week' && $slot['date'] !== $prev_week_date):
                                        $prev_week_date = $slot['date'];
                                        $day_pending_count = $week_day_pending[$slot['date']] ?? 0;
                                ?>
                                <tr class="week-day-divider">
                                    <td colspan="9">
                                        <span class="week-day-divider-name"><i class="bi bi-calendar-event me-2"></i><?= htmlspecialchars($date_obj->format('l')) ?></span>
                                        <span class="week-day-divider-date"><?= htmlspecialchars($date_obj->format('d M Y')) ?></span>
                                        <?php if ($is_today): ?><span class="today-tag">Today</span><?php endif; ?>
                                        <span class="week-day-divider-count <?= $day_pending_count > 0 ? 'has-pending' : '' ?>">
                                            <?= $day_pending_count > 0 ? $day_pending_count . ' pending' : 'All done' ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php
                                    $mapping_type = $slot['mapping_type'];
                                    // Build params per type
                                    if ($mapping_type === 'lecture') {
                                        $params = http_build_query([
                                            'faculty' => $slot['faculty'],
                                            'term'    => $slot['term'],
                                            'sem'     => $slot['sem'],
                                            'subject' => $slot['subject'],
                                            'class'   => $slot['class'],
                                            'date'    => $slot['date'],
                                            'slot'    => $slot['slot'],
                                        ]);
                                    } else {
                                        // Lab & Tutorial use 'batch'
                                        $params = http_build_query([
                                            'faculty' => $slot['faculty'],
                                            'term'    => $slot['term'],
                                            'sem'     => $slot['sem'],
                                            'subject' => $slot['subject'],
                                            'batch'   => $slot['class'],
                                            'date'    => $slot['date'],
                                            'slot'    => $slot['slot'],
                                            'batch_lab_map[' . $slot['class'] . ']' => ($slot['lab_no'] ?? ''),
                                        ]);
                                    }
                                    if ($mapping_type === 'lecture') {
                                        $take_url   = 'takelecatt.php?' . $params;
                                        $edit_url   = $slot['filled'] ? 'editlecatt.php?id=' . $slot['attendance_id'] : null;
                                        $summary_url = $slot['filled'] ? 'attendanceSummary.php?type=lecture&id=' . $slot['attendance_id'] : null;
                                    } elseif ($mapping_type === 'lab') {
                                        $take_url   = 'takelabatt.php?' . $params;
                                        $edit_url   = $slot['filled'] ? 'editlabatt.php?id=' . $slot['attendance_id'] : null;
                                        $summary_url = $slot['filled'] ? 'attendanceSummary.php?type=lab&id=' . $slot['attendance_id'] : null;
                                    } else {
                                        $take_url   = 'taketutatt.php?' . $params;
                                        $edit_url   = $slot['filled'] ? 'edittutatt.php?id=' . $slot['attendance_id'] : null;
                                        $summary_url = $slot['filled'] ? 'attendanceSummary.php?type=tutorial&id=' . $slot['attendance_id'] : null;
                                    }
                                    $type_pill_class = $mapping_type === 'lab' ? 'type-lab' : ($mapping_type === 'tutorial' ? 'type-tut' : 'type-lec');
                                    $type_pill_label = $mapping_type === 'lab' ? 'Lab' : ($mapping_type === 'tutorial' ? 'Tut' : 'Lec');
                                    $type_pill_icon  = $mapping_type === 'lab' ? 'bi-camera-video' : ($mapping_type === 'tutorial' ? 'bi-book' : 'bi-easel2');
                                    $class_label = $mapping_type === 'lecture' ? 'Class' : 'Batch';
                                    $row_class = '';
                                    if ($slot['skipped']) $row_class = 'row-skipped';
                                    elseif (!$slot['filled'] && $is_today) $row_class = 'row-today';
                                    elseif (!$slot['filled']) $row_class = 'row-pending';
                                ?>
                                <?php $row_clickable = !$slot['skipped'] && !$slot['filled']; ?>
                                <tr class="<?= $row_class ?><?= $row_clickable ? ' row-clickable' : '' ?>"<?= $row_clickable ? ' data-take-url="' . htmlspecialchars($take_url) . '"' : '' ?>>
                                    <td class="text-center col-num"><span class="row-num"><?= $i + 1 ?></span></td>
                                    <td class="text-center col-type">
                                        <span class="type-pill <?= $type_pill_class ?>" title="<?= ucfirst($type_pill_label) ?>"><i class="bi <?= $type_pill_icon ?>"></i><?= $type_pill_label ?></span>
                                    </td>
                                    <td class="col-date" data-sort="<?= htmlspecialchars($slot['date']) ?>">
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

.filter-pill-week.filter-pill-active {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%) !important;
    box-shadow: 0 4px 10px rgba(14, 165, 233, 0.3);
}

/* ---------- Week view day dividers ---------- */
.week-day-divider td {
    background: linear-gradient(180deg, #eef2ff 0%, #e0e7ff 100%) !important;
    border-top: 2px solid #c7d2fe !important;
    padding: 0.55rem 1rem !important;
    font-size: 0.85rem;
}

.week-day-divider-name {
    font-weight: 800;
    color: #3730a3;
    letter-spacing: 0.01em;
}

.week-day-divider-date {
    color: #6366f1;
    font-weight: 600;
    margin-left: 0.6rem;
}

.week-day-divider .today-tag {
    margin-left: 0.6rem;
}

.week-day-divider-count {
    float: right;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    background: #d1fae5;
    color: #047857;
}

.week-day-divider-count.has-pending {
    background: #fee2e2;
    color: #b91c1c;
}

/* ---------- Mobile filter dropdown (replaces pills on small screens) ---------- */
.attendance-filter-select-wrap {
    position: relative;
    width: 100%;
}

.attendance-filter-select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    width: 100%;
    padding: 0.7rem 2.6rem 0.7rem 2.7rem;
    font-size: 0.9rem;
    font-weight: 700;
    color: #1e293b;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border: 1.5px solid #e2e8f0;
    border-radius: 0.7rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.attendance-filter-select:focus {
    outline: none;
    border-color: #5c6bc0;
    box-shadow: 0 0 0 3px rgba(92, 107, 192, 0.18);
}

.attendance-filter-select-icon,
.attendance-filter-select-chevron {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    font-size: 1rem;
}

.attendance-filter-select-icon {
    left: 0.95rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 0.45rem;
    font-size: 0.85rem;
    background: #eef2ff;
    color: #4338ca;
}

.attendance-filter-select-chevron {
    right: 0.95rem;
    color: #94a3b8;
    font-size: 0.85rem;
}

/* Accent the leading icon by active filter */
.attendance-filter-select-wrap[data-filter="week"] .attendance-filter-select-icon { background: #e0f2fe; color: #0284c7; }
.attendance-filter-select-wrap[data-filter="unfilled"] .attendance-filter-select-icon { background: #fee2e2; color: #dc2626; }
.attendance-filter-select-wrap[data-filter="filled"] .attendance-filter-select-icon { background: #d1fae5; color: #059669; }
.attendance-filter-select-wrap[data-filter="skipped"] .attendance-filter-select-icon { background: #e2e8f0; color: #475569; }

@media (max-width: 767.98px) {
    .attendance-table-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .attendance-table-toolbar-left {
        width: 100%;
    }

    .attendance-table-toolbar-right {
        width: 100%;
    }

    .attendance-table-toolbar-right .attendance-bulk-form,
    .attendance-table-toolbar-right .bulk-autofill-btn {
        width: 100%;
        justify-content: center;
    }
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

.row-clickable {
    cursor: pointer;
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
    padding: 0rem !important;
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
    .attendance-table-wrap .dataTables_length,
    .attendance-table-wrap .dataTables_filter {
        text-align: left;
        float: none;
        width: 100%;
    }
}

/* ── Mobile card layout ──────────────────────────────────────────────────────
   Below 768px each table row becomes a compact card so nothing needs
   horizontal scrolling. The table markup is deliberately left untouched —
   DataTables still owns search/sort/pagination, and it keeps working because
   the same <tr>/<td> elements are simply re-styled: the row becomes a flex
   container and the cells flow inline, so date/day/class/slot/status share a
   wrapped line rather than each claiming its own. Values are shown without
   field labels; `order` puts them in reading order regardless of column
   position.                                                              */
@media (max-width: 767.98px) {
    /* The shell's horizontal gutters are trimmed globally in design-system.css
       so cards get nearly the full screen width. */
    .attendance-table-wrap {
        overflow-x: visible;
        padding: 0.5rem 0.15rem;
        background: #f1f5f9;
    }
    /* The card body is flush, so the DataTables length/search controls need
       their own small gutter to avoid sitting against the screen edge. */
    .attendance-table-wrap .dataTables_length,
    .attendance-table-wrap .dataTables_filter,
    .attendance-table-wrap .dataTables_info,
    .attendance-table-wrap .dataTables_paginate {
        padding-left: 0.4rem;
        padding-right: 0.4rem;
    }
    .attendance-data-table,
    .attendance-data-table tbody {
        display: block;
        width: auto;
    }
    /* Header row is meaningless once cells stack — values are self-explanatory
       in the card. Hidden with display:none rather than a clip-based
       visually-hidden helper, because the <th> cells keep their desktop widths
       in layout flow and would push the page wider than the viewport. Sorting
       stays reachable through the DataTables controls above the list. */
    .attendance-data-table thead {
        display: none;
    }
    .attendance-data-table {
        min-width: 0;
        background: transparent;
    }
    /* Each card is a flex row so the small meta cells (date, day, class, slot,
       status) sit side by side and wrap, instead of each taking its own line. */
    .attendance-data-table tbody tr {
        position: relative;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.3rem 0.5rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #cbd5e1;
        border-radius: 0.6rem;
        margin-bottom: 0.5rem;
        padding: 0.55rem 0.65rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    }
    .attendance-data-table tbody tr:last-child {
        margin-bottom: 0;
    }
    /* Status is conveyed by the left edge colour of each card. */
    .attendance-data-table tbody tr.row-pending { border-left-color: #f59e0b; }
    .attendance-data-table tbody tr.row-today   { border-left-color: #3949ab; }
    .attendance-data-table tbody tr.row-skipped { border-left-color: #94a3b8; opacity: 0.85; }

    /* Bootstrap styles cells via `.table > :not(caption) > * > *`, which beats a
       plain `td` selector — hence the !important on padding/border here. */
    .attendance-data-table tbody tr td {
        display: block;
        width: auto;
        border: 0 !important;
        padding: 0 !important;
        text-align: left !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    /* Row number sits as a faint badge in the top-right corner. */
    .attendance-data-table tbody tr td.col-num {
        position: absolute;
        top: 0.4rem;
        right: 0.55rem;
        width: auto;
    }
    .attendance-data-table tbody tr td.col-num .row-num {
        font-size: 0.65rem;
        color: #cbd5e1;
        font-weight: 700;
    }

    /* Line 1: type pill + status pill (status pushed right, clear of the
       row-number badge). Line 2: subject. Line 3: the remaining meta values. */
    .attendance-data-table tbody tr td.col-type {
        order: 1;
        flex: 0 0 auto;
    }
    .attendance-data-table tbody tr td.col-subject {
        order: 3;
        flex: 1 1 100%;
        padding-right: 1.5rem !important;
    }
    .attendance-data-table tbody tr td.col-subject .subject-cell {
        flex-direction: row;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 0.35rem;
    }
    .attendance-data-table tbody tr td.col-subject .subject-name {
        font-size: 0.92rem;
        font-weight: 700;
        line-height: 1.25;
        white-space: normal;
    }
    .attendance-data-table tbody tr td.col-subject .subject-sem {
        font-size: 0.7rem;
    }

    /* Meta cells share one wrapped line, separated by thin dividers. */
    .attendance-data-table tbody tr td.col-date { order: 4; }
    .attendance-data-table tbody tr td.col-day  { order: 5; }
    .attendance-data-table tbody tr td.col-class { order: 6; }
    .attendance-data-table tbody tr td.col-slot { order: 7; }
    /* Status rides the top line beside the type pill, pushed right but stopping
       short of the row-number badge in the corner. */
    .attendance-data-table tbody tr td.col-status {
        order: 2;
        margin-left: auto;
        margin-right: 1.4rem;
    }
    .attendance-data-table tbody tr td.col-status .status-pill {
        min-width: 0;
        font-size: 0.62rem;
        padding: 0.22rem 0.45rem;
    }

    .attendance-data-table tbody tr td.col-date .date-cell {
        justify-content: flex-start;
        gap: 0.3rem;
    }
    /* Compact the calendar chip into a single inline date. */
    .attendance-data-table tbody tr td.col-date .date-cell-day {
        width: auto;
        height: auto;
        background: transparent;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 700;
        box-shadow: none;
        border-radius: 0;
        padding: 0;
    }
    .attendance-data-table tbody tr td.col-date .date-cell-month {
        flex-direction: row;
        gap: 0.2rem;
        font-size: 0.75rem;
        color: #64748b;
        text-transform: none;
    }
    .attendance-data-table tbody tr td.col-date .date-cell-month small {
        font-size: 0.75rem;
    }

    /* Action buttons close the card on their own line. */
    .attendance-data-table tbody tr td.col-action {
        order: 8;
        flex: 1 1 100%;
        margin-top: 0.15rem;
        padding-top: 0.45rem !important;
        border-top: 1px solid #f1f5f9 !important;
    }
    .attendance-data-table tbody tr td.col-action .action-buttons {
        justify-content: flex-start;
        gap: 0.4rem;
    }
    .attendance-data-table tbody tr td.col-action .action-btn {
        width: auto !important;
        min-width: 44px;
        height: 34px !important;
        min-height: 34px !important;
        flex: 1 1 0;
        max-width: 130px;
    }
    .attendance-data-table tbody tr td.col-action form.d-inline-flex {
        flex: 1 1 0;
        max-width: 140px;
    }
    .attendance-data-table tbody tr td.col-action form.d-inline-flex .action-btn {
        width: 100% !important;
        max-width: none;
    }

    /* Week-view day dividers span the full width as section headings. */
    .attendance-data-table tbody tr.week-day-divider {
        display: block;
        background: transparent;
        border: 0;
        box-shadow: none;
        padding: 0.4rem 0 0.2rem;
        margin-bottom: 0.2rem;
    }
    .attendance-data-table tbody tr.week-day-divider td {
        padding: 0 !important;
        border: 0 !important;
    }

    /* Keep the tap-anywhere affordance obvious on touch devices. */
    .attendance-data-table tbody tr.row-clickable {
        cursor: pointer;
    }
    .attendance-data-table tbody tr.row-clickable:active {
        background: #f8fafc;
    }
}
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableElement = document.getElementById('attendanceDataTable');
    if (!tableElement) {
        return;
    }

    // Clicking anywhere on a pending row opens its Take Attendance page,
    // except when the click lands on an action button/link inside the row.
    tableElement.addEventListener('click', function (e) {
        if (e.target.closest('a, button, form, input')) return;
        const row = e.target.closest('tr[data-take-url]');
        if (row) {
            window.location.href = row.dataset.takeUrl;
        }
    });

    // Week view renders chronological rows grouped by day-divider rows;
    // DataTables would re-sort and paginate them, breaking the grouping.
    const isWeekView = <?= $filter_status === 'week' ? 'true' : 'false' ?>;
    if (isWeekView) {
        return;
    }

    if (typeof window.jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') {
        return;
    }

    const dataTable = jQuery(tableElement).DataTable({
        pageLength: 50,
        order: [[2, 'desc']],
        autoWidth: true,
        dom: '<"row g-2 mb-2"<"col-sm-6 col-md-6 d-flex align-items-center"l><"col-sm-6 col-md-6 d-flex justify-content-end align-items-center"f>>t<"row mt-2"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6 d-flex justify-content-end"p>>',
        columnDefs: [
            { targets: 0, orderable: false, searchable: false, className: 'text-center' },
            { targets: 1, className: 'text-start', orderable: false },
            { targets: 2, className: 'text-center' },
            { targets: 3, className: 'text-start', orderable: false },
            { targets: 4, className: 'text-center' },
            { targets: 5, className: 'text-center' },
            { targets: 6, className: 'text-center' },
            { targets: 7, orderable: false, searchable: false, className: 'text-center' },
            { targets: 8, orderable: false, searchable: false, className: 'text-center' }
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

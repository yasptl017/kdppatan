<?php
include('dbconfig.php');

function short_name($full_name) {
    $full_name = trim((string)$full_name);
    if ($full_name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $full_name);
    if (count($parts) >= 2) {
        return $parts[0] . ' ' . $parts[1];
    }
    return $full_name;
}

function lab_column_exists(mysqli $conn, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'labattendance' AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function ensure_lab_attendance_columns(mysqli $conn): void {
    if (!lab_column_exists($conn, 'description')) {
        $conn->query("ALTER TABLE labattendance ADD COLUMN description VARCHAR(255) NULL AFTER totalPcUsed");
    }
}

ensure_lab_attendance_columns($conn);

function normalize_batch_values($batchInput) {
    if (is_array($batchInput)) {
        $rawBatches = $batchInput;
    } elseif (!empty($batchInput)) {
        $rawBatches = explode(',', (string)$batchInput);
    } else {
        $rawBatches = [];
    }

    $normalized = [];
    foreach ($rawBatches as $batch) {
        $batch = trim((string)$batch);
        if ($batch !== '' && !in_array($batch, $normalized, true)) {
            $normalized[] = $batch;
        }
    }

    return $normalized;
}

function normalize_batch_lab_map($batchLabInput, array $selectedBatches, $fallbackLab = '') {
    $map = [];
    if (is_array($batchLabInput)) {
        foreach ($batchLabInput as $batch => $labNo) {
            $batch = trim((string)$batch);
            $labNo = trim((string)$labNo);
            if ($batch !== '' && $labNo !== '' && in_array($batch, $selectedBatches, true)) {
                $map[$batch] = $labNo;
            }
        }
    }

    // Backward compatibility for old single-batch form submissions.
    if (empty($map) && count($selectedBatches) === 1 && !empty($fallbackLab)) {
        $map[$selectedBatches[0]] = trim((string)$fallbackLab);
    }

    return $map;
}

function render_hidden_inputs($name, $value) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            render_hidden_inputs($name . '[' . $key . ']', $item);
        }
        return;
    }

    echo '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES) . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES) . '">' . PHP_EOL;
}

$data = $_POST ?: $_GET;
$selected_batches = normalize_batch_values($data['batch'] ?? []);
$batch_lab_map = normalize_batch_lab_map($data['batch_lab_map'] ?? [], $selected_batches, $data['lab'] ?? '');

// Fallback: when arrived via a direct link (e.g. myAttendance.php) that didn't pass
// batch_lab_map, resolve lab numbers from the labmapping table using
// faculty/term/sem/subject/batch/date/slot.
if (empty($batch_lab_map) && !empty($selected_batches)) {
    $lookup_faculty = trim((string)($data['faculty'] ?? ''));
    $lookup_term    = trim((string)($data['term'] ?? ''));
    $lookup_sem     = trim((string)($data['sem'] ?? ''));
    $lookup_subject = trim((string)($data['subject'] ?? ''));
    $lookup_date    = trim((string)($data['date'] ?? ''));
    $lookup_slot    = trim((string)($data['slot'] ?? ''));

    if ($lookup_faculty !== '' && $lookup_term !== '' && $lookup_sem !== '' && $lookup_subject !== '' && $lookup_date !== '' && $lookup_slot !== '') {
        $dow = (int)date('w', strtotime($lookup_date));
        $dow_str = (string)$dow;

        $escaped_faculty = $conn->real_escape_string($lookup_faculty);
        $escaped_term    = $conn->real_escape_string($lookup_term);
        $escaped_sem     = $conn->real_escape_string($lookup_sem);
        $escaped_subject = $conn->real_escape_string($lookup_subject);
        $escaped_slot    = $conn->real_escape_string($lookup_slot);

        $map_sql = "SELECT id, batch, labNo, slot FROM labmapping
                    WHERE faculty = '{$escaped_faculty}'
                      AND term = '{$escaped_term}'
                      AND sem = '{$escaped_sem}'
                      AND subject = '{$escaped_subject}'
                      AND start_date <= '{$lookup_date}'
                      AND end_date   >= '{$lookup_date}'
                      AND FIND_IN_SET('{$dow_str}', repeat_days) > 0";
        $map_res = $conn->query($map_sql);
        if ($map_res) {
            while ($mr = $map_res->fetch_assoc()) {
                $parsed_batches_m = [];
                $parsed_labs_m    = [];
                $parsed_slots_m   = [];
                $raw_batch = (string)($mr['batch'] ?? '');
                $raw_lab   = (string)($mr['labNo'] ?? '');
                $raw_slot  = (string)($mr['slot'] ?? '');

                if ($raw_batch !== '' && $raw_batch[0] === '{') $parsed_batches_m = json_decode($raw_batch, true) ?: [];
                if ($raw_lab   !== '' && $raw_lab[0]   === '{') $parsed_labs_m    = json_decode($raw_lab,   true) ?: [];
                if ($raw_slot  !== '' && $raw_slot[0]  === '{') $parsed_slots_m   = json_decode($raw_slot,  true) ?: [];

                $batches_day = [];
                $labs_day    = [];
                $slot_day    = '';
                if (!empty($parsed_batches_m)) {
                    $batches_day = (array)($parsed_batches_m[$dow] ?? $parsed_batches_m[$dow_str] ?? []);
                    $labs_day    = (array)($parsed_labs_m[$dow]    ?? $parsed_labs_m[$dow_str]    ?? []);
                    $slot_day    = (string)($parsed_slots_m[$dow]   ?? $parsed_slots_m[$dow_str]   ?? '');
                } else {
                    // Legacy single-batch format
                    $batches_day = [$raw_batch];
                    $labs_day    = [$raw_lab];
                    $slot_day    = $raw_slot;
                }

                if ($slot_day !== '' && strcasecmp($slot_day, $lookup_slot) !== 0) {
                    continue;
                }

                foreach ($batches_day as $bi => $bval) {
                    $blabel = strtoupper(trim((string)$bval));
                    $llabel = trim((string)($labs_day[$bi] ?? ''));
                    if ($blabel !== '' && $llabel !== '' && in_array($blabel, array_map('strtoupper', $selected_batches), true)) {
                        $batch_lab_map[$blabel] = $llabel;
                    }
                }
            }
        }
    }
}

$data['batch'] = $selected_batches;
$data['batch_lab_map'] = $batch_lab_map;
unset($data['lab']);

$batch_csv = implode(',', $selected_batches);
$batch_lab_pairs = [];
foreach ($selected_batches as $batch_name) {
    if (!empty($batch_lab_map[$batch_name])) {
        $batch_lab_pairs[] = $batch_name . ':' . $batch_lab_map[$batch_name];
    }
}
$batch_lab_csv = implode(', ', $batch_lab_pairs);
$selected_labs = array_values(array_unique(array_values($batch_lab_map)));
$selected_batches_normalized = array_values(array_unique(array_map(function ($batch_name) {
    return strtoupper(trim((string)$batch_name));
}, $selected_batches)));
$batch_lab_map_normalized = [];
foreach ($batch_lab_map as $batch_name => $lab_name) {
    $normalized_batch = strtoupper(trim((string)$batch_name));
    if ($normalized_batch !== '') {
        $batch_lab_map_normalized[$normalized_batch] = $lab_name;
    }
}

// Handle form submission
$attendance_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $date = $_POST['date'];
    $time = $_POST['slot'];
    $term = $_POST['term'];
    $faculty = $_POST['faculty'];
    $sem = $_POST['sem'];
    $subject = $_POST['subject'];
    $mark_mode = trim((string)($_POST['mark_mode'] ?? 'normal'));
    $description = trim((string)($_POST['description'] ?? ''));
    $batch = $batch_csv;
    $labNo = $batch_lab_csv;

    // Apply mark_mode to present tokens
    if ($mark_mode === 'all_absent') {
        $present_tokens = [];
    } else {
        $present_tokens = isset($_POST['present']) ? array_map('trim', (array)$_POST['present']) : [];
        $present_tokens = array_values(array_unique(array_filter($present_tokens, function ($value) {
            return $value !== '';
        })));
    }

    $totalPcUsedInput = $_POST['totalPcUsedByLab'] ?? [];
    $totalPcUsedMap = [];

    if (is_array($totalPcUsedInput)) {
        foreach ($totalPcUsedInput as $lab_name => $pc_used) {
            $lab_name = trim((string)$lab_name);
            if ($lab_name === '' || !in_array($lab_name, $selected_labs, true)) {
                continue;
            }
            $pc_used = max(0, (int)$pc_used);
            $totalPcUsedMap[$lab_name] = $pc_used;
        }
    }

    // Backward compatibility: single "totalPcUsed" textbox (covers all selected labs).
    if (empty($totalPcUsedMap) && isset($_POST['totalPcUsed']) && $_POST['totalPcUsed'] !== '') {
        $single_pc = max(0, (int)$_POST['totalPcUsed']);
        foreach ($selected_labs as $lab_name) {
            $totalPcUsedMap[$lab_name] = $single_pc;
        }
    }

    if (empty($selected_batches)) {
        $attendance_error = 'Please select at least one batch.';
    } elseif (count($batch_lab_map) !== count($selected_batches)) {
        $missing = array_values(array_diff(array_map('strtoupper', $selected_batches), array_keys($batch_lab_map)));
        $attendance_error = 'Lab number is missing for batch(es): ' . implode(', ', $missing) . '. Please pick a lab for each batch.';
    } elseif (empty($selected_labs) || count($totalPcUsedMap) !== count($selected_labs)) {
        $attendance_error = 'Please enter total PC used for every selected lab.';
    } else {
        // Load batch enrollments so we can verify present tokens
        $batch_enrollments = [];
        $escaped_term_check = $conn->real_escape_string($term);
        $escaped_sem_check = $conn->real_escape_string($sem);
        $escaped_batches_check = array_map(function ($batch_name) use ($conn) {
            return "'" . $conn->real_escape_string($batch_name) . "'";
        }, $selected_batches_normalized);
        $students_check = $conn->query("SELECT enrollmentNo FROM students WHERE term = '{$escaped_term_check}' AND sem = '{$escaped_sem_check}' AND UPPER(TRIM(labBatch)) IN (" . implode(',', $escaped_batches_check) . ") AND enrollmentNo IS NOT NULL AND TRIM(enrollmentNo) <> ''");
        if ($students_check) {
            while ($sr = $students_check->fetch_assoc()) {
                $enrollment = trim((string)($sr['enrollmentNo'] ?? ''));
                if ($enrollment !== '') {
                    $batch_enrollments[] = $enrollment;
                }
            }
        }
        $batch_enrollment_set = array_flip($batch_enrollments);

        $present_filtered = [];
        foreach ($present_tokens as $enrollment_no) {
            if (isset($batch_enrollment_set[$enrollment_no])) {
                $present_filtered[] = $enrollment_no;
            }
        }

        $present_csv = implode(',', $present_filtered);

        $total_pc_pairs = [];
        foreach ($selected_labs as $lab_name) {
            $total_pc_pairs[] = $lab_name . ':' . $totalPcUsedMap[$lab_name];
        }
        $totalPcUsed = implode(', ', $total_pc_pairs);

        $description_or_null = ($description !== '') ? $description : null;

        $stmt = $conn->prepare("INSERT INTO labattendance (date, logdate, time, term, faculty, sem, subject, batch, labNo, presentNo, totalPcUsed, description) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssss", $date, $time, $term, $faculty, $sem, $subject, $batch, $labNo, $present_csv, $totalPcUsed, $description_or_null);
        $stmt->execute();
        $attendance_id = (int)$conn->insert_id;
        $stmt->close();
        header("Location: attendanceSummary.php?type=lab&id=" . $attendance_id);
        exit();
    }
}

$faculty_id = $data['faculty'];
$faculty_query = "SELECT Name FROM faculty WHERE id = '$faculty_id'";
$faculty_result = $conn->query($faculty_query);
$faculty_name = ($faculty_result->num_rows > 0) ? $faculty_result->fetch_assoc()['Name'] : $faculty_id;

$students_result = false;
if (!empty($selected_batches_normalized)) {
    $escaped_term = $conn->real_escape_string($data['term']);
    $escaped_sem = $conn->real_escape_string($data['sem']);
    $escaped_batches = array_map(function ($batch_name) use ($conn) {
        return "'" . $conn->real_escape_string($batch_name) . "'";
    }, $selected_batches_normalized);

    $students_query = "SELECT id, enrollmentNo, name, TRIM(labBatch) AS labBatch FROM students WHERE term = '{$escaped_term}' AND sem = '{$escaped_sem}' AND UPPER(TRIM(labBatch)) IN (" . implode(',', $escaped_batches) . ") ORDER BY UPPER(TRIM(labBatch)), enrollmentNo, name";
    $students_result = $conn->query($students_query);
}

if (!$students_result) {
    $students_result = $conn->query("SELECT id, enrollmentNo, name, labBatch FROM students WHERE 1 = 0");
}

$total_students = $students_result ? $students_result->num_rows : 0;

$missing_batches = [];
if (!empty($selected_batches_normalized)) {
    $escaped_term = $conn->real_escape_string($data['term']);
    $escaped_sem = $conn->real_escape_string($data['sem']);
    $escaped_batches = array_map(function ($batch_name) use ($conn) {
        return "'" . $conn->real_escape_string($batch_name) . "'";
    }, $selected_batches_normalized);

    $batch_count_query = "SELECT UPPER(TRIM(labBatch)) AS batch_name, COUNT(*) AS total FROM students WHERE term = '{$escaped_term}' AND sem = '{$escaped_sem}' AND UPPER(TRIM(labBatch)) IN (" . implode(',', $escaped_batches) . ") GROUP BY UPPER(TRIM(labBatch))";
    $batch_count_result = $conn->query($batch_count_query);
    $batch_counts = [];
    if ($batch_count_result) {
        while ($batch_count_row = $batch_count_result->fetch_assoc()) {
            $batch_counts[$batch_count_row['batch_name']] = (int)$batch_count_row['total'];
        }
    }

    foreach ($selected_batches_normalized as $batch_name) {
        if (!isset($batch_counts[$batch_name]) || $batch_counts[$batch_name] === 0) {
            $missing_batches[] = $batch_name;
        }
    }
}

// ── Autofill: nearest attendance records (same day → before → after) ───────────
// Collect enrollment numbers of students in the selected batches
$batch_enrollments = [];
if ($students_result && $total_students > 0) {
    $students_result->data_seek(0);
    while ($s = $students_result->fetch_assoc()) {
        if (!empty($s['enrollmentNo'])) $batch_enrollments[] = $s['enrollmentNo'];
    }
    $students_result->data_seek(0);
}

$autofill_records = [];
if (!empty($batch_enrollments)) {
    $att_date = $data['date'];
    $att_date_esc = $conn->real_escape_string($att_date);

    // Track found record dates to show in label
    $found_dates = [];

    // 1) SAME DAY records first
    // Lecture records today
    $lec_res = $conn->query("SELECT id, subject, class, time, presentNo, date FROM lecattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$att_date_esc}' ORDER BY id DESC");
    if ($lec_res) {
        while ($row = $lec_res->fetch_assoc()) {
            $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
            $filt = array_values(array_intersect($all, $batch_enrollments));
            if (!empty($filt)) {
                $autofill_records[] = ['type' => 'Lecture', 'label' => 'Lecture  -  ' . $row['subject'] . '  -  Class ' . $row['class'] . '  -  ' . $row['time'], 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => 0];
            }
        }
    }

    // Other lab records today
    $lab_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM labattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$att_date_esc}' AND labNo IS NOT NULL AND labNo!='' ORDER BY id DESC");
    if ($lab_res) {
        while ($row = $lab_res->fetch_assoc()) {
            $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
            $filt = array_values(array_intersect($all, $batch_enrollments));
            if (!empty($filt)) {
                $autofill_records[] = ['type' => 'Lab', 'label' => 'Lab  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'], 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => 0];
            }
        }
    }

    // Tutorial records today
    $tut_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM tutattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$att_date_esc}' ORDER BY id DESC");
    if ($tut_res) {
        while ($row = $tut_res->fetch_assoc()) {
            $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
            $filt = array_values(array_intersect($all, $batch_enrollments));
            if (!empty($filt)) {
                $autofill_records[] = ['type' => 'Tutorial', 'label' => 'Tutorial  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'], 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => 0];
            }
        }
    }

    // 2) PREVIOUS DAYS (going backwards, up to 30 days)
    $prev_found = false;
    for ($i = 1; $i <= 30; $i++) {
        $prev_date = date('Y-m-d', strtotime($att_date . " -{$i} days"));
        $prev_date_esc = $conn->real_escape_string($prev_date);

        // Lecture
        $lec_res = $conn->query("SELECT id, subject, class, time, presentNo, date FROM lecattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$prev_date_esc}' ORDER BY id DESC");
        if ($lec_res && $lec_res->num_rows > 0) {
            $prev_found = true;
            while ($row = $lec_res->fetch_assoc()) {
                $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                $filt = array_values(array_intersect($all, $batch_enrollments));
                if (!empty($filt)) {
                    $autofill_records[] = ['type' => 'Lecture', 'label' => 'Lecture  -  ' . $row['subject'] . '  -  Class ' . $row['class'] . '  -  ' . $row['time'] . ' (' . $prev_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => -$i];
                }
            }
        }

        // Lab
        $lab_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM labattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$prev_date_esc}' AND labNo IS NOT NULL AND labNo!='' ORDER BY id DESC");
        if ($lab_res && $lab_res->num_rows > 0) {
            $prev_found = true;
            while ($row = $lab_res->fetch_assoc()) {
                $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                $filt = array_values(array_intersect($all, $batch_enrollments));
                if (!empty($filt)) {
                    $autofill_records[] = ['type' => 'Lab', 'label' => 'Lab  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'] . ' (' . $prev_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => -$i];
                }
            }
        }

        // Tutorial
        $tut_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM tutattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$prev_date_esc}' ORDER BY id DESC");
        if ($tut_res && $tut_res->num_rows > 0) {
            $prev_found = true;
            while ($row = $tut_res->fetch_assoc()) {
                $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                $filt = array_values(array_intersect($all, $batch_enrollments));
                if (!empty($filt)) {
                    $autofill_records[] = ['type' => 'Tutorial', 'label' => 'Tutorial  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'] . ' (' . $prev_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => -$i];
                }
            }
        }

        // Stop if we found records on this day
        if ($prev_found) break;
    }

    // 3) NEXT DAYS (only if no previous records found, going forwards, up to 30 days)
    if (!$prev_found) {
        for ($i = 1; $i <= 30; $i++) {
            $next_date = date('Y-m-d', strtotime($att_date . " +{$i} days"));
            $next_date_esc = $conn->real_escape_string($next_date);

            $found_in_this_day = false;

            // Lecture
            $lec_res = $conn->query("SELECT id, subject, class, time, presentNo, date FROM lecattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$next_date_esc}' ORDER BY id DESC");
            if ($lec_res && $lec_res->num_rows > 0) {
                $found_in_this_day = true;
                while ($row = $lec_res->fetch_assoc()) {
                    $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                    $filt = array_values(array_intersect($all, $batch_enrollments));
                    if (!empty($filt)) {
                        $autofill_records[] = ['type' => 'Lecture', 'label' => 'Lecture  -  ' . $row['subject'] . '  -  Class ' . $row['class'] . '  -  ' . $row['time'] . ' (' . $next_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => $i];
                    }
                }
            }

            // Lab
            $lab_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM labattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$next_date_esc}' AND labNo IS NOT NULL AND labNo!='' ORDER BY id DESC");
            if ($lab_res && $lab_res->num_rows > 0) {
                $found_in_this_day = true;
                while ($row = $lab_res->fetch_assoc()) {
                    $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                    $filt = array_values(array_intersect($all, $batch_enrollments));
                    if (!empty($filt)) {
                        $autofill_records[] = ['type' => 'Lab', 'label' => 'Lab  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'] . ' (' . $next_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => $i];
                    }
                }
            }

            // Tutorial
            $tut_res = $conn->query("SELECT id, subject, batch, presentNo, date FROM tutattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$next_date_esc}' ORDER BY id DESC");
            if ($tut_res && $tut_res->num_rows > 0) {
                $found_in_this_day = true;
                while ($row = $tut_res->fetch_assoc()) {
                    $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
                    $filt = array_values(array_intersect($all, $batch_enrollments));
                    if (!empty($filt)) {
                        $autofill_records[] = ['type' => 'Tutorial', 'label' => 'Tutorial  -  ' . $row['subject'] . '  -  Batch ' . $row['batch'] . ' (' . $next_date . ')', 'present' => $filt, 'sort_date' => $row['date'], 'date_order' => $i];
                    }
                }
            }

            // Stop if we found records on this day
            if ($found_in_this_day) break;
        }
    }

    // Sort: same day first, then by date_order (nearest first)
    usort($autofill_records, function($a, $b) {
        if ($a['date_order'] != $b['date_order']) {
            return abs($a['date_order']) - abs($b['date_order']);
        }
        return 0;
    });
}
// ─────────────────────────────────────────────────────────────────────────────
?>

<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-check2-square me-2"></i>Take Lab Attendance</h1>

            <?php if ($attendance_error !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($attendance_error); ?></div>
            <?php endif; ?>
            <?php if (!empty($missing_batches)): ?>
                <div class="alert alert-warning">
                    No students found for selected batch(es): <?= htmlspecialchars(implode(', ', $missing_batches)); ?>.
                </div>
            <?php endif; ?>

            <!-- Lab Details Card -->
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <h4>Lab Details</h4>
                    <div class="row g-2" style="font-size:0.9rem;">
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Faculty</span>
                            <strong><?= htmlspecialchars($faculty_name) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Term</span>
                            <strong><?= htmlspecialchars($data['term']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Semester</span>
                            <strong><?= htmlspecialchars($data['sem']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Subject</span>
                            <strong><?= htmlspecialchars($data['subject']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Batches &amp; Lab</span>
                            <strong><?= htmlspecialchars(!empty($selected_batches) ? implode(', ', $selected_batches) . '  -  ' . $batch_lab_csv : '-') ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Date &amp; Slot</span>
                            <strong><?= htmlspecialchars($data['date']) ?> &bull; <?= htmlspecialchars($data['slot']) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Autofill Panel -->
            <?php if (!empty($autofill_records)): ?>
            <div class="app-card shadow-sm mb-3" style="border: 2px solid #ffc107; border-left: 5px solid #ffc107;">
                <div class="app-card-body">
                    <h5 class="mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Today's Attendance - Click to Autofill</h5>
                    <p class="text-muted mb-2" style="font-size:0.82rem;">Only students in batch(es) <strong><?= htmlspecialchars(implode(', ', $selected_batches)) ?></strong> will be marked.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $badge_colors = ['Lecture' => 'primary', 'Lab' => 'danger', 'Tutorial' => 'success'];
                        foreach ($autofill_records as $rec):
                            $color = $badge_colors[$rec['type']] ?? 'secondary';
                        ?>
                        <button type="button"
                                class="btn btn-outline-<?= $color ?> btn-sm autofill-btn"
                                style="border-width: 2px; font-weight: 500;"
                                data-present="<?= htmlspecialchars(json_encode($rec['present'])) ?>"
                                title="<?= htmlspecialchars($rec['label']) ?> (<?= count($rec['present']) ?> students from these batches)">

                            <?= htmlspecialchars($rec['label']) ?>
                            <span class="badge bg-<?= $color ?> ms-1"><?= count($rec['present']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance Form -->
            <form method="POST" action="takelabatt.php" id="labAttendanceForm">
                <?php foreach ($data as $key => $value): ?>
                    <?php render_hidden_inputs($key, $value); ?>
                <?php endforeach; ?>
                <input type="hidden" name="mark_mode" id="markModeField" value="normal">
                <input type="hidden" name="description" id="attendanceDescriptionField" value="">

                <div class="app-card shadow-sm">
                    <div class="app-card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <h4 class="mb-0">Mark Attendance
                                <span class="text-muted fw-normal" style="font-size:0.875rem;">(<?= $total_students ?> students)</span>
                            </h4>
                            <div class="attendance-actions mb-0">
                                <button type="button" class="btn btn-sm btn-outline-success" id="markAllBtn">
                                    <i class="bi bi-check-all me-1"></i>Mark All Present
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">
                                    <i class="bi bi-x-lg me-1"></i>Clear All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" id="allAbsentBtn">
                                    <i class="bi bi-x-octagon me-1"></i>All Absent
                                </button>
                            </div>
                        </div>

                        <?php if ($total_students > 0): ?>
                            <div class="row g-2" id="student-cards">
                                <?php
                                $students_result->data_seek(0);
                                while ($student = $students_result->fetch_assoc()):
                                    $student_roll = !empty($student['enrollmentNo']) ? $student['enrollmentNo'] : $student['id'];
                                    $student_batch = strtoupper(trim((string)$student['labBatch']));
                                    $student_lab = $batch_lab_map_normalized[$student_batch] ?? '';
                                    $display_name = short_name($student['name']);
                                ?>
                                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                    <label class="card shadow-sm p-2 text-center student-card" style="cursor:pointer;">
                                        <input type="checkbox" name="present[]" value="<?= htmlspecialchars((string)$student_roll); ?>" class="d-none attendance-checkbox" data-lab="<?= htmlspecialchars($student_lab); ?>">
                                        <div class="student-info">
                                            <strong><?= htmlspecialchars((string)$student_roll); ?></strong>
                                            <span class="d-block text-truncate" title="<?= htmlspecialchars($display_name); ?>">
                                                <?= htmlspecialchars($display_name); ?>
                                            </span>
                                            <span class="d-block text-muted">
                                                Batch <?= htmlspecialchars($student_batch); ?>
                                                <?php if ($student_lab !== ''): ?>
                                                    &middot; Lab <?= htmlspecialchars($student_lab); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </label>
                                </div>
                                <?php endwhile; ?>
                            </div>

                            <div class="pc-used-section mt-4 p-3" id="pc-used-container">
                                <div class="row g-3 align-items-end">
                                    <div class="col-12 col-md-6">
                                        <label for="totalPcUsedInput" class="form-label fw-semibold">
                                            <i class="bi bi-pc-display text-primary me-1"></i>
                                            Total PC Used
                                            <span class="text-muted fw-normal" style="font-size:0.78rem;">
                                                 -  half of present: <span id="pcDefaultValue" class="text-primary fw-bold">0</span>
                                            </span>
                                        </label>
                                        <input type="number"
                                               id="totalPcUsedInput"
                                               name="totalPcUsed"
                                               class="form-control form-control-lg pc-used-input"
                                               value="0"
                                               min="0"
                                               style="max-width:200px;font-weight:600;">
                                        <small class="text-muted">Auto-set to half of marked present students. You can edit manually.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                                <button type="submit" name="submit_attendance" class="btn btn-success px-4">
                                    Submit Attendance
                                </button>
                                <span class="text-muted" id="present-count" style="font-size:0.875rem;">0 marked present</span>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>No students found for the selected criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <style>
    .pc-used-section {
        background: linear-gradient(135deg, #f0f4ff 0%, #ffffff 100%);
        border: 1px solid #c7d2fe;
        border-radius: 0.75rem;
        box-shadow: 0 2px 6px rgba(102, 126, 234, 0.06);
    }
    .pc-used-section h5 {
        color: #1e293b;
        font-weight: 700;
        font-size: 1.05rem;
    }
    .pc-default-hint {
        font-style: italic;
    }
    .pc-used-input:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
    }
    .pc-used-input.is-user-edited {
        background: #ecfdf5;
        border-color: #10b981;
    }
    .pc-default-value {
        font-size: 1rem;
        padding: 0 0.25rem;
    }
</style>
            <div class="modal fade" id="allAbsentModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Mark All Absent</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">Optional description (example: Lab cancelled due to power outage):</p>
                            <textarea class="form-control" id="allAbsentDescription" rows="3" maxlength="255" placeholder="Description (optional)"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmAllAbsentBtn">Save All Absent</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    const attendanceForm = document.getElementById('labAttendanceForm');
    const submitAttendanceBtn = attendanceForm?.querySelector('button[name="submit_attendance"]');
    const cards = document.querySelectorAll('.student-card');
    const attendanceCheckboxes = document.querySelectorAll('.attendance-checkbox');
    const pcUsedInputs = document.querySelectorAll('.pc-used-input');
    const countEl = document.getElementById('present-count');
    const markModeField = document.getElementById('markModeField');
    const descriptionField = document.getElementById('attendanceDescriptionField');
    const allAbsentBtn = document.getElementById('allAbsentBtn');
    const allAbsentDescription = document.getElementById('allAbsentDescription');
    const confirmAllAbsentBtn = document.getElementById('confirmAllAbsentBtn');
    const allAbsentModalEl = document.getElementById('allAbsentModal');
    const allAbsentModal = (window.bootstrap && allAbsentModalEl) ? bootstrap.Modal.getOrCreateInstance(allAbsentModalEl) : null;

    function setNormalMode() {
        if (markModeField) markModeField.value = 'normal';
        if (descriptionField) descriptionField.value = '';
    }

    function submitAttendanceForm() {
        if (!attendanceForm || !submitAttendanceBtn) return;
        if (typeof attendanceForm.requestSubmit === 'function') {
            attendanceForm.requestSubmit(submitAttendanceBtn);
        } else {
            submitAttendanceBtn.click();
        }
    }

    function updateCount() {
        const checked = document.querySelectorAll('.attendance-checkbox:checked').length;
        if (countEl) countEl.textContent = checked + ' marked present';
    }

    function updatePcDefaultsByLab() {
        const presentCount = document.querySelectorAll('.attendance-checkbox:checked').length;
        const suggested = Math.ceil(presentCount / 2);

        pcUsedInputs.forEach(function (input) {
            if (!input.dataset.userEdited || input.dataset.userEdited === '0') {
                input.value = suggested;
                input.classList.remove('is-user-edited');
            }
        });

        const hint = document.getElementById('pcDefaultValue');
        if (hint) hint.textContent = suggested;
    }

    // Track which fields the user has manually edited
    pcUsedInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            this.dataset.userEdited = '1';
            this.classList.add('is-user-edited');
        });
        input.dataset.userEdited = '0';
    });

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            const checkbox = card.querySelector('.attendance-checkbox');
            checkbox.checked = !checkbox.checked;
            card.classList.toggle('bg-success-subtle', checkbox.checked);
            card.classList.toggle('border-success', checkbox.checked);
            setNormalMode();
            updateCount();
            updatePcDefaultsByLab();
        });
    });

    document.getElementById('markAllBtn')?.addEventListener('click', function () {
        cards.forEach(card => {
            const cb = card.querySelector('.attendance-checkbox');
            cb.checked = true;
            card.classList.add('bg-success-subtle', 'border-success');
        });
        setNormalMode();
        updateCount();
        updatePcDefaultsByLab();
    });

    document.getElementById('clearAllBtn')?.addEventListener('click', function () {
        cards.forEach(card => {
            const cb = card.querySelector('.attendance-checkbox');
            cb.checked = false;
            card.classList.remove('bg-success-subtle', 'border-success');
        });
        // Reset all PC user-edited flags so auto-fill works again
        pcUsedInputs.forEach(function (input) { input.dataset.userEdited = '0'; });
        setNormalMode();
        updateCount();
        updatePcDefaultsByLab();
    });

    // Autofill buttons
    document.querySelectorAll('.autofill-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const presentSet = new Set(JSON.parse(this.dataset.present));
            cards.forEach(function (card) {
                const cb = card.querySelector('.attendance-checkbox');
                if (presentSet.has(cb.value)) {
                    cb.checked = true;
                    card.classList.add('bg-success-subtle', 'border-success');
                }
            });
            setNormalMode();
            updateCount();
            updatePcDefaultsByLab();
        });
    });

    allAbsentBtn?.addEventListener('click', function () {
        if (allAbsentDescription) allAbsentDescription.value = '';
        if (allAbsentModal) {
            allAbsentModal.show();
            return;
        }

        const note = window.prompt('Optional description (example: Lab cancelled due to power outage):', '') || '';
        if (!window.confirm('Save attendance as all absent?')) {
            return;
        }
        if (markModeField) markModeField.value = 'all_absent';
        if (descriptionField) descriptionField.value = note.trim();
        submitAttendanceForm();
    });

    confirmAllAbsentBtn?.addEventListener('click', function () {
        cards.forEach(card => {
            const cb = card.querySelector('.attendance-checkbox');
            cb.checked = false;
            card.classList.remove('bg-success-subtle', 'border-success');
        });
        updateCount();
        updatePcDefaultsByLab();

        if (markModeField) markModeField.value = 'all_absent';
        if (descriptionField) descriptionField.value = (allAbsentDescription?.value || '').trim();
        if (allAbsentModal) allAbsentModal.hide();

        submitAttendanceForm();
    });

    attendanceForm?.addEventListener('submit', function () {
        if (markModeField?.value !== 'all_absent') {
            setNormalMode();
        }
    });

    updateCount();
    updatePcDefaultsByLab();
</script>

<?php include('footer.php'); ?>
</body>
</html>

<?php $conn->close(); ?>
<?php
include('dbconfig.php');

$session_faculty_name = $_SESSION['Name'] ?? '';
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$is_embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
$base_self_url = 'addLabMapping.php' . ($is_embedded ? '?embedded=1' : '');
$default_date = date('Y-m-d');

function compare_terms_desc($left, $right) {
    return strnatcmp((string)$right, (string)$left);
}

function group_mappings_by_term(array $mappings) {
    $grouped = [];
    foreach ($mappings as $mapping) {
        $term = (string)($mapping['term'] ?? '');
        if (!isset($grouped[$term])) {
            $grouped[$term] = [];
        }
        $grouped[$term][] = $mapping;
    }

    uksort($grouped, 'compare_terms_desc');
    return $grouped;
}

// Auto-create table if missing
$conn->query("CREATE TABLE IF NOT EXISTS `labmapping` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `faculty`     VARCHAR(50)  NOT NULL,
    `term`        VARCHAR(20)  NOT NULL,
    `sem`         VARCHAR(10)  NOT NULL,
    `subject`     VARCHAR(100) NOT NULL,
    `batch`       TEXT         NOT NULL,
    `labNo`       TEXT         NOT NULL,
    `slot`        TEXT         NOT NULL,
    `start_date`  DATE         NOT NULL,
    `end_date`    DATE         NOT NULL,
    `repeat_days` VARCHAR(20)  NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat comma-separated',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure columns are TEXT to hold JSON
$conn->query("ALTER TABLE labmapping MODIFY slot TEXT NOT NULL");
$conn->query("ALTER TABLE labmapping MODIFY batch TEXT NOT NULL");
$conn->query("ALTER TABLE labmapping MODIFY labNo TEXT NOT NULL");

$success_msg = '';
$error_msg = '';

$fac_id_stmt = $conn->prepare("SELECT id FROM faculty WHERE Name = ?");
$fac_id_stmt->bind_param('s', $session_faculty_name);
$fac_id_stmt->execute();
$fac_row = $fac_id_stmt->get_result()->fetch_assoc();
$fac_id_stmt->close();
$logged_faculty_id = $fac_row ? (string)$fac_row['id'] : '';

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mapping'])) {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    if ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM labmapping WHERE id = ? AND faculty = ?");
        $stmt->bind_param('is', $del_id, $logged_faculty_id);
        $stmt->execute();
        $deleted_rows = $stmt->affected_rows;
        $stmt->close();
        if ($deleted_rows > 0) {
            $success_msg = 'Lab mapping deleted.';
        } else {
            $error_msg = 'Unable to delete lab mapping.';
        }

        if ($deleted_rows > 0 && $edit_id === $del_id) {
            $edit_id = 0;
        }
    }
}

// Load data for form
$faculty_result = $conn->query("SELECT id, Name FROM faculty WHERE status = 1 ORDER BY Name");
$sem_result = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");
$subject_result = $conn->query("SELECT subjectName, subjectCode, sem FROM subjects WHERE status = 1 ORDER BY sem, subjectName");
$slot_result = $conn->query("SELECT timeslot FROM timeslot WHERE status = 1 ORDER BY sequence");
$batch_result = $conn->query("SELECT DISTINCT TRIM(labBatch) AS batch FROM students WHERE labBatch IS NOT NULL AND TRIM(labBatch) <> '' ORDER BY batch");
$lab_result = $conn->query("SELECT labNo FROM labs WHERE status = 1 ORDER BY labNo");

$subjects = [];
while ($row = $subject_result->fetch_assoc()) {
    $subjects[] = $row;
}

$term_rows = [];
$term_res = $conn->query("SELECT DISTINCT term FROM students ORDER BY term DESC");
while ($tr = $term_res->fetch_assoc()) {
    $term_rows[] = $tr['term'];
}
$default_term = $term_rows[0] ?? '';

// Load selected mapping for edit mode
$editing_mapping = null;
if ($edit_id > 0) {
    $edit_stmt = $conn->prepare("SELECT * FROM labmapping WHERE id = ? AND faculty = ?");
    $edit_stmt->bind_param('is', $edit_id, $logged_faculty_id);
    $edit_stmt->execute();
    $editing_mapping = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();

    if (!$editing_mapping) {
        $error_msg = 'Selected mapping not found.';
        $edit_id = 0;
    }
}

$form_defaults = [
    'faculty' => $logged_faculty_id,
    'term' => $default_term,
    'sem' => '',
    'subject' => '',
    'batch' => '',
    'labNo' => '',
    'slot' => '',
    'start_date' => $default_date,
    'end_date' => $default_date,
    'repeat_days' => [],
    'day_data' => [], // [dayNum => ['slot'=>'', 'pairs'=>[['batch'=>'','labNo'=>''], ...]]]
];

$form_values = $form_defaults;
if ($editing_mapping) {
    $stored_slot = (string)$editing_mapping['slot'];
    $stored_batch = (string)$editing_mapping['batch'];
    $stored_lab = (string)$editing_mapping['labNo'];
    $repeat_days = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)$editing_mapping['repeat_days'])),
        static fn($day) => $day >= 0 && $day <= 6
    )));
    $day_data = [];
    // Check if slot is JSON (new format)
    if ($stored_slot !== '' && $stored_slot[0] === '{') {
        $parsed_slots = json_decode($stored_slot, true) ?: [];
        $parsed_batch = json_decode($stored_batch, true) ?: [];
        $parsed_lab = json_decode($stored_lab, true) ?: [];
        foreach ($parsed_slots as $k => $v) {
            $dk = (int)$k;
            // Check if batch/lab have multiple entries (pairs format)
            $bk = $parsed_batch[$k] ?? $parsed_batch[$dk] ?? '';
            $lk = $parsed_lab[$k] ?? $parsed_lab[$dk] ?? '';
            // If batch starts with array marker, it's the new multi-pair format
            if (is_array($bk)) {
                $pairs = [];
                foreach ($bk as $pi => $pb) {
                    $pl = is_array($lk) ? ($lk[$pi] ?? '') : '';
                    if ($pb !== '' && $pl !== '') {
                        $pairs[] = ['batch' => (string)$pb, 'labNo' => (string)$pl];
                    }
                }
                $day_data[$dk] = ['slot' => (string)$v, 'pairs' => $pairs];
            } else {
                // Single batch-lab pair
                $day_data[$dk] = [
                    'slot' => (string)$v,
                    'pairs' => ($bk !== '' && $lk !== '') ? [['batch' => (string)$bk, 'labNo' => (string)$lk]] : [],
                ];
            }
        }
        $repeat_days = array_keys($day_data);
        sort($repeat_days);
    } else {
        // Old format: single slot/batch/lab for all repeat days
        foreach ($repeat_days as $d) {
            $day_data[$d] = [
                'slot' => $stored_slot,
                'batch' => $stored_batch,
                'labNo' => $stored_lab,
                'pairs' => [['batch' => $stored_batch, 'labNo' => $stored_lab]],
            ];
        }
    }
    $form_values = [
        'faculty' => (string)$editing_mapping['faculty'],
        'term' => (string)$editing_mapping['term'],
        'sem' => (string)$editing_mapping['sem'],
        'subject' => (string)$editing_mapping['subject'],
        'batch' => $stored_batch,
        'labNo' => $stored_lab,
        'slot' => $stored_slot,
        'start_date' => (string)$editing_mapping['start_date'],
        'end_date' => (string)$editing_mapping['end_date'],
        'repeat_days' => $repeat_days,
        'day_data' => $day_data,
    ];
}

// Handle save (insert/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mapping'])) {
    $mapping_id  = (int)($_POST['mapping_id'] ?? 0);
    $faculty     = trim((string)($_POST['faculty'] ?? ''));
    $term        = trim((string)($_POST['term'] ?? ''));
    $sem         = trim((string)($_POST['sem'] ?? ''));
    $subject     = trim((string)($_POST['subject'] ?? ''));
    $start_date  = trim((string)($_POST['start_date'] ?? ''));
    $end_date    = trim((string)($_POST['end_date'] ?? ''));

    // Build per-day data from POST - each day can have multiple batch-lab pairs
    $day_data = [];
    $selected_days = [];
    for ($d = 0; $d <= 6; $d++) {
        $slot_val = trim((string)($_POST['day_slot_' . $d] ?? ''));
        if ($slot_val === '') continue;
        $pairs = [];
        $pi = 0;
        while (true) {
            if (!isset($_POST['day_batch_' . $d . '_' . $pi])) break;
            $b = trim((string)($_POST['day_batch_' . $d . '_' . $pi]));
            $l = trim((string)($_POST['day_lab_' . $d . '_' . $pi]));
            if ($b !== '' && $l !== '') {
                $pairs[] = ['batch' => $b, 'labNo' => $l];
            }
            $pi++;
            // safety: limit to 20 pairs per day
            if ($pi > 20) break;
        }
        if (!empty($pairs)) {
            $day_data[$d] = ['slot' => $slot_val, 'pairs' => $pairs];
            $selected_days[] = $d;
        }
    }
    $repeat_days = array_values(array_unique(array_filter(
        array_map('intval', $selected_days),
        static fn($day) => $day >= 0 && $day <= 6
    )));
    sort($repeat_days);

    // Encode to JSON arrays for storage: slot={day:slot}, batch={day:[batch1,batch2]}, labNo={day:[lab1,lab2]}
    $slots_for_json = [];
    $batches_for_json = [];
    $labs_for_json = [];
    foreach ($day_data as $d => $dd) {
        $slots_for_json[(string)$d] = $dd['slot'];
        $batches_for_json[(string)$d] = array_map(fn($p) => $p['batch'], $dd['pairs']);
        $labs_for_json[(string)$d] = array_map(fn($p) => $p['labNo'], $dd['pairs']);
    }
    $slot_json = json_encode($slots_for_json, JSON_FORCE_OBJECT);
    $batch_json = json_encode($batches_for_json, JSON_FORCE_OBJECT);
    $lab_json = json_encode($labs_for_json, JSON_FORCE_OBJECT);

    $form_values = [
        'faculty' => $faculty,
        'term' => $term,
        'sem' => $sem,
        'subject' => $subject,
        'batch' => $batch_json,
        'labNo' => $lab_json,
        'slot' => $slot_json,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'repeat_days' => $repeat_days,
        'day_data' => $day_data,
    ];

    if ($faculty === '' || $term === '' || $sem === '' || $subject === '' || $start_date === '' || $end_date === '' || empty($repeat_days)) {
        $error_msg = 'All fields are required including at least one day with slot and batch-lab pair selected.';
    } elseif ($end_date < $start_date) {
        $error_msg = 'End date must be on or after start date.';
    } else {
        $repeat_days_csv = implode(',', $repeat_days);

        if ($mapping_id > 0) {
            $check_stmt = $conn->prepare("SELECT id FROM labmapping WHERE id = ? AND faculty = ?");
            $check_stmt->bind_param('is', $mapping_id, $logged_faculty_id);
            $check_stmt->execute();
            $mapping_exists = (bool)$check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$mapping_exists) {
                $error_msg = 'Mapping not found for update.';
            } else {
                $stmt = $conn->prepare("UPDATE labmapping SET faculty = ?, term = ?, sem = ?, subject = ?, batch = ?, labNo = ?, slot = ?, start_date = ?, end_date = ?, repeat_days = ? WHERE id = ? AND faculty = ?");
                $stmt->bind_param('ssssssssssis', $faculty, $term, $sem, $subject, $batch_json, $lab_json, $slot_json, $start_date, $end_date, $repeat_days_csv, $mapping_id, $logged_faculty_id);
                if ($stmt->execute()) {
                    $success_msg = 'Lab mapping updated successfully.';
                    $edit_id = $mapping_id;
                } else {
                    $error_msg = 'Failed to update lab mapping.';
                }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO labmapping (faculty, term, sem, subject, batch, labNo, slot, start_date, end_date, repeat_days) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssssss', $faculty, $term, $sem, $subject, $batch_json, $lab_json, $slot_json, $start_date, $end_date, $repeat_days_csv);
            if ($stmt->execute()) {
                $success_msg = 'Lab mapping saved successfully.';
                $form_values = $form_defaults;
            } else {
                $error_msg = 'Failed to save lab mapping.';
            }
            $stmt->close();
        }
    }
}

$is_edit_mode = $edit_id > 0;
$form_action = $base_self_url;
if ($is_edit_mode) {
    $form_action .= ($is_embedded ? '&' : '?') . 'edit_id=' . $edit_id;
}

$mappings_stmt = $conn->prepare("SELECT m.*, f.Name AS faculty_name FROM labmapping m LEFT JOIN faculty f ON f.id = m.faculty WHERE m.faculty = ? ORDER BY m.start_date DESC, m.id DESC");
$mappings_stmt->bind_param('s', $logged_faculty_id);
$mappings_stmt->execute();
$mappings_result = $mappings_stmt->get_result();
$mappings_stmt->close();
$mappings = [];
if ($mappings_result) {
    while ($row = $mappings_result->fetch_assoc()) {
        $mappings[] = $row;
    }
}
$grouped_mappings = group_mappings_by_term($mappings);
$open_terms = [];
if (!empty($grouped_mappings)) {
    $grouped_terms = array_keys($grouped_mappings);
    $open_terms[] = (string)$grouped_terms[0];
}
if ($is_edit_mode && !empty($form_values['term'])) {
    $open_terms[] = (string)$form_values['term'];
}
$open_terms = array_values(array_unique($open_terms));
$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<?php if ($is_embedded): ?>
<style>
    .app-header,
    #app-sidepanel,
    .app-footer {
        display: none !important;
    }
    .app-wrapper {
        margin-left: 0 !important;
        padding-top: 0 !important;
    }
    .app-content {
        padding-top: 0 !important;
    }
    .app-content .container-xl {
        max-width: 100% !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
    .app-content .row.g-4 > .col-12.col-lg-5,
    .app-content .row.g-4 > .col-12.col-lg-7 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
</style>
<?php endif; ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-diagram-3 me-2"></i>Lab Mapping</h1>

            <?php if ($success_msg !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="mb-0"><?= $is_edit_mode ? 'Edit Lab Mapping' : 'New Lab Mapping' ?></h4>
                                <?php if ($is_edit_mode): ?>
                                    <a href="<?= htmlspecialchars($base_self_url) ?>" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="<?= htmlspecialchars($form_action) ?>" id="labMappingForm">
                                <input type="hidden" name="mapping_id" value="<?= $is_edit_mode ? (int)$edit_id : 0 ?>">

                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Faculty Name</label>
                                        <select name="faculty" class="form-control" required>
                                            <option value="">Select Faculty</option>
                                            <?php if ($faculty_result): ?>
                                                <?php $faculty_result->data_seek(0); while ($fac = $faculty_result->fetch_assoc()): ?>
                                                    <option value="<?= htmlspecialchars((string)$fac['id']) ?>" <?= ((string)$fac['id'] === (string)$form_values['faculty']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($fac['Name']) ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Term</label>
                                        <select name="term" class="form-control" required>
                                            <option value="">Term</option>
                                            <?php foreach ($term_rows as $tr): ?>
                                                <option value="<?= htmlspecialchars($tr) ?>" <?= ((string)$tr === (string)$form_values['term']) ? 'selected' : '' ?>><?= htmlspecialchars($tr) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Semester</label>
                                        <select name="sem" class="form-control" id="semSelect" required>
                                            <option value="">Sem</option>
                                            <?php if ($sem_result): ?>
                                                <?php $sem_result->data_seek(0); while ($sem = $sem_result->fetch_assoc()): ?>
                                                    <option value="<?= htmlspecialchars((string)$sem['sem']) ?>" <?= ((string)$sem['sem'] === (string)$form_values['sem']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars((string)$sem['sem']) ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Subject</label>
                                        <select name="subject" class="form-control" id="subjectSelect" required>
                                            <option value="">Select Semester first</option>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-2">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars((string)$form_values['start_date']) ?>" required>
                                    </div>

                                    <div class="col-6 col-md-2">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars((string)$form_values['end_date']) ?>" required>
                                    </div>
                                </div>

                                <!-- Day & Schedule Table -->
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Day &amp; Schedule</label>
                                    <div class="table-responsive">
                                        <table class="table table-bordered mb-0 day-slot-table" style="font-size:0.82rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="text-center" style="width:60px">Day</th>
                                                    <th class="text-center" style="width:130px">Slot</th>
                                                    <th class="text-center">Batch &amp; Lab</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                    $batches = [];
                                                    if ($batch_result) { $batch_result->data_seek(0); while ($b = $batch_result->fetch_assoc()) { $batches[] = (string)$b['batch']; } }
                                                    $labs = [];
                                                    if ($lab_result) { $lab_result->data_seek(0); while ($l = $lab_result->fetch_assoc()) { $labs[] = (string)$l['labNo']; } }
                                                    $slot_list = [];
                                                    if ($slot_result) { $slot_result->data_seek(0); while ($s = $slot_result->fetch_assoc()) { $slot_list[] = (string)$s['timeslot']; } }
                                                    $day_order = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'];
                                                ?>
                                                <?php foreach ($day_order as $num => $name):
                                                    $checked = in_array((int)$num, (array)$form_values['repeat_days'], true);
                                                    $dd = $form_values['day_data'][$num] ?? [];
                                                    $saved_slot = (string)($dd['slot'] ?? '');
                                                    $pairs = $dd['pairs'] ?? [];
                                                    if (empty($pairs) && $checked) { $pairs = [['batch' => '', 'labNo' => '']]; }
                                                ?>
                                                <tr class="<?= $checked ? 'table-primary' : '' ?> day-slot-row" data-day="<?= $num ?>">
                                                    <td class="text-center align-middle">
                                                        <div class="form-check d-flex justify-content-center">
                                                            <input type="checkbox" name="day_enable_<?= $num ?>" value="<?= $num ?>" class="form-check-input day-enable-cb" id="day-enable-<?= $num ?>" <?= $checked ? 'checked' : '' ?>>
                                                            <label class="form-check-label ms-1 fw-semibold" for="day-enable-<?= $num ?>"><?= $name ?></label>
                                                        </div>
                                                    </td>
                                                    <td class="align-middle">
                                                        <select name="day_slot_<?= $num ?>" class="form-control form-control-sm day-slot-select">
                                                            <option value="">—</option>
                                                            <?php foreach ($slot_list as $sn): ?>
                                                            <option value="<?= htmlspecialchars($sn) ?>" <?= ($sn === $saved_slot) ? 'selected' : '' ?>><?= htmlspecialchars($sn) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td class="align-middle">
                                                        <div class="batch-lab-list" data-day="<?= $num ?>">
                                                            <?php $pi = 0; foreach ($pairs as $pair): ?>
                                                            <div class="batch-lab-item d-flex gap-2 mb-1 align-items-center">
                                                                <select name="day_batch_<?= $num ?>_<?= $pi ?>" class="form-control form-control-sm" style="max-width:160px;">
                                                                    <option value="">Select Batch</option>
                                                                    <?php foreach ($batches as $bn): ?>
                                                                    <option value="<?= htmlspecialchars($bn) ?>" <?= ($bn === (string)($pair['batch'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($bn) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <select name="day_lab_<?= $num ?>_<?= $pi ?>" class="form-control form-control-sm" style="max-width:140px;">
                                                                    <option value="">Select Lab</option>
                                                                    <?php foreach ($labs as $ln): ?>
                                                                    <option value="<?= htmlspecialchars($ln) ?>" <?= ($ln === (string)($pair['labNo'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($ln) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="button" class="btn btn-sm btn-outline-danger remove-batch-lab" title="Remove" <?= $pi === 0 && count($pairs) === 1 ? 'disabled' : '' ?>><i class="bi bi-x"></i></button>
                                                            </div>
                                                            <?php $pi++; endforeach; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary mt-1 add-batch-lab" data-day="<?= $num ?>"><i class="bi bi-plus-circle"></i> Add Batch</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">Check a day, select its slot, then add batch &amp; lab pairs. Each batch can have a different lab.</small>
                                </div>

                                <button type="submit" name="save_mapping" class="btn btn-primary">
                                    <i class="bi <?= $is_edit_mode ? 'bi-check2-circle' : 'bi-plus-circle' ?> me-1"></i><?= $is_edit_mode ? 'Update Lab Mapping' : 'Save Lab Mapping' ?>
                                </button>
                            </form>

                            <!-- Hidden template source for JS to clone batch/lab options -->
                            <div style="display:none;" aria-hidden="true">
                                <select id="batchOptionsTemplate"><?php foreach ($batches as $bn): ?><option value="<?= htmlspecialchars($bn) ?>"><?= htmlspecialchars($bn) ?></option><?php endforeach; ?></select>
                                <select id="labOptionsTemplate"><?php foreach ($labs as $ln): ?><option value="<?= htmlspecialchars($ln) ?>"><?= htmlspecialchars($ln) ?></option><?php endforeach; ?></select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Lab Mappings (full width below form) -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4 class="mb-3">Existing Lab Mappings</h4>
                            <?php if (!empty($grouped_mappings)): ?>
                                <div class="accordion" id="labMappingAccordion">
                                    <?php foreach ($grouped_mappings as $term => $term_mappings): ?>
                                        <?php
                                            $accordion_id = 'lab-term-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$term);
                                            $is_open = in_array((string)$term, $open_terms, true);
                                        ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="<?= htmlspecialchars($accordion_id) ?>-header">
                                                <button class="accordion-button <?= $is_open ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($accordion_id) ?>" aria-expanded="<?= $is_open ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($accordion_id) ?>">
                                                    <span class="fw-semibold">Term <?= htmlspecialchars($term) ?></span>
                                                    <span class="badge bg-light text-dark border ms-2" style="color: black"><?= count($term_mappings) ?> mapping<?= count($term_mappings) === 1 ? '' : 's' ?></span>
                                                </button>
                                            </h2>
                                            <div id="<?= htmlspecialchars($accordion_id) ?>" class="accordion-collapse collapse <?= $is_open ? 'show' : '' ?>" aria-labelledby="<?= htmlspecialchars($accordion_id) ?>-header">
                                                <div class="accordion-body px-0 pb-0">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.82rem;">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>Faculty</th>
                                                                    <th>Sem</th>
                                                                    <th>Subject</th>
                                                                    <th>Period</th>
                                                                    <th>Days</th>
                                                                    <th></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php foreach ($term_mappings as $m): ?>
                                                                <?php
                                                                    $days_arr = array_values(array_unique(array_filter(
                                                                        array_map('intval', explode(',', (string)$m['repeat_days'])),
                                                                        static fn($day) => $day >= 0 && $day <= 6
                                                                    )));
                                                                    $stored_slot = (string)$m['slot'];
                                                                    $stored_batch = (string)$m['batch'];
                                                                    $stored_lab = (string)$m['labNo'];
                                                                    $has_json = ($stored_slot !== '' && $stored_slot[0] === '{');
                                                                    $parsed_slots = $has_json ? (json_decode($stored_slot, true) ?: []) : [];
                                                                    $parsed_batches = $has_json ? (json_decode($stored_batch, true) ?: []) : [];
                                                                    $parsed_labs = $has_json ? (json_decode($stored_lab, true) ?: []) : [];
                                                                    $day_parts = [];
                                                                    foreach ($days_arr as $d):
                                                                        $dkey = (string)$d;
                                                                        $sn = $has_json ? ($parsed_slots[$d] ?? $parsed_slots[$dkey] ?? '') : $stored_slot;
                                                                        $ba = $has_json ? ($parsed_batches[$d] ?? $parsed_batches[$dkey] ?? '') : $stored_batch;
                                                                        $la = $has_json ? ($parsed_labs[$d] ?? $parsed_labs[$dkey] ?? '') : $stored_lab;
                                                                        // Determine if batch/lab are arrays (multi-pair) or strings (single)
                                                                        if (is_array($ba) && is_array($la)):
                                                                            $pair_strs = [];
                                                                            foreach ($ba as $bi => $bv):
                                                                                $lv = $la[$bi] ?? '';
                                                                                if ($bv !== '' && $lv !== ''):
                                                                                    $pair_strs[] = htmlspecialchars($bv) . ' → ' . htmlspecialchars($lv);
                                                                                endif;
                                                                            endforeach;
                                                                            $pair_text = !empty($pair_strs) ? implode(', ', $pair_strs) : '';
                                                                            $day_label = $day_names[$d] ?? (string)$d;
                                                                            $slot_part = $sn !== '' ? htmlspecialchars($sn) . ' · ' : '';
                                                                            $day_parts[] = $day_label . ' (' . $slot_part . $pair_text . ')';
                                                                        else:
                                                                            // Old format: single batch/lab
                                                                            if ($sn !== '' && $ba !== '' && $la !== ''):
                                                                                $day_parts[] = ($day_names[$d] ?? (string)$d) . ' (' . htmlspecialchars($sn) . ' · ' . htmlspecialchars($ba) . ' → ' . htmlspecialchars($la) . ')';
                                                                            elseif ($sn !== ''):
                                                                                $day_parts[] = ($day_names[$d] ?? (string)$d) . ' (' . htmlspecialchars($sn) . ')';
                                                                            else:
                                                                                $day_parts[] = $day_names[$d] ?? (string)$d;
                                                                            endif;
                                                                        endif;
                                                                    endforeach;
                                                                ?>
                                                                <tr class="<?= ($is_edit_mode && $edit_id === (int)$m['id']) ? 'table-warning' : '' ?>">
                                                                    <td><?= htmlspecialchars($m['faculty_name'] ?? $m['faculty']) ?></td>
                                                                    <td><small class="text-muted">Sem <?= htmlspecialchars($m['sem']) ?></small></td>
                                                                    <td><?= htmlspecialchars($m['subject']) ?></td>
                                                                    <td style="white-space:nowrap;"><?= htmlspecialchars($m['start_date']) ?><br><?= htmlspecialchars($m['end_date']) ?></td>
                                                                    <td><?= implode(', ', $day_parts) ?></td>
                                                                    <td>
                                                                        <div class="d-flex gap-1">
                                                                            <a href="addLabMapping.php?edit_id=<?= (int)$m['id'] ?><?= $is_embedded ? '&embedded=1' : '' ?>" class="btn btn-outline-warning btn-sm" title="Edit lab mapping">
                                                                                <i class="bi bi-pencil"></i>
                                                                            </a>
                                                                            <form method="POST" action="<?= htmlspecialchars($base_self_url) ?>" onsubmit="return confirm('Delete this lab mapping?')">
                                                                                <input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>">
                                                                                <button type="submit" name="delete_mapping" class="btn btn-outline-danger btn-sm">
                                                                                    <i class="bi bi-trash"></i>
                                                                                </button>
                                                                            </form>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No lab mappings yet. Create one using the form above.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    const allSubjects = <?= json_encode($subjects) ?>;
    const semSelect = document.getElementById('semSelect');
    const subjectSelect = document.getElementById('subjectSelect');
    const selectedSem = <?= json_encode((string)$form_values['sem']) ?>;
    const selectedSubject = <?= json_encode((string)$form_values['subject']) ?>;

    function populateSubjects(sem, selectedValue = '') {
        if (!sem) {
            subjectSelect.innerHTML = '<option value="">Select Semester first</option>';
            return;
        }

        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        allSubjects
            .filter(s => String(s.sem) === String(sem))
            .forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.subjectName;
                opt.textContent = s.subjectName + (s.subjectCode ? ' (' + s.subjectCode + ')' : '');
                if (String(s.subjectName) === String(selectedValue)) {
                    opt.selected = true;
                }
                subjectSelect.appendChild(opt);
            });
    }

    semSelect.addEventListener('change', function () {
        populateSubjects(this.value);
    });

    populateSubjects(selectedSem || semSelect.value, selectedSubject);

    // Day-slot row: enable/disable controls based on checkbox
    document.querySelectorAll('.day-slot-row').forEach(function (row) {
        const cb = row.querySelector('.day-enable-cb');
        const slotSel = row.querySelector('.day-slot-select');
        function syncRow() {
            row.classList.toggle('table-primary', cb.checked);
            if (!cb.checked) {
                if (slotSel) slotSel.value = '';
                row.querySelectorAll('.batch-lab-item select').forEach(function (sel) { sel.value = ''; });
            } else {
                // When checked, ensure at least one batch-lab row exists
                const list = row.querySelector('.batch-lab-list');
                if (list && list.querySelectorAll('.batch-lab-item').length === 0) {
                    list.querySelector('.add-batch-lab').click();
                }
            }
            updateRemoveButtons(row);
        }
        cb.addEventListener('change', syncRow);
        if (slotSel) {
            slotSel.addEventListener('change', function () {
                if (this.value !== '' && !cb.checked) {
                    cb.checked = true;
                    syncRow();
                }
            });
        }
    });

    // Add Batch row in a day
    document.querySelectorAll('.add-batch-lab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const day = this.getAttribute('data-day');
            const list = this.closest('.batch-lab-list');
            const nextIdx = list.querySelectorAll('.batch-lab-item').length;
            // If no items exist, auto-check the day
            const row = list.closest('.day-slot-row');
            if (row) {
                const cb = row.querySelector('.day-enable-cb');
                if (cb && !cb.checked) {
                    cb.checked = true;
                    row.classList.add('table-primary');
                }
            }
            // Get batch/lab options from any existing item or use global source
            const firstItem = list.querySelector('.batch-lab-item');
            let batchOpts = '<option value="">Select Batch</option>';
            let labOpts = '<option value="">Select Lab</option>';
            // Always prefer the hidden template source — it has all available batches/labs
            const batchTpl = document.getElementById('batchOptionsTemplate');
            const labTpl = document.getElementById('labOptionsTemplate');
            if (batchTpl) batchOpts = '<option value="">Select Batch</option>' + batchTpl.innerHTML;
            if (labTpl) labOpts = '<option value="">Select Lab</option>' + labTpl.innerHTML;
            const newItem = document.createElement('div');
            newItem.className = 'batch-lab-item d-flex gap-2 mb-1 align-items-center';
            newItem.innerHTML = '<select name="day_batch_' + day + '_' + nextIdx + '" class="form-control form-control-sm" style="max-width:160px;">' + batchOpts + '</select>'
                + '<select name="day_lab_' + day + '_' + nextIdx + '" class="form-control form-control-sm" style="max-width:140px;">' + labOpts + '</select>'
                + '<button type="button" class="btn btn-sm btn-outline-danger remove-batch-lab" title="Remove"><i class="bi bi-x"></i></button>';
            list.insertBefore(newItem, this);
            bindRemoveButtons();
            updateRemoveButtonsForRow(list);
        });
    });

    function bindRemoveButtons() {
        document.querySelectorAll('.remove-batch-lab').forEach(function (btn) {
            if (btn._bound) return;
            btn._bound = true;
            btn.addEventListener('click', function () {
                const item = this.closest('.batch-lab-item');
                const list = item.parentElement;
                item.remove();
                // Re-index names to keep them sequential
                reindexPairs(list);
                updateRemoveButtonsForRow(list);
            });
        });
    }

    function reindexPairs(list) {
        const items = list.querySelectorAll('.batch-lab-item');
        items.forEach(function (it, idx) {
            it.querySelectorAll('select').forEach(function (sel) {
                const m = sel.name.match(/^day_(batch|lab)_(\d+)_/);
                if (m) {
                    sel.name = 'day_' + m[1] + '_' + m[2] + '_' + idx;
                }
            });
        });
    }

    function updateRemoveButtons(row) {
        const list = row.querySelector('.batch-lab-list');
        updateRemoveButtonsForRow(list);
    }

    function updateRemoveButtonsForRow(list) {
        if (!list) return;
        const items = list.querySelectorAll('.batch-lab-item');
        items.forEach(function (it, idx) {
            const btn = it.querySelector('.remove-batch-lab');
            if (btn) btn.disabled = (items.length === 1);
        });
        // Show/hide Add Batch button based on whether the day is enabled
        const addBtn = list.querySelector('.add-batch-lab');
        if (addBtn) {
            const row = list.closest('.day-slot-row');
            const cb = row ? row.querySelector('.day-enable-cb') : null;
            addBtn.style.display = (cb && cb.checked) ? '' : 'none';
        }
    }

    bindRemoveButtons();
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

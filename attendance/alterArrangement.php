<?php
include('dbconfig.php');

// Alternative arrangements are an administrative data-entry feature.
$current_username = trim((string)($_SESSION['username'] ?? ''));
if (strcasecmp($current_username, 'admin') !== 0) {
    header('Location: home.php');
    exit();
}

function arrangement_ensure_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS `alternative_arrangements` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `term` VARCHAR(20) NOT NULL,
        `arrangement_date` DATE NOT NULL,
        `mapping_type` VARCHAR(10) NOT NULL,
        `mapping_id` INT NOT NULL,
        `slot` VARCHAR(100) NOT NULL,
        `original_faculty` VARCHAR(50) NOT NULL,
        `alternate_faculty` VARCHAR(50) NOT NULL,
        `sem` VARCHAR(10) NOT NULL DEFAULT '',
        `subject` VARCHAR(100) NOT NULL DEFAULT '',
        `class_or_batch` VARCHAR(100) NOT NULL DEFAULT '',
        `lab_no` VARCHAR(100) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_alternate_arrangement_slot` (`mapping_type`, `mapping_id`, `arrangement_date`, `slot`, `class_or_batch`),
        KEY `idx_arrangement_date` (`arrangement_date`),
        KEY `idx_arrangement_term` (`term`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function arrangement_redirect(array $params = []): void
{
    header('Location: alterArrangement.php' . ($params ? '?' . http_build_query($params) : ''));
    exit();
}

function arrangement_json_day_value($stored, int $day)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if ($stored[0] !== '{') return $stored;
    $decoded = json_decode($stored, true);
    if (!is_array($decoded)) return '';
    return $decoded[$day] ?? $decoded[(string)$day] ?? '';
}

function arrangement_day_values($stored, int $day): array
{
    $value = arrangement_json_day_value($stored, $day);
    if (is_array($value)) {
        return array_values(array_filter(array_map(static function ($item) {
            return trim((string)$item);
        }, $value), static function ($item) { return $item !== ''; }));
    }
    $value = trim((string)$value);
    return $value === '' ? [] : [$value];
}

function arrangement_entry_key(array $entry): string
{
    return implode('|', [
        (string)$entry['mapping_type'],
        (int)$entry['mapping_id'],
        (string)$entry['slot'],
        (string)$entry['class_or_batch'],
    ]);
}

/** Return the mapped loads for one faculty on the selected day. */
function arrangement_loads(mysqli $conn, string $term, string $date, string $faculty): array
{
    if ($term === '' || $date === '' || $faculty === '') return [];
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $date) return [];
    $day = (int)$date_obj->format('w');
    $rows = [];
    $queries = [
        'lecture' => "SELECT id, faculty, term, sem, subject, class AS class_or_batch, '' AS lab_no, slot, repeat_days
                      FROM lecmapping WHERE term = ? AND faculty = ? AND start_date <= ? AND end_date >= ?",
        'lab' => "SELECT id, faculty, term, sem, subject, batch AS class_or_batch, labNo AS lab_no, slot, repeat_days
                  FROM labmapping WHERE term = ? AND faculty = ? AND start_date <= ? AND end_date >= ?",
        'tutorial' => "SELECT id, faculty, term, sem, subject, tutBatch AS class_or_batch, '' AS lab_no, slot, repeat_days
                       FROM tutmapping WHERE term = ? AND faculty = ? AND start_date <= ? AND end_date >= ?",
    ];
    foreach ($queries as $type => $sql) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('ssss', $term, $faculty, $date, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($mapping = $result->fetch_assoc()) {
            $repeat_days = array_map('intval', array_filter(explode(',', (string)$mapping['repeat_days']), 'strlen'));
            if (!in_array($day, $repeat_days, true)) continue;
            $slots = arrangement_day_values($mapping['slot'], $day);
            $groups = arrangement_day_values($mapping['class_or_batch'], $day);
            $labs = arrangement_day_values($mapping['lab_no'], $day);
            if (empty($slots)) continue;
            foreach ($slots as $slot) {
                if ($type === 'lab' || $type === 'tutorial') {
                    foreach ($groups as $index => $group) {
                        $rows[] = [
                            'mapping_type' => $type, 'mapping_id' => (int)$mapping['id'],
                            'slot' => $slot, 'term' => (string)$mapping['term'], 'sem' => (string)$mapping['sem'],
                            'subject' => (string)$mapping['subject'], 'class_or_batch' => $group,
                            'lab_no' => $type === 'lab' ? (string)($labs[$index] ?? ($labs[0] ?? '')) : '',
                            'original_faculty' => (string)$mapping['faculty'],
                        ];
                    }
                } else {
                    $rows[] = [
                        'mapping_type' => $type, 'mapping_id' => (int)$mapping['id'],
                        'slot' => $slot, 'term' => (string)$mapping['term'], 'sem' => (string)$mapping['sem'],
                        'subject' => (string)$mapping['subject'],
                        'class_or_batch' => (string)($groups[0] ?? ''), 'lab_no' => '',
                        'original_faculty' => (string)$mapping['faculty'],
                    ];
                }
            }
        }
        $stmt->close();
    }
    usort($rows, static function ($a, $b) {
        return strnatcasecmp($a['slot'] . $a['mapping_type'] . $a['class_or_batch'], $b['slot'] . $b['mapping_type'] . $b['class_or_batch']);
    });
    return $rows;
}

arrangement_ensure_table($conn);

// Remove an individual saved arrangement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_arrangement'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM alternative_arrangements WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    arrangement_redirect(['msg' => 'Arrangement deleted.']);
}

// Load the row first so Edit can pre-fill the schedule filters.
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_row = null;
if ($edit_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM alternative_arrangements WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Save new entries, or update the one arrangement being edited.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_arrangements'])) {
    $term = trim((string)($_POST['term'] ?? ''));
    $date = trim((string)($_POST['arrangement_date'] ?? ''));
    $faculty = trim((string)($_POST['original_faculty'] ?? ''));
    $updating_id = (int)($_POST['editing_id'] ?? 0);
    $replacements = (array)($_POST['replacement'] ?? []);
    $loads = arrangement_loads($conn, $term, $date, $faculty);
    $valid_loads = [];
    foreach ($loads as $load) $valid_loads[arrangement_entry_key($load)] = $load;

    $active_faculty = [];
    $faculty_result = $conn->query('SELECT id FROM faculty WHERE status = 1');
    while ($faculty_row = $faculty_result->fetch_assoc()) $active_faculty[(string)$faculty_row['id']] = true;

    $selected = [];
    foreach ($replacements as $key => $alternate) {
        $alternate = trim((string)$alternate);
        if ($alternate === '') continue;
        if (!isset($valid_loads[$key])) continue;
        if ($alternate === $faculty || !isset($active_faculty[$alternate])) {
            arrangement_redirect(['err' => 'Choose an active faculty member other than the faculty on leave.']);
        }
        $selected[$key] = ['load' => $valid_loads[$key], 'alternate' => $alternate];
    }
    if (empty($selected)) {
        arrangement_redirect(['term' => $term, 'date' => $date, 'faculty' => $faculty, 'err' => 'Select an alternate faculty member for at least one mapped slot.']);
    }
    if ($updating_id > 0 && count($selected) !== 1) {
        arrangement_redirect(['edit' => $updating_id, 'err' => 'An arrangement update must contain exactly one slot.']);
    }

    if ($updating_id > 0) {
        $picked = reset($selected);
        $load = $picked['load'];
        $stmt = $conn->prepare("UPDATE alternative_arrangements
            SET term = ?, arrangement_date = ?, mapping_type = ?, mapping_id = ?, slot = ?, original_faculty = ?, alternate_faculty = ?, sem = ?, subject = ?, class_or_batch = ?, lab_no = ?
            WHERE id = ?");
        $stmt->bind_param('sssisssssssi', $load['term'], $date, $load['mapping_type'], $load['mapping_id'], $load['slot'], $load['original_faculty'], $picked['alternate'], $load['sem'], $load['subject'], $load['class_or_batch'], $load['lab_no'], $updating_id);
        $ok = $stmt->execute();
        $stmt->close();
        arrangement_redirect($ok ? ['msg' => 'Arrangement updated.'] : ['edit' => $updating_id, 'err' => 'Unable to update the arrangement.']);
    }

    $stmt = $conn->prepare("INSERT INTO alternative_arrangements
        (term, arrangement_date, mapping_type, mapping_id, slot, original_faculty, alternate_faculty, sem, subject, class_or_batch, lab_no)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE alternate_faculty = VALUES(alternate_faculty), sem = VALUES(sem), subject = VALUES(subject), lab_no = VALUES(lab_no)");
    $saved = 0;
    foreach ($selected as $picked) {
        $load = $picked['load'];
        $stmt->bind_param('sssisssssss', $load['term'], $date, $load['mapping_type'], $load['mapping_id'], $load['slot'], $load['original_faculty'], $picked['alternate'], $load['sem'], $load['subject'], $load['class_or_batch'], $load['lab_no']);
        if ($stmt->execute()) $saved++;
    }
    $stmt->close();
    arrangement_redirect($saved ? ['msg' => $saved . ' arrangement' . ($saved === 1 ? '' : 's') . ' saved.'] : ['err' => 'Unable to save the arrangement.']);
}

// Terms: use the greatest available value by default, as requested.
$terms = [];
$term_result = $conn->query("SELECT DISTINCT term FROM (
    SELECT term FROM terms WHERE term <> '' UNION SELECT term FROM lecmapping WHERE term <> ''
    UNION SELECT term FROM labmapping WHERE term <> '' UNION SELECT term FROM tutmapping WHERE term <> ''
) available_terms");
if ($term_result) while ($row = $term_result->fetch_assoc()) $terms[] = trim((string)$row['term']);
usort($terms, static function ($a, $b) { return strnatcasecmp($b, $a); });

$selected_term = trim((string)($_GET['term'] ?? ($edit_row['term'] ?? ($terms[0] ?? ''))));
$selected_date = trim((string)($_GET['date'] ?? ($edit_row['arrangement_date'] ?? date('Y-m-d'))));
$selected_faculty = trim((string)($_GET['faculty'] ?? ($edit_row['original_faculty'] ?? '')));
$faculty_rows = [];
$faculty_result = $conn->query('SELECT id, Name, initial FROM faculty WHERE status = 1 ORDER BY Name, initial');
if ($faculty_result) while ($row = $faculty_result->fetch_assoc()) $faculty_rows[] = $row;

$loads = arrangement_loads($conn, $selected_term, $selected_date, $selected_faculty);
if ($edit_row) {
    $edit_key = implode('|', [(string)$edit_row['mapping_type'], (int)$edit_row['mapping_id'], (string)$edit_row['slot'], (string)$edit_row['class_or_batch']]);
    $loads = array_values(array_filter($loads, static function ($load) use ($edit_key) {
        return arrangement_entry_key($load) === $edit_key;
    }));
}

$saved_rows = [];
$saved_result = $conn->query("SELECT a.*, f.Name AS original_name, af.Name AS alternate_name
    FROM alternative_arrangements a
    LEFT JOIN faculty f ON f.id = a.original_faculty
    LEFT JOIN faculty af ON af.id = a.alternate_faculty
    ORDER BY a.arrangement_date DESC, a.id DESC");
if ($saved_result) while ($row = $saved_result->fetch_assoc()) $saved_rows[] = $row;

$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));
$day_name = ($selected_date !== '' && strtotime($selected_date) !== false) ? date('l', strtotime($selected_date)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>
<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-arrow-left-right me-2"></i>Alter Arrangement</h1>
            <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>This is a one-day alternative faculty arrangement. It records the change only; it does not alter the regular timetable or attendance entries.</div>
            <?php if ($msg !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <?php if ($err !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="app-card shadow-sm mb-4"><div class="app-card-body">
                <h4 class="mb-3"><?= $edit_row ? 'Edit Arrangement' : 'New Arrangement' ?></h4>
                <?php if ($edit_row): ?><p class="text-muted">Change the replacement faculty for this mapped slot.</p><?php endif; ?>
                <?php if (!$edit_row): ?>
                <form method="GET" action="alterArrangement.php" class="row g-3 align-items-end">
                    <div class="col-12 col-md-3"><label class="form-label">Term</label><select name="term" class="form-select" required><option value="">Select term</option><?php foreach ($terms as $term): ?><option value="<?= htmlspecialchars($term) ?>" <?= $term === $selected_term ? 'selected' : '' ?>><?= htmlspecialchars($term) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 col-md-3"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" required><div class="form-text"><?= htmlspecialchars($day_name) ?></div></div>
                    <div class="col-12 col-md-4"><label class="form-label">Faculty on leave</label><select name="faculty" class="form-select" required><option value="">Select faculty</option><?php foreach ($faculty_rows as $faculty): ?><option value="<?= htmlspecialchars((string)$faculty['id']) ?>" <?= (string)$faculty['id'] === $selected_faculty ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)$faculty['Name'] . ' ' . (string)$faculty['initial'])) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-calendar-check me-1"></i>Load Slots</button></div>
                </form>
                <?php endif; ?>

                <?php if ($selected_faculty !== '' && !$edit_row && empty($loads)): ?><div class="alert alert-warning mt-4 mb-0">No active mapping was found for this faculty on the selected date.</div><?php endif; ?>
                <?php if (!empty($loads)): ?>
                <form method="POST" action="alterArrangement.php" class="mt-4">
                    <input type="hidden" name="term" value="<?= htmlspecialchars($selected_term) ?>">
                    <input type="hidden" name="arrangement_date" value="<?= htmlspecialchars($selected_date) ?>">
                    <input type="hidden" name="original_faculty" value="<?= htmlspecialchars($selected_faculty) ?>">
                    <?php if ($edit_row): ?><input type="hidden" name="editing_id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>
                    <div class="table-responsive"><table class="table table-bordered align-middle mb-3"><thead class="table-light"><tr><th>Type</th><th>Slot</th><th>Semester</th><th>Subject</th><th>Class / Batch</th><th>Lab</th><th style="min-width:250px">Alternate Faculty</th></tr></thead><tbody>
                    <?php foreach ($loads as $load): $key = arrangement_entry_key($load); $current_alternate = $edit_row ? (string)$edit_row['alternate_faculty'] : ''; ?>
                        <tr><td class="text-capitalize"><?= htmlspecialchars($load['mapping_type']) ?></td><td><?= htmlspecialchars($load['slot']) ?></td><td><?= htmlspecialchars($load['sem']) ?></td><td><?= htmlspecialchars($load['subject']) ?></td><td><?= htmlspecialchars($load['class_or_batch']) ?></td><td><?= htmlspecialchars($load['lab_no']) ?></td><td><select class="form-select" name="replacement[<?= htmlspecialchars($key) ?>]" <?= $edit_row ? 'required' : '' ?>><option value="">Select alternate faculty</option><?php foreach ($faculty_rows as $faculty): if ((string)$faculty['id'] === $selected_faculty) continue; ?><option value="<?= htmlspecialchars((string)$faculty['id']) ?>" <?= (string)$faculty['id'] === $current_alternate ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)$faculty['Name'] . ' ' . (string)$faculty['initial'])) ?></option><?php endforeach; ?></select></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <div class="d-flex gap-2"><button type="submit" name="save_arrangements" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $edit_row ? 'Update Arrangement' : 'Submit Arrangement' ?></button><?php if ($edit_row): ?><a href="alterArrangement.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div>
                </form>
                <?php elseif ($edit_row): ?><div class="alert alert-warning mt-4 mb-0">The original mapped slot no longer exists for this date. You may delete this saved arrangement below.</div><?php endif; ?>
            </div></div>

            <div class="app-card shadow-sm"><div class="app-card-body">
                <h4 class="mb-3">Saved Arrangements <span class="text-muted fs-6">(<?= count($saved_rows) ?>)</span></h4>
                <div class="table-responsive"><table class="table table-striped table-bordered align-middle"><thead><tr><th>Date</th><th>Term</th><th>Slot</th><th>Load</th><th>Faculty on Leave</th><th>Alternate Faculty</th><th class="text-center">Actions</th></tr></thead><tbody>
                <?php if (empty($saved_rows)): ?><tr><td colspan="7" class="text-center text-muted py-4">No alternative arrangements saved yet.</td></tr><?php else: foreach ($saved_rows as $row): ?>
                    <tr><td><?= htmlspecialchars(date('d M Y', strtotime((string)$row['arrangement_date']))) ?><small class="d-block text-muted"><?= htmlspecialchars(date('D', strtotime((string)$row['arrangement_date']))) ?></small></td><td><?= htmlspecialchars($row['term']) ?></td><td><?= htmlspecialchars($row['slot']) ?></td><td><span class="text-capitalize"><?= htmlspecialchars($row['mapping_type']) ?></span><small class="d-block text-muted"><?= htmlspecialchars(trim($row['subject'] . ' ' . $row['class_or_batch'] . ($row['lab_no'] ? ' · ' . $row['lab_no'] : ''))) ?></small></td><td><?= htmlspecialchars($row['original_name'] ?: $row['original_faculty']) ?></td><td><?= htmlspecialchars($row['alternate_name'] ?: $row['alternate_faculty']) ?></td><td class="text-center text-nowrap"><a class="btn btn-warning btn-sm" href="alterArrangement.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="POST" action="alterArrangement.php" class="d-inline" onsubmit="return confirm('Delete this alternative arrangement?');"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-danger btn-sm" type="submit" name="delete_arrangement">Delete</button></form></td></tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </div></div>
        </div>
    </div>
</div>
<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

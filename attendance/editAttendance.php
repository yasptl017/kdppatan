<?php
include('dbconfig.php');

$type    = strtolower(trim((string)($_REQUEST['type'] ?? 'lecture')));
$allowed = ['lecture', 'lab', 'tutorial'];
if (!in_array($type, $allowed, true)) {
    $type = 'lecture';
}

// Filters
$filter_term    = trim((string)($_REQUEST['term'] ?? ''));
$filter_sem     = trim((string)($_REQUEST['sem'] ?? ''));
$filter_subject = trim((string)($_REQUEST['subject'] ?? ''));
$filter_date    = trim((string)($_REQUEST['date'] ?? ''));
// Faculty filter is auto-enforced from session — no longer user-configurable

$success_msg = trim((string)($_GET['success'] ?? ''));
$error_msg   = trim((string)($_GET['error'] ?? ''));

// Resolve logged-in faculty ID from session username
$logged_in_faculty_id = 0;
$logged_in_faculty_name = '';
$session_username = trim((string)($_SESSION['username'] ?? ''));
if ($session_username !== '') {
    $facLookup = $conn->prepare("SELECT id, Name FROM faculty WHERE username = ? LIMIT 1");
    if ($facLookup) {
        $facLookup->bind_param('s', $session_username);
        $facLookup->execute();
        $facRow = $facLookup->get_result()->fetch_assoc();
        if ($facRow) {
            $logged_in_faculty_id = (int)$facRow['id'];
            $logged_in_faculty_name = trim((string)$facRow['Name']);
        }
        $facLookup->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attendance'])) {
    $delete_id   = (int)($_POST['delete_id'] ?? 0);
    $delete_type = strtolower(trim((string)($_POST['delete_type'] ?? $type)));
    $table_map   = [
        'lecture'  => 'lecattendance',
        'lab'      => 'labattendance',
        'tutorial' => 'tutattendance',
    ];

    $redirect_params = [
        'type'    => $delete_type,
        'term'    => $filter_term,
        'sem'     => $filter_sem,
        'subject' => $filter_subject,
        'date'    => $filter_date,
    ];

    if (!isset($table_map[$delete_type]) || $delete_id <= 0) {
        $redirect_params['error'] = 'Invalid attendance record.';
        header('Location: editAttendance.php?' . http_build_query($redirect_params));
        exit();
    }

    if ($logged_in_faculty_id <= 0) {
        $redirect_params['error'] = 'Unable to verify faculty identity.';
        header('Location: editAttendance.php?' . http_build_query($redirect_params));
        exit();
    }

    $table = $table_map[$delete_type];
    // Verify ownership before deleting
    $del_fac_id = (string)$logged_in_faculty_id;
    $del_fac_name = $logged_in_faculty_name !== '' ? $logged_in_faculty_name : $del_fac_id;
    $stmt  = $conn->prepare("DELETE FROM {$table} WHERE id = ? AND (faculty = ? OR faculty = ?)");
    $stmt->bind_param('iss', $delete_id, $del_fac_id, $del_fac_name);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($ok && $affected > 0) {
        $redirect_params['success'] = 'Attendance record deleted successfully.';
    } else {
        $redirect_params['error'] = 'Failed to delete attendance record.';
    }
    header('Location: editAttendance.php?' . http_build_query($redirect_params));
    exit();
}

// Build query — always restrict to logged-in faculty's records.
// Older rows may store the faculty NAME instead of the id, so match both.
$table_for = ['lecture' => 'lecattendance', 'lab' => 'labattendance', 'tutorial' => 'tutattendance'];
$table = $table_for[$type];
$group_col = $type === 'lecture' ? 'class' : 'batch';
$extra_cols = $type === 'lab' ? ', labNo' : '';

$fac_id_str = (string)$logged_in_faculty_id;
$base_where = ['(faculty = ? OR faculty = ?)'];
$base_params = [$fac_id_str, $logged_in_faculty_name !== '' ? $logged_in_faculty_name : $fac_id_str];
$base_types = 'ss';
if ($type === 'lab') {
    // lab uses labattendance rows that have a lab number (rows without one are tutorials)
    $base_where[] = "COALESCE(TRIM(labNo), '') <> ''";
}

// Filter dropdown options, built from this faculty's own records
$opt_term = $opt_sem = $opt_subject = [];
$opt_stmt = $conn->prepare("SELECT DISTINCT term, sem, subject FROM {$table} WHERE " . implode(' AND ', $base_where));
$opt_stmt->bind_param($base_types, ...$base_params);
$opt_stmt->execute();
$opt_res = $opt_stmt->get_result();
while ($o = $opt_res->fetch_assoc()) {
    $opt_term[(string)$o['term']] = true;
    $opt_sem[(string)$o['sem']] = true;
    $opt_subject[(string)$o['subject']] = true;
}
$opt_stmt->close();
$opt_term = array_keys($opt_term);
$opt_sem = array_keys($opt_sem);
$opt_subject = array_keys($opt_subject);
usort($opt_term, fn($x, $y) => strnatcmp($y, $x));
usort($opt_sem, 'strnatcmp');
usort($opt_subject, 'strnatcasecmp');

$where = $base_where;
$params = $base_params;
$types = $base_types;
if ($filter_term !== '')    { $where[] = 'term = ?';    $params[] = $filter_term;    $types .= 's'; }
if ($filter_sem !== '')     { $where[] = 'sem = ?';     $params[] = $filter_sem;     $types .= 's'; }
if ($filter_subject !== '') { $where[] = 'subject = ?'; $params[] = $filter_subject; $types .= 's'; }
if ($filter_date !== '')    { $where[] = 'date = ?';    $params[] = $filter_date;    $types .= 's'; }

$sql  = "SELECT id, date, time, term, faculty, sem, subject, {$group_col}{$extra_cols}, presentNo FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY date DESC, time DESC, id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// Faculty lookup map
$faculty_map = [];
$fac_res = $conn->query("SELECT id, Name FROM faculty");
while ($frow = $fac_res->fetch_assoc()) {
    $faculty_map[(string)$frow['id']] = $frow['Name'];
}

$edit_page = ['lecture' => 'editlecatt.php', 'lab' => 'editlabatt.php', 'tutorial' => 'edittutatt.php'];
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-pencil-square me-2"></i>Edit Attendance</h1>

            <?php if ($success_msg !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <!-- Type Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= $type === 'lecture'  ? 'active' : '' ?>" href="editAttendance.php?type=lecture">
                        <i class="bi bi-journal-text me-1"></i>Lecture
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $type === 'lab'      ? 'active' : '' ?>" href="editAttendance.php?type=lab">
                        <i class="bi bi-camera-video me-1"></i>Lab
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $type === 'tutorial' ? 'active' : '' ?>" href="editAttendance.php?type=tutorial">
                        <i class="bi bi-book me-1"></i>Tutorial
                    </a>
                </li>
            </ul>

            <!-- Filter Form -->
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <form method="GET" action="editAttendance.php" class="row g-2 align-items-end">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                        <div class="col-6 col-md-2">
                            <label class="form-label form-label-sm mb-1">Term</label>
                            <select name="term" class="form-control form-control-sm">
                                <option value="">All</option>
                                <?php foreach ($opt_term as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $filter_term ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label form-label-sm mb-1">Semester</label>
                            <select name="sem" class="form-control form-control-sm">
                                <option value="">All</option>
                                <?php foreach ($opt_sem as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $filter_sem ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label form-label-sm mb-1">Subject</label>
                            <select name="subject" class="form-control form-control-sm">
                                <option value="">All</option>
                                <?php foreach ($opt_subject as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $filter_subject ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label form-label-sm mb-1">Date</label>
                            <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="bi bi-search me-1"></i>Filter
                            </button>
                            <a href="editAttendance.php?type=<?= htmlspecialchars($type) ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        </div>
                    </form>
                    <p class="text-muted mb-0 mt-2" style="font-size:0.8rem;">
                        <i class="bi bi-info-circle me-1"></i>Only showing your attendance records.
                    </p>
                </div>
            </div>

            <!-- Records Table -->
            <div class="app-card shadow-sm">
                <div class="app-card-body">
                    <?php if ($records->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Slot</th>
                                        <th>Faculty</th>
                                        <th>Term</th>
                                        <th>Sem</th>
                                        <th>Subject</th>
                                        <?php if ($type === 'lecture'): ?>
                                            <th>Class</th>
                                        <?php else: ?>
                                            <th>Batch(es)</th>
                                        <?php endif; ?>
                                        <th>Present</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; while ($row = $records->fetch_assoc()): ?>
                                        <?php
                                        $fac_name    = $faculty_map[(string)$row['faculty']] ?? $row['faculty'];
                                        $present_cnt = count(array_filter(array_map('trim', explode(',', (string)$row['presentNo']))));
                                        ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td><?= htmlspecialchars($row['date']) ?></td>
                                            <td><?= htmlspecialchars($row['time']) ?></td>
                                            <td><?= htmlspecialchars($fac_name) ?></td>
                                            <td><?= htmlspecialchars($row['term']) ?></td>
                                            <td><?= htmlspecialchars($row['sem']) ?></td>
                                            <td><?= htmlspecialchars($row['subject']) ?></td>
                                            <?php if ($type === 'lecture'): ?>
                                                <td><?= htmlspecialchars($row[$group_col]) ?></td>
                                            <?php else: ?>
                                                <td><?= htmlspecialchars($row[$group_col]) ?></td>
                                            <?php endif; ?>
                                            <td><span class="badge bg-success"><?= $present_cnt ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <a href="<?= $edit_page[$type] ?>?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil me-1"></i>Edit
                                                    </a>
                                                    <form method="POST" action="editAttendance.php" class="d-inline" onsubmit="return confirm('Delete this attendance record? This cannot be undone.');">
                                                        <input type="hidden" name="delete_attendance" value="1">
                                                        <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                                                        <input type="hidden" name="delete_type" value="<?= htmlspecialchars($type) ?>">
                                                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                                        <input type="hidden" name="term" value="<?= htmlspecialchars($filter_term) ?>">
                                                        <input type="hidden" name="sem" value="<?= htmlspecialchars($filter_sem) ?>">
                                                        <input type="hidden" name="subject" value="<?= htmlspecialchars($filter_subject) ?>">
                                                        <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash me-1"></i>Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-1"></i>No <?= htmlspecialchars($type) ?> attendance records found for your account.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

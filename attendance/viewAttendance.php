<?php
require_once __DIR__ . '/auth.php';
require_login();
include('dbconfig.php');

$filter_sem  = trim((string)($_GET['sem'] ?? ''));
$filter_date = trim((string)($_GET['date'] ?? ''));
$date_is_valid = $filter_date === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date);
$has_filter = $filter_sem !== '' && $filter_date !== '' && $date_is_valid;

// Semester choices are drawn from the records as well as the active semester
// list, so older attendance remains viewable after a semester is deactivated.
$semester_options = [];
$semester_result = $conn->query("SELECT DISTINCT sem FROM (\n    SELECT sem FROM semester WHERE sem IS NOT NULL AND TRIM(sem) <> ''\n    UNION SELECT sem FROM lecattendance WHERE sem IS NOT NULL AND TRIM(sem) <> ''\n    UNION SELECT sem FROM labattendance WHERE sem IS NOT NULL AND TRIM(sem) <> ''\n    UNION SELECT sem FROM tutattendance WHERE sem IS NOT NULL AND TRIM(sem) <> ''\n) AS attendance_semesters ORDER BY CAST(sem AS UNSIGNED), sem");
if ($semester_result) {
    while ($row = $semester_result->fetch_assoc()) {
        $semester_options[] = (string)$row['sem'];
    }
}

$records = [];
if ($has_filter) {
    $queries = [
        'lecture' => "SELECT id, date, time, term, faculty, sem, subject, class AS attendance_group, presentNo\n                      FROM lecattendance WHERE sem = ? AND date = ?",
        'lab' => "SELECT id, date, time, term, faculty, sem, subject, batch AS attendance_group, presentNo\n                  FROM labattendance WHERE sem = ? AND date = ?",
        'tutorial' => "SELECT id, date, time, term, faculty, sem, subject, batch AS attendance_group, presentNo\n                       FROM tutattendance WHERE sem = ? AND date = ?",
    ];

    foreach ($queries as $type => $sql) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('ss', $filter_sem, $filter_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['type'] = $type;
            $records[] = $row;
        }
        $stmt->close();
    }

    usort($records, static function (array $a, array $b): int {
        return [$a['time'], $a['type'], $a['subject'], $a['id']] <=> [$b['time'], $b['type'], $b['subject'], $b['id']];
    });
}

$faculty_map = [];
$faculty_result = $conn->query("SELECT id, Name FROM faculty");
if ($faculty_result) {
    while ($row = $faculty_result->fetch_assoc()) {
        $faculty_map[(string)$row['id']] = $row['Name'];
    }
}

function attendance_view_list_values($value): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$value)), static fn($item) => $item !== '')));
}

function attendance_view_students(mysqli $conn, array $record): array
{
    $group_column = $record['type'] === 'lecture' ? 'class' : ($record['type'] === 'lab' ? 'labBatch' : 'tutBatch');
    $groups = attendance_view_list_values($record['attendance_group']);
    if (empty($groups)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($groups), '?'));
    $sql = "SELECT enrollmentNo, name, `{$group_column}` AS attendance_group\n            FROM students\n            WHERE term = ? AND sem = ?\n              AND UPPER(TRIM(`{$group_column}`)) IN ({$placeholders})\n              AND enrollmentNo IS NOT NULL AND TRIM(enrollmentNo) <> ''";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $normalized_groups = array_map('strtoupper', $groups);
    $params = array_merge([(string)$record['term'], (string)$record['sem']], $normalized_groups);
    $types = 'ss' . str_repeat('s', count($normalized_groups));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return attendance_sort_students_naturally($students, 'enrollmentNo', 'name', 'attendance_group');
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h1 class="app-page-title mb-0"><i class="bi bi-eye me-2"></i>View Attendance Records</h1>
                <a href="home.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house me-1"></i>Dashboard</a>
            </div>

            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <form method="GET" action="viewAttendance.php" class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="sem">Semester</label>
                            <select name="sem" id="sem" class="form-select" required>
                                <option value="">Select semester</option>
                                <?php foreach ($semester_options as $semester): ?>
                                    <option value="<?= htmlspecialchars($semester) ?>" <?= $filter_sem === $semester ? 'selected' : '' ?>><?= htmlspecialchars($semester) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="date">Date</label>
                            <input type="date" name="date" id="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" required>
                        </div>
                        <div class="col-12 col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>View Records</button>
                            <a href="viewAttendance.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                    <p class="text-muted mb-0 mt-3 small"><i class="bi bi-lock me-1"></i>This screen is view-only. It shows attendance filed by all faculty for the selected day.</p>
                </div>
            </div>

            <?php if ($filter_date !== '' && !$date_is_valid): ?>
                <div class="alert alert-danger">Please select a valid date.</div>
            <?php elseif ($has_filter && empty($records)): ?>
                <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No attendance records were found for semester <?= htmlspecialchars($filter_sem) ?> on <?= htmlspecialchars($filter_date) ?>.</div>
            <?php elseif ($has_filter): ?>
                <div class="alert alert-light border"><strong><?= count($records) ?></strong> attendance record(s) found. Expand a record to see every student's Present or Absent status.</div>
                <div class="accordion" id="attendance-records">
                    <?php foreach ($records as $index => $record): ?>
                        <?php
                        $present_set = array_flip(attendance_view_list_values($record['presentNo']));
                        $students = attendance_view_students($conn, $record);
                        $present_count = 0;
                        foreach ($students as $student) {
                            if (isset($present_set[trim((string)$student['enrollmentNo'])])) $present_count++;
                        }
                        $absent_count = count($students) - $present_count;
                        $type_label = ucfirst($record['type']);
                        $faculty_name = $faculty_map[(string)$record['faculty']] ?? $record['faculty'];
                        $collapse_id = 'attendance-record-' . $index;
                        ?>
                        <div class="accordion-item mb-2 border rounded overflow-hidden">
                            <h2 class="accordion-header" id="heading-<?= $index ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>" aria-expanded="false" aria-controls="<?= $collapse_id ?>">
                                    <span class="d-flex flex-wrap align-items-center gap-2 w-100 me-3">
                                        <span class="badge text-bg-primary"><?= htmlspecialchars($type_label) ?></span>
                                        <strong><?= htmlspecialchars($record['subject']) ?></strong>
                                        <span class="text-muted"><?= htmlspecialchars($record['time']) ?> · <?= htmlspecialchars($record['attendance_group']) ?> · <?= htmlspecialchars($faculty_name) ?></span>
                                        <span class="ms-md-auto"><span class="badge text-bg-success me-1">Present: <?= $present_count ?></span><span class="badge text-bg-danger">Absent: <?= $absent_count ?></span></span>
                                    </span>
                                </button>
                            </h2>
                            <div id="<?= $collapse_id ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?= $index ?>" data-bs-parent="#attendance-records">
                                <div class="accordion-body">
                                    <?php if (empty($students)): ?>
                                        <div class="alert alert-warning mb-0">No matching students are available for this record's term, semester, and class/batch.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover align-middle mb-0">
                                                <thead class="table-light"><tr><th>#</th><th>Enrollment No.</th><th>Name</th><th><?= $record['type'] === 'lecture' ? 'Class' : 'Batch' ?></th><th>Status</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($students as $student_index => $student): ?>
                                                        <?php $is_present = isset($present_set[trim((string)$student['enrollmentNo'])]); ?>
                                                        <tr>
                                                            <td><?= $student_index + 1 ?></td>
                                                            <td><?= htmlspecialchars($student['enrollmentNo']) ?></td>
                                                            <td><?= htmlspecialchars($student['name']) ?></td>
                                                            <td><?= htmlspecialchars($student['attendance_group']) ?></td>
                                                            <td><span class="badge text-bg-<?= $is_present ? 'success' : 'danger' ?>"><?= $is_present ? 'Present' : 'Absent' ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

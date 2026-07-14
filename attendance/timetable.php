<?php
include('dbconfig.php');
require_once __DIR__ . '/auth.php';
require_login();

$term_rows = [];
$term_result = $conn->query("SELECT DISTINCT term FROM students ORDER BY term DESC");
if ($term_result) {
    while ($tr = $term_result->fetch_assoc()) {
        $term_rows[] = (string)$tr['term'];
    }
}
$default_term = $term_rows[0] ?? '';
$selected_term = isset($_GET['term']) ? trim((string)$_GET['term']) : $default_term;

// semesters
$sem_rows = [];
$sem_res = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");
if ($sem_res) {
    while ($sr = $sem_res->fetch_assoc()) {
        $sem_rows[] = (string)$sr['sem'];
    }
}
$selected_sem = isset($_GET['sem']) ? trim((string)$_GET['sem']) : '';

// classes (distinct from lecture mappings)
$class_rows = [];
$class_res = $conn->query("SELECT DISTINCT TRIM(class) AS class FROM lecmapping WHERE TRIM(class) <> '' ORDER BY class");
if ($class_res) {
    while ($cr = $class_res->fetch_assoc()) {
        $class_rows[] = (string)$cr['class'];
    }
}
$selected_class = isset($_GET['class']) ? trim((string)$_GET['class']) : '';

$timeslots = [
    '10:30 - 11:30',
    '11:30 - 12:30',
    '1:00 - 2:00',
    '2:00 - 3:00',
    '3:10 - 4:10',
    '4:10 - 5:10',
];

$day_order = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'];

$matrix = [];
foreach (array_keys($day_order) as $d) {
    $matrix[$d] = [];
    foreach ($timeslots as $ts) {
        $matrix[$d][$ts] = [];
    }
}

// Helper to add mapping entry
function add_entry(&$matrix, $day, $slot, $label) {
    if ($slot === '') return;
    if (!isset($matrix[$day][$slot])) {
        $matrix[$day][$slot] = [];
    }
    $matrix[$day][$slot][] = $label;
}

// Load mappings from lecture, lab and tutorial tables
$mapping_tables = [
    'lecmapping' => 'Lecture',
    'labmapping' => 'Lab',
    'tutmapping' => 'Tutorial',
];

foreach ($mapping_tables as $tbl => $label_type) {
    // Build where clause dynamically to support term/sem/class filters
    $where = ["m.term = ?"];
    $types = 's';
    $params = [$selected_term];

    if ($selected_sem !== '') {
        $where[] = "m.sem = ?";
        $types .= 's';
        $params[] = $selected_sem;
    }

    // class filter logic differs per table
    if ($selected_class !== '') {
        if ($tbl === 'lecmapping') {
            $where[] = "m.class = ?";
            $types .= 's';
            $params[] = $selected_class;
        } elseif ($tbl === 'labmapping') {
            $where[] = "m.batch = ?";
            $types .= 's';
            $params[] = $selected_class;
        } else {
            $where[] = "m.tutBatch = ?";
            $types .= 's';
            $params[] = $selected_class;
        }
    }

    $where_sql = implode(' AND ', $where);
    $sql = "SELECT m.*, f.Name AS faculty_name FROM {$tbl} m LEFT JOIN faculty f ON f.id = m.faculty WHERE {$where_sql} ORDER BY m.start_date DESC, m.id DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // bind params dynamically
        if ($types !== '') {
            $bind_names = [];
            $bind_names[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_name = 'bind' . $i;
                $$bind_name = $params[$i];
                $bind_names[] = &$$bind_name;
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_names);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $stored_slot = (string)($row['slot'] ?? '');
            $repeat_days_csv = (string)($row['repeat_days'] ?? '');
            $repeat_days = $repeat_days_csv === '' ? [] : array_map('intval', array_filter(explode(',', $repeat_days_csv)));

            $day_slots = [];
            if ($stored_slot !== '' && $stored_slot[0] === '{') {
                $parsed = json_decode($stored_slot, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $k => $v) {
                        $day_slots[(int)$k] = (string)$v;
                    }
                }
            } else {
                // old format: same slot for all repeat_days
                foreach ($repeat_days as $d) {
                    $day_slots[(int)$d] = $stored_slot;
                }
            }

            foreach ($day_slots as $dnum => $slotval) {
                $slotval = trim((string)$slotval);
                if ($slotval === '') continue;

                // Build plain label according to naming rules
                $subject = isset($row['subject']) ? trim((string)$row['subject']) : '';
                $faculty = isset($row['faculty_name']) ? trim((string)$row['faculty_name']) : '';

                if ($tbl === 'lecmapping') {
                    $class = trim((string)($row['class'] ?? ''));
                    $label = sprintf('lec - %s - %s - %s', $subject, $class, $faculty);
                } elseif ($tbl === 'labmapping') {
                    $batch = trim((string)($row['batch'] ?? ''));
                    $location = trim((string)($row['labNo'] ?? ''));
                    $label = sprintf('lab - %s - %s - %s - %s', $subject, $batch, $faculty, $location);
                } else {
                    $batch = trim((string)($row['tutBatch'] ?? ''));
                    $label = sprintf('tut - %s - %s - %s', $subject, $batch, $faculty);
                }

                // Add plain label to matrix (multiple entries will be shown each on new line)
                add_entry($matrix, (int)$dnum, $slotval, $label);
            }
        }
        $stmt->close();
    }
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
            <h1 class="app-page-title"><i class="bi bi-table me-2"></i>Timetable</h1>

            <div class="row mb-3">
                <div class="col-12 col-md-4">
                    <form method="GET" action="timetable.php">
                        <label class="form-label">Term / Semester / Class</label>
                        <div class="d-flex gap-2">
                            <select name="term" class="form-control" onchange="this.form.submit()">
                                <?php foreach ($term_rows as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= ($t === $selected_term) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="sem" class="form-control" onchange="this.form.submit()">
                                <option value="">All Semesters</option>
                                <?php foreach ($sem_rows as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= ($s === $selected_sem) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="class" class="form-control" onchange="this.form.submit()">
                                <option value="">All Classes</option>
                                <?php foreach ($class_rows as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= ($c === $selected_class) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="app-card shadow-sm"><div class="app-card-body">
                        <div class="table-responsive">
                            <style>
                                .empty-slot { background-color: #e6ffe6; }
                                .cell-entry { padding:4px 6px; font-size:0.92rem; }
                            </style>
                            <table class="table table-bordered mb-0" style="font-size:0.95rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:150px">Time \ Day</th>
                                        <?php foreach ($day_order as $dname): ?>
                                            <th class="text-center"><?= htmlspecialchars($dname) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Build a simple cell text map for easy comparison
                                    $cell_text = [];
                                    $rowspan_tracker = [];
                                    foreach ($day_order as $dnum => $dname) {
                                        foreach ($timeslots as $ts) {
                                            $cells = $matrix[$dnum][$ts] ?? [];
                                            // Use subject and class if available, otherwise raw HTML
                                            if (empty($cells)) {
                                                $cell_text[$dnum][$ts] = '';
                                            } else {
                                                // strip tags to compare textual content for rowspan merging
                                                // compare by joined text
                                                $plain = implode(' | ', $cells);
                                                $cell_text[$dnum][$ts] = $plain;
                                            }
                                        }
                                    }

                                    foreach ($timeslots as $ri => $ts): ?>
                                        <tr>
                                            <td class="fw-semibold align-middle"><?= htmlspecialchars($ts) ?></td>
                                            <?php foreach ($day_order as $dnum => $dname):
                                                // If this cell was already merged by a previous rowspan, skip
                                                $skip = false;
                                                if (isset($rowspan_tracker[$dnum][$ts]) && $rowspan_tracker[$dnum][$ts] > 0) {
                                                    $rowspan_tracker[$dnum][$ts]--;
                                                    $skip = true;
                                                }
                                                if ($skip) continue;

                                                $text = $cell_text[$dnum][$ts] ?? '';
                                                if ($text === '') {
                                                    echo '<td class="empty-slot">&nbsp;</td>';
                                                    continue;
                                                }

                                                // Determine rowspan by checking how many following timeslots have identical text
                                                $rowspan = 1;
                                                for ($k = $ri + 1; $k < count($timeslots); $k++) {
                                                    $next_ts = $timeslots[$k];
                                                    $next_text = $cell_text[$dnum][$next_ts] ?? '';
                                                    if ($next_text === $text) {
                                                        $rowspan++;
                                                    } else {
                                                        break;
                                                    }
                                                }

                                                // Mark tracker to skip subsequent cells merged by rowspan
                                                if ($rowspan > 1) {
                                                    for ($k = 1; $k < $rowspan; $k++) {
                                                        $future_ts = $timeslots[$ri + $k];
                                                        $rowspan_tracker[$dnum][$future_ts] = ($rowspan_tracker[$dnum][$future_ts] ?? 0) + 1;
                                                    }
                                                }

                                                // Render cell: show each entry on its own line
                                                $entries = $matrix[$dnum][$ts] ?? [];
                                                if (!empty($entries)) {
                                                    $escaped = array_map('htmlspecialchars', $entries);
                                                    $html = implode('<br>', $escaped);
                                                } else {
                                                    $html = '';
                                                }

                                                $rowspan_attr = $rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '';
                                                echo '<td' . $rowspan_attr . '>' . $html . '</td>';
                                            endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div></div>
                </div>
            </div>

        </div>
    </div>
    <?php include('footer.php'); ?>
</div>
</body>
</html>

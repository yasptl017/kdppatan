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
    $stmt = $conn->prepare("SELECT m.*, f.Name AS faculty_name FROM {$tbl} m LEFT JOIN faculty f ON f.id = m.faculty WHERE m.term = ? ORDER BY m.start_date DESC, m.id DESC");
    if ($stmt) {
        $stmt->bind_param('s', $selected_term);
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

                // Add plain label to matrix (multiple entries will be concatenated)
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
                        <label class="form-label">Select Term</label>
                        <select name="term" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($term_rows as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>" <?= ($t === $selected_term) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                    foreach ($day_order as $dnum => $dname) {
                                        foreach ($timeslots as $ts) {
                                            $cells = $matrix[$dnum][$ts] ?? [];
                                            // Use subject and class if available, otherwise raw HTML
                                            if (empty($cells)) {
                                                $cell_text[$dnum][$ts] = '';
                                            } else {
                                                // strip tags to compare textual content for rowspan merging
                                                $plain = strip_tags(implode('', $cells));
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

                                                // Render cell: output original HTML entries from matrix
                                                $html = '';
                                                $entries = $matrix[$dnum][$ts] ?? [];
                                                if (!empty($entries)) {
                                                    // prefer showing subject line only (first line)
                                                    $conc = strip_tags(implode('', $entries));
                                                    $html = htmlspecialchars($conc);
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

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
usort($term_rows, static function ($left, $right) {
    if (is_numeric($left) && is_numeric($right)) {
        return (float)$right <=> (float)$left;
    }
    return strnatcmp((string)$right, (string)$left);
});
$default_term = $term_rows[0] ?? '';
$requested_term = isset($_GET['term']) ? trim((string)$_GET['term']) : '';
$selected_term = in_array($requested_term, $term_rows, true) ? $requested_term : $default_term;

$timeslots = [
    '10:30 - 11:30',
    '11:30 - 12:30',
    '1:00 - 2:00',
    '2:00 - 3:00',
    '3:10 - 4:10',
    '4:10 - 5:10',
];

$day_order = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'];

function add_entry(&$matrix, $day, $slot, $label) {
    if ($slot === '') return;
    if (!isset($matrix[$day][$slot])) {
        $matrix[$day][$slot] = [];
    }
    if (!in_array($label, $matrix[$day][$slot], true)) {
        $matrix[$day][$slot][] = $label;
    }
}

function blank_matrix(array $timeslots, array $day_order): array {
    $matrix = [];
    foreach (array_keys($day_order) as $d) {
        $matrix[$d] = [];
        foreach ($timeslots as $ts) {
            $matrix[$d][$ts] = [];
        }
    }
    return $matrix;
}

function resolve_slot_to_timeslots(string $stored, array $timeslots): array {
    $stored = trim($stored);
    if ($stored === '') return [];

    foreach ($timeslots as $ts) {
        if (strcasecmp(trim($ts), $stored) === 0) return [$ts];
    }

    if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $stored, $m)) {
        $to_minutes = fn($t) => intval(explode(':', $t)[0]) * 60 + intval(explode(':', $t)[1]);
        $smin = $to_minutes($m[1]);
        $emin = $to_minutes($m[2]);
        $matches = [];
        foreach ($timeslots as $ts) {
            if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $ts, $mm)) {
                $ts_smin = $to_minutes($mm[1]);
                $ts_emin = $to_minutes($mm[2]);
                if ($ts_smin >= $smin && $ts_emin <= $emin) {
                    $matches[] = $ts;
                }
            }
        }
        return $matches;
    }

    return [];
}

function repeat_days_from_csv(string $csv): array {
    return array_values(array_unique(array_filter(
        array_map('intval', explode(',', $csv)),
        static fn($day) => $day >= 0 && $day <= 6
    )));
}

function day_slots_from_row(array $row): array {
    $stored_slot = trim((string)($row['slot'] ?? ''));
    $repeat_days = repeat_days_from_csv((string)($row['repeat_days'] ?? ''));
    $day_slots = [];

    if ($stored_slot !== '' && $stored_slot[0] === '{') {
        $parsed = json_decode($stored_slot, true);
        if (is_array($parsed)) {
            foreach ($parsed as $day => $slot) {
                $day_slots[(int)$day] = trim((string)$slot);
            }
        }
    } else {
        foreach ($repeat_days as $day) {
            $day_slots[(int)$day] = $stored_slot;
        }
    }

    return $day_slots;
}

function values_for_day(string $stored, int $day): array {
    $stored = trim($stored);
    if ($stored === '') return [];

    if ($stored[0] === '{') {
        $parsed = json_decode($stored, true);
        if (!is_array($parsed)) return [];
        $value = $parsed[(string)$day] ?? $parsed[$day] ?? [];
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value))));
        }
        $value = trim((string)$value);
        return $value === '' ? [] : [$value];
    }

    return [$stored];
}

function build_timetable_matrix($conn, string $term, string $sem, string $class, array $lab_batches, array $tut_batches, array $timeslots, array $day_order): array {
    $matrix = blank_matrix($timeslots, $day_order);

    $stmt = $conn->prepare("SELECT m.*, f.Name AS faculty_name FROM lecmapping m LEFT JOIN faculty f ON f.id = m.faculty WHERE m.term = ? AND m.sem = ? AND TRIM(m.class) = ? ORDER BY m.start_date DESC, m.id DESC");
    if ($stmt) {
        $stmt->bind_param('sss', $term, $sem, $class);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $subject = trim((string)($row['subject'] ?? ''));
            $faculty = trim((string)($row['faculty_name'] ?? ''));
            $label = sprintf('lec - %s - %s - %s', $subject, $class, $faculty);

            foreach (day_slots_from_row($row) as $day => $slot) {
                foreach (resolve_slot_to_timeslots($slot, $timeslots) ?: [$slot] as $resolved_slot) {
                    add_entry($matrix, (int)$day, $resolved_slot, $label);
                }
            }
        }
        $stmt->close();
    }

    $lab_lookup = array_flip($lab_batches);
    $stmt = $conn->prepare("SELECT m.*, f.Name AS faculty_name FROM labmapping m LEFT JOIN faculty f ON f.id = m.faculty WHERE m.term = ? AND m.sem = ? ORDER BY m.start_date DESC, m.id DESC");
    if ($stmt) {
        $stmt->bind_param('ss', $term, $sem);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $subject = trim((string)($row['subject'] ?? ''));
            $faculty = trim((string)($row['faculty_name'] ?? ''));

            foreach (day_slots_from_row($row) as $day => $slot) {
                $batches = values_for_day((string)($row['batch'] ?? ''), (int)$day);
                $labs = values_for_day((string)($row['labNo'] ?? ''), (int)$day);
                foreach ($batches as $idx => $batch) {
                    if (!isset($lab_lookup[$batch])) continue;
                    $location = trim((string)($labs[$idx] ?? ($labs[0] ?? '')));
                    $label = sprintf('lab - %s - %s - %s - %s', $subject, $batch, $faculty, $location);
                    foreach (resolve_slot_to_timeslots($slot, $timeslots) ?: [$slot] as $resolved_slot) {
                        add_entry($matrix, (int)$day, $resolved_slot, $label);
                    }
                }
            }
        }
        $stmt->close();
    }

    $tut_lookup = array_flip($tut_batches);
    $stmt = $conn->prepare("SELECT m.*, f.Name AS faculty_name FROM tutmapping m LEFT JOIN faculty f ON f.id = m.faculty WHERE m.term = ? AND m.sem = ? ORDER BY m.start_date DESC, m.id DESC");
    if ($stmt) {
        $stmt->bind_param('ss', $term, $sem);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $subject = trim((string)($row['subject'] ?? ''));
            $faculty = trim((string)($row['faculty_name'] ?? ''));

            foreach (day_slots_from_row($row) as $day => $slot) {
                $batches = values_for_day((string)($row['tutBatch'] ?? ''), (int)$day);
                foreach ($batches as $batch) {
                    if (!isset($tut_lookup[$batch])) continue;
                    $label = sprintf('tut - %s - %s - %s', $subject, $batch, $faculty);
                    foreach (resolve_slot_to_timeslots($slot, $timeslots) ?: [$slot] as $resolved_slot) {
                        add_entry($matrix, (int)$day, $resolved_slot, $label);
                    }
                }
            }
        }
        $stmt->close();
    }

    return $matrix;
}

function render_timetable_table(array $matrix, array $timeslots, array $day_order): void {
    $cell_text = [];
    $rowspan_tracker = [];
    foreach ($day_order as $dnum => $dname) {
        foreach ($timeslots as $ts) {
            $cell_text[$dnum][$ts] = empty($matrix[$dnum][$ts]) ? '' : implode(' | ', $matrix[$dnum][$ts]);
        }
    }
    ?>
    <div class="table-responsive">
        <table class="table table-bordered mb-0 timetable-table">
            <thead class="table-light">
                <tr>
                    <th style="width:150px">Time \ Day</th>
                    <?php foreach ($day_order as $dname): ?>
                        <th class="text-center"><?= htmlspecialchars($dname) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($timeslots as $ri => $ts): ?>
                    <tr>
                        <td class="fw-semibold align-middle"><?= htmlspecialchars($ts) ?></td>
                        <?php foreach ($day_order as $dnum => $dname):
                            if (isset($rowspan_tracker[$dnum][$ts]) && $rowspan_tracker[$dnum][$ts] > 0) {
                                $rowspan_tracker[$dnum][$ts]--;
                                continue;
                            }

                            $text = $cell_text[$dnum][$ts] ?? '';
                            if ($text === '') {
                                echo '<td class="empty-slot">&nbsp;</td>';
                                continue;
                            }

                            $rowspan = 1;
                            for ($k = $ri + 1; $k < count($timeslots); $k++) {
                                $next_ts = $timeslots[$k];
                                if (($cell_text[$dnum][$next_ts] ?? '') !== $text) break;
                                $rowspan++;
                            }

                            if ($rowspan > 1) {
                                for ($k = 1; $k < $rowspan; $k++) {
                                    $future_ts = $timeslots[$ri + $k];
                                    $rowspan_tracker[$dnum][$future_ts] = ($rowspan_tracker[$dnum][$future_ts] ?? 0) + 1;
                                }
                            }

                            $entries = array_map('htmlspecialchars', $matrix[$dnum][$ts] ?? []);
                            $rowspan_attr = $rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '';
                            echo '<td' . $rowspan_attr . '>' . implode('<br>', $entries) . '</td>';
                        endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$timetable_groups = [];
if ($selected_term !== '') {
    $stmt = $conn->prepare("SELECT sem, class, labBatch, tutBatch FROM students WHERE term = ? AND TRIM(class) <> '' GROUP BY sem, class, labBatch, tutBatch ORDER BY sem, class, labBatch, tutBatch");
    if ($stmt) {
        $stmt->bind_param('s', $selected_term);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sem = trim((string)$row['sem']);
            $class = trim((string)$row['class']);
            if ($sem === '' || $class === '') continue;
            $key = $sem . '|' . $class;
            if (!isset($timetable_groups[$key])) {
                $timetable_groups[$key] = [
                    'sem' => $sem,
                    'class' => $class,
                    'lab_batches' => [],
                    'tut_batches' => [],
                    'matrix' => [],
                ];
            }
            $lab_batch = trim((string)($row['labBatch'] ?? ''));
            $tut_batch = trim((string)($row['tutBatch'] ?? ''));
            if ($lab_batch !== '') $timetable_groups[$key]['lab_batches'][$lab_batch] = $lab_batch;
            if ($tut_batch !== '') $timetable_groups[$key]['tut_batches'][$tut_batch] = $tut_batch;
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT DISTINCT sem, TRIM(class) AS class FROM lecmapping WHERE term = ? AND TRIM(class) <> '' ORDER BY sem, class");
    if ($stmt) {
        $stmt->bind_param('s', $selected_term);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sem = trim((string)$row['sem']);
            $class = trim((string)$row['class']);
            if ($sem === '' || $class === '') continue;
            $key = $sem . '|' . $class;
            if (!isset($timetable_groups[$key])) {
                $timetable_groups[$key] = [
                    'sem' => $sem,
                    'class' => $class,
                    'lab_batches' => [],
                    'tut_batches' => [],
                    'matrix' => [],
                ];
            }
        }
        $stmt->close();
    }
}

foreach ($timetable_groups as &$group) {
    $group['lab_batches'] = array_values($group['lab_batches']);
    $group['tut_batches'] = array_values($group['tut_batches']);
    $group['matrix'] = build_timetable_matrix(
        $conn,
        $selected_term,
        $group['sem'],
        $group['class'],
        $group['lab_batches'],
        $group['tut_batches'],
        $timeslots,
        $day_order
    );
}
unset($group);

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
                <h1 class="app-page-title mb-0"><i class="bi bi-table me-2"></i>Timetable</h1>
                <form method="GET" action="timetable.php" class="d-flex align-items-center gap-2">
                    <label for="termSelect" class="form-label mb-0 fw-semibold">Term</label>
                    <select id="termSelect" name="term" class="form-control form-control-sm" onchange="this.form.submit()">
                        <?php foreach ($term_rows as $term): ?>
                            <option value="<?= htmlspecialchars($term) ?>" <?= $term === $selected_term ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <style>
                .empty-slot { background-color: #e6ffe6; }
                .timetable-table { font-size: 0.95rem; }
                .timetable-table td { vertical-align: middle; }
            </style>

            <?php if (empty($timetable_groups)): ?>
                <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No class timetable data found.</div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($timetable_groups as $group): ?>
                        <div class="col-12">
                            <div class="app-card shadow-sm">
                                <div class="app-card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                        <h4 class="mb-0">Sem <?= htmlspecialchars($group['sem']) ?> - Class <?= htmlspecialchars($group['class']) ?></h4>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if (!empty($group['lab_batches'])): ?>
                                                <span class="badge bg-primary-subtle text-dark border">Lab: <?= htmlspecialchars(implode(', ', $group['lab_batches'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($group['tut_batches'])): ?>
                                                <span class="badge bg-success-subtle text-dark border">Tutorial: <?= htmlspecialchars(implode(', ', $group['tut_batches'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php render_timetable_table($group['matrix'], $timeslots, $day_order); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include('footer.php'); ?>
</div>
</body>
</html>

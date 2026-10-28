<?php
include('dbconfig.php');

// ── Helpers (same matching rules as pendingAttendance.php / myAttendance.php) ──
if (!function_exists('attendance_norm_text')) {
    function attendance_norm_text($value) {
        $s = (string)$value;
        if ($s === '') return '';
        $s = strtr($s, [
            "\xE3\x80\x82" => '.', "\xEF\xBC\x9A" => ':', "\xEF\xBC\x8C" => ',',
            "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-', "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'", "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xC2\xA0" => ' ', "\xE3\x80\x80" => ' ', "\xE2\x80\x82" => ' ', "\xE2\x80\x83" => ' ',
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
        return trim($s, '-');
    }
    function attendance_norm_key($type, $term, $sem, $subject, $class_or_batch, $date, $time) {
        return strtolower(trim((string)$type)) . '|' . attendance_norm_text($term) . '|'
             . attendance_norm_text($sem) . '|' . attendance_norm_text($subject) . '|'
             . attendance_norm_text($class_or_batch) . '|' . attendance_norm_text($date) . '|'
             . attendance_norm_time($time);
    }
    function attendance_norm_loose($value) {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$value));
    }
    function attendance_loose_key($type, $term, $sem, $subject, $class_or_batch, $date, $time) {
        return attendance_norm_loose($type) . '|' . attendance_norm_loose($term) . '|'
             . attendance_norm_loose($sem) . '|' . attendance_norm_loose($subject) . '|'
             . attendance_norm_loose($class_or_batch) . '|' . attendance_norm_loose($date) . '|'
             . attendance_norm_loose($time);
    }
}
if (!function_exists('attendance_exception_key')) {
    function attendance_exception_key($mapping_type, $mapping_id, $date, $class_or_batch = '') {
        return strtolower(trim((string)$mapping_type)) . ':' . (int)$mapping_id . '|' . (string)$date
             . '|' . strtolower(trim((string)$class_or_batch));
    }
    function attendance_is_skipped(array $exceptions_set, $mapping_type, $mapping_id, $date, $class_or_batch = '') {
        return isset($exceptions_set[attendance_exception_key($mapping_type, $mapping_id, $date, '')])
            || isset($exceptions_set[attendance_exception_key($mapping_type, $mapping_id, $date, $class_or_batch)]);
    }
}

// ── Logged-in faculty ────────────────────────────────────────────────────────
$session_faculty_name = $_SESSION['Name'] ?? '';
$fac_id_stmt = $conn->prepare("SELECT id FROM faculty WHERE Name = ?");
$fac_id_stmt->bind_param('s', $session_faculty_name);
$fac_id_stmt->execute();
$fac_row = $fac_id_stmt->get_result()->fetch_assoc();
$fac_id_stmt->close();
$logged_faculty_id = $fac_row ? (string)$fac_row['id'] : '0';

// ── Terms this faculty has mappings in ───────────────────────────────────────
$available_terms = [];
$terms_stmt = $conn->prepare("SELECT term FROM lecmapping WHERE faculty = ? UNION SELECT term FROM labmapping WHERE faculty = ? UNION SELECT term FROM tutmapping WHERE faculty = ?");
$terms_stmt->bind_param('sss', $logged_faculty_id, $logged_faculty_id, $logged_faculty_id);
$terms_stmt->execute();
$terms_res = $terms_stmt->get_result();
while ($tr = $terms_res->fetch_assoc()) {
    $t = trim((string)$tr['term']);
    if ($t !== '') $available_terms[$t] = true;
}
$terms_stmt->close();
$available_terms = array_keys($available_terms);
usort($available_terms, fn($a, $b) => strnatcmp($b, $a));

$filter_term = trim((string)($_GET['term'] ?? ''));
if ($filter_term === '' && !empty($available_terms)) {
    $filter_term = $available_terms[0];
}

// ── Load this faculty's mappings for the term ────────────────────────────────
$mappings_rows = [];
$specs = [
    ['lecture',  "SELECT 'lecture' AS mapping_type, id, faculty, term, sem, subject, class AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM lecmapping WHERE faculty = ? AND term = ?"],
    ['lab',      "SELECT 'lab' AS mapping_type, id, faculty, term, sem, subject, batch AS class_or_batch, labNo, slot, start_date, end_date, repeat_days FROM labmapping WHERE faculty = ? AND term = ?"],
    ['tutorial', "SELECT 'tutorial' AS mapping_type, id, faculty, term, sem, subject, tutBatch AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM tutmapping WHERE faculty = ? AND term = ?"],
];
foreach ($specs as [$stype, $sql]) {
    $st = $conn->prepare($sql);
    if (!$st) continue;
    $st->bind_param('ss', $logged_faculty_id, $filter_term);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) $mappings_rows[] = $row;
    $st->close();
}

// ── Exceptions (cancelled slots) ─────────────────────────────────────────────
$exceptions_set = [];
$exc_col_res = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecmapping_exceptions' AND COLUMN_NAME = 'mapping_type' LIMIT 1");
$has_exc_type = ($exc_col_res instanceof mysqli_result) && $exc_col_res->num_rows > 0;
$ids_by_type = [];
foreach ($mappings_rows as $mr) {
    $ids_by_type[(string)$mr['mapping_type']][(int)$mr['id']] = true;
}
foreach ($ids_by_type as $exc_type => $exc_id_map) {
    $exc_ids = array_keys($exc_id_map);
    $ph = implode(',', array_fill(0, count($exc_ids), '?'));
    if ($has_exc_type) {
        $exc_stmt = $conn->prepare("SELECT mapping_id, date, class_or_batch FROM lecmapping_exceptions WHERE mapping_type = ? AND mapping_id IN ($ph)");
        if (!$exc_stmt) continue;
        $exc_stmt->bind_param('s' . str_repeat('i', count($exc_ids)), ...array_merge([$exc_type], $exc_ids));
    } else {
        if ($exc_type !== 'lecture') continue;
        $exc_stmt = $conn->prepare("SELECT mapping_id, date, '' AS class_or_batch FROM lecmapping_exceptions WHERE mapping_id IN ($ph)");
        if (!$exc_stmt) continue;
        $exc_stmt->bind_param(str_repeat('i', count($exc_ids)), ...$exc_ids);
    }
    $exc_stmt->execute();
    $exc_res = $exc_stmt->get_result();
    while ($er = $exc_res->fetch_assoc()) {
        $exceptions_set[attendance_exception_key($exc_type, $er['mapping_id'], $er['date'], $er['class_or_batch'] ?? '')] = true;
    }
    $exc_stmt->close();
}

// ── Expand mappings into slots (holidays kept and flagged) ───────────────────
$holiday_dates = attendance_holiday_dates($conn);
$slot_list = [];
$today = new DateTime('today');
$today_str = $today->format('Y-m-d');

foreach ($mappings_rows as $m) {
    $mapping_type = (string)$m['mapping_type'];
    $repeat_days = array_map('intval', explode(',', (string)$m['repeat_days']));
    $cur = new DateTime((string)$m['start_date']);
    $end = new DateTime((string)$m['end_date']);
    $end->modify('+1 day');

    $stored_slot  = (string)($m['slot'] ?? '');
    $stored_batch = (string)($m['class_or_batch'] ?? '');
    $stored_lab   = (string)($m['labNo'] ?? '');
    $parsed_slots   = ($stored_slot  !== '' && $stored_slot[0]  === '{') ? (json_decode($stored_slot, true)  ?: []) : null;
    $parsed_batches = ($stored_batch !== '' && $stored_batch[0] === '{') ? (json_decode($stored_batch, true) ?: []) : null;
    $parsed_labs    = ($stored_lab   !== '' && $stored_lab[0]   === '{') ? (json_decode($stored_lab, true)   ?: []) : null;

    $base = [
        'mapping_id' => (int)$m['id'], 'mapping_type' => $mapping_type,
        'term' => trim((string)$m['term']), 'sem' => (string)$m['sem'],
        'subject' => (string)$m['subject'],
    ];

    while ($cur < $end) {
        $dow = (int)$cur->format('w');
        if (in_array($dow, $repeat_days, true)) {
            $date_str = $cur->format('Y-m-d');
            $dow_str  = (string)$dow;
            $slot_value = $parsed_slots !== null
                ? (string)($parsed_slots[$dow] ?? $parsed_slots[$dow_str] ?? '')
                : $stored_slot;
            if ($slot_value === '') { $cur->modify('+1 day'); continue; }

            $entries = []; // [class_or_batch, lab_no]
            if ($mapping_type === 'lab' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                $labs_day = (array)($parsed_labs[$dow] ?? $parsed_labs[$dow_str] ?? []);
                foreach ((array)$parsed_batches[$dow] as $bi => $bv) {
                    $bl = (string)$bv;
                    $ll = (string)($labs_day[$bi] ?? '');
                    if ($bl === '' || $ll === '') continue;
                    $entries[] = [$bl, $ll];
                }
            } elseif ($mapping_type === 'tutorial' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                foreach ((array)$parsed_batches[$dow] as $bv) {
                    if ((string)$bv === '') continue;
                    $entries[] = [(string)$bv, ''];
                }
            } else {
                $entries[] = [$stored_batch, ''];
            }

            foreach ($entries as [$cb, $lab_no]) {
                $slot_list[] = $base + [
                    'date' => $date_str, 'class' => $cb, 'slot' => $slot_value, 'lab_no' => $lab_no,
                    'holiday' => isset($holiday_dates[$date_str]) ? $holiday_dates[$date_str] : null,
                    'skipped' => attendance_is_skipped($exceptions_set, $mapping_type, (int)$m['id'], $date_str, $cb),
                ];
            }
        }
        $cur->modify('+1 day');
    }
}

// ── Which slots were actually taken ──────────────────────────────────────────
$filled_lookup = [];
if (!empty($slot_list)) {
    $unique_sems = array_values(array_unique(array_column($slot_list, 'sem')));
    $sp = implode(',', array_fill(0, count($unique_sems), '?'));
    $types = 's' . str_repeat('s', count($unique_sems));
    foreach (['lecattendance|lecture|class', 'labattendance|lab|batch', 'tutattendance|tutorial|batch'] as $spec) {
        [$table, $type, $col] = explode('|', $spec);
        $att_stmt = $conn->prepare("SELECT date, time, term, sem, subject, {$col} FROM {$table} WHERE term = ? AND sem IN ($sp)");
        if (!$att_stmt) continue;
        $att_stmt->bind_param($types, ...array_merge([$filter_term], $unique_sems));
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $filled_lookup[attendance_norm_key($type, $ar['term'], $ar['sem'], $ar['subject'], $ar[$col], $ar['date'], $ar['time'])] = true;
            $filled_lookup[attendance_loose_key($type, $ar['term'], $ar['sem'], $ar['subject'], $ar[$col], $ar['date'], $ar['time'])] = true;
        }
        $att_stmt->close();
    }
}

// ── Classify slots and aggregate ─────────────────────────────────────────────
// Precedence: taken > holiday > cancelled > upcoming > absent
$type_labels = ['lecture' => 'Lecture', 'lab' => 'Lab', 'tutorial' => 'Tutorial'];
$zero = ['planned' => 0, 'taken' => 0, 'absent' => 0, 'holiday' => 0, 'cancelled' => 0, 'upcoming' => 0];
$rows = [];
$totals = [];
foreach ($type_labels as $k => $_) $totals[$k] = $zero;
$grand = $zero;
$absent_slots = [];

foreach ($slot_list as $s) {
    $filled = isset($filled_lookup[attendance_norm_key($s['mapping_type'], $s['term'], $s['sem'], $s['subject'], $s['class'], $s['date'], $s['slot'])])
           || isset($filled_lookup[attendance_loose_key($s['mapping_type'], $s['term'], $s['sem'], $s['subject'], $s['class'], $s['date'], $s['slot'])]);
    if ($filled)                     $status = 'taken';
    elseif ($s['holiday'] !== null)  $status = 'holiday';
    elseif ($s['skipped'])           $status = 'cancelled';
    elseif ($s['date'] > $today_str) $status = 'upcoming';
    else                             $status = 'absent';

    $key = $s['mapping_type'] . '|' . $s['sem'] . '|' . $s['subject'] . '|' . $s['class'];
    if (!isset($rows[$key])) {
        $rows[$key] = ['type' => $s['mapping_type'], 'sem' => $s['sem'], 'subject' => $s['subject'], 'class' => $s['class']] + $zero;
    }
    foreach ([&$rows[$key], &$totals[$s['mapping_type']], &$grand] as &$bucket) {
        $bucket['planned']++;
        $bucket[$status]++;
    }
    unset($bucket);

    if ($status === 'absent') $absent_slots[] = $s;
}

usort($rows, function ($a, $b) {
    $o = ['lecture' => 0, 'lab' => 1, 'tutorial' => 2];
    return [$o[$a['type']], $a['sem'], $a['subject'], $a['class']] <=> [$o[$b['type']], $b['sem'], $b['subject'], $b['class']];
});
usort($absent_slots, fn($a, $b) => strcmp($b['date'], $a['date']));
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<style>
    .plan-table th, .plan-table td { text-align: center; vertical-align: middle; white-space: nowrap; }
    .plan-table th:nth-child(-n+4), .plan-table td:nth-child(-n+4) { text-align: left; }
    .plan-table tfoot td { font-weight: 700; background: #f8fafc; }
    .num-absent { color: #b91c1c; font-weight: 700; }
    .num-taken { color: #047857; font-weight: 700; }
    .num-holiday { color: #b45309; font-weight: 700; }
    .type-pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 1rem; font-size: 0.72rem; font-weight: 700; }
    .type-pill.lecture { background: #dbeafe; color: #1d4ed8; }
    .type-pill.lab { background: #fee2e2; color: #b91c1c; }
    .type-pill.tutorial { background: #dcfce7; color: #15803d; }
</style>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-clipboard-data me-2"></i>My Planning</h1>

            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <form method="GET" action="myPlanning.php" class="row g-2 align-items-end">
                        <div class="col-8 col-md-3">
                            <label class="form-label form-label-sm mb-1">Term</label>
                            <select name="term" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($available_terms as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= $t === $filter_term ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4 col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Show</button>
                        </div>
                    </form>
                    <div class="text-muted small mt-2">
                        Planned = all scheduled slots from your mappings. Absent = past slots with no attendance entered
                        (excluding holidays and cancelled slots). Up to <?= $today->format('d M Y') ?>.
                    </div>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="alert alert-info">No mappings found for you<?= $filter_term !== '' ? ' in term ' . htmlspecialchars($filter_term) : '' ?>.</div>
            <?php else: ?>
                <div class="app-card shadow-sm mb-3">
                    <div class="app-card-body">
                        <h5 class="mb-3">Summary</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered plan-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th><th>Sem</th><th>Subject</th><th>Class / Batch</th>
                                        <th>Planned</th><th>Taken</th><th>Absent</th><th>Holiday</th><th>Cancelled</th><th>Upcoming</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td><span class="type-pill <?= $r['type'] ?>"><?= $type_labels[$r['type']] ?></span></td>
                                            <td><?= htmlspecialchars($r['sem']) ?></td>
                                            <td><?= htmlspecialchars($r['subject']) ?></td>
                                            <td><?= htmlspecialchars($r['class'] !== '' ? $r['class'] : '-') ?></td>
                                            <td><?= $r['planned'] ?></td>
                                            <td class="num-taken"><?= $r['taken'] ?></td>
                                            <td class="<?= $r['absent'] ? 'num-absent' : '' ?>"><?= $r['absent'] ?></td>
                                            <td class="<?= $r['holiday'] ? 'num-holiday' : '' ?>"><?= $r['holiday'] ?></td>
                                            <td><?= $r['cancelled'] ?></td>
                                            <td><?= $r['upcoming'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <?php foreach ($type_labels as $tk => $tl): if ($totals[$tk]['planned'] === 0) continue; $tt = $totals[$tk]; ?>
                                        <tr>
                                            <td colspan="4">Total <?= $tl ?></td>
                                            <td><?= $tt['planned'] ?></td><td><?= $tt['taken'] ?></td><td><?= $tt['absent'] ?></td>
                                            <td><?= $tt['holiday'] ?></td><td><?= $tt['cancelled'] ?></td><td><?= $tt['upcoming'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td colspan="4">Grand Total</td>
                                        <td><?= $grand['planned'] ?></td><td><?= $grand['taken'] ?></td><td><?= $grand['absent'] ?></td>
                                        <td><?= $grand['holiday'] ?></td><td><?= $grand['cancelled'] ?></td><td><?= $grand['upcoming'] ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="app-card shadow-sm mb-3">
                    <div class="app-card-body">
                        <h5 class="mb-3">Absent Slots <span class="badge bg-danger"><?= count($absent_slots) ?></span></h5>
                        <?php if (empty($absent_slots)): ?>
                            <div class="text-muted">No absent slots. All past slots have attendance entered.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered plan-table mb-0">
                                    <thead class="table-light">
                                        <tr><th>Date</th><th>Type</th><th>Sem</th><th>Subject</th><th>Class / Batch</th><th>Slot</th><th>Lab</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($absent_slots as $a): ?>
                                            <tr>
                                                <td><?= (new DateTime($a['date']))->format('d M Y (D)') ?></td>
                                                <td><span class="type-pill <?= $a['mapping_type'] ?>"><?= $type_labels[$a['mapping_type']] ?></span></td>
                                                <td><?= htmlspecialchars($a['sem']) ?></td>
                                                <td><?= htmlspecialchars($a['subject']) ?></td>
                                                <td><?= htmlspecialchars($a['class'] !== '' ? $a['class'] : '-') ?></td>
                                                <td><?= htmlspecialchars($a['slot']) ?></td>
                                                <td><?= htmlspecialchars($a['lab_no'] !== '' ? $a['lab_no'] : '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

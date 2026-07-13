<?php
include('dbconfig.php');

// Admin-only page
$current_username = trim((string)($_SESSION['username'] ?? ''));
if (strcasecmp($current_username, 'admin') !== 0) {
    header('Location: home.php');
    exit();
}

// ── Normalization helpers (same as myAttendance.php) ─────────────────────────
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

// ── Load all terms ───────────────────────────────────────────────────────────
$terms_result = $conn->query("SELECT DISTINCT term FROM lecmapping UNION SELECT DISTINCT term FROM labmapping UNION SELECT DISTINCT term FROM tutmapping ORDER BY term DESC");
$available_terms = [];
while ($tr = $terms_result->fetch_assoc()) {
    $t = trim((string)$tr['term']);
    if ($t !== '') $available_terms[] = $t;
}

$filter_term = trim((string)($_GET['term'] ?? ''));
if ($filter_term === '' && !empty($available_terms)) {
    $filter_term = $available_terms[0];
}

// ── Faculty name map ─────────────────────────────────────────────────────────
$faculty_map = [];
$fac_res = $conn->query("SELECT id, Name FROM faculty");
while ($frow = $fac_res->fetch_assoc()) {
    $faculty_map[(string)$frow['id']] = $frow['Name'];
}

// ── Load ALL mappings for the selected term ──────────────────────────────────
$mappings_rows = [];

$lec_stmt = $conn->prepare("SELECT 'lecture' AS mapping_type, id, faculty, term, sem, subject, class AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM lecmapping WHERE term = ?");
if ($lec_stmt) {
    $lec_stmt->bind_param('s', $filter_term);
    $lec_stmt->execute();
    $lec_res = $lec_stmt->get_result();
    while ($r = $lec_res->fetch_assoc()) $mappings_rows[] = $r;
    $lec_stmt->close();
}

$lab_stmt = $conn->prepare("SELECT 'lab' AS mapping_type, id, faculty, term, sem, subject, batch AS class_or_batch, labNo, slot, start_date, end_date, repeat_days FROM labmapping WHERE term = ?");
if ($lab_stmt) {
    $lab_stmt->bind_param('s', $filter_term);
    $lab_stmt->execute();
    $lab_res = $lab_stmt->get_result();
    while ($r = $lab_res->fetch_assoc()) $mappings_rows[] = $r;
    $lab_stmt->close();
}

$tut_stmt = $conn->prepare("SELECT 'tutorial' AS mapping_type, id, faculty, term, sem, subject, tutBatch AS class_or_batch, '' AS labNo, slot, start_date, end_date, repeat_days FROM tutmapping WHERE term = ?");
if ($tut_stmt) {
    $tut_stmt->bind_param('s', $filter_term);
    $tut_stmt->execute();
    $tut_res = $tut_stmt->get_result();
    while ($r = $tut_res->fetch_assoc()) $mappings_rows[] = $r;
    $tut_stmt->close();
}

// ── Load ALL exceptions ──────────────────────────────────────────────────────
$exceptions_set = [];
$lec_ids = array_column(array_filter($mappings_rows, fn($r) => ($r['mapping_type'] ?? '') === 'lecture'), 'id');
if (!empty($lec_ids)) {
    $ph = implode(',', array_fill(0, count($lec_ids), '?'));
    $exc_stmt = $conn->prepare("SELECT mapping_id, date FROM lecmapping_exceptions WHERE mapping_id IN ($ph)");
    if ($exc_stmt) {
        $exc_stmt->bind_param(str_repeat('i', count($lec_ids)), ...$lec_ids);
        $exc_stmt->execute();
        $exc_res = $exc_stmt->get_result();
        while ($er = $exc_res->fetch_assoc()) $exceptions_set['lecture:' . $er['mapping_id'] . '|' . $er['date']] = true;
        $exc_stmt->close();
    }
}

// ── Expand mappings into slots ───────────────────────────────────────────────
$slot_list = [];
$today = new DateTime('today');

foreach ($mappings_rows as $m) {
    $mapping_type = (string)($m['mapping_type'] ?? 'lecture');
    $repeat_days = array_map('intval', explode(',', (string)$m['repeat_days']));
    $cur = new DateTime((string)$m['start_date']);
    $end = new DateTime((string)$m['end_date']);
    if ($end > $today) $end = clone $today;
    if ($cur > $end) continue;
    $end->modify('+1 day');

    $stored_slot  = (string)($m['slot'] ?? '');
    $stored_batch = (string)($m['class_or_batch'] ?? '');
    $stored_lab   = (string)($m['labNo'] ?? '');
    $parsed_slots   = ($stored_slot  !== '' && $stored_slot[0]  === '{') ? (json_decode($stored_slot, true)  ?: []) : null;
    $parsed_batches = ($stored_batch !== '' && $stored_batch[0] === '{') ? (json_decode($stored_batch, true) ?: []) : null;
    $parsed_labs    = ($stored_lab   !== '' && $stored_lab[0]   === '{') ? (json_decode($stored_lab, true)   ?: []) : null;

    while ($cur < $end) {
        $dow = (int)$cur->format('w');
        if (in_array($dow, $repeat_days, true)) {
            $date_str = $cur->format('Y-m-d');
            $dow_str  = (string)$dow;

            $slot_value = $parsed_slots !== null
                ? (string)($parsed_slots[$dow] ?? $parsed_slots[$dow_str] ?? '')
                : $stored_slot;
            if ($slot_value === '') { $cur->modify('+1 day'); continue; }

            if ($mapping_type === 'lab' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                $batches_day = (array)($parsed_batches[$dow] ?? $parsed_batches[$dow_str] ?? []);
                $labs_day    = (array)($parsed_labs[$dow] ?? $parsed_labs[$dow_str] ?? []);
                foreach ($batches_day as $bi => $bv) {
                    $bl = (string)$bv;
                    $ll = (string)($labs_day[$bi] ?? '');
                    if ($bl === '' || $ll === '') continue;
                    $slot_list[] = [
                        'mapping_id' => (int)$m['id'], 'mapping_type' => $mapping_type,
                        'date' => $date_str, 'faculty' => (string)$m['faculty'],
                        'term' => trim((string)$m['term']), 'sem' => (string)$m['sem'],
                        'subject' => (string)$m['subject'], 'class' => $bl,
                        'slot' => $slot_value, 'lab_no' => $ll,
                        'skipped' => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                    ];
                }
            } elseif ($mapping_type === 'tutorial' && $parsed_batches !== null && is_array($parsed_batches[$dow] ?? null)) {
                foreach ((array)($parsed_batches[$dow] ?? $parsed_batches[$dow_str] ?? []) as $bv) {
                    $bl = (string)$bv;
                    if ($bl === '') continue;
                    $slot_list[] = [
                        'mapping_id' => (int)$m['id'], 'mapping_type' => $mapping_type,
                        'date' => $date_str, 'faculty' => (string)$m['faculty'],
                        'term' => trim((string)$m['term']), 'sem' => (string)$m['sem'],
                        'subject' => (string)$m['subject'], 'class' => $bl,
                        'slot' => $slot_value, 'lab_no' => '',
                        'skipped' => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                    ];
                }
            } else {
                $slot_list[] = [
                    'mapping_id' => (int)$m['id'], 'mapping_type' => $mapping_type,
                    'date' => $date_str, 'faculty' => (string)$m['faculty'],
                    'term' => trim((string)$m['term']), 'sem' => (string)$m['sem'],
                    'subject' => (string)$m['subject'], 'class' => (string)$stored_batch,
                    'slot' => $slot_value, 'lab_no' => '',
                    'skipped' => isset($exceptions_set[$mapping_type . ':' . (int)$m['id'] . '|' . $date_str]),
                ];
            }
        }
        $cur->modify('+1 day');
    }
}

// ── Build filled lookup ──────────────────────────────────────────────────────
$filled_lookup = [];
if (!empty($slot_list)) {
    $unique_terms = array_values(array_unique(array_column($slot_list, 'term')));
    $unique_sems  = array_values(array_unique(array_column($slot_list, 'sem')));

    if (!empty($unique_terms) && !empty($unique_sems)) {
        $tp = implode(',', array_fill(0, count($unique_terms), '?'));
        $sp = implode(',', array_fill(0, count($unique_sems),  '?'));
        $types = str_repeat('s', count($unique_terms) + count($unique_sems));
        $params = array_merge($unique_terms, $unique_sems);

        foreach (['lecattendance|lecture|class', 'labattendance|lab|batch', 'tutattendance|tutorial|batch'] as $spec) {
            [$table, $type, $col] = explode('|', $spec);
            $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, {$col} FROM {$table} WHERE term IN ($tp) AND sem IN ($sp)");
            if (!$att_stmt) continue;
            $att_stmt->bind_param($types, ...$params);
            $att_stmt->execute();
            $att_res = $att_stmt->get_result();
            while ($ar = $att_res->fetch_assoc()) {
                $ks = attendance_norm_key($type, $ar['term'], $ar['sem'], $ar['subject'], $ar[$col], $ar['date'], $ar['time']);
                $kl = attendance_loose_key($type, $ar['term'], $ar['sem'], $ar['subject'], $ar[$col], $ar['date'], $ar['time']);
                $filled_lookup[$ks] = (int)$ar['id'];
                $filled_lookup[$kl] = (int)$ar['id'];
            }
            $att_stmt->close();
        }
    }
}

// ── Annotate slots ───────────────────────────────────────────────────────────
foreach ($slot_list as &$slot) {
    $ks = attendance_norm_key($slot['mapping_type'], $slot['term'], $slot['sem'], $slot['subject'], $slot['class'], $slot['date'], $slot['slot']);
    $kl = attendance_loose_key($slot['mapping_type'], $slot['term'], $slot['sem'], $slot['subject'], $slot['class'], $slot['date'], $slot['slot']);
    $matched = isset($filled_lookup[$ks]) ? $ks : (isset($filled_lookup[$kl]) ? $kl : null);
    $slot['filled'] = $matched !== null;
}
unset($slot);

// Sort: date desc, then faculty, then type
usort($slot_list, function ($a, $b) {
    $d = strcmp($b['date'], $a['date']);
    if ($d !== 0) return $d;
    $f = strcasecmp($faculty_map[$a['faculty']] ?? $a['faculty'], $faculty_map[$b['faculty']] ?? $b['faculty']);
    if ($f !== 0) return $f;
    return strcmp($a['mapping_type'], $b['mapping_type']);
});

// Filter: pending only (not filled, not skipped)
$pending_slots = array_values(array_filter($slot_list, fn($s) => !$s['filled'] && !$s['skipped']));

// Group by faculty
$grouped = [];
foreach ($pending_slots as $slot) {
    $fac_id = $slot['faculty'];
    if (!isset($grouped[$fac_id])) $grouped[$fac_id] = [];
    $grouped[$fac_id][] = $slot;
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
            <h1 class="app-page-title"><i class="bi bi-hourglass-split me-2"></i>Pending Attendance</h1>

            <!-- Filter -->
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <form method="GET" action="pendingAttendance.php" class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label form-label-sm mb-1">Term</label>
                            <select name="term" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($available_terms as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= $t === $filter_term ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-search me-1"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Summary -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body text-center py-3">
                            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;font-weight:600;">Total Pending</div>
                            <div style="font-size:1.8rem;font-weight:700;color:#dc3545;"><?= count($pending_slots) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body text-center py-3">
                            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;font-weight:600;">Faculties</div>
                            <div style="font-size:1.8rem;font-weight:700;color:#0d6efd;"><?= count($grouped) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body text-center py-3">
                            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;font-weight:600;">Term</div>
                            <div style="font-size:1.2rem;font-weight:700;"><?= htmlspecialchars($filter_term) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body text-center py-3">
                            <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;font-weight:600;">Up To</div>
                            <div style="font-size:1.2rem;font-weight:700;"><?= $today->format('Y-m-d') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($pending_slots)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-1"></i>No pending attendance slots for term <?= htmlspecialchars($filter_term) ?> up to today.
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $fac_id => $fac_slots):
                    $fac_name = $faculty_map[$fac_id] ?? $fac_id;
                ?>
                    <div class="app-card shadow-sm mb-3">
                        <div class="app-card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0">
                                    <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($fac_name) ?>
                                    <span class="text-muted fw-normal" style="font-size:0.85rem;">(ID: <?= htmlspecialchars($fac_id) ?>)</span>
                                </h5>
                                <span class="badge bg-danger" style="font-size:0.9rem;"><?= count($fac_slots) ?> pending</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Date</th>
                                            <th>Slot</th>
                                            <th>Type</th>
                                            <th>Subject</th>
                                            <th>Class / Batch</th>
                                            <th>Sem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($fac_slots as $slot):
                                            $type_colors = ['lecture' => 'primary', 'lab' => 'danger', 'tutorial' => 'success'];
                                            $tc = $type_colors[$slot['mapping_type']] ?? 'secondary';
                                        ?>
                                            <tr>
                                                <td><?= $i++ ?></td>
                                                <td><strong><?= htmlspecialchars($slot['date']) ?></strong></td>
                                                <td><?= htmlspecialchars($slot['slot']) ?></td>
                                                <td><span class="badge bg-<?= $tc ?>"><?= ucfirst(htmlspecialchars($slot['mapping_type'])) ?></span></td>
                                                <td><?= htmlspecialchars($slot['subject']) ?></td>
                                                <td><?= htmlspecialchars($slot['class']) ?></td>
                                                <td><?= htmlspecialchars($slot['sem']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

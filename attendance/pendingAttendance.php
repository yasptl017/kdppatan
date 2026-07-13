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

// Stats per type
$type_counts = ['lecture' => 0, 'lab' => 0, 'tutorial' => 0];
foreach ($pending_slots as $slot) {
    $type_counts[$slot['mapping_type']] = ($type_counts[$slot['mapping_type']] ?? 0) + 1;
}

$dow_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<style>
    .stat-card {
        border: none;
        border-radius: 0.75rem;
        overflow: hidden;
        transition: transform 0.15s;
    }
    .stat-card:hover { transform: translateY(-2px); }
    .stat-card-inner {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.1rem 1.2rem;
        background: #fff;
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 0.65rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .stat-info { flex: 1; }
    .stat-label {
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #64748b;
        margin-bottom: 0.15rem;
    }
    .stat-value {
        font-size: 1.55rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .stat-card .stat-bottom {
        height: 4px;
    }

    .faculty-card {
        border: 1px solid #e2e8f0;
        border-left: 4px solid #6366f1;
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .faculty-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.85rem 1.2rem;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom: 1px solid #e2e8f0;
    }
    .faculty-name {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }
    .faculty-id {
        font-size: 0.78rem;
        color: #94a3b8;
        margin-left: 0.4rem;
    }
    .pending-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.7rem;
        border-radius: 2rem;
        font-size: 0.78rem;
        font-weight: 700;
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }
    .pending-badge i { font-size: 0.7rem; }

    .slot-table { margin-bottom: 0; }
    .slot-table thead th {
        background: #f8fafc;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        padding: 0.6rem 0.8rem;
        border-bottom: 2px solid #e2e8f0;
    }
    .slot-table tbody td {
        padding: 0.55rem 0.8rem;
        font-size: 0.85rem;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
    .slot-table tbody tr:last-child td { border-bottom: none; }
    .slot-table tbody tr:hover { background: #f8fafc; }

    .date-cell {
        font-weight: 700;
        color: #1e293b;
    }
    .dow-label {
        display: inline-block;
        font-size: 0.68rem;
        font-weight: 600;
        color: #6366f1;
        background: #eef2ff;
        padding: 0.1rem 0.35rem;
        border-radius: 0.25rem;
        margin-left: 0.3rem;
    }
    .type-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.55rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .type-lecture { background: #eff6ff; color: #2563eb; }
    .type-lab     { background: #fef2f2; color: #dc2626; }
    .type-tutorial { background: #f0fdf4; color: #16a34a; }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
    }
    .empty-state .icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: #f0fdf4;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        font-size: 2rem;
        color: #16a34a;
    }
    .empty-state h5 { font-weight: 700; color: #1e293b; }
    .empty-state p { color: #64748b; font-size: 0.9rem; }

    /* ── Print ──────────────────────────────────────────────── */
    .print-header { display: none; }
    @media print {
        .no-print,
        .app-header, .app-sidepanel, .sidepanel-drop, #sidepanel-drop,
        .app-sidepanel-footer,
        .stat-card:hover { transform: none; }
        .filter-card { display: none !important; }

        body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .app-wrapper { padding: 0; margin: 0; }
        .app-content { padding: 0 !important; }
        .container-xl { max-width: 100%; padding: 0 0.5rem; }

        .print-header {
            display: block;
            text-align: center;
            padding: 0.8rem 0 0.6rem;
            border-bottom: 2px solid #1e293b;
            margin-bottom: 1rem;
        }
        .print-header h2 {
            font-size: 1.25rem;
            font-weight: 800;
            margin: 0;
            color: #1e293b;
        }
        .print-header .sub {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 0.2rem;
        }

        .stat-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; break-inside: avoid; }
        .stat-card .stat-bottom { height: 3px; }
        .stat-icon { width: 36px; height: 36px; font-size: 1rem; }
        .stat-value { font-size: 1.2rem; }

        .faculty-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #cbd5e1 !important; margin-bottom: 0.8rem; }
        .faculty-header { background: #f1f5f9 !important; padding: 0.6rem 1rem; }
        .slot-table thead th { background: #f1f5f9 !important; padding: 0.4rem 0.6rem; font-size: 0.68rem; }
        .slot-table tbody td { padding: 0.35rem 0.6rem; font-size: 0.8rem; }
        .dow-label { background: #e2e8f0 !important; }
        .type-lecture { background: #dbeafe !important; }
        .type-lab     { background: #fee2e2 !important; }
        .type-tutorial { background: #dcfce7 !important; }
        .pending-badge { background: #fee2e2 !important; border-color: #fca5a5 !important; }

        .filter-card { display: none !important; }

        @page {
            size: A4;
            margin: 1cm;
        }
    }
</style>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">

            <!-- Print-only header -->
            <div class="print-header">
                <h2><i class="bi bi-hourglass-split me-1"></i>Pending Attendance Report</h2>
                <div class="sub">Term: <?= htmlspecialchars($filter_term) ?> &middot; Up to: <?= $today->format('d M Y') ?> &middot; Generated: <?= (new DateTime())->format('d M Y h:i A') ?></div>
            </div>

            <!-- Title row -->
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <h1 class="app-page-title mb-0"><i class="bi bi-hourglass-split me-2"></i>Pending Attendance</h1>
                <?php if (!empty($pending_slots)): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print();">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                <?php endif; ?>
            </div>

            <!-- Filter -->
            <div class="app-card shadow-sm mb-3 filter-card no-print">
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

            <!-- Summary stat cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card shadow-sm">
                        <div class="stat-card-inner">
                            <div class="stat-icon" style="background:#fef2f2;color:#dc2626;">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Pending</div>
                                <div class="stat-value" style="color:#dc2626;"><?= count($pending_slots) ?></div>
                            </div>
                        </div>
                        <div class="stat-bottom" style="background: linear-gradient(90deg, #dc2626, #f87171);"></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card shadow-sm">
                        <div class="stat-card-inner">
                            <div class="stat-icon" style="background:#eff6ff;color:#2563eb;">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Faculties</div>
                                <div class="stat-value" style="color:#2563eb;"><?= count($grouped) ?></div>
                            </div>
                        </div>
                        <div class="stat-bottom" style="background: linear-gradient(90deg, #2563eb, #60a5fa);"></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card shadow-sm">
                        <div class="stat-card-inner">
                            <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;">
                                <i class="bi bi-calendar3"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Term</div>
                                <div class="stat-value" style="color:#16a34a;font-size:1.1rem;"><?= htmlspecialchars($filter_term) ?></div>
                            </div>
                        </div>
                        <div class="stat-bottom" style="background: linear-gradient(90deg, #16a34a, #4ade80);"></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card shadow-sm">
                        <div class="stat-card-inner">
                            <div class="stat-icon" style="background:#fefce8;color:#ca8a04;">
                                <i class="bi bi-calendar-event"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Up To</div>
                                <div class="stat-value" style="color:#ca8a04;font-size:1.1rem;"><?= $today->format('d M Y') ?></div>
                            </div>
                        </div>
                        <div class="stat-bottom" style="background: linear-gradient(90deg, #ca8a04, #facc15);"></div>
                    </div>
                </div>
            </div>

            <!-- Type breakdown -->
            <?php if (!empty($pending_slots)): ?>
            <div class="row g-2 mb-4">
                <div class="col-12 col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#eff6ff;">
                        <span class="type-pill type-lecture"><i class="bi bi-journal-text me-1"></i>Lecture</span>
                        <strong style="color:#2563eb;font-size:1.1rem;"><?= $type_counts['lecture'] ?></strong>
                        <span class="text-muted" style="font-size:0.78rem;">pending</span>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#fef2f2;">
                        <span class="type-pill type-lab"><i class="bi bi-camera-video me-1"></i>Lab</span>
                        <strong style="color:#dc2626;font-size:1.1rem;"><?= $type_counts['lab'] ?></strong>
                        <span class="text-muted" style="font-size:0.78rem;">pending</span>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f0fdf4;">
                        <span class="type-pill type-tutorial"><i class="bi bi-book me-1"></i>Tutorial</span>
                        <strong style="color:#16a34a;font-size:1.1rem;"><?= $type_counts['tutorial'] ?></strong>
                        <span class="text-muted" style="font-size:0.78rem;">pending</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($pending_slots)): ?>
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-check-circle"></i></div>
                    <h5>All Clear!</h5>
                    <p>No pending attendance slots for term <strong><?= htmlspecialchars($filter_term) ?></strong> up to today.</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $fac_id => $fac_slots):
                    $fac_name = $faculty_map[$fac_id] ?? $fac_id;
                ?>
                    <div class="faculty-card shadow-sm mb-3">
                        <div class="faculty-header">
                            <div>
                                <span class="faculty-name"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($fac_name) ?></span>
                                <span class="faculty-id">ID: <?= htmlspecialchars($fac_id) ?></span>
                            </div>
                            <span class="pending-badge">
                                <i class="bi bi-hourglass-split"></i>
                                <?= count($fac_slots) ?> pending
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="slot-table table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
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
                                        $dow_idx = (int)(new DateTime($slot['date']))->format('w');
                                        $tc = $slot['mapping_type'];
                                    ?>
                                        <tr>
                                            <td class="text-muted" style="font-size:0.78rem;"><?= $i++ ?></td>
                                            <td>
                                                <span class="date-cell"><?= date('d M Y', strtotime($slot['date'])) ?></span>
                                                <span class="dow-label"><?= $dow_names[$dow_idx] ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($slot['slot']) ?></td>
                                            <td>
                                                <span class="type-pill type-<?= $tc ?>">
                                                    <?php if ($tc === 'lecture'): ?><i class="bi bi-journal-text"></i>
                                                    <?php elseif ($tc === 'lab'): ?><i class="bi bi-camera-video"></i>
                                                    <?php else: ?><i class="bi bi-book"></i>
                                                    <?php endif; ?>
                                                    <?= ucfirst($tc) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($slot['subject']) ?></td>
                                            <td><?= htmlspecialchars($slot['class']) ?></td>
                                            <td><?= htmlspecialchars($slot['sem']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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

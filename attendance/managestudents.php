<?php
include 'dbconfig.php'; // Include database connection
require_once __DIR__ . '/studentreport.config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Stream the student list as an .xlsx download.
 *
 * The rows passed in are exactly the rows the table renders, in the same order,
 * so the file always matches the list that was on screen when Export was clicked.
 *
 * @param array $rows          Student rows straight from the filtered query.
 * @param array $filter_labels Human-readable list of the filters in force.
 * @param array $filters       Raw filter values, used to name the file.
 */
function download_students_excel(array $rows, array $filter_labels, array $filters)
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Students');

    $lastCol = 'I';

    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', 'Student List');
    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', empty($filter_labels)
        ? 'All Students'
        : 'Filters: ' . implode(', ', $filter_labels));
    $sheet->mergeCells("A3:{$lastCol}3");
    $sheet->setCellValue('A3', 'Total: ' . count($rows) . ' student(s)    Generated: ' . date('d-m-Y H:i'));

    $headerRow = 5;
    $headings = ['ID', 'Enrollment', 'Name', 'Sem', 'Class', 'Lab Batch', 'Tut Batch', 'Term', 'Status'];
    $col = 'A';
    foreach ($headings as $heading) {
        $sheet->setCellValue($col . $headerRow, $heading);
        $col++;
    }

    $rowNum = $headerRow + 1;
    foreach ($rows as $row) {
        $sheet->setCellValue("A{$rowNum}", (int)$row['id']);
        // Enrollment numbers are long digit strings — force text so Excel keeps
        // leading zeros and never switches to scientific notation.
        $sheet->setCellValueExplicit("B{$rowNum}", (string)$row['enrollmentNo'], DataType::TYPE_STRING);
        $sheet->setCellValue("C{$rowNum}", (string)$row['name']);
        $sheet->setCellValue("D{$rowNum}", (string)$row['sem']);
        $sheet->setCellValue("E{$rowNum}", (string)$row['class']);
        $sheet->setCellValue("F{$rowNum}", (string)$row['labBatch']);
        $sheet->setCellValue("G{$rowNum}", (string)$row['tutBatch']);
        $sheet->setCellValue("H{$rowNum}", (string)$row['term']);
        $sheet->setCellValue("I{$rowNum}", ((int)$row['status'] === 1) ? 'Active' : 'Disabled');
        $rowNum++;
    }

    $lastDataRow = max($headerRow, $rowNum - 1);

    $sheet->getStyle('A1:A3')->getFont()->setBold(true);
    $sheet->getStyle('A1')->getFont()->setSize(14);
    $sheet->getStyle("A1:{$lastCol}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A1:{$lastCol}3")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true);
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastDataRow}")->getBorders()
        ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastDataRow}")->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("B{$headerRow}:B{$lastDataRow}")->getNumberFormat()->setFormatCode('@');
    $sheet->getStyle("A{$headerRow}:B{$lastDataRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("D{$headerRow}:{$lastCol}{$lastDataRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $widths = ['A' => 8, 'B' => 20, 'C' => 32, 'D' => 8, 'E' => 8, 'F' => 11, 'G' => 11, 'H' => 12, 'I' => 11];
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
    $sheet->freezePane('A' . ($headerRow + 1));

    $name_parts = ['students'];
    if ($filters['term'] !== '')    { $name_parts[] = 'term' . $filters['term']; }
    if ($filters['sem'] !== '')     { $name_parts[] = 'sem' . $filters['sem']; }
    if ($filters['class'] !== '')   { $name_parts[] = 'class' . $filters['class']; }
    if ($filters['lab'] !== '')     { $name_parts[] = 'lab' . $filters['lab']; }
    if ($filters['tut'] !== '')     { $name_parts[] = 'tut' . $filters['tut']; }
    if ($filters['status'] === '1') { $name_parts[] = 'active'; }
    if ($filters['status'] === '0') { $name_parts[] = 'disabled'; }
    $filename = implode('_', $name_parts) . '_' . date('Y-m-d') . '.xlsx';
    $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// optional message from add/update/delete redirects
$msg = '';
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

$bulk_error = '';
$bulk_success = '';

// Add Student (Form handling)
if (isset($_POST['add_student'])) {
    $term = $_POST['term'];
    $enrollmentNo = $_POST['enrollmentNo'];
    $name = $_POST['name'];
    $sem = $_POST['sem'];
    $class = $_POST['class'];
    $labBatch = $_POST['labBatch'];
    $tutBatch = $_POST['tutBatch'];
    $status = 1; // Default status as active

    $stmt = $conn->prepare("INSERT INTO students (term, enrollmentNo, name, sem, class, labBatch, tutBatch, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssi", $term, $enrollmentNo, $name, $sem, $class, $labBatch, $tutBatch, $status);
    $stmt->execute();
    $stmt->close();
}

// Toggle Student Status
if (isset($_GET['toggle_status_id'])) {
    $id = $_GET['toggle_status_id'];
    $result = $conn->query("SELECT status FROM students WHERE id = $id");
    $row = $result->fetch_assoc();
    $new_status = ($row['status'] == 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE students SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    $stmt->execute();
    $stmt->close();
}

// ─── Filters ──────────────────────────────────────────────────────────────
$search        = isset($_GET['search'])        ? trim((string)$_GET['search'])        : '';
$filter_term   = isset($_GET['filter_term'])   ? trim((string)$_GET['filter_term'])   : '';
$filter_sem    = isset($_GET['filter_sem'])    ? trim((string)$_GET['filter_sem'])    : '';
$filter_class  = isset($_GET['filter_class'])  ? trim((string)$_GET['filter_class'])  : '';
$filter_lab    = isset($_GET['filter_lab'])    ? trim((string)$_GET['filter_lab'])    : '';
$filter_tut    = isset($_GET['filter_tut'])    ? trim((string)$_GET['filter_tut'])    : '';
$filter_status = isset($_GET['filter_status']) ? trim((string)$_GET['filter_status']) : '';
$export        = strtolower(trim((string)($_GET['export'] ?? '')));

// Distinct option lists for filter dropdowns (so the form shows only real values
// present in the students table).
$distinct_terms  = [];
$distinct_sems   = [];
$distinct_class  = [];
$distinct_lab    = [];
$distinct_tut    = [];

$opt_res = $conn->query("SELECT DISTINCT term  FROM students WHERE term  IS NOT NULL AND term  <> '' ORDER BY term ASC");
if ($opt_res) { while ($r = $opt_res->fetch_assoc()) { $distinct_terms[] = $r['term']; } }
$opt_res = $conn->query("SELECT DISTINCT sem   FROM students WHERE sem   IS NOT NULL AND sem   <> '' ORDER BY sem+0 ASC, sem ASC");
if ($opt_res) { while ($r = $opt_res->fetch_assoc()) { $distinct_sems[] = $r['sem']; } }
$opt_res = $conn->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class <> '' ORDER BY class ASC");
if ($opt_res) { while ($r = $opt_res->fetch_assoc()) { $distinct_class[] = $r['class']; } }
$opt_res = $conn->query("SELECT DISTINCT labBatch FROM students WHERE labBatch IS NOT NULL AND labBatch <> '' ORDER BY labBatch ASC");
if ($opt_res) { while ($r = $opt_res->fetch_assoc()) { $distinct_lab[] = $r['labBatch']; } }
$opt_res = $conn->query("SELECT DISTINCT tutBatch FROM students WHERE tutBatch IS NOT NULL AND tutBatch <> '' ORDER BY tutBatch ASC");
if ($opt_res) { while ($r = $opt_res->fetch_assoc()) { $distinct_tut[] = $r['tutBatch']; } }

// Build WHERE clause from active filters
$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[] = "(enrollmentNo LIKE ? OR name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($filter_term !== '') {
    $where[] = "term = ?";
    $params[] = $filter_term;
    $types .= 's';
}
if ($filter_sem !== '') {
    $where[] = "sem = ?";
    $params[] = $filter_sem;
    $types .= 's';
}
if ($filter_class !== '') {
    $where[] = "class = ?";
    $params[] = $filter_class;
    $types .= 's';
}
if ($filter_lab !== '') {
    $where[] = "labBatch = ?";
    $params[] = $filter_lab;
    $types .= 's';
}
if ($filter_tut !== '') {
    $where[] = "tutBatch = ?";
    $params[] = $filter_tut;
    $types .= 's';
}
if ($filter_status === '1' || $filter_status === '0') {
    $where[] = "status = ?";
    $params[] = (int)$filter_status;
    $types .= 'i';
}

$where_sql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

// Whether any filter narrows the set. Computed here (before the delete handler)
// because the delete guard depends on it; re-used for the UI further down.
$has_active_filter_precheck = !empty($where);

// ─── Bulk delete (filtered) ───────────────────────────────────────────────────
// Deletes exactly the set the current filters describe — the same $where_sql
// that drives the table below, so what is listed is what is removed.
//
// Guards, in order:
//   1. At least one filter must be active. Deleting the entire students table
//      by submitting an unfiltered form is never allowed.
//   2. The master password (the same one that unlocks the student detailed
//      report) must be supplied and correct.
//   3. The filter signature posted with the form must match the filters that
//      are active now, so a stale form cannot delete a different set than the
//      one the user was looking at when they clicked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_students'])) {
    $posted_signature = (string)($_POST['filter_signature'] ?? '');
    $current_signature = implode('|', [
        $search, $filter_term, $filter_sem, $filter_class,
        $filter_lab, $filter_tut, $filter_status,
    ]);
    $bulk_password = (string)($_POST['bulk_master_password'] ?? '');

    if (!$has_active_filter_precheck) {
        $bulk_error = 'Select at least one filter before deleting. Deleting all students at once is not allowed.';
    } elseif ($posted_signature !== $current_signature) {
        $bulk_error = 'The filters changed since this form was loaded. Please review the list and try again.';
    } elseif ($bulk_password === '') {
        $bulk_error = 'Enter the master password to confirm deletion.';
    } elseif (!attendance_master_password_verify($bulk_password)) {
        $bulk_error = 'Incorrect master password. No students were deleted.';
    } else {
        $del_sql = "DELETE FROM students" . $where_sql;
        $del_stmt = $conn->prepare($del_sql);
        if ($del_stmt) {
            if (!empty($params)) {
                $del_stmt->bind_param($types, ...$params);
            }
            if ($del_stmt->execute()) {
                $deleted_count = $del_stmt->affected_rows;
                $bulk_success = $deleted_count . ' student record(s) deleted.';
            } else {
                $bulk_error = 'Could not delete the selected students. Please try again.';
            }
            $del_stmt->close();
        } else {
            $bulk_error = 'Could not prepare the delete request. Please try again.';
        }
    }
}

$sql = "SELECT * FROM students" . $where_sql . " ORDER BY id ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Count summary for the active filter
$total_filtered = $result ? $result->num_rows : 0;
$total_all      = 0;
$cnt = $conn->query("SELECT COUNT(*) AS c FROM students");
if ($cnt && $crow = $cnt->fetch_assoc()) { $total_all = (int)$crow['c']; }

$has_active_filter = $search !== '' || $filter_term !== '' || $filter_sem !== ''
                  || $filter_class !== '' || $filter_lab !== '' || $filter_tut !== ''
                  || $filter_status !== '';

// Human-readable list of the filters in force, so the bulk-delete confirmation
// spells out exactly which set is about to be removed.
$active_filter_labels = [];
if ($search !== '')        { $active_filter_labels[] = 'Search "' . $search . '"'; }
if ($filter_term !== '')   { $active_filter_labels[] = 'Term ' . $filter_term; }
if ($filter_sem !== '')    { $active_filter_labels[] = 'Sem ' . $filter_sem; }
if ($filter_class !== '')  { $active_filter_labels[] = 'Class ' . $filter_class; }
if ($filter_lab !== '')    { $active_filter_labels[] = 'Lab ' . $filter_lab; }
if ($filter_tut !== '')    { $active_filter_labels[] = 'Tut ' . $filter_tut; }
if ($filter_status === '1') { $active_filter_labels[] = 'Active only'; }
if ($filter_status === '0') { $active_filter_labels[] = 'Disabled only'; }

// Count of active filters (for badge on Clear Filters button)
$active_filter_count = 0;
if ($search !== '')        { $active_filter_count++; }
if ($filter_term !== '')   { $active_filter_count++; }
if ($filter_sem !== '')    { $active_filter_count++; }
if ($filter_class !== '')  { $active_filter_count++; }
if ($filter_lab !== '')    { $active_filter_count++; }
if ($filter_tut !== '')    { $active_filter_count++; }
if ($filter_status !== '') { $active_filter_count++; }

// Query string that carries only the filters into the export link. Built from
// the parsed values rather than $_GET so stray parameters (msg, toggle_status_id)
// are never replayed by clicking Export.
$filter_query = [];
if ($search !== '')        { $filter_query['search']        = $search; }
if ($filter_term !== '')   { $filter_query['filter_term']   = $filter_term; }
if ($filter_sem !== '')    { $filter_query['filter_sem']    = $filter_sem; }
if ($filter_class !== '')  { $filter_query['filter_class']  = $filter_class; }
if ($filter_lab !== '')    { $filter_query['filter_lab']    = $filter_lab; }
if ($filter_tut !== '')    { $filter_query['filter_tut']    = $filter_tut; }
if ($filter_status !== '') { $filter_query['filter_status'] = $filter_status; }
$export_url = 'managestudents.php?' . http_build_query($filter_query + ['export' => 'excel']);

// ─── Excel export (filtered) ─────────────────────────────────────────────────
// Streams the same rows the table renders below, in the same order, so the
// download matches the list on screen. Runs before any HTML is emitted.
if ($export === 'excel' && $result && $total_filtered > 0) {
    $export_rows = [];
    while ($export_row = $result->fetch_assoc()) {
        $export_rows[] = $export_row;
    }
    download_students_excel($export_rows, $active_filter_labels, [
        'term'   => $filter_term,
        'sem'    => $filter_sem,
        'class'  => $filter_class,
        'lab'    => $filter_lab,
        'tut'    => $filter_tut,
        'status' => $filter_status,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include 'head.php'; ?>
<!-- Include DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

<body class="app">
<?php include 'header.php'; ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-people me-2"></i>Manage Students</h1>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-info py-2 mb-3"><?= $msg; ?></div>
            <?php endif; ?>

            <!-- Add Student Form -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Add Student</h4>
                            <form method="POST" action="managestudents.php">
                                <div class="row g-3 mb-3">
                                    <div class="col-6 col-md-2">
                                        <label class="form-label">Term</label>
                                        <input type="text" name="term" class="form-control" placeholder="Term" required>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Enrollment No</label>
                                        <input type="text" name="enrollmentNo" class="form-control" placeholder="Enrollment No" required>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Name</label>
                                        <input type="text" name="name" class="form-control" placeholder="Full name" required>
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Sem</label>
                                        <input type="text" name="sem" class="form-control" placeholder="Sem" required>
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Class</label>
                                        <input type="text" name="class" class="form-control" placeholder="A-D" required>
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Lab</label>
                                        <input type="text" name="labBatch" class="form-control" placeholder="Batch" required>
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Tut</label>
                                        <input type="text" name="tutBatch" class="form-control" placeholder="Batch" required>
                                    </div>
                                </div>
                                <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student List -->
            <div class="row">
                <div class="col-12">
                    <div class="app-card app-card-body shadow-sm">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <h4 class="mb-0">Student List</h4>
                            <div class="text-muted small">
                                <?php if ($has_active_filter): ?>
                                    Showing <strong><?= $total_filtered; ?></strong> of <?= $total_all; ?> student(s)
                                <?php else: ?>
                                    Total <strong><?= $total_all; ?></strong> student(s)
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Filters -->
                        <form method="GET" action="managestudents.php" id="studentsFilterForm" class="students-filter-bar mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-md-2 col-lg-2">
                                    <label class="form-label small text-muted mb-1">Term</label>
                                    <select name="filter_term" class="form-select form-select-sm">
                                        <option value="">All Terms</option>
                                        <?php foreach ($distinct_terms as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt); ?>" <?= $filter_term === (string)$opt ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2 col-lg-1">
                                    <label class="form-label small text-muted mb-1">Sem</label>
                                    <select name="filter_sem" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <?php foreach ($distinct_sems as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt); ?>" <?= $filter_sem === (string)$opt ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2 col-lg-1">
                                    <label class="form-label small text-muted mb-1">Class</label>
                                    <select name="filter_class" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <?php foreach ($distinct_class as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt); ?>" <?= $filter_class === (string)$opt ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2 col-lg-1">
                                    <label class="form-label small text-muted mb-1">Lab Batch</label>
                                    <select name="filter_lab" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <?php foreach ($distinct_lab as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt); ?>" <?= $filter_lab === (string)$opt ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2 col-lg-1">
                                    <label class="form-label small text-muted mb-1">Tut Batch</label>
                                    <select name="filter_tut" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <?php foreach ($distinct_tut as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt); ?>" <?= $filter_tut === (string)$opt ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2 col-lg-1">
                                    <label class="form-label small text-muted mb-1">Status</label>
                                    <select name="filter_status" class="form-select form-select-sm">
                                        <option value="">All</option>
                                        <option value="1" <?= $filter_status === '1' ? 'selected' : ''; ?>>Active</option>
                                        <option value="0" <?= $filter_status === '0' ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                </div>
                                <?php if ($has_active_filter): ?>
                                    <div class="col-12 col-md-12 col-lg-2 d-flex gap-2">
                                        <a href="managestudents.php" class="btn btn-clear-filters btn-sm flex-fill" id="clearFiltersBtn">
                                            <i class="bi bi-x-circle-fill me-1"></i>
                                            <span>Clear Filters</span>
                                            <span class="badge bg-danger ms-1 filter-count-badge"><?= $active_filter_count; ?></span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($bulk_success !== ''): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($bulk_success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($bulk_error !== ''): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($bulk_error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_active_filter && $total_filtered > 0): ?>
                            <!-- Bulk delete: removes exactly the filtered set shown below. -->
                            <div class="bulk-delete-bar mb-3">
                                <div class="bulk-delete-summary">
                                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                                    <span>
                                        <strong><?= (int)$total_filtered; ?></strong>
                                        student<?= $total_filtered === 1 ? '' : 's'; ?> match the current filter
                                        <?php if (!empty($active_filter_labels)): ?>
                                            (<?= htmlspecialchars(implode(', ', $active_filter_labels)); ?>)
                                        <?php endif; ?>.
                                    </span>
                                </div>
                                <a href="<?= htmlspecialchars($export_url); ?>" class="btn btn-success btn-sm bulk-export-btn">
                                    <i class="bi bi-file-earmark-excel me-1"></i>Export <?= (int)$total_filtered; ?>
                                </a>
                                <button type="button" class="btn btn-danger btn-sm bulk-delete-toggle" id="bulkDeleteToggle">
                                    <i class="bi bi-trash3 me-1"></i>Delete These <?= (int)$total_filtered; ?>
                                </button>

                                <div class="bulk-delete-confirm d-none" id="bulkDeleteConfirm">
                                    <form method="POST" action="managestudents.php?<?= htmlspecialchars(http_build_query($_GET)); ?>" autocomplete="off"
                                          onsubmit="return confirm('Permanently delete <?= (int)$total_filtered; ?> student record(s)? This cannot be undone.');">
                                        <input type="hidden" name="filter_signature" value="<?= htmlspecialchars(implode('|', [$search, $filter_term, $filter_sem, $filter_class, $filter_lab, $filter_tut, $filter_status])); ?>">
                                        <p class="bulk-delete-warning mb-2">
                                            This permanently deletes <strong><?= (int)$total_filtered; ?></strong> student
                                            record<?= $total_filtered === 1 ? '' : 's'; ?>. Attendance already recorded is
                                            stored against enrollment numbers and is <strong>not</strong> removed, but those
                                            students will no longer appear in registers or reports.
                                        </p>
                                        <label class="form-label small fw-semibold mb-1" for="bulk_master_password">Master Password</label>
                                        <div class="bulk-delete-actions">
                                            <input type="password" id="bulk_master_password" name="bulk_master_password"
                                                   class="form-control form-control-sm" placeholder="Enter master password"
                                                   autocomplete="new-password" required>
                                            <button type="submit" name="bulk_delete_students" value="1" class="btn btn-danger btn-sm">
                                                <i class="bi bi-trash3 me-1"></i>Confirm Delete
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="bulkDeleteCancel">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                       <!-- Table for Students -->
                        <div class="table-responsive">
                            <table id="studentsTable" class="table table-striped table-bordered display">
                                <thead>
                                    <tr>
                                        <th class="text-center">ID</th>
                                        <th class="text-center">Enrollment</th>
                                        <th class="text-center">Name</th>
                                        <th class="text-center">Sem</th>
                                        <th class="text-center">Class</th>
                                        <th class="text-center">Lab</th>
                                        <th class="text-center">Tut</th>
                                        <th class="text-center">Term</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result && $total_filtered > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()) { ?>
                                            <tr>
                                                <td class="text-center"><?= (int)$row['id']; ?></td>
                                                <td><?= htmlspecialchars($row['enrollmentNo']); ?></td>
                                                <td><?= htmlspecialchars($row['name']); ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['sem']); ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['class']); ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['labBatch']); ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['tutBatch']); ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['term']); ?></td>
                                                <td class="text-center">
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Disabled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="editstudent.php?id=<?= (int)$row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                                    <a href="managestudents.php?toggle_status_id=<?= (int)$row['id']; ?>" class="btn btn-<?= ((int)$row['status'] === 1) ? 'danger' : 'success'; ?> btn-sm">
                                                        <?= ((int)$row['status'] === 1) ? 'Disable' : 'Enable'; ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox me-2"></i>
                                                No students match the current filter.
                                                <?php if ($has_active_filter): ?>
                                                    <a href="managestudents.php" class="ms-2">Clear filters</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>

        </div><!--//container-xl-->
    </div><!--//app-content-->
</div><!--//app-wrapper-->

<?php include 'footer.php'; ?>

<!-- jQuery & DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<style>
    /* Filter bar styling */
    .students-filter-bar {
        background: #f8f9fb;
        border: 1px solid #e7e9ed;
        border-radius: 0.5rem;
        padding: 0.85rem 1rem;
    }
    .students-filter-bar .form-label.small {
        font-weight: 500;
        letter-spacing: 0.02em;
    }
    .students-filter-bar .input-group-text {
        border-right: 0;
        color: #828d9f;
    }
    .students-filter-bar .input-group .form-control {
        border-left: 0;
    }
    .students-filter-bar .form-select-sm,
    .students-filter-bar .form-control-sm {
        font-size: 0.825rem;
    }

    /* Clear Filters button — proper design with explicit border color */
    .btn-clear-filters {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        font-weight: 600;
        font-size: 0.825rem;
        padding: 0.4rem 0.85rem;
        color: #dc3545;
        background: #ffffff;
        border: 1.5px solid #dc3545;
        border-radius: 0.4rem;
        text-decoration: none;
        transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
        box-shadow: 0 1px 2px rgba(220, 53, 69, 0.08);
    }
    .btn-clear-filters:hover,
    .btn-clear-filters:focus {
        color: #ffffff;
        background: #dc3545;
        border-color: #c82333;
        outline: none;
        text-decoration: none;
        box-shadow: 0 3px 8px rgba(220, 53, 69, 0.25);
    }
    .btn-clear-filters:active {
        transform: translateY(1px);
        background: #c82333;
        border-color: #bd2130;
    }
    .btn-clear-filters i {
        font-size: 0.95rem;
    }
    .btn-clear-filters .filter-count-badge {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: 0.6rem;
        line-height: 1.2;
    }
    /* When the dropdown is open (hover/focus), invert the badge for contrast */
    .btn-clear-filters:hover .filter-count-badge,
    .btn-clear-filters:focus .filter-count-badge {
        background: #ffffff !important;
        color: #dc3545 !important;
    }

    /* ── Bulk delete bar ─────────────────────────────────────────────────── */
    .bulk-delete-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0.9rem;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc3545;
        border-radius: 0.5rem;
        background: #fef2f2;
    }
    .bulk-delete-summary {
        flex: 1 1 260px;
        font-size: 0.85rem;
        color: #7f1d1d;
        line-height: 1.4;
    }
    .bulk-delete-toggle,
    .bulk-export-btn {
        white-space: nowrap;
    }
    /* Export sits beside Delete in the red bar — keep it readable against
       the light red background instead of inheriting the danger styling. */
    .bulk-export-btn {
        font-weight: 600;
    }
    .bulk-delete-confirm {
        flex: 1 1 100%;
        border-top: 1px solid #fecaca;
        padding-top: 0.75rem;
        margin-top: 0.15rem;
    }
    .bulk-delete-warning {
        font-size: 0.8rem;
        color: #7f1d1d;
        line-height: 1.45;
    }
    .bulk-delete-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
    }
    .bulk-delete-actions .form-control {
        max-width: 240px;
    }
    @media (max-width: 575.98px) {
        .bulk-delete-toggle,
        .bulk-export-btn,
        .bulk-delete-actions .btn {
            width: 100%;
        }
        .bulk-delete-actions .form-control {
            max-width: none;
        }
    }
</style>
<script>
    $(document).ready(function () {
        $('#studentsTable').DataTable({
            "order": [[0, "asc"]],
            "columnDefs": [
                { "orderable": false, "targets": 9 } // Disable sort on Actions
            ],
            "pageLength": 50,
            "language": {
                "emptyTable": "No students match the current filter."
            }
        });

        // Auto-submit when a select filter changes (skip the search box
        // which requires manual submission so it doesn't fire on every keystroke).
        $('#studentsFilterForm select').on('change', function () {
            $('#studentsFilterForm').trigger('submit');
        });

        // Bulk delete: reveal the master-password confirmation step.
        $('#bulkDeleteToggle').on('click', function () {
            $('#bulkDeleteConfirm').removeClass('d-none');
            $(this).addClass('d-none');
            $('#bulk_master_password').trigger('focus');
        });
        $('#bulkDeleteCancel').on('click', function () {
            $('#bulkDeleteConfirm').addClass('d-none');
            $('#bulkDeleteToggle').removeClass('d-none');
            $('#bulk_master_password').val('');
        });
    });
</script>

</body>
</html>

<?php $conn->close(); ?>
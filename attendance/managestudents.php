<?php
include 'dbconfig.php'; // Include database connection

// optional message from add/update/delete redirects
$msg = '';
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

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

// Count of active filters (for badge on Clear Filters button)
$active_filter_count = 0;
if ($search !== '')        { $active_filter_count++; }
if ($filter_term !== '')   { $active_filter_count++; }
if ($filter_sem !== '')    { $active_filter_count++; }
if ($filter_class !== '')  { $active_filter_count++; }
if ($filter_lab !== '')    { $active_filter_count++; }
if ($filter_tut !== '')    { $active_filter_count++; }
if ($filter_status !== '') { $active_filter_count++; }
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
    });
</script>

</body>
</html>

<?php $conn->close(); ?>
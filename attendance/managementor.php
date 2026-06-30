<?php
include('dbconfig.php');

$msg = '';
$msg_type = 'success';
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
    $msg_type = 'success';
}
if (isset($_GET['err'])) {
    $msg = htmlspecialchars($_GET['err']);
    $msg_type = 'danger';
}

// Auto-create table if missing
$conn->query("CREATE TABLE IF NOT EXISTS `studentmentor` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `term`        VARCHAR(20)  NOT NULL,
  `sem`         VARCHAR(10)  NOT NULL,
  `enrollmentNo` VARCHAR(20) NOT NULL,
  `studentName` VARCHAR(100) NOT NULL,
  `batch`       VARCHAR(20)  NOT NULL,
  `mentorName`  VARCHAR(100) NOT NULL,
  `status`      INT(2)       NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_term_enrollment` (`term`, `enrollmentNo`),
  KEY `idx_mentor_name` (`mentorName`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Handle Add (single record) ───────────────────────────────────────────────
if (isset($_POST['add_mentor'])) {
    $term         = trim((string)($_POST['term'] ?? ''));
    $sem          = trim((string)($_POST['sem'] ?? ''));
    $enrollmentNo = trim((string)($_POST['enrollmentNo'] ?? ''));
    $studentName  = trim((string)($_POST['studentName'] ?? ''));
    $batch        = trim((string)($_POST['batch'] ?? ''));
    $mentorName   = trim((string)($_POST['mentorName'] ?? ''));

    if ($term === '' || $sem === '' || $enrollmentNo === '' || $studentName === '' || $batch === '' || $mentorName === '') {
        header('Location: managementor.php?err=' . urlencode('All fields are required.'));
        exit();
    }

    // Upsert by term + enrollmentNo
    $stmt = $conn->prepare("SELECT id FROM studentmentor WHERE term = ? AND enrollmentNo = ? LIMIT 1");
    $stmt->bind_param('ss', $term, $enrollmentNo);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $upd = $conn->prepare("UPDATE studentmentor SET sem = ?, studentName = ?, batch = ?, mentorName = ?, status = 1 WHERE id = ?");
        $upd->bind_param('ssssi', $sem, $studentName, $batch, $mentorName, $existing['id']);
        $upd->execute();
        $upd->close();
        header('Location: managementor.php?msg=' . urlencode('Mentor assignment updated.'));
        exit();
    } else {
        $ins = $conn->prepare("INSERT INTO studentmentor (term, sem, enrollmentNo, studentName, batch, mentorName, status) VALUES (?,?,?,?,?,?,1)");
        $ins->bind_param('ssssss', $term, $sem, $enrollmentNo, $studentName, $batch, $mentorName);
        $ins->execute();
        $ins->close();
        header('Location: managementor.php?msg=' . urlencode('Mentor assignment added.'));
        exit();
    }
}

// ── Handle Update (from edit modal) ──────────────────────────────────────────
if (isset($_POST['update_mentor'])) {
    $id           = (int)($_POST['id'] ?? 0);
    $term         = trim((string)($_POST['term'] ?? ''));
    $sem          = trim((string)($_POST['sem'] ?? ''));
    $enrollmentNo = trim((string)($_POST['enrollmentNo'] ?? ''));
    $studentName  = trim((string)($_POST['studentName'] ?? ''));
    $batch        = trim((string)($_POST['batch'] ?? ''));
    $mentorName   = trim((string)($_POST['mentorName'] ?? ''));

    if ($id > 0 && $term !== '' && $sem !== '' && $enrollmentNo !== '' && $studentName !== '' && $batch !== '' && $mentorName !== '') {
        $stmt = $conn->prepare("UPDATE studentmentor SET term = ?, sem = ?, enrollmentNo = ?, studentName = ?, batch = ?, mentorName = ? WHERE id = ?");
        $stmt->bind_param('ssssssi', $term, $sem, $enrollmentNo, $studentName, $batch, $mentorName, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: managementor.php?msg=' . urlencode('Mentor assignment updated.'));
        exit();
    }
    header('Location: managementor.php?err=' . urlencode('Failed to update mentor assignment.'));
    exit();
}

// ── Handle Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    if ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM studentmentor WHERE id = ?");
        $stmt->bind_param('i', $del_id);
        $stmt->execute();
        $stmt->close();
        header('Location: managementor.php?msg=' . urlencode('Mentor assignment deleted.'));
        exit();
    }
}

// ── Handle Toggle Status ─────────────────────────────────────────────────────
if (isset($_GET['toggle_status_id'])) {
    $tid = (int)$_GET['toggle_status_id'];
    if ($tid > 0) {
        $row = $conn->query("SELECT status FROM studentmentor WHERE id = $tid")->fetch_assoc();
        if ($row) {
            $new_status = ((int)$row['status'] === 1) ? 0 : 1;
            $stmt = $conn->prepare("UPDATE studentmentor SET status = ? WHERE id = ?");
            $stmt->bind_param('ii', $new_status, $tid);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: managementor.php?msg=' . urlencode('Status updated.'));
        exit();
    }
}

// ── Handle CSV Upload ────────────────────────────────────────────────────────
if (isset($_POST['upload_csv'])) {
    if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        header('Location: managementor.php?err=' . urlencode('Please upload a CSV file.'));
        exit();
    }

    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        header('Location: managementor.php?err=' . urlencode('Only CSV files are allowed.'));
        exit();
    }

    $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$file) {
        header('Location: managementor.php?err=' . urlencode('Could not read uploaded file.'));
        exit();
    }

    $header = fgetcsv($file, 0, ',', '"', '\\');
    if (!$header) {
        fclose($file);
        header('Location: managementor.php?err=' . urlencode('CSV appears to be empty.'));
        exit();
    }

    // Normalize header
    $expected = ['term', 'sem', 'enrollmentno', 'studentname', 'batch', 'mentorname'];
    $normalized = array_map(function ($h) { return strtolower(trim((string)$h)); }, $header);
    if ($normalized !== $expected) {
        fclose($file);
        header('Location: managementor.php?err=' . urlencode('CSV header columns must be (in order): term, sem, enrollmentNo, studentName, batch, mentorName.'));
        exit();
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $row_num  = 1;
    while (($row = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
        $row_num++;
        if (count($row) < 6) { $skipped++; continue; }
        $term         = trim((string)$row[0]);
        $sem          = trim((string)$row[1]);
        $enrollmentNo = trim((string)$row[2]);
        $studentName  = trim((string)$row[3]);
        $batch        = trim((string)$row[4]);
        $mentorName   = trim((string)$row[5]);

        if ($term === '' || $sem === '' || $enrollmentNo === '' || $studentName === '' || $batch === '' || $mentorName === '') {
            $skipped++;
            continue;
        }

        $check = $conn->prepare("SELECT id FROM studentmentor WHERE term = ? AND enrollmentNo = ? LIMIT 1");
        $check->bind_param('ss', $term, $enrollmentNo);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $upd = $conn->prepare("UPDATE studentmentor SET sem = ?, studentName = ?, batch = ?, mentorName = ?, status = 1 WHERE id = ?");
            $upd->bind_param('ssssi', $sem, $studentName, $batch, $mentorName, $existing['id']);
            $upd->execute();
            $upd->close();
            $updated++;
        } else {
            $ins = $conn->prepare("INSERT INTO studentmentor (term, sem, enrollmentNo, studentName, batch, mentorName, status) VALUES (?,?,?,?,?,?,1)");
            $ins->bind_param('ssssss', $term, $sem, $enrollmentNo, $studentName, $batch, $mentorName);
            $ins->execute();
            $ins->close();
            $inserted++;
        }
    }
    fclose($file);

    $msg_text = "CSV processed: {$inserted} inserted, {$updated} updated";
    if ($skipped > 0) $msg_text .= ", {$skipped} skipped (blank fields)";
    header('Location: managementor.php?msg=' . urlencode($msg_text));
    exit();
}

// ── Search + Pagination ──────────────────────────────────────────────────────
$search = trim((string)($_GET['search'] ?? ''));
$where  = '';
$params = [];
$types  = '';
if ($search !== '') {
    $like = '%' . $search . '%';
    $where = "WHERE enrollmentNo LIKE ? OR studentName LIKE ? OR mentorName LIKE ? OR batch LIKE ? OR term LIKE ?";
    $params = [$like, $like, $like, $like, $like];
    $types  = 'sssss';
}

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$total_sql = "SELECT COUNT(*) AS total FROM studentmentor $where";
if ($where !== '') {
    $stmt = $conn->prepare($total_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $total_row = $conn->query($total_sql)->fetch_assoc();
}
$total      = (int)($total_row['total'] ?? 0);
$total_pages = max(1, (int)ceil($total / $limit));

$list_sql = "SELECT * FROM studentmentor $where ORDER BY term DESC, sem, batch, enrollmentNo LIMIT ? OFFSET ?";
$list_res = null;
if ($where !== '') {
    $stmt = $conn->prepare($list_sql);
    // bind like params + int limit + int offset
    $all_params = $params;
    $all_params[] = $limit;
    $all_params[] = $offset;
    $all_types   = $types . 'ii';
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $list_res = $stmt->get_result();
    $stmt->close();
} else {
    $stmt = $conn->prepare($list_sql);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $list_res = $stmt->get_result();
    $stmt->close();
}

// Helper for active-row highlight when editing
$edit_id = (int)($_GET['edit_id'] ?? 0);

// Get distinct terms for dropdown suggestions
$terms_res = $conn->query("SELECT DISTINCT term FROM studentmentor ORDER BY term DESC");
$known_terms = [];
if ($terms_res) {
    while ($tr = $terms_res->fetch_assoc()) { $known_terms[] = $tr['term']; }
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
            <h1 class="app-page-title"><i class="bi bi-people-fill me-2"></i>Manage Student Mentors</h1>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show">
                    <?= $msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Add Single + Bulk Upload -->
            <div class="row mb-4 g-4">
                <div class="col-12 col-lg-7">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4><i class="bi bi-plus-circle me-1"></i>Add Mentor Assignment</h4>
                            <form method="POST" action="managementor.php">
                                <div class="row g-3 mb-3">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label">Term</label>
                                        <input list="knownTerms" type="text" name="term" class="form-control" placeholder="e.g. 252" required>
                                        <datalist id="knownTerms">
                                            <?php foreach ($known_terms as $kt): ?>
                                                <option value="<?= htmlspecialchars($kt) ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label">Sem</label>
                                        <input type="text" name="sem" class="form-control" placeholder="e.g. 4" required>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Enrollment No</label>
                                        <input type="text" name="enrollmentNo" class="form-control" placeholder="e.g. 226310307007" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Student Name</label>
                                        <input type="text" name="studentName" class="form-control" placeholder="Full name" required>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label">Batch</label>
                                        <input type="text" name="batch" class="form-control" placeholder="e.g. A1" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Mentor Name</label>
                                        <input type="text" name="mentorName" class="form-control" placeholder="Mentor full name" required>
                                    </div>
                                    <div class="col-12 col-md-3 d-flex align-items-end">
                                        <button type="submit" name="add_mentor" class="btn btn-primary w-100">
                                            <i class="bi bi-plus-circle me-1"></i>Add
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-5">
                    <div class="app-card shadow-sm h-100" style="background:linear-gradient(135deg,#e8eaf6,#f3f4fd);">
                        <div class="app-card-body">
                            <h4><i class="bi bi-cloud-upload me-1"></i>Bulk Upload CSV</h4>
                            <form method="POST" action="managementor.php" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label">Choose CSV File</label>
                                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                                </div>
                                <button type="submit" name="upload_csv" class="btn btn-primary">
                                    <i class="bi bi-cloud-upload me-1"></i>Upload &amp; Import
                                </button>
                                <a href="mentor_upload.csv" class="btn btn-sm btn-outline-primary ms-2" download>
                                    <i class="bi bi-download me-1"></i>Download Sample CSV
                                </a>
                            </form>

                            <hr>
                            <p class="text-muted mb-2" style="font-size:0.82rem;">Columns (with header row, in this exact order):</p>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0" style="font-size:0.78rem;">
                                    <thead class="table-light">
                                        <tr><th>#</th><th>Column</th><th>Example</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>1</td><td>term</td><td>252</td></tr>
                                        <tr><td>2</td><td>sem</td><td>4</td></tr>
                                        <tr><td>3</td><td>enrollmentNo</td><td>226310307007</td></tr>
                                        <tr><td>4</td><td>studentName</td><td>BHATT DHAIRYA...</td></tr>
                                        <tr><td>5</td><td>batch</td><td>A1</td></tr>
                                        <tr><td>6</td><td>mentorName</td><td>Yagnesh Patel</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mb-0 mt-2 text-muted" style="font-size:0.78rem;">
                                <i class="bi bi-info-circle me-1"></i>Existing (term + enrollmentNo) rows are updated; new rows are inserted.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- List -->
            <div class="row">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4 class="mb-3">Mentor Assignments
                                <span class="text-muted fw-normal" style="font-size:0.875rem;">(<?= $total ?> record<?= $total === 1 ? '' : 's' ?>)</span>
                            </h4>

                            <form method="GET" action="managementor.php" class="mb-3">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search"
                                           value="<?= htmlspecialchars($search) ?>"
                                           placeholder="Search by enrollment no, student name, mentor, batch, or term">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search me-1"></i>Search
                                    </button>
                                    <?php if ($search !== ''): ?>
                                        <a href="managementor.php" class="btn btn-outline-secondary">Clear</a>
                                    <?php endif; ?>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-striped table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">ID</th>
                                            <th class="text-center">Term</th>
                                            <th class="text-center">Sem</th>
                                            <th class="text-center">Enrollment No</th>
                                            <th>Student Name</th>
                                            <th class="text-center">Batch</th>
                                            <th>Mentor Name</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($list_res && $list_res->num_rows > 0): ?>
                                        <?php while ($row = $list_res->fetch_assoc()): ?>
                                            <?php $row_id = (int)$row['id']; ?>
                                            <tr class="<?= $row_id === $edit_id ? 'table-warning' : '' ?>">
                                                <td class="text-center"><?= $row_id ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['term']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['sem']) ?></td>
                                                <td class="text-center"><code><?= htmlspecialchars($row['enrollmentNo']) ?></code></td>
                                                <td><?= htmlspecialchars($row['studentName']) ?></td>
                                                <td class="text-center"><span class="badge bg-primary-subtle text-dark border"><?= htmlspecialchars($row['batch']) ?></span></td>
                                                <td><?= htmlspecialchars($row['mentorName']) ?></td>
                                                <td class="text-center">
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Disabled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex gap-1 justify-content-center">
                                                        <button type="button" class="btn btn-warning btn-sm edit-btn"
                                                                title="Edit"
                                                                data-id="<?= $row_id ?>"
                                                                data-term="<?= htmlspecialchars($row['term']) ?>"
                                                                data-sem="<?= htmlspecialchars($row['sem']) ?>"
                                                                data-enrollment="<?= htmlspecialchars($row['enrollmentNo']) ?>"
                                                                data-student="<?= htmlspecialchars($row['studentName']) ?>"
                                                                data-batch="<?= htmlspecialchars($row['batch']) ?>"
                                                                data-mentor="<?= htmlspecialchars($row['mentorName']) ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="managementor.php?toggle_status_id=<?= $row_id ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                                                           class="btn btn-<?= (int)$row['status'] === 1 ? 'outline-danger' : 'outline-success' ?> btn-sm"
                                                           title="<?= (int)$row['status'] === 1 ? 'Disable' : 'Enable' ?>">
                                                            <i class="bi bi-<?= (int)$row['status'] === 1 ? 'pause-fill' : 'play-fill' ?>"></i>
                                                        </a>
                                                        <a href="managementor.php?delete_id=<?= $row_id ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                                                           class="btn btn-outline-danger btn-sm"
                                                           title="Delete"
                                                           onclick="return confirm('Delete this mentor assignment?');">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="text-center text-muted py-4">
                                            <?php if ($search !== ''): ?>
                                                No records match your search.
                                            <?php else: ?>
                                                No mentor assignments yet. Use the form above or upload a CSV.
                                            <?php endif; ?>
                                        </td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                            <nav class="mt-3">
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editMentorModal" tabindex="-1" aria-labelledby="editMentorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="managementor.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="editMentorModalLabel"><i class="bi bi-pencil-square me-1"></i>Edit Mentor Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="editId" value="">
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label class="form-label">Term</label>
                            <input type="text" name="term" id="editTerm" class="form-control" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Sem</label>
                            <input type="text" name="sem" id="editSem" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Enrollment No</label>
                            <input type="text" name="enrollmentNo" id="editEnrollment" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Student Name</label>
                            <input type="text" name="studentName" id="editStudent" class="form-control" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label">Batch</label>
                            <input type="text" name="batch" id="editBatch" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Mentor Name</label>
                            <input type="text" name="mentorName" id="editMentor" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_mentor" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editId').value          = this.dataset.id;
            document.getElementById('editTerm').value       = this.dataset.term;
            document.getElementById('editSem').value        = this.dataset.sem;
            document.getElementById('editEnrollment').value = this.dataset.enrollment;
            document.getElementById('editStudent').value    = this.dataset.student;
            document.getElementById('editBatch').value      = this.dataset.batch;
            document.getElementById('editMentor').value     = this.dataset.mentor;
            const modalEl = document.getElementById('editMentorModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    });
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>
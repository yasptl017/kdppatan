<?php
include('dbconfig.php');

// Admin-only page
$current_username = trim((string)($_SESSION['username'] ?? ''));
if (strcasecmp($current_username, 'admin') !== 0) {
    header('Location: home.php');
    exit();
}

attendance_ensure_holidays_table($conn);

$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

function holidays_redirect(array $params)
{
    header('Location: manageholidays.php?' . http_build_query($params));
    exit();
}

// ── Add holiday (single date, or an inclusive date range) ────────────────────
if (isset($_POST['add_holiday'])) {
    $from_date = trim((string)($_POST['holiday_date'] ?? ''));
    $to_date   = trim((string)($_POST['holiday_to_date'] ?? ''));
    $name      = trim((string)($_POST['name'] ?? ''));

    if ($from_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        holidays_redirect(['err' => 'Please choose a valid date.']);
    }
    if ($to_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        $to_date = $from_date;
    }
    if ($to_date < $from_date) {
        holidays_redirect(['err' => 'The "to" date cannot be before the "from" date.']);
    }

    $cur = new DateTime($from_date);
    $end = new DateTime($to_date);
    $end->modify('+1 day'); // inclusive
    // Guard against a mistyped year turning into thousands of inserts.
    if ((int)$cur->diff($end)->days > 400) {
        holidays_redirect(['err' => 'Date range is too long (max 400 days).']);
    }

    // INSERT ... ON DUPLICATE KEY refreshes an existing row instead of failing,
    // so re-adding a date is a harmless rename/re-enable.
    $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, name, status) VALUES (?, ?, 1)
                            ON DUPLICATE KEY UPDATE name = VALUES(name), status = 1");
    if (!$stmt) {
        holidays_redirect(['err' => 'Could not save the holiday. Please try again.']);
    }
    $added = 0;
    while ($cur < $end) {
        $d = $cur->format('Y-m-d');
        $stmt->bind_param('ss', $d, $name);
        if ($stmt->execute()) {
            $added++;
        }
        $cur->modify('+1 day');
    }
    $stmt->close();

    holidays_redirect(['msg' => $added === 1 ? 'Holiday added.' : "$added holiday dates added."]);
}

// ── Update holiday ───────────────────────────────────────────────────────────
if (isset($_POST['update_holiday'])) {
    $id   = (int)($_POST['id'] ?? 0);
    $date = trim((string)($_POST['holiday_date'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));

    if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        holidays_redirect(['err' => 'Invalid holiday details.']);
    }

    $stmt = $conn->prepare("UPDATE holidays SET holiday_date = ?, name = ? WHERE id = ?");
    $stmt->bind_param('ssi', $date, $name, $id);
    $ok = $stmt->execute();
    $stmt->close();

    holidays_redirect($ok
        ? ['msg' => 'Holiday updated.']
        : ['err' => 'Another holiday already exists on that date.']);
}

// ── Toggle status ────────────────────────────────────────────────────────────
if (isset($_GET['toggle_status_id'])) {
    $id = (int)$_GET['toggle_status_id'];
    $stmt = $conn->prepare("UPDATE holidays SET status = 1 - status WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    holidays_redirect(['msg' => 'Holiday status updated.']);
}

// ── Delete ───────────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    holidays_redirect(['msg' => 'Holiday deleted.']);
}

// ── Listing ──────────────────────────────────────────────────────────────────
$search = trim((string)($_GET['search'] ?? ''));
$edit_id = (int)($_GET['edit'] ?? 0);

$limit  = 50;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$like = '%' . $search . '%';

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM holidays WHERE name LIKE ? OR holiday_date LIKE ?");
$count_stmt->bind_param('ss', $like, $like);
$count_stmt->execute();
$total_rows = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $limit));

$list_stmt = $conn->prepare("SELECT * FROM holidays WHERE name LIKE ? OR holiday_date LIKE ?
                             ORDER BY holiday_date DESC LIMIT ? OFFSET ?");
$list_stmt->bind_param('ssii', $like, $like, $limit, $offset);
$list_stmt->execute();
$holidays = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

$edit_row = null;
if ($edit_id > 0) {
    $e_stmt = $conn->prepare("SELECT * FROM holidays WHERE id = ?");
    $e_stmt->bind_param('i', $edit_id);
    $e_stmt->execute();
    $edit_row = $e_stmt->get_result()->fetch_assoc();
    $e_stmt->close();
}

$day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$today_str = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-calendar-x me-2"></i>Manage Holidays</h1>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($err !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Holidays are non-teaching days. Slots falling on an active holiday are hidden from
                <strong>My Attendance</strong> and <strong>Pending Attendance</strong>, and are excluded from the
                counts. Extra Attendance still lets faculty file attendance on a holiday when a class actually ran.
            </div>

            <!-- Add / Edit form -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <?php if ($edit_row): ?>
                                <h4>Edit Holiday</h4>
                                <form method="POST" action="manageholidays.php">
                                    <input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>">
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">Date</label>
                                            <input type="date" name="holiday_date" class="form-control" value="<?= htmlspecialchars((string)$edit_row['holiday_date']) ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Holiday Name</label>
                                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars((string)$edit_row['name']) ?>" placeholder="e.g. Diwali">
                                        </div>
                                        <div class="col-12 col-md-3 d-flex align-items-end gap-2">
                                            <button type="submit" name="update_holiday" class="btn btn-primary flex-grow-1">Update</button>
                                            <a href="manageholidays.php" class="btn btn-outline-secondary">Cancel</a>
                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <h4>Add Holiday</h4>
                                <p class="text-muted mb-3">Leave <em>To Date</em> empty for a single day, or set it to add a whole vacation range.</p>
                                <form method="POST" action="manageholidays.php">
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">From Date</label>
                                            <input type="date" name="holiday_date" class="form-control" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">To Date <span class="text-muted">(optional)</span></label>
                                            <input type="date" name="holiday_to_date" class="form-control">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Holiday Name</label>
                                            <input type="text" name="name" class="form-control" placeholder="e.g. Independence Day">
                                        </div>
                                        <div class="col-12 col-md-2 d-flex align-items-end">
                                            <button type="submit" name="add_holiday" class="btn btn-primary w-100">Add</button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Holiday list -->
            <div class="row">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Holiday List <span class="text-muted fs-6">(<?= $total_rows ?>)</span></h4>

                            <form method="GET" action="manageholidays.php">
                                <div class="input-group mb-4">
                                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or date (e.g. 2026-08)">
                                    <button class="btn btn-primary" type="submit">Search</button>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-striped table-bordered display">
                                    <thead>
                                        <tr>
                                            <th class="text-center">ID</th>
                                            <th class="text-center">Date</th>
                                            <th class="text-center">Day</th>
                                            <th>Holiday Name</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($holidays)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4">No holidays added yet.</td></tr>
                                        <?php else: foreach ($holidays as $row):
                                            $date = (string)$row['holiday_date'];
                                            $dow  = (int)date('w', strtotime($date));
                                        ?>
                                            <tr<?= $date === $today_str ? ' class="table-warning"' : '' ?>>
                                                <td class="text-center"><?= (int)$row['id'] ?></td>
                                                <td class="text-center"><?= htmlspecialchars(date('d M Y', strtotime($date))) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($day_names[$dow]) ?></td>
                                                <td><?= $row['name'] !== '' ? htmlspecialchars((string)$row['name']) : '<span class="text-muted">—</span>' ?></td>
                                                <td class="text-center">
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Disabled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="manageholidays.php?edit=<?= (int)$row['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                                    <a href="manageholidays.php?toggle_status_id=<?= (int)$row['id'] ?>" class="btn btn-<?= (int)$row['status'] === 1 ? 'secondary' : 'success' ?> btn-sm">
                                                        <?= (int)$row['status'] === 1 ? 'Disable' : 'Enable' ?>
                                                    </a>
                                                    <a href="manageholidays.php?delete_id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm"
                                                       onclick="return confirm('Delete the holiday on <?= htmlspecialchars(date('d M Y', strtotime($date))) ?>?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
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

        </div><!--//container-xl-->
    </div><!--//app-content-->
</div><!--//app-wrapper-->

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

<?php
include('dbconfig.php');

// ── Auto-create terms table if missing ─────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `terms` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `academic_year` VARCHAR(20) NOT NULL,
    `term`         VARCHAR(20) NOT NULL,
    `sem`          VARCHAR(10) NOT NULL,
    `start_date`   DATE         NOT NULL,
    `end_date`     DATE         NOT NULL,
    `status`       INT(2)      NOT NULL DEFAULT 1,
    `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// optional message from add/update/delete redirects
$msg = '';
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// Add Term (Form handling)
if (isset($_POST['add_term'])) {
    $academic_year = trim($_POST['academic_year']);
    $term          = trim($_POST['term']);
    $sem           = trim($_POST['sem']);
    $start_date    = trim($_POST['start_date']);
    $end_date      = trim($_POST['end_date']);
    $status        = 1; // Default status as active

    $stmt = $conn->prepare("INSERT INTO terms (academic_year, term, sem, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $academic_year, $term, $sem, $start_date, $end_date, $status);
    $stmt->execute();
    $stmt->close();

    header("Location: manageterm.php?msg=Term added successfully");
    exit();
}

// Toggle Term Status
if (isset($_GET['toggle_status_id'])) {
    $id = (int)$_GET['toggle_status_id'];

    $result = $conn->query("SELECT status FROM terms WHERE id = $id");
    $row = $result->fetch_assoc();
    $new_status = ($row['status'] == 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE terms SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: manageterm.php?msg=Term status updated");
    exit();
}

// Search functionality
$search = '';
if (isset($_GET['search'])) {
    $search = $_GET['search'];
}

// Pagination functionality
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_result = $conn->query("SELECT COUNT(*) AS total FROM terms WHERE academic_year LIKE '%$search%' OR term LIKE '%$search%' OR sem LIKE '%$search%'");
$total_row = $total_result->fetch_assoc();
$total_pages = ceil($total_row['total'] / $limit);

$query = "SELECT * FROM terms WHERE academic_year LIKE '%$search%' OR term LIKE '%$search%' OR sem LIKE '%$search%' ORDER BY start_date DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-calendar-range me-2"></i>Manage Term</h1>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-success mb-3"><?= $msg ?></div>
            <?php endif; ?>

            <!-- Add Term Form -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Add Term</h4>
                            <form method="POST" action="manageterm.php">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Academic Year</label>
                                        <input type="text" name="academic_year" class="form-control" placeholder="e.g. 2024-25" required>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Term</label>
                                        <input type="text" name="term" class="form-control" placeholder="e.g. ODD" required>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Sem</label>
                                        <input type="text" name="sem" class="form-control" placeholder="e.g. 3" required>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" required>
                                    </div>
                                    <div class="col-12 col-md-2 d-flex align-items-end">
                                        <button type="submit" name="add_term" class="btn btn-primary w-100">Add Term</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Term List -->
            <div class="row">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Term List</h4>

                            <!-- Search Bar -->
                            <form method="GET" action="manageterm.php">
                                <div class="input-group mb-4">
                                    <input type="text" class="form-control" name="search" value="<?php echo $search; ?>" placeholder="Search by academic year, term, or semester">
                                    <button class="btn btn-primary" type="submit">Search</button>
                                </div>
                            </form>

                            <!-- Table -->
                            <div class="table-responsive">
                                <table id="termTable" class="table table-striped table-bordered display">
                                    <thead>
                                        <tr>
                                            <th class="text-center">ID</th>
                                            <th class="text-center">Academic Year</th>
                                            <th class="text-center">Term</th>
                                            <th class="text-center">Sem</th>
                                            <th class="text-center">Start Date</th>
                                            <th class="text-center">End Date</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $result->fetch_assoc()) { ?>
                                            <tr>
                                                <td class="text-center"><?php echo $row['id']; ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($row['academic_year']); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($row['term']); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($row['sem']); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($row['start_date']); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($row['end_date']); ?></td>
                                                <td class="text-center"><?php echo $row['status'] == 1 ? 'Active' : 'Disabled'; ?></td>
                                                <td class="text-center">
                                                    <a href="editterm.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                                    <a href="manageterm.php?toggle_status_id=<?php echo $row['id']; ?>" class="btn btn-<?php echo $row['status'] == 1 ? 'danger' : 'success'; ?> btn-sm">
                                                        <?php echo $row['status'] == 1 ? 'Disable' : 'Enable'; ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo $search; ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++) { ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo $search; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php } ?>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo $search; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
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

<?php
$conn->close();
?>

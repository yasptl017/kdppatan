<?php
include('dbconfig.php');

// optional message from add/update/delete redirects
$msg = '';
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// Add Slot (Form handling)
if (isset($_POST['add_slot'])) {
    $timeslot = $_POST['timeslot'];
    $sequence = $_POST['sequence'];
    $status = 1; // Default status as active

    // Insert the slot data into the database
    $stmt = $conn->prepare("INSERT INTO timeslot (timeslot, sequence, status) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $timeslot, $sequence, $status);
    $stmt->execute();
    $stmt->close();
}

// Toggle Slot Status (Change status between 0 and 1)
if (isset($_GET['toggle_status_id'])) {
    $id = $_GET['toggle_status_id'];
    
    // Get current status
    $result = $conn->query("SELECT status FROM timeslot WHERE id = $id");
    $row = $result->fetch_assoc();
    $new_status = ($row['status'] == 1) ? 0 : 1; // Toggle the status
    
    // Update the slot status
    $stmt = $conn->prepare("UPDATE timeslot SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    $stmt->execute();
    $stmt->close();
}

// Move Slot Up / Down (swap sequence with the adjacent slot)
if (isset($_GET['move']) && isset($_GET['move_id'])) {
    $move_id = (int)$_GET['move_id'];
    $dir = ($_GET['move'] === 'up') ? 'up' : 'down';

    // Current slot
    $cur_res = $conn->query("SELECT id, sequence FROM timeslot WHERE id = $move_id");
    if ($cur_res && $cur = $cur_res->fetch_assoc()) {
        $cur_seq = (int)$cur['sequence'];

        // Neighbour in the requested direction, ordered the same way as the list
        if ($dir === 'up') {
            $nbr_sql = "SELECT id, sequence FROM timeslot
                        WHERE sequence < $cur_seq OR (sequence = $cur_seq AND id < $move_id)
                        ORDER BY sequence DESC, id DESC LIMIT 1";
        } else {
            $nbr_sql = "SELECT id, sequence FROM timeslot
                        WHERE sequence > $cur_seq OR (sequence = $cur_seq AND id > $move_id)
                        ORDER BY sequence ASC, id ASC LIMIT 1";
        }

        $nbr_res = $conn->query($nbr_sql);
        if ($nbr_res && $nbr = $nbr_res->fetch_assoc()) {
            $nbr_id  = (int)$nbr['id'];
            $nbr_seq = (int)$nbr['sequence'];

            if ($nbr_seq === $cur_seq) {
                // Same sequence number on both rows - nudge the one moving up
                $up_id   = ($dir === 'up') ? $move_id : $nbr_id;
                $down_id = ($dir === 'up') ? $nbr_id : $move_id;
                $stmt = $conn->prepare("UPDATE timeslot SET sequence = ? WHERE id = ?");
                $new_seq = $cur_seq + 1;
                $stmt->bind_param("ii", $new_seq, $down_id);
                $stmt->execute();
                $stmt->close();
            } else {
                // Swap the two sequence values
                $stmt = $conn->prepare("UPDATE timeslot SET sequence = ? WHERE id = ?");
                $stmt->bind_param("ii", $nbr_seq, $move_id);
                $stmt->execute();
                $stmt->bind_param("ii", $cur_seq, $nbr_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // Redirect so a refresh does not repeat the move
    $qs = 'msg=' . urlencode('Slot order updated');
    if (isset($_GET['search']) && $_GET['search'] !== '') {
        $qs .= '&search=' . urlencode($_GET['search']);
    }
    if (isset($_GET['page'])) {
        $qs .= '&page=' . (int)$_GET['page'];
    }
    header("Location: manageslot.php?$qs");
    exit();
}

// Search functionality
$search = '';
if (isset($_GET['search'])) {
    $search = $_GET['search'];
}

// Pagination functionality
$limit = 50; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Query to get total records for pagination
$total_result = $conn->query("SELECT COUNT(*) AS total FROM timeslot WHERE timeslot LIKE '%$search%'");
$total_row = $total_result->fetch_assoc();
$total_pages = ceil($total_row['total'] / $limit);

// Fetch the slot records with pagination and search
$query = "SELECT * FROM timeslot WHERE timeslot LIKE '%$search%' ORDER BY sequence ASC, id ASC LIMIT $limit OFFSET $offset";
$result = $conn->query($query);

// Buffer rows so each one knows whether it is first/last (for disabling arrows)
$slots = [];
while ($srow = $result->fetch_assoc()) {
    $slots[] = $srow;
}
$slot_count = count($slots);
?>

<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-clock me-2"></i>Manage Slots</h1>

            <?php if ($msg !== '') { ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php } ?>

            <!-- Add Slot Form -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Add Slot</h4>
                            <form method="POST" action="manageslot.php">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Timeslot</label>
                                        <input type="text" name="timeslot" class="form-control" placeholder="e.g. 09:00-10:00" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Sequence</label>
                                        <input type="number" name="sequence" class="form-control" placeholder="Order number" required>
                                    </div>
                                    <div class="col-12 col-md-4 d-flex align-items-end">
                                        <button type="submit" name="add_slot" class="btn btn-primary w-100">Add Slot</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slot List -->
            <div class="row">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                        <h4>Slot List</h4>

                        <!-- Search Bar -->
                        <form method="GET" action="manageslot.php">
                            <div class="input-group mb-4">
                                <input type="text" class="form-control" name="search" value="<?php echo $search; ?>" placeholder="Search by timeslot">
                                <button class="btn btn-primary" type="submit">Search</button>
                            </div>
                        </form>

                        <!-- Table for Slots -->
                        <div class="table-responsive">
                            <table id="slotTable" class="table table-striped table-bordered display">
                                <thead>
                                    <tr>
                                        <th class="text-center">ID</th>
                                        <th class="text-center">Timeslot</th>
                                        <th class="text-center">Sequence</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $move_qs = '&search=' . urlencode($search) . '&page=' . $page;
                                    foreach ($slots as $i => $row) {
                                        $is_first = ($page <= 1 && $i === 0);
                                        $is_last  = ($page >= $total_pages && $i === $slot_count - 1);
                                    ?>
                                        <tr>
                                            <td class="text-center"><?php echo $row['id']; ?></td>
                                            <td><?php echo $row['timeslot']; ?></td>
                                            <td class="text-center">
                                                <?php echo $row['sequence']; ?>
                                                <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Reorder slot">
                                                    <?php if ($is_first) { ?>
                                                        <span class="btn btn-outline-secondary btn-sm disabled"><i class="bi bi-arrow-up"></i></span>
                                                    <?php } else { ?>
                                                        <a href="manageslot.php?move=up&move_id=<?php echo $row['id']; ?><?php echo $move_qs; ?>" class="btn btn-outline-secondary btn-sm" title="Move up"><i class="bi bi-arrow-up"></i></a>
                                                    <?php } ?>
                                                    <?php if ($is_last) { ?>
                                                        <span class="btn btn-outline-secondary btn-sm disabled"><i class="bi bi-arrow-down"></i></span>
                                                    <?php } else { ?>
                                                        <a href="manageslot.php?move=down&move_id=<?php echo $row['id']; ?><?php echo $move_qs; ?>" class="btn btn-outline-secondary btn-sm" title="Move down"><i class="bi bi-arrow-down"></i></a>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                            <td class="text-center"><?php echo $row['status'] == 1 ? 'Active' : 'Disabled'; ?></td>
                                            <td class="text-center">
                                                <a href="editslot.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                                <a href="manageslot.php?toggle_status_id=<?php echo $row['id']; ?>" class="btn btn-<?php echo $row['status'] == 1 ? 'danger' : 'success'; ?> btn-sm">
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

        </div><!--//container-xl-->
    </div><!--//app-content-->
</div><!--//app-wrapper-->

<?php include('footer.php'); ?>
</body>
</html>

<?php
$conn->close();
?>

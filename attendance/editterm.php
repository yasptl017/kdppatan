<?php
include('dbconfig.php');

// Fetch term data to edit
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $result = $conn->query("SELECT * FROM terms WHERE id = $id");
    $term = $result->fetch_assoc();
} else {
    header("Location: manageterm.php");
    exit();
}

// Handle Term Update
if (isset($_POST['update_term'])) {
    $academic_year = trim($_POST['academic_year']);
    $term_name     = trim($_POST['term']);
    $sem           = trim($_POST['sem']);
    $start_date    = trim($_POST['start_date']);
    $end_date      = trim($_POST['end_date']);

    $stmt = $conn->prepare("UPDATE terms SET academic_year = ?, term = ?, sem = ?, start_date = ?, end_date = ? WHERE id = ?");
    $stmt->bind_param("sssssi", $academic_year, $term_name, $sem, $start_date, $end_date, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: manageterm.php?msg=Term updated successfully");
    exit();
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
            <h1 class="app-page-title"><i class="bi bi-pencil-square me-2"></i>Edit Term</h1>

            <div class="row mb-4">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Edit Term Details</h4>
                            <form method="POST" action="editterm.php?id=<?php echo $id; ?>">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Academic Year</label>
                                        <input type="text" name="academic_year" class="form-control" value="<?php echo htmlspecialchars($term['academic_year']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Term</label>
                                        <input type="text" name="term" class="form-control" value="<?php echo htmlspecialchars($term['term']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Sem</label>
                                        <input type="text" name="sem" class="form-control" value="<?php echo htmlspecialchars($term['sem']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($term['start_date']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($term['end_date']); ?>" required>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="update_term" class="btn btn-primary">Update Term</button>
                                    <a href="manageterm.php" class="btn btn-outline-secondary">Cancel</a>
                                </div>
                            </form>
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

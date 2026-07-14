<?php
include('dbconfig.php'); // Include database connection
$short_name_col = $conn->query("SHOW COLUMNS FROM subjects LIKE 'subjectShortName'");
if ($short_name_col && $short_name_col->num_rows === 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN subjectShortName VARCHAR(100) NULL AFTER subjectName");
}

// Check if user is logged in
if (!isset($_SESSION['Name'])) {
    header("Location: index.php"); // Redirect to login page if not logged in
    exit();
}

// Fetch Subject Details for Editing
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $result = $conn->query("SELECT * FROM subjects WHERE id = $id");
    $subject = $result->fetch_assoc();
}

// Handle Subject Update
if (isset($_POST['update_subject'])) {
    $subjectCode = $_POST['subjectCode'];
    $subjectName = $_POST['subjectName'];
    $subjectShortName = $_POST['subjectShortName'];
    $sem = $_POST['sem'];

    // Update the subject data in the database
    $stmt = $conn->prepare("UPDATE subjects SET subjectCode = ?, subjectName = ?, subjectShortName = ?, sem = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $subjectCode, $subjectName, $subjectShortName, $sem, $id);
    $stmt->execute();
    $stmt->close();

    // Redirect to the manage subject page with a success message
    header("Location: managesubjects.php?msg=Subject updated successfully");
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
            <h1 class="app-page-title"><i class="bi bi-pencil-square me-2"></i>Edit Subject</h1>

            <div class="row mb-4">
                <div class="col-12 col-lg-8">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Edit Subject Details</h4>
                            <form method="POST" action="editsubjects.php?id=<?php echo $subject['id']; ?>">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Subject Code</label>
                                        <input type="text" name="subjectCode" class="form-control" value="<?php echo htmlspecialchars($subject['subjectCode']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Subject Name</label>
                                        <input type="text" name="subjectName" class="form-control" value="<?php echo htmlspecialchars($subject['subjectName']); ?>" required>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Short Name</label>
                                        <input type="text" name="subjectShortName" class="form-control" value="<?php echo htmlspecialchars((string)($subject['subjectShortName'] ?? '')); ?>">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Semester</label>
                                        <input type="text" name="sem" class="form-control" value="<?php echo htmlspecialchars($subject['sem']); ?>" required>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="update_subject" class="btn btn-primary">Update Subject</button>
                                    <a href="managesubjects.php" class="btn btn-outline-secondary">Cancel</a>
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

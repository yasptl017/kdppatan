<!DOCTYPE html>
<html lang="en">
<?php
session_start();
include "dbconfig.php";
include "head.php";

$message = "";
$messageType = "";

// Create table if it doesn't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS dept_results (
        id int(11) NOT NULL AUTO_INCREMENT,
        department varchar(255) NOT NULL,
        title varchar(255) NOT NULL,
        display_order int(11) NOT NULL DEFAULT 0,
        file_path varchar(255) NOT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        KEY department (department),
        KEY display_order (display_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$uploadDir = "uploads/dept_results/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* ===================================
   ADD / UPDATE RESULTS
=================================== */
if (isset($_POST['save_results'])) {
    $id = intval($_POST['results_id']);
    $department = $conn->real_escape_string($_POST['department']);
    $title = $conn->real_escape_string($_POST['title']);
    $display_order = intval($_POST['display_order']);

    $oldFile = "";
    if ($id > 0) {
        $old = $conn->query("SELECT file_path FROM dept_results WHERE id=$id LIMIT 1");
        if ($old && $old->num_rows > 0) {
            $oldFile = $old->fetch_assoc()['file_path'];
        }
    }

    $filePath = $oldFile;
    if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xlsx', 'xls'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $filePath = $uploadDir . "dept_results_" . time() . "_" . rand(1000, 9999) . "." . $ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                if (!empty($oldFile) && file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        } else {
            $message = "Invalid file type. Please upload PDF, DOC, DOCX, image or Excel files.";
            $messageType = "danger";
        }
    }

    if (empty($message)) {
        if ($id === 0) {
            if (empty($filePath)) {
                $message = "Please upload a file.";
                $messageType = "danger";
            } else {
                $sql = "INSERT INTO dept_results (department, title, display_order, file_path)
                        VALUES ('$department', '$title', $display_order, '$filePath')";
                if ($conn->query($sql)) {
                    $message = "Result added successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $conn->error;
                    $messageType = "danger";
                }
            }
        } else {
            $sql = "UPDATE dept_results SET
                        department='$department',
                        title='$title',
                        display_order=$display_order,
                        file_path='$filePath'
                    WHERE id=$id";
            if ($conn->query($sql)) {
                $message = "Result updated successfully!";
                $messageType = "success";
            } else {
                $message = "Error: " . $conn->error;
                $messageType = "danger";
            }
        }
    }
}

/* ===================================
   DELETE
=================================== */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $old = $conn->query("SELECT file_path FROM dept_results WHERE id=$id LIMIT 1");
    if ($old && $old->num_rows > 0) {
        $file = $old->fetch_assoc()['file_path'];
        if (!empty($file) && file_exists($file)) {
            unlink($file);
        }
    }
    if ($conn->query("DELETE FROM dept_results WHERE id=$id")) {
        $message = "Record deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error: " . $conn->error;
        $messageType = "danger";
    }
}

// Filter by role
if ($_SESSION['role'] == 'Admin') {
    $records = $conn->query("SELECT * FROM dept_results ORDER BY department ASC, display_order ASC, id DESC");
    $departments = $conn->query("SELECT department FROM departments WHERE visibility = 1 ORDER BY department ASC");
    $defaultDept = '';
} else {
    $userDept = $conn->real_escape_string($_SESSION['user_name']);
    $records = $conn->query("SELECT * FROM dept_results WHERE department='$userDept' ORDER BY display_order ASC, id DESC");
    $departments = $conn->query("SELECT department FROM departments WHERE visibility = 1 AND department='$userDept' ORDER BY department ASC");
    $defaultDept = $userDept;
}
?>

<!-- Page-specific CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<style>
    .form-card {
        background: #fff;
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .action-buttons { display: flex; gap: 5px; justify-content: center; }
</style>

<body>
<?php include "sidebar.php"; ?>
<?php include "header.php"; ?>

<main class="main-content" id="mainContent">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0"><i class="fas fa-file-alt me-2"></i>Manage Department Results</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#resultsModal" onclick="addResults()">
            <i class="fas fa-plus-circle"></i> Add Result
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <table id="resultsTable" class="table table-striped table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr class="text-center">
                    <th width="50">#</th>
                    <th>Department</th>
                    <th>Title</th>
                    <th>Display Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($records && $records->num_rows > 0) {
                    $index = 1;
                    while ($row = $records->fetch_assoc()) {
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $index++; ?></td>
                            <td><?php echo htmlspecialchars($row['department']); ?></td>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td class="text-center"><?php echo $row['display_order']; ?></td>
                            <td class="text-center">
                                <div class="action-buttons">
                                    <button class="btn btn-warning btn-sm" title="Edit"
                                            onclick='editResults(<?php echo json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            data-bs-toggle="modal" data-bs-target="#resultsModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm"
                                       onclick="return confirm('Are you sure?');" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

</main>

<!-- Add/Edit Modal -->
<div class="modal fade" id="resultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="results_id" id="results_id" value="0">

                    <div class="mb-3">
                        <label for="department" class="form-label">Department *</label>
                        <select name="department" id="department" class="form-select" required>
                            <option value="">Select Department</option>
                            <?php
                            $dept_query = $conn->query("SELECT department FROM departments WHERE visibility = 1 ORDER BY department ASC");
                            while ($dept = $dept_query->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($dept['department']) . '">' . htmlspecialchars($dept['department']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="title" class="form-label">Title *</label>
                        <input type="text" name="title" id="title" class="form-control" placeholder="e.g., Semester 5 Results" required>
                    </div>

                    <div class="mb-3">
                        <label for="display_order" class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="display_order" class="form-control" value="0" min="0">
                    </div>

                    <div class="mb-3">
                        <label for="file" class="form-label">Upload File *</label>
                        <input type="file" name="file" id="file" class="form-control" 
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xlsx,.xls">
                        <small class="text-muted">Supported: PDF, DOC, DOCX, Images, Excel</small>
                        <div id="currentFile" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_results" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>

<script>
    $(document).ready(function () {
        $('#resultsTable').DataTable({
            pageLength: 10,
            ordering: true,
            searching: true
        });
    });

    function addResults() {
        document.getElementById('results_id').value = '0';
        document.getElementById('title').value = '';
        document.getElementById('display_order').value = '0';
        document.getElementById('file').value = '';
        document.getElementById('department').value = <?php echo json_encode($defaultDept); ?>;
        document.getElementById('modalTitle').textContent = 'Add Result';
        document.getElementById('file').setAttribute('required', 'required');
        document.getElementById('currentFile').innerHTML = '';
    }

    function editResults(data) {
        document.getElementById('results_id').value = data.id;
        document.getElementById('department').value = data.department;
        document.getElementById('title').value = data.title;
        document.getElementById('display_order').value = data.display_order;
        document.getElementById('file').value = '';
        document.getElementById('file').removeAttribute('required');
        document.getElementById('modalTitle').textContent = 'Edit Result';
        document.getElementById('currentFile').innerHTML = data.file_path
            ? '<div class="alert alert-info py-2 mb-0"><strong>Current file:</strong> <a href="' + data.file_path + '" target="_blank">View File</a></div>'
            : '';
    }

    // Handle modal close to reset form
    document.getElementById('resultsModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('title').value = '';
        document.getElementById('display_order').value = '0';
        document.getElementById('file').value = '';
        document.getElementById('file').removeAttribute('required');
        document.getElementById('currentFile').innerHTML = '';
    });
</script>
</body>
</html>

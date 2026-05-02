<!DOCTYPE html>
<html lang="en">
<?php
session_start();
include "dbconfig.php";
include "head.php";

$message = "";
$messageType = "";

$conn->query("
    CREATE TABLE IF NOT EXISTS dept_academic_calendar (
        id int(11) NOT NULL AUTO_INCREMENT,
        department varchar(255) NOT NULL,
        title varchar(255) NOT NULL,
        display_order int(11) NOT NULL DEFAULT 0,
        file_path varchar(255) NOT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$uploadDir = "uploads/dept_academic_calendar/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_POST['save_calendar'])) {
    $id = intval($_POST['calendar_id']);
    $department = $conn->real_escape_string($_POST['department']);
    $title = $conn->real_escape_string($_POST['title']);
    $display_order = intval($_POST['display_order']);

    $oldFile = "";
    if ($id > 0) {
        $old = $conn->query("SELECT file_path FROM dept_academic_calendar WHERE id=$id LIMIT 1");
        if ($old && $old->num_rows > 0) {
            $oldFile = $old->fetch_assoc()['file_path'];
        }
    }

    $filePath = $oldFile;
    if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $filePath = $uploadDir . "dept_acal_" . time() . "_" . rand(1000, 9999) . "." . $ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                if (!empty($oldFile) && file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        } else {
            $message = "Invalid file type. Please upload PDF, DOC, DOCX, or image files.";
            $messageType = "danger";
        }
    }

    if (empty($message)) {
        if ($id === 0) {
            if (empty($filePath)) {
                $message = "Please upload a file.";
                $messageType = "danger";
            } else {
                $sql = "INSERT INTO dept_academic_calendar (department, title, display_order, file_path)
                        VALUES ('$department', '$title', $display_order, '$filePath')";
                if ($conn->query($sql)) {
                    $message = "Academic calendar added successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $conn->error;
                    $messageType = "danger";
                }
            }
        } else {
            $sql = "UPDATE dept_academic_calendar SET
                        department='$department',
                        title='$title',
                        display_order=$display_order,
                        file_path='$filePath'
                    WHERE id=$id";
            if ($conn->query($sql)) {
                $message = "Academic calendar updated successfully!";
                $messageType = "success";
            } else {
                $message = "Error: " . $conn->error;
                $messageType = "danger";
            }
        }
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $old = $conn->query("SELECT file_path FROM dept_academic_calendar WHERE id=$id LIMIT 1");
    if ($old && $old->num_rows > 0) {
        $file = $old->fetch_assoc()['file_path'];
        if (!empty($file) && file_exists($file)) {
            unlink($file);
        }
    }

    if ($conn->query("DELETE FROM dept_academic_calendar WHERE id=$id")) {
        $message = "Academic calendar deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error deleting academic calendar: " . $conn->error;
        $messageType = "danger";
    }
}

if ($_SESSION['role'] == 'Admin') {
    $records = $conn->query("SELECT * FROM dept_academic_calendar ORDER BY department ASC, display_order ASC, id DESC");
    $departments = $conn->query("SELECT department FROM departments WHERE visibility = 1 ORDER BY department ASC");
    $defaultDept = '';
} else {
    $userDept = $conn->real_escape_string($_SESSION['user_name']);
    $records = $conn->query("SELECT * FROM dept_academic_calendar WHERE department='$userDept' ORDER BY display_order ASC, id DESC");
    $departments = $conn->query("SELECT department FROM departments WHERE visibility = 1 AND department='$userDept' ORDER BY department ASC");
    $defaultDept = $_SESSION['user_name'];
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<style>
    .form-card {
        background: #fff;
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: center;
    }
    .file-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
</style>

<body>
<?php include "sidebar.php"; ?>
<?php include "header.php"; ?>

<main class="main-content" id="mainContent">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0">
            <i class="fas fa-calendar-alt me-2"></i>Manage Department Academic Calendar
        </h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calendarModal" onclick="addCalendar()">
            <i class="fas fa-plus-circle"></i> Add Academic Calendar
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <table id="calendarTable" class="table table-striped table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr class="text-center">
                    <th width="50">#</th>
                    <th>Department</th>
                    <th>Title</th>
                    <th>Order</th>
                    <th>File</th>
                    <th width="130">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($row = $records->fetch_assoc()): ?>
                <tr>
                    <td class="text-center"><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td class="text-center"><?php echo $row['display_order']; ?></td>
                    <td>
                        <?php if (!empty($row['file_path'])): ?>
                            <a href="<?php echo htmlspecialchars($row['file_path']); ?>" target="_blank" class="file-link text-decoration-none">
                                <i class="fas fa-file-alt text-primary"></i> View File
                            </a>
                        <?php else: ?>
                            <span class="text-muted">No file</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-warning"
                                    onclick='editCalendar(<?php echo json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'
                                    data-bs-toggle="modal" data-bs-target="#calendarModal" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete=<?php echo $row['id']; ?>"
                               onclick="return confirm('Delete this academic calendar?');"
                               class="btn btn-sm btn-danger" title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</main>

<div class="modal fade" id="calendarModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="calendarForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="fas fa-calendar-alt me-2"></i>Add Academic Calendar
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="calendar_id" id="calendar_id">

                    <div class="mb-3">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="department" id="department" class="form-control" required>
                            <option value="">-- Select Department --</option>
                            <?php
                            $departments->data_seek(0);
                            while ($dept = $departments->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($dept['department']); ?>">
                                    <?php echo htmlspecialchars($dept['department']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Order <span class="text-danger">*</span></label>
                        <input type="number" name="display_order" id="display_order" class="form-control" value="0" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="title" class="form-control" placeholder="e.g., Term 251 Academic Calendar" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">File <span id="fileRequired" class="text-danger">*</span></label>
                        <input type="file" name="file" id="file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">
                        <small class="text-muted">PDF, DOC, DOCX, JPG, PNG, GIF, and WEBP files are supported.</small>
                        <div id="currentFile" class="mt-2"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" name="save_calendar" class="btn btn-success">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>

<script>
function addCalendar() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-calendar-alt me-2"></i>Add Academic Calendar';
    document.getElementById('calendar_id').value = '';
    document.getElementById('department').value = <?php echo json_encode($defaultDept); ?>;
    document.getElementById('display_order').value = '0';
    document.getElementById('title').value = '';
    document.getElementById('file').value = '';
    document.getElementById('fileRequired').style.display = 'inline';
    document.getElementById('currentFile').innerHTML = '';
}

function editCalendar(row) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Update Academic Calendar';
    document.getElementById('calendar_id').value = row.id;
    document.getElementById('department').value = row.department;
    document.getElementById('display_order').value = row.display_order;
    document.getElementById('title').value = row.title;
    document.getElementById('file').value = '';
    document.getElementById('fileRequired').style.display = 'none';
    document.getElementById('currentFile').innerHTML = row.file_path
        ? '<div class="alert alert-info py-2 mb-0"><strong>Current file:</strong> <a href="' + row.file_path + '" target="_blank">View File</a></div>'
        : '';
}

$(document).ready(function() {
    $('#calendarTable').DataTable({
        pageLength: 25,
        order: [[1, 'asc'], [3, 'asc']],
        columnDefs: [{ orderable: false, targets: [5] }],
        language: {
            search: "Search:",
            infoEmpty: "No records",
            zeroRecords: "No matching records found"
        }
    });
});
</script>

</body>
</html>

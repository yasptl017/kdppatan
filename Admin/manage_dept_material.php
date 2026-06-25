<!DOCTYPE html>
<html lang="en">
<?php
session_start();
include "dbconfig.php";
include "head.php";

$message = "";
$messageType = "";

$conn->query("
    CREATE TABLE IF NOT EXISTS dept_material (
        id int(11) NOT NULL AUTO_INCREMENT,
        department varchar(255) NOT NULL,
        subject varchar(255) NOT NULL,
        title varchar(255) NOT NULL,
        display_order int(11) NOT NULL DEFAULT 0,
        file_path varchar(255) NOT NULL,
        material_url varchar(500) DEFAULT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        KEY department (department),
        KEY subject (subject),
        KEY display_order (display_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Add material_url column if it doesn't exist (for existing tables)
$colCheck = $conn->query("SHOW COLUMNS FROM dept_material LIKE 'material_url'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE dept_material ADD COLUMN material_url varchar(500) DEFAULT NULL AFTER file_path");
}

$uploadDir = "uploads/dept_material/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_POST['save_material'])) {
    $id = intval($_POST['material_id']);
    $department = $conn->real_escape_string($_POST['department']);
    $subject = $conn->real_escape_string($_POST['subject']);
    $title = $conn->real_escape_string($_POST['title']);
    $display_order = intval($_POST['display_order']);
    $material_url = $conn->real_escape_string(trim($_POST['material_url'] ?? ''));
    $upload_type = $_POST['upload_type'] ?? 'file';

    $oldFile = "";
    if ($id > 0) {
        $old = $conn->query("SELECT file_path FROM dept_material WHERE id=$id LIMIT 1");
        if ($old && $old->num_rows > 0) {
            $oldFile = $old->fetch_assoc()['file_path'];
        }
    }

    $filePath = $oldFile;
    if ($upload_type === 'file') {
        $material_url = ''; // Clear URL if file upload is chosen
        if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xlsx', 'xls', 'zip', 'rar'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $filePath = $uploadDir . "dept_material_" . time() . "_" . rand(1000, 9999) . "." . $ext;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                    if (!empty($oldFile) && file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
            } else {
                $message = "Invalid file type. Please upload PDF, DOC, PPT, image, Excel, ZIP, or RAR files.";
                $messageType = "danger";
            }
        }
    } else {
        // URL upload type - clear file path if new entry
        if ($id === 0) {
            $filePath = '';
        }
    }

    if (empty($message)) {
        if ($id === 0) {
            if (empty($filePath) && empty($material_url)) {
                $message = "Please upload a file or provide a URL.";
                $messageType = "danger";
            } else {
                $sql = "INSERT INTO dept_material (department, subject, title, display_order, file_path, material_url)
                        VALUES ('$department', '$subject', '$title', $display_order, '$filePath', '$material_url')";
                if ($conn->query($sql)) {
                    $message = "Material added successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $conn->error;
                    $messageType = "danger";
                }
            }
        } else {
            $sql = "UPDATE dept_material SET
                        department='$department',
                        subject='$subject',
                        title='$title',
                        display_order=$display_order,
                        file_path='$filePath',
                        material_url='$material_url'
                    WHERE id=$id";
            if ($conn->query($sql)) {
                $message = "Material updated successfully!";
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
    $old = $conn->query("SELECT file_path FROM dept_material WHERE id=$id LIMIT 1");
    if ($old && $old->num_rows > 0) {
        $file = $old->fetch_assoc()['file_path'];
        if (!empty($file) && file_exists($file)) {
            unlink($file);
        }
    }
    if ($conn->query("DELETE FROM dept_material WHERE id=$id")) {
        $message = "Record deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error: " . $conn->error;
        $messageType = "danger";
    }
}

if ($_SESSION['role'] == 'Admin') {
    $records = $conn->query("SELECT * FROM dept_material ORDER BY department ASC, subject ASC, display_order ASC, id DESC");
    $defaultDept = '';
} else {
    $userDept = $conn->real_escape_string($_SESSION['user_name']);
    $records = $conn->query("SELECT * FROM dept_material WHERE department='$userDept' ORDER BY subject ASC, display_order ASC, id DESC");
    $defaultDept = $userDept;
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
    .action-buttons { display: flex; gap: 5px; justify-content: center; }
</style>

<body>
<?php include "sidebar.php"; ?>
<?php include "header.php"; ?>

<main class="main-content" id="mainContent">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0"><i class="fas fa-book-reader me-2"></i>Manage Department Materials</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#materialModal" onclick="addMaterial()">
            <i class="fas fa-plus-circle"></i> Add Material
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <table id="materialTable" class="table table-striped table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr class="text-center">
                    <th width="50">#</th>
                    <th>Department</th>
                    <th>Subject</th>
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
                            <td><?php echo htmlspecialchars($row['subject']); ?></td>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td class="text-center"><?php echo $row['display_order']; ?></td>
                            <td class="text-center">
                                <div class="action-buttons">
                                    <button class="btn btn-warning btn-sm" title="Edit"
                                            onclick='editMaterial(<?php echo json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            data-bs-toggle="modal" data-bs-target="#materialModal">
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

<div class="modal fade" id="materialModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Material</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="material_id" id="material_id" value="0">

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
                        <label for="subject" class="form-label">Subject *</label>
                        <input type="text" name="subject" id="subject" class="form-control" placeholder="e.g., Mathematics" required>
                    </div>

                    <div class="mb-3">
                        <label for="title" class="form-label">Title *</label>
                        <input type="text" name="title" id="title" class="form-control" placeholder="e.g., Unit 1 Notes" required>
                    </div>

                    <div class="mb-3">
                        <label for="display_order" class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="display_order" class="form-control" value="0" min="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Upload Type *</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="upload_type" id="upload_type_file" value="file" checked>
                            <label class="btn btn-outline-primary" for="upload_type_file"><i class="fas fa-file-upload me-1"></i>Upload File</label>
                            <input type="radio" class="btn-check" name="upload_type" id="upload_type_url" value="url">
                            <label class="btn btn-outline-primary" for="upload_type_url"><i class="fas fa-link me-1"></i>External URL</label>
                        </div>
                    </div>

                    <div class="mb-3" id="file_upload_section">
                        <label for="file" class="form-label">Upload File *</label>
                        <input type="file" name="file" id="file" class="form-control"
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.xlsx,.xls,.zip,.rar">
                        <small class="text-muted">Supported: PDF, DOC, PPT, Images, Excel, ZIP, RAR</small>
                        <div id="currentFile" class="mt-2"></div>
                    </div>

                    <div class="mb-3" id="url_upload_section" style="display:none;">
                        <label for="material_url" class="form-label">Material URL *</label>
                        <input type="url" name="material_url" id="material_url" class="form-control" placeholder="https://example.com/material.pdf">
                        <small class="text-muted">Enter the full URL (opens in new tab when viewed)</small>
                        <div id="currentUrl" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_material" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>

<script>
    $(document).ready(function () {
        $('#materialTable').DataTable({
            pageLength: 10,
            ordering: true,
            searching: true
        });
    });

    // Toggle between file upload and URL input
    document.querySelectorAll('input[name="upload_type"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            toggleUploadType(this.value);
        });
    });

    function toggleUploadType(type) {
        const fileSection = document.getElementById('file_upload_section');
        const urlSection = document.getElementById('url_upload_section');
        const fileInput = document.getElementById('file');
        const urlInput = document.getElementById('material_url');

        if (type === 'file') {
            fileSection.style.display = 'block';
            urlSection.style.display = 'none';
            urlInput.removeAttribute('required');
        } else {
            fileSection.style.display = 'none';
            urlSection.style.display = 'block';
            fileInput.removeAttribute('required');
        }
    }

    function addMaterial() {
        document.getElementById('material_id').value = '0';
        document.getElementById('subject').value = '';
        document.getElementById('title').value = '';
        document.getElementById('display_order').value = '0';
        document.getElementById('file').value = '';
        document.getElementById('material_url').value = '';
        document.getElementById('department').value = <?php echo json_encode($defaultDept); ?>;
        document.getElementById('modalTitle').textContent = 'Add Material';
        document.getElementById('file').setAttribute('required', 'required');
        document.getElementById('currentFile').innerHTML = '';
        document.getElementById('currentUrl').innerHTML = '';
        document.getElementById('upload_type_file').checked = true;
        toggleUploadType('file');
    }

    function editMaterial(data) {
        document.getElementById('material_id').value = data.id;
        document.getElementById('department').value = data.department;
        document.getElementById('subject').value = data.subject;
        document.getElementById('title').value = data.title;
        document.getElementById('display_order').value = data.display_order;
        document.getElementById('file').value = '';
        document.getElementById('file').removeAttribute('required');
        document.getElementById('modalTitle').textContent = 'Edit Material';

        // Determine if this entry uses URL or file
        if (data.material_url && data.material_url.trim() !== '') {
            document.getElementById('upload_type_url').checked = true;
            toggleUploadType('url');
            document.getElementById('material_url').value = data.material_url;
            document.getElementById('currentUrl').innerHTML = '<div class="alert alert-info py-2 mb-0"><strong>Current URL:</strong> <a href="' + data.material_url + '" target="_blank">' + data.material_url + '</a></div>';
            document.getElementById('currentFile').innerHTML = '';
        } else {
            document.getElementById('upload_type_file').checked = true;
            toggleUploadType('file');
            document.getElementById('material_url').value = '';
            document.getElementById('currentUrl').innerHTML = '';
            document.getElementById('currentFile').innerHTML = data.file_path
                ? '<div class="alert alert-info py-2 mb-0"><strong>Current file:</strong> <a href="' + data.file_path + '" target="_blank">View File</a></div>'
                : '';
        }
    }

    document.getElementById('materialModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('subject').value = '';
        document.getElementById('title').value = '';
        document.getElementById('display_order').value = '0';
        document.getElementById('file').value = '';
        document.getElementById('material_url').value = '';
        document.getElementById('file').removeAttribute('required');
        document.getElementById('currentFile').innerHTML = '';
        document.getElementById('currentUrl').innerHTML = '';
        document.getElementById('upload_type_file').checked = true;
        toggleUploadType('file');
    });
</script>
</body>
</html>

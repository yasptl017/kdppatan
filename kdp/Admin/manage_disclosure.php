<!DOCTYPE html>
<html lang="en">
<?php
session_start();
include "dbconfig.php";
include "head.php";

$message = "";
$messageType = "";

$conn->query("
    CREATE TABLE IF NOT EXISTS disclosure_files (
        id int(11) NOT NULL AUTO_INCREMENT,
        heading varchar(255) NOT NULL DEFAULT '',
        title varchar(255) NOT NULL,
        display_order int(11) NOT NULL DEFAULT 0,
        file varchar(500) NOT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
$headingCheck = $conn->query("SHOW COLUMNS FROM disclosure_files LIKE 'heading'");
if ($headingCheck && $headingCheck->num_rows === 0) {
    $conn->query("ALTER TABLE disclosure_files ADD heading varchar(255) NOT NULL DEFAULT '' AFTER id");
}

$uploadDir = "uploads/disclosure/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_POST['save_disclosure'])) {
    $id = intval($_POST['disclosure_id']);
    $heading = $conn->real_escape_string($_POST['heading']);
    $title = $conn->real_escape_string($_POST['title']);
    $display_order = intval($_POST['display_order']);

    $oldFile = "";
    if ($id > 0) {
        $old = $conn->query("SELECT file FROM disclosure_files WHERE id=$id LIMIT 1");
        if ($old && $old->num_rows > 0) {
            $oldFile = $old->fetch_assoc()['file'];
        }
    }

    $uploadedFile = $oldFile;
    if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $uploadedFile = $uploadDir . "disclosure_" . time() . "_" . rand(1000, 9999) . "." . $ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadedFile)) {
                if (!empty($oldFile) && file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        } else {
            $message = "Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, WEBP";
            $messageType = "danger";
        }
    }

    if (empty($message)) {
        if ($id === 0) {
            if (empty($uploadedFile)) {
                $message = "Please upload a file.";
                $messageType = "danger";
            } else {
                $sql = "INSERT INTO disclosure_files (heading, title, display_order, file)
                        VALUES ('$heading', '$title', $display_order, '$uploadedFile')";
                if ($conn->query($sql)) {
                    $message = "Disclosure file added successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $conn->error;
                    $messageType = "danger";
                }
            }
        } else {
            $sql = "UPDATE disclosure_files SET
                        heading='$heading',
                        title='$title',
                        display_order=$display_order,
                        file='$uploadedFile'
                    WHERE id=$id";
            if ($conn->query($sql)) {
                $message = "Disclosure file updated successfully!";
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
    $old = $conn->query("SELECT file FROM disclosure_files WHERE id=$id LIMIT 1");
    if ($old && $old->num_rows > 0) {
        $file = $old->fetch_assoc()['file'];
        if (!empty($file) && file_exists($file)) {
            unlink($file);
        }
    }

    if ($conn->query("DELETE FROM disclosure_files WHERE id=$id")) {
        $message = "Disclosure file deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error deleting disclosure file: " . $conn->error;
        $messageType = "danger";
    }
}

$records = $conn->query("SELECT * FROM disclosure_files ORDER BY heading ASC, display_order ASC, id DESC");
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<style>
    .form-card { background:#fff; padding:2rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
    .action-buttons { display:flex; gap:5px; justify-content:center; }
</style>

<body>
<?php include "sidebar.php"; ?>
<?php include "header.php"; ?>

<main class="main-content" id="mainContent">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0"><i class="fas fa-file-alt me-2"></i>Manage Disclosure</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#disclosureModal" onclick="addDisclosure()">
            <i class="fas fa-plus-circle"></i> Add Disclosure
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <table id="disclosureTable" class="table table-bordered table-striped align-middle">
            <thead>
                <tr class="text-center">
                    <th width="60">#</th>
                    <th>Heading</th>
                    <th>Title</th>
                    <th>Order</th>
                    <th>File</th>
                    <th width="140">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($row = $records->fetch_assoc()): ?>
                <tr>
                    <td class="text-center"><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($row['heading']); ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td class="text-center"><?php echo $row['display_order']; ?></td>
                    <td>
                        <?php if (!empty($row['file'])): ?>
                            <a href="<?php echo htmlspecialchars($row['file']); ?>" target="_blank">View File</a>
                        <?php else: ?>
                            <span class="text-muted">No file</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-warning btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#disclosureModal"
                                    onclick='editDisclosure(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?delete=<?php echo $row['id']; ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete this disclosure file?');">
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

<div class="modal fade" id="disclosureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Add Disclosure</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="disclosure_id" id="disclosure_id">

                    <div class="mb-3">
                        <label class="form-label">Heading <span class="text-danger">*</span></label>
                        <input type="text" name="heading" id="heading" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="title" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Order <span class="text-danger">*</span></label>
                        <input type="number" name="display_order" id="display_order" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">File <span id="fileRequired" class="text-danger">*</span></label>
                        <input type="file" name="file" id="file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                        <small class="text-muted">PDF, DOC, DOCX, JPG, PNG, and WEBP files are supported.</small>
                        <div id="currentFile" class="mt-2"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" name="save_disclosure" class="btn btn-success">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>

<script>
$(document).ready(function () {
    $('#disclosureTable').DataTable({
        pageLength: 25,
        order: [[1, 'asc'], [3, 'asc']]
    });
});

function addDisclosure() {
    document.getElementById("modalTitle").innerText = "Add Disclosure";
    document.getElementById("disclosure_id").value = "";
    document.getElementById("heading").value = "";
    document.getElementById("title").value = "";
    document.getElementById("display_order").value = "";
    document.getElementById("file").value = "";
    document.getElementById("fileRequired").style.display = "inline";
    document.getElementById("currentFile").innerHTML = "";
}

function editDisclosure(row) {
    document.getElementById("modalTitle").innerText = "Update Disclosure";
    document.getElementById("disclosure_id").value = row.id;
    document.getElementById("heading").value = row.heading;
    document.getElementById("title").value = row.title;
    document.getElementById("display_order").value = row.display_order;
    document.getElementById("file").value = "";
    document.getElementById("fileRequired").style.display = "none";
    document.getElementById("currentFile").innerHTML = row.file
        ? '<div class="alert alert-info py-2 mb-0"><strong>Current file:</strong> <a href="' + row.file + '" target="_blank">View File</a></div>'
        : "";
}
</script>

</body>
</html>

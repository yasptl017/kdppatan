<?php
include('dbconfig.php');
require_once __DIR__ . '/auth.php';
require_login();

$success_msg = '';
$error_msg = '';
$session_username = trim((string)($_SESSION['username'] ?? ''));
$session_name = trim((string)($_SESSION['Name'] ?? ''));
$current_user = null;

if ($session_username !== '') {
    $stmt = $conn->prepare("SELECT id, username, password, Name FROM faculty WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $session_username);
        $stmt->execute();
        $current_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$current_user && $session_name !== '') {
    $stmt = $conn->prepare("SELECT id, username, password, Name FROM faculty WHERE Name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $session_name);
        $stmt->execute();
        $current_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (!$current_user) {
        $error_msg = 'Unable to verify your account. Please log out and log in again.';
    } elseif ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error_msg = 'Please fill all password fields.';
    } elseif (!hash_equals((string)$current_user['password'], $current_password)) {
        $error_msg = 'Current password is incorrect.';
    } elseif ($new_password !== $confirm_password) {
        $error_msg = 'New password and confirm password do not match.';
    } elseif (strlen($new_password) < 4) {
        $error_msg = 'New password must be at least 4 characters.';
    } else {
        $stmt = $conn->prepare("UPDATE faculty SET password = ? WHERE id = ?");
        if ($stmt) {
            $faculty_id = (int)$current_user['id'];
            $stmt->bind_param('si', $new_password, $faculty_id);
            if ($stmt->execute()) {
                $success_msg = 'Password changed successfully.';
                $current_user['password'] = $new_password;
            } else {
                $error_msg = 'Unable to change password. Please try again.';
            }
            $stmt->close();
        } else {
            $error_msg = 'Unable to change password. Please try again.';
        }
    }
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
            <h1 class="app-page-title"><i class="bi bi-key me-2"></i>Change Password</h1>

            <?php if ($success_msg !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($success_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12 col-lg-6">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4 class="mb-3">Update Password</h4>
                            <form method="POST" action="changePassword.php" autocomplete="off">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="new_password" class="form-control" minlength="4" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" minlength="4" required>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check2-circle me-1"></i>Change Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('footer.php'); ?>
</div>
</body>
</html>
<?php $conn->close(); ?>

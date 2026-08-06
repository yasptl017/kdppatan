<?php
include('dbconfig.php');
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/studentreport.config.php';

$success_msg = '';
$error_msg = '';

$session_username = trim((string)($_SESSION['username'] ?? ''));
$session_name = trim((string)($_SESSION['Name'] ?? ''));
$current_user = null;

// Confirm the change with the faculty member's own login password, so a
// left-open session cannot be used to silently reset the student master
// password.
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

$env_locked = attendance_master_password_env_locked();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $my_password = (string)($_POST['my_password'] ?? '');
    $new_master = (string)($_POST['new_master_password'] ?? '');
    $confirm_master = (string)($_POST['confirm_master_password'] ?? '');

    if ($env_locked) {
        $error_msg = 'The master password is currently set by a server environment variable, which overrides this screen. Remove ATTENDANCE_MASTER_PASSWORD / ATTENDANCE_MASTER_PASSWORD_HASH from the server configuration to manage it here.';
    } elseif (!$current_user) {
        $error_msg = 'Unable to verify your account. Please log out and log in again.';
    } elseif ($my_password === '' || $new_master === '' || $confirm_master === '') {
        $error_msg = 'Please fill all fields.';
    } elseif (!hash_equals((string)$current_user['password'], $my_password)) {
        $error_msg = 'Your login password is incorrect.';
    } elseif ($new_master !== $confirm_master) {
        $error_msg = 'New master password and confirmation do not match.';
    } elseif (strlen($new_master) < 6) {
        $error_msg = 'Master password must be at least 6 characters.';
    } else {
        list($saved, $save_error) = attendance_master_password_save($new_master);
        if ($saved) {
            $success_msg = 'Master password updated. Share the new password with students who need the detailed report.';
        } else {
            $error_msg = $save_error;
        }
    }
}

$is_customised = attendance_master_password_is_customised();
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-shield-lock me-2"></i>Student Report Master Password</h1>

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
                <div class="col-12 col-lg-7">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4 class="mb-2">Update Master Password</h4>
                            <p class="text-muted" style="font-size:0.9rem;">
                                Students enter this password on the login page to unlock the
                                detailed subject-wise attendance report (lecture, lab and
                                tutorial counts). It is shared by all students &mdash; it is not
                                a per-student password.
                            </p>

                            <?php if ($env_locked): ?>
                                <div class="alert alert-warning" style="font-size:0.875rem;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    The master password is currently supplied by a server
                                    environment variable, which takes priority over this screen.
                                    Changes made here would have no effect until that variable is removed.
                                </div>
                            <?php elseif (!$is_customised): ?>
                                <div class="alert alert-warning" style="font-size:0.875rem;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    The master password is still the installation default
                                    (<code>kdp@2026</code>). Please change it.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success" style="font-size:0.875rem;">
                                    <i class="bi bi-check2-circle me-1"></i>
                                    A custom master password is set. Existing passwords cannot be
                                    displayed &mdash; set a new one if it has been forgotten.
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="masterPassword.php" autocomplete="off">
                                <div class="mb-3">
                                    <label class="form-label" for="my_password">Your Login Password</label>
                                    <input type="password" id="my_password" name="my_password"
                                           class="form-control" autocomplete="current-password"
                                           <?= $env_locked ? 'disabled' : 'required' ?>>
                                    <div class="form-text">Confirms it is really you making this change.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="new_master_password">New Master Password</label>
                                    <input type="password" id="new_master_password" name="new_master_password"
                                           class="form-control" minlength="6" autocomplete="new-password"
                                           <?= $env_locked ? 'disabled' : 'required' ?>>
                                    <div class="form-text">Minimum 6 characters.</div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label" for="confirm_master_password">Confirm New Master Password</label>
                                    <input type="password" id="confirm_master_password" name="confirm_master_password"
                                           class="form-control" minlength="6" autocomplete="new-password"
                                           <?= $env_locked ? 'disabled' : 'required' ?>>
                                </div>
                                <button type="submit" class="btn btn-primary" <?= $env_locked ? 'disabled' : '' ?>>
                                    <i class="bi bi-check2-circle me-1"></i>Update Master Password
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

<?php
require_once __DIR__ . '/auth.php';
if (trim((string)($_SESSION['Name'] ?? '')) !== '') {
    header("Location: home.php");
    exit();
}

include('dbconfig.php');
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['signin-password'];

    $stmt = $conn->prepare("SELECT * FROM faculty WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($password == $user['password']) {
            if ($user['status'] == 1) {
                session_regenerate_id(true);
                $_SESSION['Name'] = $user['Name'];
                header("Location: home.php");
                exit();
            } else {
                $error_message = "Your account is not active. Please contact the admin.";
            }
        } else {
            $error_message = "Wrong credentials. Please try again.";
        }
    } else {
        $error_message = "No user found with that username.";
    }

    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>KDP-MIS | Login</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3949ab">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/portal.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: stretch;
        }
        .login-left {
            background: linear-gradient(135deg, #3949ab 0%, #5c6bc0 50%, #7986cb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
            overflow: hidden;
        }
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        .login-left::after {
            content: '';
            position: absolute;
            bottom: -20%;
            left: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.07);
            border-radius: 50%;
        }
        .login-left-content {
            position: relative;
            z-index: 1;
            color: #fff;
            text-align: center;
            max-width: 380px;
        }
        .login-left-content .school-name {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .login-left-content .school-dept {
            font-size: 1rem;
            opacity: 0.85;
            margin-bottom: 2rem;
        }
        .login-left-content .feature-list {
            list-style: none;
            padding: 0;
            text-align: left;
        }
        .login-left-content .feature-list li {
            padding: 0.4rem 0;
            font-size: 0.95rem;
            opacity: 0.9;
        }
        .login-left-content .feature-list li::before {
            content: '✓';
            margin-right: 0.6rem;
            font-weight: 700;
        }
        .login-right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            background: #f8f9fc;
        }
        .login-box {
            width: 100%;
            max-width: 420px;
        }
        .login-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .login-logo img {
            width: 44px;
            height: 44px;
        }
        .login-logo span {
            font-size: 1.5rem;
            font-weight: 800;
            color: #3949ab;
            letter-spacing: -0.5px;
        }

        /* Left branding panel: large logo + KDP, PATAN */
        .branding-logo {
            display: flex;
            justify-content: center;
        }
        .branding-logo-img {
            width: 160px;
            height: 160px;
            object-fit: contain;
            background: #ffffff;
            border-radius: 50%;
            padding: 8px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
        }
        .branding-name {
            font-size: 2.4rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: 1px;
            text-align: center;
            margin-top: 0.25rem;
        }
        .branding-sub {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.92);
            text-align: center;
            margin-top: 0.35rem;
            font-weight: 500;
        }

        /* Right login panel: medium logo + name + dept */
        .login-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .login-brand-img {
            width: 96px;
            height: 96px;
            object-fit: contain;
            background: #ffffff;
            border-radius: 50%;
            padding: 6px;
            box-shadow: 0 6px 16px rgba(57, 73, 171, 0.18);
        }
        .login-brand-text {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .login-brand-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #3949ab;
            letter-spacing: 0.5px;
        }
        .login-brand-subtitle {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 600;
        }
        .login-card {
            background: #fff;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.05);
        }
        .login-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #222;
            margin-bottom: 0.25rem;
        }
        .login-card .subtitle {
            font-size: 0.9rem;
            color: #888;
            margin-bottom: 1.75rem;
        }
        .login-card .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #444;
        }
        .login-card .form-control {
            border-radius: 0.5rem;
            padding: 0.65rem 0.9rem;
            font-size: 0.95rem;
            border-color: #d0d5dd;
        }
        .login-card .form-control:focus {
            border-color: #5c6bc0;
            box-shadow: 0 0 0 3px rgba(92,107,192,0.15);
        }
        .btn-login {
            background: linear-gradient(135deg, #3949ab, #5c6bc0);
            border: none;
            color: #fff;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.7rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #2e3b9c, #4a5abf);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(57,73,171,0.3);
        }
        @media (max-width: 767.98px) {
            .login-left { display: none; }
            .login-right { padding: 1.5rem 1rem; background: #f8f9fc; min-height: 100vh; }
            .login-card { padding: 1.5rem; }
            .branding-name { font-size: 2rem; }
            .branding-sub { font-size: 1rem; }
            .login-brand-img { width: 84px; height: 84px; }
            .login-brand-title { font-size: 1.3rem; }
        }

        @media (min-width: 768px) and (max-width: 991.98px) {
            .branding-logo-img { width: 130px; height: 130px; }
            .branding-name { font-size: 2rem; }
            .branding-sub { font-size: 1rem; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f8f9fc;">
    <div class="login-wrapper">
        <!-- Left branding panel -->
        <div class="col-md-6 login-left d-none d-md-flex">
            <div class="login-left-content">
                <div class="branding-logo mb-3">
                    <img src="assets/images/app-logo.png" alt="KDP Patan Logo" class="branding-logo-img">
                </div>
                <div class="branding-name">KDP, PATAN</div>
                <div class="branding-sub">Department of Computer Engineering</div>
                <hr style="border-color:rgba(255,255,255,0.25);margin:1.5rem 0;width:80%;">
                <p style="font-size:1.1rem;font-weight:600;margin-bottom:1rem;">Attendance Management System</p>
                <ul class="feature-list">
                    <li>Track Lecture, Lab &amp; Tutorial Attendance</li>
                    <li>Generate Muster Reports (Excel)</li>
                    <li>Manage Students, Faculty &amp; Subjects</li>
                    <li>Bulk Student Upload via CSV</li>
                </ul>
            </div>
        </div>

        <!-- Right login form -->
        <div class="col-12 col-md-6 login-right">
            <div class="login-box">
                <div class="login-brand">
                    <img src="assets/images/app-logo.png" alt="KDP Patan Logo" class="login-brand-img">
                    <div class="login-brand-text">
                        <div class="login-brand-title">KDP, PATAN</div>
                        <div class="login-brand-subtitle">Department of Computer Engineering</div>
                    </div>
                </div>

                <div class="login-card">
                    <h2>Welcome back</h2>
                    <p class="subtitle">Sign in to your account to continue</p>

                    <form method="POST" action="index.php">
                        <div class="mb-3">
                            <label class="form-label" for="signin-username">Username</label>
                            <input id="signin-username" name="username" type="text"
                                   class="form-control" placeholder="Enter your username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="signin-password">Password</label>
                            <input id="signin-password" name="signin-password" type="password"
                                   class="form-control" placeholder="Enter your password" required>
                        </div>
                        <div class="mb-4 d-flex justify-content-between align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="RememberPassword">
                                <label class="form-check-label" for="RememberPassword" style="font-size:0.85rem;">Remember me</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-login w-100">Sign In</button>
                        <button type="button" class="btn btn-outline-secondary w-100 mt-2 pwa-install-btn d-none">
                            Install App
                        </button>
                    </form>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger mt-3 mb-0" style="border-radius:0.5rem;font-size:0.875rem;">
                            
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="text-center mt-3 text-muted" style="font-size:0.8rem;">
                    &copy; <?php echo date('Y'); ?> K.D. Polytechnic, Patan
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap Icons for alert icon -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="assets/js/pwa-install.js"></script>
</body>
</html>

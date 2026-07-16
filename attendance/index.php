<?php
require_once __DIR__ . '/auth.php';
if (trim((string)($_SESSION['Name'] ?? '')) !== '') {
    header("Location: home.php");
    exit();
}

include('dbconfig.php');
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
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
                $_SESSION['username'] = $user['username'];
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

// ── Student attendance check (public, enrollment number only) ────────────────
// Shows ONLY the final combined percentage (lectures + labs + tutorials).
$att_check_enrollment = trim((string)($_POST['check_enrollment'] ?? ''));
$att_check_pct = null;
$att_check_error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_enrollment'])) {
    if ($att_check_enrollment === '') {
        $att_check_error = 'Please enter your enrollment number.';
    } else {
        // Latest term record for this enrollment (a student can appear in
        // multiple terms; the most recent one is the active one).
        $stu_stmt = $conn->prepare("SELECT id, enrollmentNo, term, sem, class, labBatch, tutBatch FROM students WHERE enrollmentNo = ? ORDER BY term DESC LIMIT 1");
        $stu_stmt->bind_param('s', $att_check_enrollment);
        $stu_stmt->execute();
        $att_student = $stu_stmt->get_result()->fetch_assoc();
        $stu_stmt->close();

        if (!$att_student) {
            $att_check_error = 'No student found with that enrollment number.';
        } else {
            $stu_id    = trim((string)$att_student['id']);
            $stu_enr   = trim((string)$att_student['enrollmentNo']);
            $stu_term  = (string)$att_student['term'];
            $stu_sem   = (string)$att_student['sem'];
            $stu_class = trim((string)$att_student['class']);
            $stu_lab_batch = strtoupper(trim((string)$att_student['labBatch']));
            $stu_tut_batch = strtoupper(trim((string)$att_student['tutBatch']));

            // presentNo holds enrollment numbers (or legacy student ids)
            $is_present = function ($presentNo) use ($stu_enr, $stu_id) {
                foreach (explode(',', (string)$presentNo) as $token) {
                    $token = trim($token);
                    if ($token !== '' && ($token === $stu_enr || $token === $stu_id)) {
                        return true;
                    }
                }
                return false;
            };
            // batch column can hold a CSV of batches
            $batch_matches = function ($batchCsv, $stuBatch) {
                if ($stuBatch === '') {
                    return false;
                }
                foreach (explode(',', (string)$batchCsv) as $token) {
                    if (strtoupper(trim($token)) === $stuBatch) {
                        return true;
                    }
                }
                return false;
            };

            $att_total = 0;
            $att_present = 0;

            $lec_stmt = $conn->prepare("SELECT presentNo FROM lecattendance WHERE term = ? AND sem = ? AND class = ?");
            $lec_stmt->bind_param('sss', $stu_term, $stu_sem, $stu_class);
            $lec_stmt->execute();
            $lec_res = $lec_stmt->get_result();
            while ($lec_row = $lec_res->fetch_assoc()) {
                $att_total++;
                if ($is_present($lec_row['presentNo'] ?? '')) {
                    $att_present++;
                }
            }
            $lec_stmt->close();

            $lab_stmt = $conn->prepare("SELECT batch, presentNo FROM labattendance WHERE term = ? AND sem = ? AND COALESCE(TRIM(labNo), '') <> ''");
            $lab_stmt->bind_param('ss', $stu_term, $stu_sem);
            $lab_stmt->execute();
            $lab_res = $lab_stmt->get_result();
            while ($lab_row = $lab_res->fetch_assoc()) {
                if (!$batch_matches($lab_row['batch'] ?? '', $stu_lab_batch)) {
                    continue;
                }
                $att_total++;
                if ($is_present($lab_row['presentNo'] ?? '')) {
                    $att_present++;
                }
            }
            $lab_stmt->close();

            $tut_stmt = $conn->prepare("SELECT batch, presentNo FROM tutattendance WHERE term = ? AND sem = ?");
            $tut_stmt->bind_param('ss', $stu_term, $stu_sem);
            $tut_stmt->execute();
            $tut_res = $tut_stmt->get_result();
            while ($tut_row = $tut_res->fetch_assoc()) {
                if (!$batch_matches($tut_row['batch'] ?? '', $stu_tut_batch)) {
                    continue;
                }
                $att_total++;
                if ($is_present($tut_row['presentNo'] ?? '')) {
                    $att_present++;
                }
            }
            $tut_stmt->close();

            if ($att_total === 0) {
                $att_check_error = 'No attendance records found yet for this enrollment number.';
            } else {
                $att_check_pct = ($att_present * 100) / $att_total;
            }
        }
    }
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
        /* Student attendance check */
        .att-check-form .input-group {
            align-items: stretch;
        }
        .att-check-form .form-control {
            height: 46px;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: 0;
        }
        .att-check-form .form-control:focus {
            z-index: 1;
        }
        .att-check-form .att-check-btn {
            height: 46px;
            padding: 0 1.15rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            font-size: 0.95rem;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        .att-check-form .att-check-btn:hover,
        .att-check-form .att-check-btn:focus {
            transform: none;
            box-shadow: 0 2px 8px rgba(57, 73, 171, 0.25);
        }
        .att-check-result {
            margin-top: 1rem;
            border-radius: 0.6rem;
            padding: 1rem;
            text-align: center;
        }
        .att-check-result-label {
            font-size: 0.8rem;
            font-weight: 600;
            opacity: 0.8;
            margin-bottom: 0.15rem;
        }
        .att-check-result-value {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .att-pct-good {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            color: #047857;
        }
        .att-pct-warn {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            color: #b45309;
        }
        .att-pct-low {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
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

                <div class="login-card mt-3" id="att-check">
                    <h2 style="font-size:1.15rem;"><i class="bi bi-mortarboard me-1"></i>Student Corner</h2>
                    <p class="subtitle" style="margin-bottom:1rem;">Check your overall attendance percentage</p>

                    <form method="POST" action="index.php#att-check" class="att-check-form">
                        <div class="input-group">
                            <input type="text" name="check_enrollment" class="form-control"
                                   placeholder="Enter Enrollment No."
                                   value="<?php echo htmlspecialchars($att_check_enrollment); ?>" required>
                            <button type="submit" class="btn btn-login att-check-btn">
                                <i class="bi bi-search me-1"></i>Check
                            </button>
                        </div>
                    </form>

                    <?php if ($att_check_pct !== null): ?>
                        <?php $att_pct_class = $att_check_pct >= 75 ? 'att-pct-good' : ($att_check_pct >= 60 ? 'att-pct-warn' : 'att-pct-low'); ?>
                        <div class="att-check-result <?php echo $att_pct_class; ?>">
                            <div class="att-check-result-label">Attendance of <?php echo htmlspecialchars($att_check_enrollment); ?></div>
                            <div class="att-check-result-value"><?php echo number_format($att_check_pct, 2); ?>%</div>
                        </div>
                    <?php elseif ($att_check_error !== ''): ?>
                        <div class="alert alert-warning mt-3 mb-0" style="border-radius:0.5rem;font-size:0.875rem;">
                            <?php echo htmlspecialchars($att_check_error); ?>
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

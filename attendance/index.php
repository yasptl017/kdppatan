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
// Shows subject-wise attendance percentage (lectures + labs + tutorials
// combined per subject) grouped by term, along with the student's name and
// enrollment number.
//
// A "Detailed Report" view additionally breaks each subject down into
// lecture / lab / tutorial present-out-of-total counts. That view is gated
// behind a master password (see studentreport.config.php) so the extra detail
// is not exposed to anyone who merely knows an enrollment number.
require_once __DIR__ . '/studentreport.config.php';

$att_check_enrollment = trim((string)($_POST['check_enrollment'] ?? ''));
$att_check_terms = [];
$att_check_error = '';
$att_student_name = '';

// Detailed-report gate
$att_detail_requested = isset($_POST['detailed_report']);
$att_detail_unlocked = false;
$att_detail_error = '';

if ($att_detail_requested) {
    $att_master_password = (string)($_POST['master_password'] ?? '');

    // Throttle guesses: 5 failures locks the form for 5 minutes per session.
    $now = time();
    $lock_until = (int)($_SESSION['att_detail_lock_until'] ?? 0);
    $fail_count = (int)($_SESSION['att_detail_fail_count'] ?? 0);

    if ($lock_until > $now) {
        $att_detail_error = 'Too many incorrect attempts. Please try again in '
            . (int)ceil(($lock_until - $now) / 60) . ' minute(s).';
    } elseif ($att_master_password === '') {
        $att_detail_error = 'Please enter the master password.';
    } elseif (attendance_master_password_verify($att_master_password)) {
        $att_detail_unlocked = true;
        $_SESSION['att_detail_fail_count'] = 0;
        $_SESSION['att_detail_lock_until'] = 0;
    } else {
        $fail_count++;
        $_SESSION['att_detail_fail_count'] = $fail_count;
        if ($fail_count >= 5) {
            $_SESSION['att_detail_lock_until'] = $now + 300;
            $_SESSION['att_detail_fail_count'] = 0;
            $att_detail_error = 'Too many incorrect attempts. Please try again in 5 minute(s).';
        } else {
            $att_detail_error = 'Incorrect master password. Please contact the department if you do not have it.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_enrollment'])) {
    if ($att_check_enrollment === '') {
        $att_check_error = 'Please enter your enrollment number.';
    } else {
        // A student can appear in multiple terms; show all of them.
        $stu_stmt = $conn->prepare("SELECT id, enrollmentNo, name, term, sem, class, labBatch, tutBatch FROM students WHERE enrollmentNo = ? ORDER BY term DESC");
        $stu_stmt->bind_param('s', $att_check_enrollment);
        $stu_stmt->execute();
        $att_students = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stu_stmt->close();

        if (empty($att_students)) {
            $att_check_error = 'No student found with that enrollment number.';
        } else {
            // presentNo holds enrollment numbers (or legacy student ids)
            $is_present = function ($presentNo, $stuEnr, $stuId) {
                foreach (explode(',', (string)$presentNo) as $token) {
                    $token = trim($token);
                    if ($token !== '' && ($token === $stuEnr || $token === $stuId)) {
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

            foreach ($att_students as $att_student) {
                $stu_id    = trim((string)$att_student['id']);
                $stu_enr   = trim((string)$att_student['enrollmentNo']);
                $stu_term  = (string)$att_student['term'];
                $stu_sem   = (string)$att_student['sem'];
                $stu_class = trim((string)$att_student['class']);
                $stu_lab_batch = strtoupper(trim((string)$att_student['labBatch']));
                $stu_tut_batch = strtoupper(trim((string)$att_student['tutBatch']));

                if ($att_student_name === '') {
                    $att_student_name = trim((string)($att_student['name'] ?? ''));
                }

                // subject => per-mode totals, e.g. ['lecture_total' => n, 'lecture_present' => n, ...]
                $subject_totals = [];
                $bump = function ($subject, $mode, $present) use (&$subject_totals) {
                    $subject = trim((string)$subject) === '' ? 'Unknown Subject' : trim((string)$subject);
                    if (!isset($subject_totals[$subject])) {
                        $subject_totals[$subject] = [
                            'lecture_total' => 0, 'lecture_present' => 0,
                            'lab_total' => 0, 'lab_present' => 0,
                            'tutorial_total' => 0, 'tutorial_present' => 0,
                        ];
                    }
                    $subject_totals[$subject][$mode . '_total']++;
                    if ($present) {
                        $subject_totals[$subject][$mode . '_present']++;
                    }
                };

                $lec_stmt = $conn->prepare("SELECT subject, presentNo FROM lecattendance WHERE term = ? AND sem = ? AND class = ?");
                $lec_stmt->bind_param('sss', $stu_term, $stu_sem, $stu_class);
                $lec_stmt->execute();
                $lec_res = $lec_stmt->get_result();
                while ($lec_row = $lec_res->fetch_assoc()) {
                    $bump($lec_row['subject'] ?? '', 'lecture', $is_present($lec_row['presentNo'] ?? '', $stu_enr, $stu_id));
                }
                $lec_stmt->close();

                $lab_stmt = $conn->prepare("SELECT subject, batch, presentNo FROM labattendance WHERE term = ? AND sem = ? AND COALESCE(TRIM(labNo), '') <> ''");
                $lab_stmt->bind_param('ss', $stu_term, $stu_sem);
                $lab_stmt->execute();
                $lab_res = $lab_stmt->get_result();
                while ($lab_row = $lab_res->fetch_assoc()) {
                    if (!$batch_matches($lab_row['batch'] ?? '', $stu_lab_batch)) {
                        continue;
                    }
                    $bump($lab_row['subject'] ?? '', 'lab', $is_present($lab_row['presentNo'] ?? '', $stu_enr, $stu_id));
                }
                $lab_stmt->close();

                $tut_stmt = $conn->prepare("SELECT subject, batch, presentNo FROM tutattendance WHERE term = ? AND sem = ?");
                $tut_stmt->bind_param('ss', $stu_term, $stu_sem);
                $tut_stmt->execute();
                $tut_res = $tut_stmt->get_result();
                while ($tut_row = $tut_res->fetch_assoc()) {
                    if (!$batch_matches($tut_row['batch'] ?? '', $stu_tut_batch)) {
                        continue;
                    }
                    $bump($tut_row['subject'] ?? '', 'tutorial', $is_present($tut_row['presentNo'] ?? '', $stu_enr, $stu_id));
                }
                $tut_stmt->close();

                if (!empty($subject_totals)) {
                    ksort($subject_totals, SORT_NATURAL | SORT_FLAG_CASE);
                    $subject_rows = [];
                    $term_overall = ['total' => 0, 'present' => 0];
                    foreach ($subject_totals as $subjectName => $counts) {
                        $grand_total = $counts['lecture_total'] + $counts['lab_total'] + $counts['tutorial_total'];
                        $grand_present = $counts['lecture_present'] + $counts['lab_present'] + $counts['tutorial_present'];
                        $term_overall['total'] += $grand_total;
                        $term_overall['present'] += $grand_present;

                        $subject_rows[] = [
                            'subject' => $subjectName,
                            'lecture_total' => $counts['lecture_total'],
                            'lecture_present' => $counts['lecture_present'],
                            'lab_total' => $counts['lab_total'],
                            'lab_present' => $counts['lab_present'],
                            'tutorial_total' => $counts['tutorial_total'],
                            'tutorial_present' => $counts['tutorial_present'],
                            'total' => $grand_total,
                            'present' => $grand_present,
                            'percentage' => $grand_total > 0 ? ($grand_present * 100) / $grand_total : 0,
                        ];
                    }
                    $att_check_terms[] = [
                        'term' => $stu_term,
                        'sem' => $stu_sem,
                        'class' => $stu_class,
                        'labBatch' => (string)$att_student['labBatch'],
                        'tutBatch' => (string)$att_student['tutBatch'],
                        'subjects' => $subject_rows,
                        'overall' => $term_overall,
                    ];
                }
            }

            if (empty($att_check_terms)) {
                $att_check_error = 'No attendance records found yet for this enrollment number.';
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
        .att-check-result-wrap {
            margin-top: 1rem;
            text-align: left;
        }
        .att-term-group {
            margin-bottom: 1rem;
        }
        .att-term-group:last-child {
            margin-bottom: 0;
        }
        .att-term-heading {
            font-size: 0.85rem;
            font-weight: 700;
            color: #3949ab;
            margin-bottom: 0.5rem;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .att-subject-list {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .att-subject-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border-radius: 0.5rem;
            padding: 0.55rem 0.85rem;
        }
        .att-subject-name {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .att-subject-pct {
            font-size: 1rem;
            font-weight: 800;
            white-space: nowrap;
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
        /* Student identity header */
        .att-student-head {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 0.5rem;
            padding: 0.65rem 0.85rem;
            margin-bottom: 0.85rem;
        }
        .att-student-name {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.3;
        }
        .att-student-enr {
            font-size: 0.82rem;
            font-weight: 600;
            color: #3949ab;
            letter-spacing: 0.3px;
            margin-top: 0.15rem;
        }
        /* Detailed report toggle + password prompt */
        .att-detail-toggle {
            margin-top: 0.85rem;
        }
        .btn-att-detail {
            width: 100%;
            border: 1px solid #3949ab;
            background: #fff;
            color: #3949ab;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.55rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        .btn-att-detail:hover {
            background: #3949ab;
            color: #fff;
        }
        .att-detail-prompt {
            margin-top: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.85rem;
        }
        .att-detail-prompt .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.35rem;
        }
        /* Match the password field to the View button so both are 46px tall
           and meet flush in the middle of the input group. */
        .att-detail-prompt .input-group {
            align-items: stretch;
        }
        .att-detail-prompt .form-control {
            height: 46px;
            padding: 0.65rem 0.9rem;
            font-size: 0.95rem;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: 0;
        }
        .att-detail-prompt .form-control:focus {
            z-index: 1;
        }
        .att-detail-prompt .att-check-btn {
            height: 46px;
        }
        .att-detail-hint {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.4rem;
            margin-bottom: 0;
        }
        /* Detailed subject-wise table */
        .att-detail-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .att-detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 460px;
        }
        .att-detail-table th,
        .att-detail-table td {
            padding: 0.45rem 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: center;
            white-space: nowrap;
        }
        .att-detail-table thead th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .att-detail-table th.att-col-subject,
        .att-detail-table td.att-col-subject {
            text-align: left;
            white-space: normal;
            font-weight: 600;
            color: #1e293b;
            min-width: 120px;
        }
        .att-detail-table td.att-col-pct {
            font-weight: 800;
        }
        .att-detail-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .att-detail-table tfoot td {
            background: #f8fafc;
            font-weight: 800;
            color: #1e293b;
            border-top: 2px solid #cbd5e1;
        }
        .att-txt-good { color: #047857; }
        .att-txt-warn { color: #b45309; }
        .att-txt-low  { color: #b91c1c; }
        .att-detail-meta {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.5rem;
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

                    <?php if (!empty($att_check_terms)): ?>
                        <div class="att-check-result-wrap">
                            <div class="att-student-head">
                                <div class="att-student-name">
                                    <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($att_student_name !== '' ? $att_student_name : 'Name not available'); ?>
                                </div>
                                <div class="att-student-enr">
                                    <i class="bi bi-card-text me-1"></i>Enrollment No: <?php echo htmlspecialchars($att_check_enrollment); ?>
                                </div>
                            </div>

                            <?php foreach ($att_check_terms as $termGroup): ?>
                                <div class="att-term-group">
                                    <div class="att-term-heading">Term <?php echo htmlspecialchars($termGroup['term']); ?> &middot; Sem <?php echo htmlspecialchars($termGroup['sem']); ?></div>

                                    <?php if ($att_detail_unlocked): ?>
                                        <div class="att-detail-meta">
                                            Class: <strong><?php echo htmlspecialchars($termGroup['class'] !== '' ? $termGroup['class'] : '-'); ?></strong>
                                            &middot; Lab Batch: <strong><?php echo htmlspecialchars(trim($termGroup['labBatch']) !== '' ? $termGroup['labBatch'] : '-'); ?></strong>
                                            &middot; Tut Batch: <strong><?php echo htmlspecialchars(trim($termGroup['tutBatch']) !== '' ? $termGroup['tutBatch'] : '-'); ?></strong>
                                        </div>
                                        <div class="att-detail-table-wrap">
                                            <table class="att-detail-table">
                                                <thead>
                                                    <tr>
                                                        <th class="att-col-subject">Subject</th>
                                                        <th>Lecture</th>
                                                        <th>Lab</th>
                                                        <th>Tutorial</th>
                                                        <th>Total</th>
                                                        <th>%</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($termGroup['subjects'] as $subjectRow): ?>
                                                        <?php $subPctTxt = $subjectRow['percentage'] >= 75 ? 'att-txt-good' : ($subjectRow['percentage'] >= 60 ? 'att-txt-warn' : 'att-txt-low'); ?>
                                                        <tr>
                                                            <td class="att-col-subject"><?php echo htmlspecialchars($subjectRow['subject']); ?></td>
                                                            <td><?php echo (int)$subjectRow['lecture_present']; ?>/<?php echo (int)$subjectRow['lecture_total']; ?></td>
                                                            <td><?php echo (int)$subjectRow['lab_present']; ?>/<?php echo (int)$subjectRow['lab_total']; ?></td>
                                                            <td><?php echo (int)$subjectRow['tutorial_present']; ?>/<?php echo (int)$subjectRow['tutorial_total']; ?></td>
                                                            <td><?php echo (int)$subjectRow['present']; ?>/<?php echo (int)$subjectRow['total']; ?></td>
                                                            <td class="att-col-pct <?php echo $subPctTxt; ?>"><?php echo number_format($subjectRow['percentage'], 2); ?>%</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <?php
                                                    $termTotal = (int)$termGroup['overall']['total'];
                                                    $termPresent = (int)$termGroup['overall']['present'];
                                                    $termPct = $termTotal > 0 ? ($termPresent * 100) / $termTotal : 0;
                                                    $termPctTxt = $termPct >= 75 ? 'att-txt-good' : ($termPct >= 60 ? 'att-txt-warn' : 'att-txt-low');
                                                ?>
                                                <tfoot>
                                                    <tr>
                                                        <td class="att-col-subject">Overall</td>
                                                        <td colspan="3"></td>
                                                        <td><?php echo $termPresent; ?>/<?php echo $termTotal; ?></td>
                                                        <td class="att-col-pct <?php echo $termPctTxt; ?>"><?php echo number_format($termPct, 2); ?>%</td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="att-subject-list">
                                            <?php foreach ($termGroup['subjects'] as $subjectRow): ?>
                                                <?php $subPctClass = $subjectRow['percentage'] >= 75 ? 'att-pct-good' : ($subjectRow['percentage'] >= 60 ? 'att-pct-warn' : 'att-pct-low'); ?>
                                                <div class="att-subject-row <?php echo $subPctClass; ?>">
                                                    <span class="att-subject-name"><?php echo htmlspecialchars($subjectRow['subject']); ?></span>
                                                    <span class="att-subject-pct"><?php echo number_format($subjectRow['percentage'], 2); ?>%</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!$att_detail_unlocked): ?>
                                <div class="att-detail-toggle">
                                    <button type="button" class="btn btn-att-detail" id="att-detail-btn"
                                            aria-expanded="<?php echo ($att_detail_requested ? 'true' : 'false'); ?>"
                                            aria-controls="att-detail-prompt">
                                        <i class="bi bi-file-earmark-bar-graph me-1"></i>Detailed Report
                                    </button>

                                    <div class="att-detail-prompt <?php echo ($att_detail_requested ? '' : 'd-none'); ?>" id="att-detail-prompt">
                                        <form method="POST" action="index.php#att-check">
                                            <input type="hidden" name="check_enrollment"
                                                   value="<?php echo htmlspecialchars($att_check_enrollment); ?>">
                                            <label class="form-label" for="master_password">Master Password</label>
                                            <div class="input-group">
                                                <input type="password" id="master_password" name="master_password"
                                                       class="form-control" placeholder="Enter master password"
                                                       autocomplete="off" required>
                                                <button type="submit" name="detailed_report" value="1"
                                                        class="btn btn-login att-check-btn">
                                                    <i class="bi bi-unlock me-1"></i>View
                                                </button>
                                            </div>
                                            <p class="att-detail-hint">
                                                The detailed subject-wise report is protected. Ask the department for the master password.
                                            </p>
                                        </form>

                                        <?php if ($att_detail_error !== ''): ?>
                                            <div class="alert alert-danger mt-2 mb-0" style="border-radius:0.5rem;font-size:0.8rem;">
                                                <?php echo htmlspecialchars($att_detail_error); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="att-detail-toggle">
                                    <form method="POST" action="index.php#att-check">
                                        <input type="hidden" name="check_enrollment"
                                               value="<?php echo htmlspecialchars($att_check_enrollment); ?>">
                                        <button type="submit" class="btn btn-att-detail">
                                            <i class="bi bi-arrow-left me-1"></i>Back to Summary
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
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
    <script>
        // Reveal the master-password prompt when "Detailed Report" is clicked.
        (function () {
            var btn = document.getElementById('att-detail-btn');
            var prompt = document.getElementById('att-detail-prompt');
            if (!btn || !prompt) {
                return;
            }
            btn.addEventListener('click', function () {
                var hidden = prompt.classList.toggle('d-none');
                btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                if (!hidden) {
                    var field = document.getElementById('master_password');
                    if (field) {
                        field.focus();
                    }
                }
            });
        })();
    </script>
</body>
</html>

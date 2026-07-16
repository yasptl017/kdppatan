<?php
require_once __DIR__ . '/auth.php';
require_login();
$current_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$mapping_pages = [
    'addLectureMapping.php',
    'addLabMapping.php',
    'addTutMapping.php',
];
$alternate_pages = [
    'lecAttendance.php',
    'labAttendance.php',
    'tutAttendance.php',
];
$manage_pages = [
    'managefaculty.php',
    'managesubjects.php',
    'managestudents.php',
    'managesemester.php',
    'manageterm.php',
    'manageslot.php',
    'managelabs.php',
    'managementor.php',
    'bulkupload.php',
    'editfaculty.php',
    'editsubjects.php',
    'editstudent.php',
    'editsemester.php',
    'editterm.php',
    'editslot.php',
];
$is_mapping_page = in_array($current_page, $mapping_pages, true);
$is_alternate_page = in_array($current_page, $alternate_pages, true);
$is_manage_page = in_array($current_page, $manage_pages, true);
$current_username = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : (isset($_SESSION['Name']) ? trim((string)$_SESSION['Name']) : '');
$is_admin_user = strcasecmp($current_username, 'admin') === 0;
$header_enrollment_search = htmlspecialchars(trim((string)($_GET['enrollment'] ?? '')));
?>
<header class="app-header fixed-top">
    <div class="app-header-inner">
        <div class="container-fluid py-2">
            <div class="app-header-content">
                <div class="row justify-content-between align-items-center">

                    <div class="col-auto d-flex align-items-center gap-2">
                        <a id="sidepanel-toggler" class="sidepanel-toggler d-inline-block d-xl-none" href="#">
                            <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" role="img"><title>Menu</title><path stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M4 7h22M4 15h22M4 23h22"></path></svg>
                        </a>
                        <a id="sidepanel-toggler-desktop" class="sidepanel-toggler-desktop d-none d-xl-inline-flex" href="#" title="Toggle Sidebar">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                        </a>
                    </div>

                    <button type="button" class="search-mobile-trigger d-none col" id="search-mobile-trigger" aria-label="Open search">
                        <i class="search-mobile-trigger-icon fa-solid fa-magnifying-glass"></i>
                    </button>

                    <div class="app-search-box d-none d-sm-block col" id="app-search-box">
                        <form class="app-search-form" method="GET" action="studentAttendance.php">
                            <div class="app-search-input-wrap">
                                <input type="text" placeholder="Search Enrollment No..." name="enrollment" value="<?= $header_enrollment_search; ?>" class="form-control search-input">
                                <button type="submit" class="btn search-btn" aria-label="Search">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="app-utilities col-auto">
                        <div class="app-utility-item app-user-dropdown dropdown">
                            <a class="dropdown-toggle d-flex align-items-center gap-2" id="user-dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                                <span class="user-icon-holder" aria-hidden="true">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <span class="d-none d-md-inline text-truncate user-name-label">
                                    <?php echo isset($_SESSION['Name']) ? htmlspecialchars($_SESSION['Name']) : 'User'; ?>
                                </span>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end user-profile-dropdown" aria-labelledby="user-dropdown-toggle">
                                <a class="dropdown-item user-profile-action <?= $current_page === 'changePassword.php' ? 'active' : '' ?>" href="changePassword.php">
                                    <i class="bi bi-key me-2"></i>
                                    <span>Change Password</span>
                                </a>
                                <a class="dropdown-item user-profile-action" href="logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>
                                    <span>Log Out</span>
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div id="app-sidepanel" class="app-sidepanel">
        <div id="sidepanel-drop" class="sidepanel-drop"></div>
        <div class="sidepanel-inner d-flex flex-column">
            <div class="sidepanel-header">
                <div class="app-branding">
                    <a class="app-logo" href="home.php">
                        <img class="logo-icon me-2" src="assets/images/app-logo.png" alt="KDP, PATAN Logo">
                        <span class="logo-text">KDP, PATAN</span>
                    </a>
                </div>
                <button type="button" id="sidepanel-close" class="sidepanel-close d-xl-none" aria-label="Close sidebar" title="Close">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>

            <nav id="app-nav-main" class="app-nav app-nav-main flex-grow-1">
                <ul class="app-menu list-unstyled accordion" id="menu-accordion">

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'home.php' ? 'active' : '' ?>" href="home.php">
                            <span class="nav-icon"><i class="bi bi-house-door"></i></span>
                            <span class="nav-link-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['myAttendanceSelect.php', 'myAttendance.php', 'takelecatt.php', 'takelabatt.php', 'taketutatt.php'], true) ? 'active' : '' ?>" href="myAttendanceSelect.php">
                            <span class="nav-icon"><i class="bi bi-calendar2-check"></i></span>
                            <span class="nav-link-text">My Attendance</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'extraAttendance.php' ? 'active' : '' ?>" href="extraAttendance.php">
                            <span class="nav-icon"><i class="bi bi-plus-circle"></i></span>
                            <span class="nav-link-text">Extra Attendance</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'studentAttendance.php' ? 'active' : '' ?>" href="studentAttendance.php">
                            <span class="nav-icon"><i class="bi bi-person-vcard"></i></span>
                            <span class="nav-link-text">Student History</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'timetable.php' ? 'active' : '' ?>" href="timetable.php">
                            <span class="nav-icon"><i class="bi bi-table"></i></span>
                            <span class="nav-link-text">Timetable</span>
                        </a>
                    </li>

                    <li class="nav-item has-submenu">
                        <a class="nav-link submenu-toggle <?= $is_mapping_page ? 'active' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#submenu-add-mapping" aria-expanded="<?= $is_mapping_page ? 'true' : 'false' ?>" aria-controls="submenu-add-mapping">
                            <span class="nav-icon"><i class="bi bi-calendar-week"></i></span>
                            <span class="nav-link-text">Add Mapping</span>
                            <span class="submenu-arrow">
                                <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-chevron-down" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                </svg>
                            </span>
                        </a>
                        <div id="submenu-add-mapping" class="collapse submenu <?= $is_mapping_page ? 'show' : '' ?>" data-bs-parent="#menu-accordion">
                            <ul class="submenu-list list-unstyled">
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'addLectureMapping.php' ? 'active' : '' ?>" href="addLectureMapping.php"><i class="bi bi-calendar-week me-1"></i>Lecture Mapping</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'addLabMapping.php' ? 'active' : '' ?>" href="addLabMapping.php"><i class="bi bi-diagram-3 me-1"></i>Lab Mapping</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'addTutMapping.php' ? 'active' : '' ?>" href="addTutMapping.php"><i class="bi bi-journal-check me-1"></i>Tutorial Mapping</a></li>
                            </ul>
                        </div>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['editAttendance.php', 'editlecatt.php', 'editlabatt.php', 'edittutatt.php'], true) ? 'active' : '' ?>" href="editAttendance.php">
                            <span class="nav-icon"><i class="bi bi-pencil-square"></i></span>
                            <span class="nav-link-text">Edit Attendance</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['lecmuster.php', 'attendanceSummary.php'], true) ? 'active' : '' ?>" href="lecmuster.php">
                            <span class="nav-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span>
                            <span class="nav-link-text">Muster Report</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'attendanceAnalysis.php' ? 'active' : '' ?>" href="attendanceAnalysis.php">
                            <span class="nav-icon"><i class="bi bi-bar-chart-line"></i></span>
                            <span class="nav-link-text">Attendance Analysis</span>
                        </a>
                    </li>
                    <?php if ($is_admin_user): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'pendingAttendance.php' ? 'active' : '' ?>" href="pendingAttendance.php">
                            <span class="nav-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="nav-link-text">Pending Attendance</span>
                        </a>
                    </li>

                    <li class="nav-item has-submenu">
                        <a class="nav-link submenu-toggle <?= $is_manage_page ? 'active' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#submenu-1" aria-expanded="<?= $is_manage_page ? 'true' : 'false' ?>" aria-controls="submenu-1">
                            <span class="nav-icon"><i class="bi bi-gear"></i></span>
                            <span class="nav-link-text">Manage</span>
                            <span class="submenu-arrow">
                                <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-chevron-down" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                </svg>
                            </span>
                        </a>
                        <div id="submenu-1" class="collapse submenu submenu-1 <?= $is_manage_page ? 'show' : '' ?>" data-bs-parent="#menu-accordion">
                            <ul class="submenu-list list-unstyled">
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['managefaculty.php', 'editfaculty.php'], true) ? 'active' : '' ?>" href="managefaculty.php"><i class="bi bi-person-badge me-1"></i>Faculty</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['managesubjects.php', 'editsubjects.php'], true) ? 'active' : '' ?>" href="managesubjects.php"><i class="bi bi-journal-bookmark me-1"></i>Subjects</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['managestudents.php', 'editstudent.php'], true) ? 'active' : '' ?>" href="managestudents.php"><i class="bi bi-people me-1"></i>Students</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['manageterm.php', 'editterm.php'], true) ? 'active' : '' ?>" href="manageterm.php"><i class="bi bi-calendar-range me-1"></i>Term</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['managesemester.php', 'editsemester.php'], true) ? 'active' : '' ?>" href="managesemester.php"><i class="bi bi-calendar3 me-1"></i>Semester</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= in_array($current_page, ['manageslot.php', 'editslot.php'], true) ? 'active' : '' ?>" href="manageslot.php"><i class="bi bi-clock me-1"></i>Slots</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'managelabs.php' ? 'active' : '' ?>" href="managelabs.php"><i class="bi bi-building me-1"></i>Labs</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'managementor.php' ? 'active' : '' ?>" href="managementor.php"><i class="bi bi-people-fill me-1"></i>Mentor</a></li>
                                <li class="submenu-item"><a class="submenu-link <?= $current_page === 'bulkupload.php' ? 'active' : '' ?>" href="bulkupload.php"><i class="bi bi-upload me-1"></i>Bulk Upload</a></li>
                            </ul>
                        </div>
                    </li>
                    <?php endif; ?>

                </ul>
            </nav>

            <div class="app-sidepanel-footer">
                <nav class="app-nav app-nav-footer">
                    <ul class="app-menu footer-menu list-unstyled">
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <span class="nav-icon"><i class="bi bi-box-arrow-right"></i></span>
                                <span class="nav-link-text"><?php echo isset($_SESSION['Name']) ? htmlspecialchars($_SESSION['Name']) : ''; ?></span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>

        </div>
    </div>
</header>

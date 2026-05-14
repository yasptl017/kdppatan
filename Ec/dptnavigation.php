<?php
/**
 * Department Navigation with Auto Active Detection
 * Automatically highlights the active page
 * 
 * Usage: <?php include 'dptnavigation.php'; ?>
 */

// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Navigation configuration
$nav_config = [
    [
        'file' => 'aboutdpt.php',
        'label' => 'About',
        'icon' => 'fa-info-circle',
        'aliases' => ['index.php'] // These pages also show "About" as active
    ],
    [
        'file' => 'notice-board.php',
        'label' => 'Notice Board',
        'icon' => 'fa-bullhorn',
        'aliases' => ['notice-details.php'] // Notice details keeps Notice Board active
    ],
    [
        'file' => 'academic-calendar.php',
        'label' => 'Academic Calendar',
        'icon' => 'fa-calendar-alt',
        'aliases' => [],
        'requires_data' => 'dept_academic_calendar'
    ],
    [
        'file' => 'timetable.php',
        'label' => 'Time Tables',
        'icon' => 'fa-clock',
        'aliases' => [],
        'requires_data' => 'dept_timetable'
    ],
    [
        'file' => 'results.php',
        'label' => 'Results',
        'icon' => 'fa-file-pdf',
        'aliases' => [],
        'requires_data' => 'dept_results'
    ],
    [
        'file' => 'material.php',
        'label' => 'Materials',
        'icon' => 'fa-book-reader',
        'aliases' => [],
        'requires_data' => 'dept_material'
    ],
    [
        'file' => 'faculty.php',
        'label' => 'Faculty',
        'icon' => 'fa-chalkboard-teacher',
        'aliases' => ['faculty-profile.php'] // Profile page keeps Faculty active
    ],
    [
        'file' => 'activities.php',
        'label' => 'Activities',
        'icon' => 'fa-running',
        'aliases' => []
    ],
    [
        'file' => 'facilities.php',
        'label' => 'Facilities',
        'icon' => 'fa-building',
        'aliases' => []
    ],
    [
        'file' => 'newsletter.php',
        'label' => 'Newsletter',
        'icon' => 'fa-newspaper',
        'aliases' => []
    ],
    [
        'file' => 'syllabus.php',
        'label' => 'Syllabus',
        'icon' => 'fa-book-open',
        'aliases' => []
    ],
];

// Only show Academic Calendar tab if this department has at least one visible record
$_nav_config_filtered = [];
foreach ($nav_config as $_nav_item) {
    if (($_nav_item['requires_data'] ?? '') === 'dept_academic_calendar') {
        $_show_calendar = false;
        if (isset($conn) && isset($DEPARTMENT_NAME)) {
            $_table_check = $conn->query("SHOW TABLES LIKE 'dept_academic_calendar'");
            if ($_table_check && $_table_check->num_rows > 0) {
                $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
                $_cal = $conn->query("SELECT id FROM dept_academic_calendar WHERE department='$_dept_esc' AND display_order >= 0 LIMIT 1");
                $_show_calendar = ($_cal && $_cal->num_rows > 0);
            }
        }
        if (!$_show_calendar) {
            continue;
        }
    }
    if (($_nav_item['requires_data'] ?? '') === 'dept_timetable') {
        $_show_timetable = false;
        if (isset($conn) && isset($DEPARTMENT_NAME)) {
            $_table_check = $conn->query("SHOW TABLES LIKE 'dept_timetable'");
            if ($_table_check && $_table_check->num_rows > 0) {
                $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
                $_tt = $conn->query("SELECT id FROM dept_timetable WHERE department='$_dept_esc' AND display_order >= 0 LIMIT 1");
                $_show_timetable = ($_tt && $_tt->num_rows > 0);
            }
        }
        if (!$_show_timetable) {
            continue;
        }
    }
    if (($_nav_item['requires_data'] ?? '') === 'dept_results') {
        $_show_results = false;
        if (isset($conn) && isset($DEPARTMENT_NAME)) {
            $_table_check = $conn->query("SHOW TABLES LIKE 'dept_results'");
            if ($_table_check && $_table_check->num_rows > 0) {
                $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
                $_res = $conn->query("SELECT id FROM dept_results WHERE department='$_dept_esc' AND display_order >= 0 LIMIT 1");
                $_show_results = ($_res && $_res->num_rows > 0);
            }
        }
        if (!$_show_results) {
            continue;
        }
    }
    if (($_nav_item['requires_data'] ?? '') === 'dept_material') {
        $_show_material = false;
        if (isset($conn) && isset($DEPARTMENT_NAME)) {
            $_table_check = $conn->query("SHOW TABLES LIKE 'dept_material'");
            if ($_table_check && $_table_check->num_rows > 0) {
                $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
                $_mat = $conn->query("SELECT id FROM dept_material WHERE department='$_dept_esc' AND display_order >= 0 LIMIT 1");
                $_show_material = ($_mat && $_mat->num_rows > 0);
            }
        }
        if (!$_show_material) {
            continue;
        }
    }
    $_nav_config_filtered[] = $_nav_item;
}
$nav_config = $_nav_config_filtered;

// Only show Placement tab if this department has at least one visible placement record
$_show_placement = false;
if (isset($conn) && isset($DEPARTMENT_NAME)) {
    $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
    $_pr = $conn->query("SELECT id FROM dept_placement WHERE department='$_dept_esc' AND display_order >= 0 LIMIT 1");
    $_show_placement = ($_pr && $_pr->num_rows > 0);
}
if ($_show_placement) {
    $nav_config[] = [
        'file'    => 'placement.php',
        'label'   => 'Placement',
        'icon'    => 'fa-briefcase',
        'aliases' => []
    ];
}

// Function to check if a nav item is active
function isNavActive($nav_item, $current_page) {
    // Check main file
    if ($nav_item['file'] === $current_page) {
        return true;
    }
    // Check aliases
    if (in_array($current_page, $nav_item['aliases'])) {
        return true;
    }
    return false;
}
?>

<!-- Department Navigation -->
<section class="py-3 bg-white border-bottom dept-nav-section">
    <div class="container">
        <div class="dept-nav-wrapper">
            <?php foreach ($nav_config as $nav): ?>
                <a href="<?php echo $nav['file']; ?>" 
                   class="dept-nav-link <?php echo isNavActive($nav, $current_page) ? 'active' : ''; ?>">
                    <i class="fas <?php echo $nav['icon']; ?> me-2"></i><?php echo $nav['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

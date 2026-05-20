<?php
/**
 * Department Navigation with Auto Active Detection
 * Automatically highlights the active page
 * Only shows nav items if the corresponding table has data for this department
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
        'aliases' => ['index.php']
    ],
    [
        'file' => 'notice-board.php',
        'label' => 'Notice Board',
        'icon' => 'fa-bullhorn',
        'aliases' => ['notice-details.php'],
        'requires_data' => 'nb'
    ],
    [
        'file' => 'academic-calendar.php',
        'label' => 'Academic Calendar',
        'icon' => 'fa-calendar-alt',
        'aliases' => [],
        'requires_data' => 'dept_academic_calendar',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'timetable.php',
        'label' => 'Time Tables',
        'icon' => 'fa-clock',
        'aliases' => [],
        'requires_data' => 'dept_timetable',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'results.php',
        'label' => 'Results',
        'icon' => 'fa-file-pdf',
        'aliases' => [],
        'requires_data' => 'dept_results',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'material.php',
        'label' => 'Materials',
        'icon' => 'fa-book-reader',
        'aliases' => [],
        'requires_data' => 'dept_material',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'faculty.php',
        'label' => 'Faculty',
        'icon' => 'fa-chalkboard-teacher',
        'aliases' => ['faculty-profile.php'],
        'requires_data' => 'faculty'
    ],
    [
        'file' => 'activities.php',
        'label' => 'Activities',
        'icon' => 'fa-running',
        'aliases' => ['activity-details.php'],
        'requires_data' => 'activities'
    ],
    [
        'file' => 'facilities.php',
        'label' => 'Facilities',
        'icon' => 'fa-building',
        'aliases' => [],
        'requires_data' => 'dept_facilities',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'newsletter.php',
        'label' => 'Newsletter',
        'icon' => 'fa-newspaper',
        'aliases' => [],
        'requires_data' => 'dept_newsletter',
        'requires_condition' => 'display_order >= 0'
    ],
    [
        'file' => 'syllabus.php',
        'label' => 'Syllabus',
        'icon' => 'fa-book-open',
        'aliases' => [],
        'requires_data' => 'dept_syllabus'
    ],
    [
        'file' => 'placement.php',
        'label' => 'Placement',
        'icon' => 'fa-briefcase',
        'aliases' => [],
        'requires_data' => 'dept_placement',
        'requires_condition' => 'display_order >= 0'
    ],
];

// Filter nav items based on data availability
$_nav_config_filtered = [];
foreach ($nav_config as $_nav_item) {
    $_table = $_nav_item['requires_data'] ?? '';
    if ($_table !== '') {
        $_show = false;
        if (isset($conn) && isset($DEPARTMENT_NAME)) {
            $_table_check = $conn->query("SHOW TABLES LIKE '$_table'");
            if ($_table_check && $_table_check->num_rows > 0) {
                $_dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
                $_condition = "department='$_dept_esc'";
                if (!empty($_nav_item['requires_condition'])) {
                    $_condition .= " AND " . $_nav_item['requires_condition'];
                }
                $_result = $conn->query("SELECT id FROM $_table WHERE $_condition LIMIT 1");
                $_show = ($_result && $_result->num_rows > 0);
            }
        }
        if (!$_show) {
            continue;
        }
    }
    $_nav_config_filtered[] = $_nav_item;
}
$nav_config = $_nav_config_filtered;

// Function to check if a nav item is active
function isNavActive($nav_item, $current_page) {
    if ($nav_item['file'] === $current_page) {
        return true;
    }
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

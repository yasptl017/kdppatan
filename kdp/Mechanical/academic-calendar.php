<!DOCTYPE html>
<html lang="en">
<?php
include 'dptname.php';
$page_title = "Academic Calendar - " . $DEPARTMENT_NAME . " - K.D. Polytechnic";
?>
<?php include '../assets/preload/head.php'; ?>
<body>
    <?php include_once "../Admin/dbconfig.php"; ?>

    <?php include '../assets/preload/topbar.php'; ?>
    <?php include '../assets/preload/header.php'; ?>
    <?php include '../assets/preload/navigation.php'; ?>
    <?php include '../assets/preload/mobilenav.php'; ?>

    <section class="page-header">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <h1 class="page-title">Academic Calendar</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="#">Departments</a></li>
                            <li class="breadcrumb-item"><a href="aboutdpt.php"><?php echo htmlspecialchars($DEPARTMENT_NAME); ?></a></li>
                            <li class="breadcrumb-item active">Academic Calendar</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <?php include 'dptnavigation.php'; ?>

    <?php
    $dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
    $calendar_result = $conn->query(
        "SELECT * FROM dept_academic_calendar
         WHERE department = '$dept_esc' AND display_order >= 0
         ORDER BY display_order ASC, id DESC"
    );
    ?>

    <section class="py-5 bg-light">
        <div class="container">
            <?php if ($calendar_result && $calendar_result->num_rows > 0): ?>
                <div class="row g-4">
                    <?php while ($calendar = $calendar_result->fetch_assoc()): ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="document-card">
                                <div class="document-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="document-content">
                                    <h5 class="document-title"><?php echo htmlspecialchars($calendar['title']); ?></h5>
                                    <a href="../Admin/<?php echo htmlspecialchars($calendar['file_path']); ?>"
                                       class="btn btn-accent btn-sm w-100"
                                       target="_blank">
                                        <i class="fas fa-download me-2"></i>Download Calendar
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>Academic calendar will be available soon.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include '../assets/preload/footer.php'; ?>
</body>
</html>

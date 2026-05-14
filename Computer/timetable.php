<!DOCTYPE html>
<html lang="en">
<?php
include 'dptname.php';
$page_title = "Time Tables - " . $DEPARTMENT_NAME . " - K.D. Polytechnic";
?>
<?php include '../assets/preload/head.php'; ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<style>
    .table-card {
        background: #fff;
        border-radius: 8px;
        padding: 1.25rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    .btn-accent {
        background-color: #4e73df;
        border-color: #4e73df;
        color: white;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .btn-accent:hover {
        background-color: #224abe;
        border-color: #224abe;
        color: white;
    }

    .no-data-message {
        text-align: center;
        padding: 3rem 1rem;
    }

    .no-data-message i {
        font-size: 3rem;
        color: #ddd;
        margin-bottom: 1rem;
        display: block;
    }
</style>

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
                    <h1 class="page-title">Time Tables</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="#">Departments</a></li>
                            <li class="breadcrumb-item"><a href="aboutdpt.php"><?php echo htmlspecialchars($DEPARTMENT_NAME); ?></a></li>
                            <li class="breadcrumb-item active">Time Tables</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <?php include 'dptnavigation.php'; ?>

    <?php
    $dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
    $timetable_result = $conn->query(
        "SELECT * FROM dept_timetable
         WHERE department = '$dept_esc' AND display_order >= 0
         ORDER BY display_order ASC, id DESC"
    );
    ?>

    <section class="py-5 bg-light">
        <div class="container">
            <?php if ($timetable_result && $timetable_result->num_rows > 0): ?>
                <div class="table-card">
                    <div class="table-responsive">
                        <table id="timetableTable" class="table table-striped table-bordered table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;">#</th>
                                    <th>Title</th>
                                    <th style="width: 140px;">Order</th>
                                    <th style="width: 170px;">File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; while ($timetable = $timetable_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($timetable['title']); ?></td>
                                        <td><?php echo (int)$timetable['display_order']; ?></td>
                                        <td>
                                            <a href="../Admin/<?php echo htmlspecialchars($timetable['file_path']); ?>"
                                               class="btn btn-accent btn-sm"
                                               target="_blank">
                                                <i class="fas fa-download me-2"></i>View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data-message">
                    <i class="fas fa-calendar-times"></i>
                    <h4>No Time Tables Available</h4>
                    <p>Time tables for this department will be available soon.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include '../assets/preload/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#timetableTable').DataTable({
                pageLength: 10,
                order: [[2, 'asc'], [0, 'asc']],
                columnDefs: [{ orderable: false, targets: 3 }]
            });
        });
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Departments & Intake - K.D. Polytechnic"; ?>
<?php include '../assets/preload/head.php'; ?>
<style>
    .intake-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        color: #000;
        font-size: 18px;
    }
    .intake-table th,
    .intake-table td {
        border: 1px solid #000;
        padding: 6px 8px;
        vertical-align: middle;
    }
    .intake-table th {
        text-align: center;
        font-weight: 700;
        font-size: 20px;
    }
    .intake-table td {
        text-align: center;
    }
    .intake-table td.branch-name {
        text-align: left;
    }
    @media (max-width: 767px) {
        .intake-table {
            font-size: 14px;
            min-width: 820px;
        }
        .intake-table th {
            font-size: 15px;
        }
    }
</style>
<body>
<?php include_once "../Admin/dbconfig.php"; ?>
<?php
$intakeColumns = [
    'supernumerary_seats' => "ALTER TABLE intake ADD supernumerary_seats int(11) NOT NULL DEFAULT 0 AFTER intek",
    'aicte_plus_supernumerary' => "ALTER TABLE intake ADD aicte_plus_supernumerary int(11) NOT NULL DEFAULT 0 AFTER supernumerary_seats",
    'tfws_seats' => "ALTER TABLE intake ADD tfws_seats int(11) NOT NULL DEFAULT 0 AFTER aicte_plus_supernumerary",
    'total_seats' => "ALTER TABLE intake ADD total_seats int(11) NOT NULL DEFAULT 0 AFTER tfws_seats"
];
foreach ($intakeColumns as $column => $alterSql) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM intake LIKE '$column'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query($alterSql);
    }
}
?>
<!-- Top Info Bar -->
<?php include '../assets/preload/topbar.php'; ?>
<!-- Header -->
<?php include '../assets/preload/header.php'; ?>
<!-- Navigation -->
<?php include '../assets/preload/navigation.php'; ?>
<!-- Mobile Navigation -->
<?php include '../assets/preload/mobilenav.php'; ?>
<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <h1 class="page-title">Departments & Intake</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="../index.php">Home</a>
                        </li>
                        <li class="breadcrumb-item">
                            <a href="#">Academics</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">
                            Departments & Intake
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
</section>
<?php
$intake_query  = "SELECT * FROM intake WHERE display_order >= 0 ORDER BY display_order ASC";
$intake_result = $conn->query($intake_query);
?>
<section class="py-5 bg-light">
    <div class="container">
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="section-title text-start">Approved Intake Capacity</h2>
                <p class="lead text-center">Sanctioned intake capacity for various diploma programs</p>
            </div>
        </div>
        <?php if ($intake_result && $intake_result->num_rows > 0): ?>
        <div class="row">
            <div class="col-12">
                <div class="table-responsive">
                    <table class="intake-table">
                        <thead>
                            <tr>
                                <th>Sr.<br>No</th>
                                <th>Branch Name</th>
                                <th>AICTE<br>INTAKE</th>
                                <th>SUPERNUMERI<br>SEAT 25%</th>
                                <th>AICTE+<br>SUPERNUMERI</th>
                                <th>TFWS<br>5%</th>
                                <th>Total Seat<br>for<br>Admission</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $sr = 1; ?>
                        <?php while ($intake = $intake_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $sr++; ?></td>
                                <td class="branch-name">
                                    <?php echo htmlspecialchars($intake['course_name']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($intake['intek']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($intake['supernumerary_seats'] ?? 0); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($intake['aicte_plus_supernumerary'] ?? 0); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($intake['tfws_seats'] ?? 0); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($intake['total_seats'] ?? 0); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info text-center">
            Intake information will be updated soon.
        </div>
        <?php endif; ?>
    </div>
</section>
<!-- Footer -->
<?php include '../assets/preload/footer.php'; ?>
</body>
</html>

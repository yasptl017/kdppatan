<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Organization - K.D. Polytechnic"; ?>
<?php include '../assets/preload/head.php'; ?>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="about-pages.css">
<body>
    <?php include_once "../Admin/dbconfig.php"; ?>
    
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
                    <h1 class="page-title">Organization</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Organization</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <!-- Organization Content -->
    <?php
    $college_query = "SELECT * FROM college_details LIMIT 1";
    $college_result = $conn->query($college_query);
    $college = $college_result->fetch_assoc();
    ?>
    <section class="py-5 bg-white">
        <div class="container">
            <!-- Organization Structure -->
            <div class="row mb-5">
                <div class="col-12">
                    <h2 class="section-title text-start">Organizational Structure</h2>
                    <p class="lead text-center">Our institute operates under a well-defined organizational structure ensuring efficient administration and academic excellence.</p>
                </div>
            </div>

            <!-- Principal -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-6 col-md-8">
                    <div class="org-card principal-card">
                        <div class="org-photo">
                            <?php if (!empty($college['principal_photo'])): ?>
                                <img src="../Admin/<?php echo $college['principal_photo']; ?>" 
                                     alt="<?php echo $college['principal_name']; ?>" 
                                     class="img-fluid">
                            <?php else: ?>
                                <div class="placeholder-photo">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="org-info">
                            <h4><?php echo $college['principal_name']; ?></h4>
                            <p class="designation">Principal</p>
                            <p class="contact-info">
                                <i class="fas fa-envelope me-2"></i><?php echo $college['email']; ?><br>
                                <i class="fas fa-phone me-2"></i><?php echo $college['contact_no']; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <?php include '../assets/preload/footer.php'; ?>
</body>
</html>

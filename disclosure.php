<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Disclosure - K.D. Polytechnic"; ?>
<?php include 'assets/preload/head.php'; ?>
<body>
    <?php include_once "Admin/dbconfig.php"; ?>
    <?php
    $conn->query("
        CREATE TABLE IF NOT EXISTS disclosure_files (
            id int(11) NOT NULL AUTO_INCREMENT,
            heading varchar(255) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            file varchar(500) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $headingCheck = $conn->query("SHOW COLUMNS FROM disclosure_files LIKE 'heading'");
    if ($headingCheck && $headingCheck->num_rows === 0) {
        $conn->query("ALTER TABLE disclosure_files ADD heading varchar(255) NOT NULL DEFAULT '' AFTER id");
    }
    ?>

    <?php include 'assets/preload/topbar.php'; ?>
    <?php include 'assets/preload/header.php'; ?>
    <?php include 'assets/preload/navigation.php'; ?>
    <?php include 'assets/preload/mobilenav.php'; ?>

    <section class="page-header">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <h1 class="page-title">Disclosure</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Disclosure</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <?php
    $disclosure_query = "SELECT * FROM disclosure_files WHERE display_order >= 0 ORDER BY heading ASC, display_order ASC, id DESC";
    $disclosure_result = $conn->query($disclosure_query);
    ?>
    <section class="py-5 bg-white">
        <div class="container">
            <?php if ($disclosure_result && $disclosure_result->num_rows > 0): ?>
                <?php
                $currentHeading = null;
                while ($disclosure = $disclosure_result->fetch_assoc()):
                    $heading = trim($disclosure['heading']) !== '' ? $disclosure['heading'] : 'General';
                    if ($currentHeading !== $heading):
                        if ($currentHeading !== null): ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="mb-5">
                            <h2 class="section-title text-start mb-4"><?php echo htmlspecialchars($heading); ?></h2>
                            <div class="row g-4">
                        <?php
                        $currentHeading = $heading;
                    endif;
                ?>
                    <div class="col-lg-6 col-md-12">
                        <div class="document-card large">
                            <div class="document-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="document-content">
                                <h4 class="document-title"><?php echo htmlspecialchars($disclosure['title']); ?></h4>
                                <p class="document-date">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Updated: <?php echo date("F d, Y", strtotime($disclosure['created_at'])); ?>
                                </p>
                                <div class="document-actions">
                                    <a href="Admin/<?php echo htmlspecialchars($disclosure['file']); ?>"
                                       class="btn btn-primary"
                                       target="_blank">
                                        <i class="fas fa-eye me-2"></i>View Document
                                    </a>
                                    <a href="Admin/<?php echo htmlspecialchars($disclosure['file']); ?>"
                                       class="btn btn-accent"
                                       download>
                                        <i class="fas fa-download me-2"></i>Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
                <?php if ($currentHeading !== null): ?>
                            </div>
                        </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>Disclosure documents will be available soon.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include 'assets/preload/footer.php'; ?>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<?php
include 'dptname.php';
$page_title = "Time Tables - " . $DEPARTMENT_NAME . " - K.D. Polytechnic";
?>
<?php include '../assets/preload/head.php'; ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<style>
    .timetable-search-container {
        margin-bottom: 2rem;
    }

    .search-box {
        position: relative;
    }

    .search-box input {
        padding-left: 40px;
        height: 45px;
        border: 2px solid #e3e6f0;
        border-radius: 8px;
        font-size: 15px;
        transition: all 0.3s ease;
    }

    .search-box input:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
    }

    .document-card {
        background: #fff;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        padding: 1.5rem;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .document-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .document-icon {
        font-size: 2.5rem;
        color: #4e73df;
        margin-bottom: 1rem;
    }

    .document-title {
        color: #2d3748;
        margin-bottom: 1rem;
        font-weight: 600;
        font-size: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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

    @media (max-width: 768px) {
        .document-card {
            margin-bottom: 1rem;
        }
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
                <!-- Search Box -->
                <div class="timetable-search-container">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="timetableSearch" class="form-control" 
                               placeholder="Search time tables by title...">
                    </div>
                </div>

                <!-- Time Tables Grid -->
                <div class="row g-4" id="timetableGrid">
                    <?php while ($timetable = $timetable_result->fetch_assoc()): ?>
                        <div class="col-lg-4 col-md-6 timetable-item" data-title="<?php echo strtolower(htmlspecialchars($timetable['title'])); ?>">
                            <div class="document-card">
                                <div class="document-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="document-content flex-grow-1">
                                    <h5 class="document-title" title="<?php echo htmlspecialchars($timetable['title']); ?>">
                                        <?php echo htmlspecialchars($timetable['title']); ?>
                                    </h5>
                                </div>
                                <a href="../Admin/<?php echo htmlspecialchars($timetable['file_path']); ?>"
                                   class="btn btn-accent btn-sm w-100 mt-auto"
                                   target="_blank">
                                    <i class="fas fa-download me-2"></i>Download / View
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
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

    <!-- Search Script -->
    <script>
        const timetableSearch = document.getElementById('timetableSearch');
        if (timetableSearch) {
            timetableSearch.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const items = document.querySelectorAll('.timetable-item');

                items.forEach(item => {
                    const title = item.getAttribute('data-title');
                    item.style.display = title.includes(searchTerm) ? '' : 'none';
                });

                const visibleItems = Array.from(items).filter(item => item.style.display !== 'none');
                const noResultsMsg = document.getElementById('noResultsMessage');

                if (visibleItems.length === 0) {
                    if (!noResultsMsg) {
                        const msg = document.createElement('div');
                        msg.id = 'noResultsMessage';
                        msg.className = 'no-data-message';
                        msg.innerHTML = '<i class="fas fa-search"></i><h4>No Time Tables Found</h4><p>Try a different search term.</p>';
                        document.getElementById('timetableGrid').appendChild(msg);
                    }
                } else if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            });
        }
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<?php
include 'dptname.php';
$page_title = "Materials - " . $DEPARTMENT_NAME . " - K.D. Polytechnic";
?>
<?php include '../assets/preload/head.php'; ?>

<style>
    .material-search-container { margin-bottom: 2rem; }
    .search-box { position: relative; }
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
    .subject-title {
        color: #2d3748;
        font-size: 1.25rem;
        font-weight: 700;
        margin: 2rem 0 1rem;
        padding-bottom: .5rem;
        border-bottom: 2px solid #e3e6f0;
    }
    .subject-title:first-child { margin-top: 0; }
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
                    <h1 class="page-title">Materials</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="#">Departments</a></li>
                            <li class="breadcrumb-item"><a href="aboutdpt.php"><?php echo htmlspecialchars($DEPARTMENT_NAME); ?></a></li>
                            <li class="breadcrumb-item active">Materials</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <?php include 'dptnavigation.php'; ?>

    <?php
    $dept_esc = $conn->real_escape_string($DEPARTMENT_NAME);
    $material_result = $conn->query(
        "SELECT * FROM dept_material
         WHERE department = '$dept_esc' AND display_order >= 0
         ORDER BY subject ASC, display_order ASC, id DESC"
    );
    ?>

    <section class="py-5 bg-light">
        <div class="container">
            <?php if ($material_result && $material_result->num_rows > 0): ?>
                <div class="material-search-container">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="materialSearch" class="form-control"
                               placeholder="Search materials by subject or title...">
                    </div>
                </div>

                <div id="materialGrid">
                    <?php
                    $currentSubject = null;
                    $openRow = false;
                    while ($material = $material_result->fetch_assoc()):
                        if ($currentSubject !== $material['subject']):
                            if ($openRow) {
                                echo '</div>';
                            }
                            $currentSubject = $material['subject'];
                            $openRow = true;
                    ?>
                            <h3 class="subject-title material-subject"><?php echo htmlspecialchars($currentSubject); ?></h3>
                            <div class="row g-4 mb-2">
                        <?php endif; ?>
                        <div class="col-lg-4 col-md-6 material-item"
                             data-title="<?php echo strtolower(htmlspecialchars($material['title'])); ?>"
                             data-subject="<?php echo strtolower(htmlspecialchars($material['subject'])); ?>">
                            <div class="document-card">
                                <div class="document-icon">
                                    <i class="fas fa-book-reader"></i>
                                </div>
                                <div class="document-content flex-grow-1">
                                    <h5 class="document-title" title="<?php echo htmlspecialchars($material['title']); ?>">
                                        <?php echo htmlspecialchars($material['title']); ?>
                                    </h5>
                                </div>
                                <a href="../Admin/<?php echo htmlspecialchars($material['file_path']); ?>"
                                   class="btn btn-accent btn-sm w-100 mt-auto"
                                   target="_blank">
                                    <i class="fas fa-download me-2"></i>Download / View
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    <?php if ($openRow) echo '</div>'; ?>
                </div>
            <?php else: ?>
                <div class="no-data-message">
                    <i class="fas fa-book"></i>
                    <h4>No Materials Available</h4>
                    <p>Materials for this department will be available soon.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include '../assets/preload/footer.php'; ?>

    <script>
        const materialSearch = document.getElementById('materialSearch');
        if (materialSearch) {
            materialSearch.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const items = document.querySelectorAll('.material-item');

                items.forEach(item => {
                    const title = item.getAttribute('data-title');
                    const subject = item.getAttribute('data-subject');
                    item.style.display = (title.includes(searchTerm) || subject.includes(searchTerm)) ? '' : 'none';
                });

                document.querySelectorAll('.material-subject').forEach(heading => {
                    const row = heading.nextElementSibling;
                    const visibleInGroup = row && Array.from(row.querySelectorAll('.material-item')).some(item => item.style.display !== 'none');
                    heading.style.display = visibleInGroup ? '' : 'none';
                    if (row) {
                        row.style.display = visibleInGroup ? '' : 'none';
                    }
                });

                const visibleItems = Array.from(items).filter(item => item.style.display !== 'none');
                const noResultsMsg = document.getElementById('noResultsMessage');

                if (visibleItems.length === 0) {
                    if (!noResultsMsg) {
                        const msg = document.createElement('div');
                        msg.id = 'noResultsMessage';
                        msg.className = 'no-data-message';
                        msg.innerHTML = '<i class="fas fa-search"></i><h4>No Materials Found</h4><p>Try a different search term.</p>';
                        document.getElementById('materialGrid').appendChild(msg);
                    }
                } else if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            });
        }
    </script>
</body>
</html>

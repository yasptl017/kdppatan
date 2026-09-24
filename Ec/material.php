<!DOCTYPE html>
<html lang="en">
<?php
include 'dptname.php';
$page_title = "Materials - " . $DEPARTMENT_NAME . " - K.D. Polytechnic";
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
            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header text-white" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
                            <h5 class="mb-0"><i class="fas fa-book-reader me-2"></i><?php echo htmlspecialchars($DEPARTMENT_NAME); ?> - Materials</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="table-responsive">
                                <table id="materialTable" class="table table-hover align-middle" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">Sr. No.</th>
                                            <th>Subject</th>
                                            <th>Title</th>
                                            <th style="width: 140px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($material_result && $material_result->num_rows > 0): ?>
                                            <?php $sr_no = 1; while ($material = $material_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo $sr_no++; ?></td>
                                                    <td><?php echo htmlspecialchars($material['subject']); ?></td>
                                                    <td><?php echo htmlspecialchars($material['title']); ?></td>
                                                    <td>
                                                        <?php if (!empty($material['material_url'])): ?>
                                                            <a href="<?php echo htmlspecialchars($material['material_url']); ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener noreferrer">
                                                                <i class="fas fa-external-link-alt me-1"></i>Open Link
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="../Admin/<?php echo htmlspecialchars($material['file_path']); ?>" class="btn btn-sm btn-primary" target="_blank">
                                                                <i class="fas fa-download me-1"></i>View
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include '../assets/preload/footer.php'; ?>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <style>
        .dataTables_wrapper { padding: 0 !important; }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter { margin-bottom: 20px; }
        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label { margin: 0; font-weight: 500; color: #6b7280; }
        .dataTables_wrapper .dataTables_length select { padding: 6px 30px 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin: 0 8px; }
        .dataTables_wrapper .dataTables_filter input { padding: 8px 15px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; width: 250px; margin-left: 8px; }
        #materialTable thead th { background: #f9fafb !important; color: #1e3a8a !important; font-weight: 600 !important; border-bottom: 2px solid #e5e7eb !important; padding: 12px 15px !important; font-size: 14px !important; }
        #materialTable tbody td { padding: 12px 15px !important; font-size: 14px !important; border-bottom: 1px solid #f3f4f6 !important; }
        #materialTable tbody tr:hover { background-color: #f9fafb !important; }
        .dataTables_wrapper .dataTables_info { padding-top: 15px; color: #6b7280; font-size: 14px; }
        .dataTables_wrapper .dataTables_paginate { padding-top: 15px; }
        .dataTables_wrapper .dataTables_empty { text-align: center; color: #9ca3af; padding: 30px !important; font-size: 15px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { padding: 0 !important; margin: 0 !important; border: none !important; border-radius: 0 !important; color: inherit !important; background: none !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled { background: none !important; color: inherit !important; border-color: transparent !important; }
        .dataTables_wrapper .dataTables_paginate .pagination { margin: 0; justify-content: flex-end; }
        .dataTables_wrapper .page-link { padding: 8px 14px !important; border: 1px solid #e5e7eb !important; color: #1e3a8a !important; font-size: 14px !important; font-weight: 500 !important; margin: 0 3px !important; border-radius: 6px !important; background: #fff !important; }
        .dataTables_wrapper .page-link:hover { background-color: #eff6ff !important; border-color: #3b82f6 !important; color: #1e3a8a !important; }
        .dataTables_wrapper .page-item.active .page-link { background-color: #3b82f6 !important; border-color: #3b82f6 !important; color: #fff !important; }
        .dataTables_wrapper .page-item.disabled .page-link { opacity: 0.5 !important; cursor: not-allowed !important; background-color: #fff !important; color: #9ca3af !important; border-color: #e5e7eb !important; }
        @media (max-width: 768px) {
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate { text-align: center; margin-bottom: 15px; }
            .dataTables_wrapper .dataTables_filter input { width: 100%; margin: 10px 0 0; }
            .dataTables_wrapper .dataTables_paginate .pagination { justify-content: center; }
        }
    </style>
    <script>
        $(document).ready(function() {
            var table = $('#materialTable').DataTable({
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
                "order": [[1, "asc"], [2, "asc"]],
                "columnDefs": [{ "orderable": false, "targets": [0, 3] }],
                "language": {
                    "lengthMenu": "Show _MENU_ entries",
                    "search": "Search:",
                    "info": "Showing _START_ to _END_ of _TOTAL_ materials",
                    "infoEmpty": "No materials available",
                    "emptyTable": "No materials available",
                    "zeroRecords": "No matching materials found"
                }
            });

            table.on('draw', function() {
                var pageInfo = table.page.info();
                table.column(0, {page: 'current'}).nodes().each(function(cell, i) {
                    cell.innerHTML = pageInfo.start + i + 1;
                });
            });
        });
    </script>
</body>
</html>

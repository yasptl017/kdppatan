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
    $grouped_disclosures = [];
    if ($disclosure_result && $disclosure_result->num_rows > 0) {
        while ($disclosure = $disclosure_result->fetch_assoc()) {
            $heading = trim($disclosure['heading']) !== '' ? $disclosure['heading'] : 'General';
            if (!isset($grouped_disclosures[$heading])) {
                $grouped_disclosures[$heading] = [];
            }
            $grouped_disclosures[$heading][] = $disclosure;
        }
    }
    ?>
    <section class="py-5 bg-white">
        <div class="container">
            <?php if (!empty($grouped_disclosures)): ?>
                <div class="disclosure-toolbar mb-4">
                    <div class="position-relative">
                        <i class="fas fa-search disclosure-search-icon"></i>
                        <input type="search"
                               id="disclosureSearch"
                               class="form-control disclosure-search-input"
                               placeholder="Search disclosure documents..."
                               aria-label="Search disclosure documents">
                    </div>
                </div>

                <div class="accordion disclosure-accordion" id="disclosureAccordion">
                    <?php $groupIndex = 0; ?>
                    <?php foreach ($grouped_disclosures as $heading => $documents): ?>
                        <?php
                        $groupIndex++;
                        $collapseId = 'disclosureGroup' . $groupIndex;
                        $headingId = 'disclosureHeading' . $groupIndex;
                        $isFirst = $groupIndex === 1;
                        ?>
                        <div class="accordion-item disclosure-group"
                             data-heading="<?php echo htmlspecialchars(strtolower($heading), ENT_QUOTES, 'UTF-8'); ?>">
                            <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                                <button class="accordion-button <?php echo $isFirst ? '' : 'collapsed'; ?>"
                                        type="button"
                                        aria-expanded="<?php echo $isFirst ? 'true' : 'false'; ?>"
                                        aria-controls="<?php echo $collapseId; ?>">
                                    <span><?php echo htmlspecialchars($heading); ?></span>
                                    <span class="badge bg-primary ms-3"><?php echo count($documents); ?></span>
                                </button>
                            </h2>
                            <div id="<?php echo $collapseId; ?>"
                                 class="accordion-collapse collapse <?php echo $isFirst ? 'show' : ''; ?>"
                                 aria-labelledby="<?php echo $headingId; ?>">
                                <div class="accordion-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover disclosure-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 80px;">Sr. No</th>
                                                    <th>Title</th>
                                                    <th style="width: 180px;">Updated</th>
                                                    <th style="width: 190px;">File</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($documents as $index => $disclosure): ?>
                                                    <?php
                                                    $title = $disclosure['title'];
                                                    $dateText = date("F d, Y", strtotime($disclosure['created_at']));
                                                    $searchText = strtolower($heading . ' ' . $title . ' ' . $dateText);
                                                    $fileUrl = 'Admin/' . $disclosure['file'];
                                                    ?>
                                                    <tr class="disclosure-row"
                                                        data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars($title); ?></td>
                                                        <td><?php echo htmlspecialchars($dateText); ?></td>
                                                        <td>
                                                            <div class="d-flex gap-2 flex-wrap">
                                                                <a href="<?php echo htmlspecialchars($fileUrl); ?>"
                                                                   class="btn btn-sm btn-primary"
                                                                   target="_blank">
                                                                    <i class="fas fa-eye me-1"></i>View
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="disclosureNoResults" class="alert alert-info text-center mt-4 d-none">
                    <i class="fas fa-info-circle me-2"></i>No disclosure documents matched your search.
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>Disclosure documents will be available soon.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <style>
        .disclosure-toolbar {
            max-width: 520px;
        }

        .disclosure-search-icon {
            color: #64748b;
            left: 16px;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 2;
        }

        .disclosure-search-input {
            border: 1px solid #dbe3ef;
            border-radius: 6px;
            min-height: 48px;
            padding-left: 44px;
        }

        .disclosure-search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12);
        }

        .disclosure-accordion .accordion-item {
            border: 1px solid #dbe3ef;
            border-radius: 6px;
            margin-bottom: 14px;
            overflow: hidden;
        }

        .disclosure-accordion .accordion-button {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 700;
            letter-spacing: 0;
        }

        .disclosure-accordion .accordion-button:not(.collapsed) {
            background: #eef5ff;
            color: var(--primary-color);
        }

        .disclosure-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 700;
            vertical-align: middle;
        }

        .disclosure-table td {
            vertical-align: middle;
        }

        @media (max-width: 575.98px) {
            .disclosure-toolbar {
                max-width: none;
            }

            .disclosure-table {
                min-width: 720px;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('disclosureSearch');
            const groups = Array.from(document.querySelectorAll('.disclosure-group'));
            const noResults = document.getElementById('disclosureNoResults');

            if (groups.length === 0) {
                return;
            }

            groups.forEach(function (group) {
                const button = group.querySelector('.accordion-button');
                const collapseElement = group.querySelector('.accordion-collapse');

                if (!button || !collapseElement) {
                    return;
                }

                button.addEventListener('click', function () {
                    if (window.bootstrap) {
                        const collapse = bootstrap.Collapse.getOrCreateInstance(collapseElement, { toggle: false });
                        if (collapseElement.classList.contains('show')) {
                            collapse.hide();
                        } else {
                            collapse.show();
                        }
                    } else {
                        collapseElement.classList.toggle('show');
                        const isOpen = collapseElement.classList.contains('show');
                        button.classList.toggle('collapsed', !isOpen);
                        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    }
                });

                collapseElement.addEventListener('shown.bs.collapse', function () {
                    button.classList.remove('collapsed');
                    button.setAttribute('aria-expanded', 'true');
                });

                collapseElement.addEventListener('hidden.bs.collapse', function () {
                    button.classList.add('collapsed');
                    button.setAttribute('aria-expanded', 'false');
                });
            });

            if (!searchInput) {
                return;
            }

            searchInput.addEventListener('input', function () {
                const query = searchInput.value.trim().toLowerCase();
                let visibleGroups = 0;

                groups.forEach(function (group) {
                    const rows = Array.from(group.querySelectorAll('.disclosure-row'));
                    const collapseElement = group.querySelector('.accordion-collapse');
                    let visibleRows = 0;

                    rows.forEach(function (row) {
                        const isMatch = query === '' || (row.dataset.search || '').includes(query);
                        row.classList.toggle('d-none', !isMatch);
                        if (isMatch) {
                            visibleRows++;
                        }
                    });

                    const hasVisibleRows = visibleRows > 0;
                    group.classList.toggle('d-none', !hasVisibleRows);

                    if (hasVisibleRows) {
                        visibleGroups++;
                    }

                    if (query !== '' && hasVisibleRows && window.bootstrap && collapseElement) {
                        bootstrap.Collapse.getOrCreateInstance(collapseElement, { toggle: false }).show();
                    }
                });

                if (noResults) {
                    noResults.classList.toggle('d-none', visibleGroups > 0);
                }
            });
        });
    </script>

    <?php include 'assets/preload/footer.php'; ?>
</body>
</html>

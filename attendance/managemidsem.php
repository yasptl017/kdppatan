<?php
include('dbconfig.php');

// Admin-only page
$current_username = trim((string)($_SESSION['username'] ?? ''));
if (strcasecmp($current_username, 'admin') !== 0) {
    header('Location: home.php');
    exit();
}

attendance_ensure_midsem_table($conn);

$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

function midsem_redirect(array $params)
{
    header('Location: managemidsem.php?' . http_build_query($params));
    exit();
}

function midsem_valid_date($d)
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

// ── Add / update ─────────────────────────────────────────────────────────────
if (isset($_POST['add_midsem']) || isset($_POST['update_midsem'])) {
    $id    = (int)($_POST['id'] ?? 0);
    $term  = trim((string)($_POST['term'] ?? ''));
    $sem   = trim((string)($_POST['sem'] ?? ''));
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end   = trim((string)($_POST['end_date'] ?? ''));
    $mode  = (($_POST['exam_mode'] ?? '') === 'hide') ? 'hide' : 'highlight';

    if ($term === '' || $sem === '' || !midsem_valid_date($start) || !midsem_valid_date($end)) {
        midsem_redirect(['err' => 'Term, Sem, Start date and End date are required.']);
    }
    if ($end < $start) {
        midsem_redirect(['err' => 'End date cannot be before start date.']);
    }

    if (isset($_POST['update_midsem']) && $id > 0) {
        $stmt = $conn->prepare("UPDATE midsem_exams SET term = ?, sem = ?, start_date = ?, end_date = ?, exam_mode = ? WHERE id = ?");
        $stmt->bind_param('sssssi', $term, $sem, $start, $end, $mode, $id);
        $stmt->execute();
        $stmt->close();
        midsem_redirect(['msg' => 'Midsem exam updated.']);
    }

    $stmt = $conn->prepare("INSERT INTO midsem_exams (term, sem, start_date, end_date, exam_mode) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $term, $sem, $start, $end, $mode);
    $stmt->execute();
    $stmt->close();
    midsem_redirect(['msg' => 'Midsem exam added.']);
}

// ── Delete ───────────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM midsem_exams WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    midsem_redirect(['msg' => 'Midsem exam deleted.']);
}

// ── Dropdown data ────────────────────────────────────────────────────────────
$term_options = [];
$tres = $conn->query("SELECT DISTINCT term FROM terms WHERE term <> '' UNION SELECT DISTINCT term FROM lecmapping WHERE term <> ''");
while ($tres && ($r = $tres->fetch_assoc())) $term_options[] = $r['term'];
usort($term_options, fn($a, $b) => strnatcmp($b, $a));

$sem_options = [];
$sres = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");
while ($sres && ($r = $sres->fetch_assoc())) $sem_options[] = $r['sem'];

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_row = null;
if ($edit_id > 0) {
    $e = $conn->prepare("SELECT * FROM midsem_exams WHERE id = ?");
    $e->bind_param('i', $edit_id);
    $e->execute();
    $edit_row = $e->get_result()->fetch_assoc();
    $e->close();
}

$list = $conn->query("SELECT * FROM midsem_exams ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$today_str = date('Y-m-d');
$form_term  = $edit_row['term'] ?? '';
$form_sem   = $edit_row['sem'] ?? '';
if ($form_term !== '' && !in_array($form_term, $term_options, true)) $term_options[] = $form_term;
if ($form_sem !== '' && !in_array($form_sem, $sem_options, true)) $sem_options[] = $form_sem;
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-pencil-square me-2"></i>Manage Midsem Exam</h1>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($err !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Slots for the chosen term and semester that fall within these dates are either <strong>highlighted</strong> (blinking, so faculty
                mark attendance only if a lecture was actually taken) or <strong>hidden</strong> from <strong>My Attendance</strong>, as selected.
            </div>

            <div class="app-card shadow-sm mb-4">
                <div class="app-card-body">
                    <h4><?= $edit_row ? 'Edit Midsem Exam' : 'Add Midsem Exam' ?></h4>
                    <form method="POST" action="managemidsem.php">
                        <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-6 col-md-2">
                                <label class="form-label">Term</label>
                                <select name="term" class="form-control" required>
                                    <option value="">Select</option>
                                    <?php foreach ($term_options as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>" <?= $t === $form_term ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Sem</label>
                                <select name="sem" class="form-control" required>
                                    <option value="">Select</option>
                                    <?php foreach ($sem_options as $sv): ?>
                                        <option value="<?= htmlspecialchars($sv) ?>" <?= $sv === $form_sem ? 'selected' : '' ?>><?= htmlspecialchars($sv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars((string)($edit_row['start_date'] ?? '')) ?>" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars((string)($edit_row['end_date'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Slots in My Attendance</label>
                                <?php $form_mode = $edit_row['exam_mode'] ?? 'highlight'; ?>
                                <select name="exam_mode" class="form-control">
                                    <option value="highlight" <?= $form_mode === 'highlight' ? 'selected' : '' ?>>Highlight (blinking)</option>
                                    <option value="hide" <?= $form_mode === 'hide' ? 'selected' : '' ?>>Hide</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                                <button type="submit" name="<?= $edit_row ? 'update_midsem' : 'add_midsem' ?>" class="btn btn-primary flex-grow-1"><?= $edit_row ? 'Update' : 'Add' ?></button>
                                <?php if ($edit_row): ?><a href="managemidsem.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="app-card shadow-sm">
                <div class="app-card-body">
                    <h4>Midsem Exam List <span class="text-muted fs-6">(<?= count($list) ?>)</span></h4>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th class="text-center">Term</th>
                                    <th class="text-center">Sem</th>
                                    <th class="text-center">Start Date</th>
                                    <th class="text-center">End Date</th>
                                    <th class="text-center">Mode</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($list)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No midsem exams added yet.</td></tr>
                                <?php else: foreach ($list as $row):
                                    if ($today_str < $row['start_date'])      { $st = ['Upcoming', 'info']; }
                                    elseif ($today_str > $row['end_date'])    { $st = ['Over', 'secondary']; }
                                    else                                      { $st = ['Ongoing', 'danger']; }
                                ?>
                                    <tr>
                                        <td class="text-center"><?= htmlspecialchars($row['term']) ?></td>
                                        <td class="text-center"><?= htmlspecialchars($row['sem']) ?></td>
                                        <td class="text-center"><?= date('d M Y', strtotime($row['start_date'])) ?></td>
                                        <td class="text-center"><?= date('d M Y', strtotime($row['end_date'])) ?></td>
                                        <td class="text-center"><?= ($row['exam_mode'] ?? 'highlight') === 'hide' ? '<span class="badge bg-dark">Hide</span>' : '<span class="badge bg-warning text-dark">Highlight</span>' ?></td>
                                        <td class="text-center"><span class="badge bg-<?= $st[1] ?>"><?= $st[0] ?></span></td>
                                        <td class="text-center">
                                            <a href="managemidsem.php?edit=<?= (int)$row['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                            <a href="managemidsem.php?delete_id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm"
                                               onclick="return confirm('Delete this midsem exam entry?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

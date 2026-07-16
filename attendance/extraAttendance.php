<?php
include('dbconfig.php');

$session_faculty_name = $_SESSION['Name'] ?? '';

// Get logged-in faculty id
$fac_id_stmt = $conn->prepare("SELECT id FROM faculty WHERE Name = ?");
$fac_id_stmt->bind_param('s', $session_faculty_name);
$fac_id_stmt->execute();
$fac_row = $fac_id_stmt->get_result()->fetch_assoc();
$fac_id_stmt->close();
$logged_faculty_id = $fac_row ? (string)$fac_row['id'] : '0';

$error_msg = '';

// ── Handle submit: redirect to the matching take-attendance page ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_extra'])) {
    $type    = trim((string)($_POST['type'] ?? ''));
    $term    = trim((string)($_POST['term'] ?? ''));
    $sem     = trim((string)($_POST['sem'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $group   = trim((string)($_POST['group'] ?? ''));   // class (lecture) or batch (lab/tut)
    $lab_no  = trim((string)($_POST['lab_no'] ?? ''));
    $date    = trim((string)($_POST['date'] ?? ''));
    $slot    = trim((string)($_POST['slot'] ?? ''));

    if (!in_array($type, ['lecture', 'lab', 'tutorial'], true)) {
        $error_msg = 'Please choose a valid session type.';
    } elseif ($term === '' || $sem === '' || $subject === '' || $group === '' || $slot === '') {
        $error_msg = 'Please fill all required fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error_msg = 'Please pick a valid date.';
    } elseif ($type === 'lab' && $lab_no === '') {
        $error_msg = 'Please select a lab for the lab session.';
    } elseif ($logged_faculty_id === '0') {
        $error_msg = 'Could not identify the logged-in faculty. Please log in again.';
    } else {
        if ($type === 'lecture') {
            $params = http_build_query([
                'faculty' => $logged_faculty_id,
                'term'    => $term,
                'sem'     => $sem,
                'subject' => $subject,
                'class'   => $group,
                'date'    => $date,
                'slot'    => $slot,
            ]);
            header('Location: takelecatt.php?' . $params);
        } elseif ($type === 'lab') {
            $params = http_build_query([
                'faculty' => $logged_faculty_id,
                'term'    => $term,
                'sem'     => $sem,
                'subject' => $subject,
                'batch'   => $group,
                'date'    => $date,
                'slot'    => $slot,
                'batch_lab_map[' . $group . ']' => $lab_no,
            ]);
            header('Location: takelabatt.php?' . $params);
        } else {
            $params = http_build_query([
                'faculty' => $logged_faculty_id,
                'term'    => $term,
                'sem'     => $sem,
                'subject' => $subject,
                'batch'   => $group,
                'date'    => $date,
                'slot'    => $slot,
            ]);
            header('Location: taketutatt.php?' . $params);
        }
        exit();
    }
}

// ── Dropdown data ─────────────────────────────────────────────────────────────
$term_rows = [];
$term_result = $conn->query("SELECT DISTINCT term FROM students ORDER BY term DESC");
if ($term_result) {
    while ($row = $term_result->fetch_assoc()) {
        $term_value = trim((string)$row['term']);
        if ($term_value !== '') {
            $term_rows[] = $term_value;
        }
    }
}

$sem_rows = [];
$sem_result = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");
if ($sem_result) {
    while ($row = $sem_result->fetch_assoc()) {
        $sem_rows[] = (string)$row['sem'];
    }
}

$subjects = [];
$subject_result = $conn->query("SELECT subjectName, subjectCode, sem FROM subjects WHERE status = 1 ORDER BY sem, subjectName");
if ($subject_result) {
    while ($row = $subject_result->fetch_assoc()) {
        $subjects[] = [
            'subjectName' => (string)$row['subjectName'],
            'subjectCode' => (string)$row['subjectCode'],
            'sem'         => (string)$row['sem'],
        ];
    }
}

$slot_rows = [];
$slot_result = $conn->query("SELECT timeslot FROM timeslot WHERE status = 1 ORDER BY sequence");
if ($slot_result) {
    while ($row = $slot_result->fetch_assoc()) {
        $slot_rows[] = (string)$row['timeslot'];
    }
}

$lab_rows = [];
$lab_result = $conn->query("SELECT labNo FROM labs WHERE status = 1 ORDER BY labNo");
if ($lab_result) {
    while ($row = $lab_result->fetch_assoc()) {
        $lab_rows[] = (string)$row['labNo'];
    }
}

// Group options per term|sem so the dropdown only shows groups that actually
// have students for the chosen term + semester.
$class_options = [];   // "term|sem" => [class, ...]
$lab_batch_options = [];
$tut_batch_options = [];

$group_result = $conn->query("SELECT DISTINCT term, sem, TRIM(class) AS val FROM students WHERE class IS NOT NULL AND TRIM(class) <> '' ORDER BY val");
if ($group_result) {
    while ($row = $group_result->fetch_assoc()) {
        $class_options[trim((string)$row['term']) . '|' . trim((string)$row['sem'])][] = (string)$row['val'];
    }
}
$group_result = $conn->query("SELECT DISTINCT term, sem, TRIM(labBatch) AS val FROM students WHERE labBatch IS NOT NULL AND TRIM(labBatch) <> '' ORDER BY val");
if ($group_result) {
    while ($row = $group_result->fetch_assoc()) {
        $lab_batch_options[trim((string)$row['term']) . '|' . trim((string)$row['sem'])][] = (string)$row['val'];
    }
}
$group_result = $conn->query("SELECT DISTINCT term, sem, TRIM(tutBatch) AS val FROM students WHERE tutBatch IS NOT NULL AND TRIM(tutBatch) <> '' ORDER BY val");
if ($group_result) {
    while ($row = $group_result->fetch_assoc()) {
        $tut_batch_options[trim((string)$row['term']) . '|' . trim((string)$row['sem'])][] = (string)$row['val'];
    }
}

// Preserve selections on validation error
$sel_type    = trim((string)($_POST['type'] ?? 'lecture'));
$sel_term    = trim((string)($_POST['term'] ?? ($term_rows[0] ?? '')));
$sel_sem     = trim((string)($_POST['sem'] ?? ''));
$sel_subject = trim((string)($_POST['subject'] ?? ''));
$sel_group   = trim((string)($_POST['group'] ?? ''));
$sel_lab     = trim((string)($_POST['lab_no'] ?? ''));
$sel_date    = trim((string)($_POST['date'] ?? date('Y-m-d')));
$sel_slot    = trim((string)($_POST['slot'] ?? ''));
if (!in_array($sel_type, ['lecture', 'lab', 'tutorial'], true)) {
    $sel_type = 'lecture';
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h1 class="app-page-title mb-0"><i class="bi bi-plus-circle me-2"></i>Extra Attendance</h1>
            </div>

            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <div class="app-card shadow-sm extra-att-card">
                <div class="app-card-body p-4">
                    <p class="text-muted extra-att-intro">
                        <i class="bi bi-info-circle me-1"></i>
                        Take attendance for a session that is <strong>not in your mapping</strong> — e.g. an extra lecture,
                        lab, or tutorial on a Sunday, a holiday, or any additional slot. Choose the details below and you
                        will be taken to the regular attendance page.
                    </p>

                    <form method="POST" action="extraAttendance.php" id="extraAttForm" autocomplete="off">
                        <!-- Session type -->
                        <div class="mb-4">
                            <label class="form-label extra-att-label">Session Type</label>
                            <div class="extra-type-picker" role="group" aria-label="Session type">
                                <input type="radio" class="btn-check" name="type" id="type-lecture" value="lecture" <?= $sel_type === 'lecture' ? 'checked' : '' ?>>
                                <label class="extra-type-btn type-lec" for="type-lecture"><i class="bi bi-easel2"></i>Lecture</label>

                                <input type="radio" class="btn-check" name="type" id="type-lab" value="lab" <?= $sel_type === 'lab' ? 'checked' : '' ?>>
                                <label class="extra-type-btn type-lab" for="type-lab"><i class="bi bi-camera-video"></i>Lab</label>

                                <input type="radio" class="btn-check" name="type" id="type-tutorial" value="tutorial" <?= $sel_type === 'tutorial' ? 'checked' : '' ?>>
                                <label class="extra-type-btn type-tut" for="type-tutorial"><i class="bi bi-book"></i>Tutorial</label>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="term">Term</label>
                                <select class="form-select" name="term" id="term" required>
                                    <option value="">Select term</option>
                                    <?php foreach ($term_rows as $term_option): ?>
                                        <option value="<?= htmlspecialchars($term_option) ?>" <?= $term_option === $sel_term ? 'selected' : '' ?>><?= htmlspecialchars($term_option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="sem">Semester</label>
                                <select class="form-select" name="sem" id="sem" required>
                                    <option value="">Select semester</option>
                                    <?php foreach ($sem_rows as $sem_option): ?>
                                        <option value="<?= htmlspecialchars($sem_option) ?>" <?= $sem_option === $sel_sem ? 'selected' : '' ?>>Sem <?= htmlspecialchars($sem_option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="subject">Subject</label>
                                <select class="form-select" name="subject" id="subject" required>
                                    <option value="">Select semester first</option>
                                </select>
                            </div>

                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="group" id="groupLabel">Class</label>
                                <select class="form-select" name="group" id="group" required>
                                    <option value="">Select term &amp; semester first</option>
                                </select>
                            </div>

                            <div class="col-md-4 col-sm-6 d-none" id="labNoWrap">
                                <label class="form-label extra-att-label" for="lab_no">Lab</label>
                                <select class="form-select" name="lab_no" id="lab_no">
                                    <option value="">Select lab</option>
                                    <?php foreach ($lab_rows as $lab_option): ?>
                                        <option value="<?= htmlspecialchars($lab_option) ?>" <?= $lab_option === $sel_lab ? 'selected' : '' ?>><?= htmlspecialchars($lab_option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="date">Date</label>
                                <input type="date" class="form-control" name="date" id="date" value="<?= htmlspecialchars($sel_date) ?>" required>
                                <div class="form-text extra-att-hint" id="dayHint"></div>
                            </div>

                            <div class="col-md-4 col-sm-6">
                                <label class="form-label extra-att-label" for="slot">Time Slot</label>
                                <select class="form-select" name="slot" id="slot" required>
                                    <option value="">Select slot</option>
                                    <?php foreach ($slot_rows as $slot_option): ?>
                                        <option value="<?= htmlspecialchars($slot_option) ?>" <?= $slot_option === $sel_slot ? 'selected' : '' ?>><?= htmlspecialchars($slot_option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2 flex-wrap">
                            <button type="submit" name="start_extra" value="1" class="btn extra-att-submit">
                                <i class="bi bi-pencil-square me-1"></i>Take Attendance
                            </button>
                            <a href="myAttendanceSelect.php" class="btn btn-outline-secondary">Back to My Attendance</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
.extra-att-card {
    max-width: 900px;
}
.extra-att-intro {
    font-size: 0.9rem;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: #0c4a6e !important;
}
.extra-att-label {
    font-weight: 600;
    color: #334155;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.extra-att-hint {
    font-size: 0.8rem;
}
.extra-att-hint.is-weekend {
    color: #b45309;
    font-weight: 600;
}
.extra-type-picker {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.extra-type-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 1.25rem;
    border-radius: 0.6rem;
    border: 2px solid #e2e8f0;
    background: #fff;
    color: #475569;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.extra-type-btn:hover {
    border-color: #94a3b8;
}
.btn-check:checked + .extra-type-btn.type-lec {
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    border-color: #6366f1;
    color: #4338ca;
}
.btn-check:checked + .extra-type-btn.type-lab {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border-color: #10b981;
    color: #047857;
}
.btn-check:checked + .extra-type-btn.type-tut {
    background: linear-gradient(135deg, #fff7ed, #ffedd5);
    border-color: #f59e0b;
    color: #b45309;
}
.extra-att-submit {
    color: #fff;
    border: 0;
    border-radius: 0.5rem;
    background: linear-gradient(135deg, #1f7a8c, #2a9d8f);
    box-shadow: 0 10px 24px rgba(31, 122, 140, 0.22);
    font-weight: 600;
    letter-spacing: 0.2px;
    padding: 0.55rem 1.4rem;
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
}
.extra-att-submit:hover,
.extra-att-submit:focus {
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 14px 28px rgba(31, 122, 140, 0.28);
    filter: saturate(1.05);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const subjectsData    = <?= json_encode($subjects, JSON_UNESCAPED_UNICODE) ?>;
    const classOptions    = <?= json_encode($class_options, JSON_UNESCAPED_UNICODE) ?>;
    const labBatchOptions = <?= json_encode($lab_batch_options, JSON_UNESCAPED_UNICODE) ?>;
    const tutBatchOptions = <?= json_encode($tut_batch_options, JSON_UNESCAPED_UNICODE) ?>;

    const savedSubject = <?= json_encode($sel_subject, JSON_UNESCAPED_UNICODE) ?>;
    const savedGroup   = <?= json_encode($sel_group, JSON_UNESCAPED_UNICODE) ?>;

    const termSelect    = document.getElementById('term');
    const semSelect     = document.getElementById('sem');
    const subjectSelect = document.getElementById('subject');
    const groupSelect   = document.getElementById('group');
    const groupLabel    = document.getElementById('groupLabel');
    const labWrap       = document.getElementById('labNoWrap');
    const labSelect     = document.getElementById('lab_no');
    const dateInput     = document.getElementById('date');
    const dayHint       = document.getElementById('dayHint');

    function currentType() {
        const checked = document.querySelector('input[name="type"]:checked');
        return checked ? checked.value : 'lecture';
    }

    function fillSelect(select, values, placeholder, selectedValue) {
        select.innerHTML = '';
        const ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder;
        select.appendChild(ph);
        (values || []).forEach(function (value) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            if (String(value) === String(selectedValue)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function refreshSubjects(keepSelection) {
        const sem = semSelect.value;
        const selected = keepSelection ? (subjectSelect.value || savedSubject) : '';
        subjectSelect.innerHTML = '';
        const ph = document.createElement('option');
        ph.value = '';
        ph.textContent = sem === '' ? 'Select semester first' : 'Select subject';
        subjectSelect.appendChild(ph);
        subjectsData.forEach(function (s) {
            if (sem !== '' && String(s.sem) !== String(sem)) return;
            const opt = document.createElement('option');
            opt.value = s.subjectName;
            opt.textContent = s.subjectName + (s.subjectCode ? ' (' + s.subjectCode + ')' : '');
            if (String(s.subjectName) === String(selected)) opt.selected = true;
            subjectSelect.appendChild(opt);
        });
    }

    function refreshGroups(keepSelection) {
        const type = currentType();
        const key = termSelect.value + '|' + semSelect.value;
        let source, label;
        if (type === 'lab') {
            source = labBatchOptions;
            label = 'Lab Batch';
        } else if (type === 'tutorial') {
            source = tutBatchOptions;
            label = 'Tutorial Batch';
        } else {
            source = classOptions;
            label = 'Class';
        }
        groupLabel.textContent = label;
        const values = source[key] || [];
        const placeholder = (termSelect.value === '' || semSelect.value === '')
            ? 'Select term & semester first'
            : (values.length ? 'Select ' + label.toLowerCase() : 'No students found for this term/sem');
        const selected = keepSelection ? (groupSelect.value || savedGroup) : '';
        fillSelect(groupSelect, values, placeholder, selected);
    }

    function refreshLabVisibility() {
        const isLab = currentType() === 'lab';
        labWrap.classList.toggle('d-none', !isLab);
        labSelect.required = isLab;
        if (!isLab) labSelect.value = '';
    }

    function refreshDayHint() {
        if (!dateInput.value) {
            dayHint.textContent = '';
            return;
        }
        const d = new Date(dateInput.value + 'T00:00:00');
        if (isNaN(d.getTime())) {
            dayHint.textContent = '';
            return;
        }
        const dayName = d.toLocaleDateString('en-US', { weekday: 'long' });
        const isWeekend = d.getDay() === 0 || d.getDay() === 6;
        dayHint.textContent = dayName + (isWeekend ? ' (weekend)' : '');
        dayHint.classList.toggle('is-weekend', isWeekend);
    }

    document.querySelectorAll('input[name="type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            refreshGroups(false);
            refreshLabVisibility();
        });
    });
    termSelect.addEventListener('change', function () { refreshGroups(false); });
    semSelect.addEventListener('change', function () {
        refreshSubjects(false);
        refreshGroups(false);
    });
    dateInput.addEventListener('change', refreshDayHint);

    // Initial population (restores selections after a validation error)
    refreshSubjects(true);
    refreshGroups(true);
    refreshLabVisibility();
    refreshDayHint();
});
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

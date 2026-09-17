<?php
include('dbconfig.php');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function csv_tokens($value) {
    $items = array_map('trim', explode(',', (string)$value));
    return array_values(array_filter($items, function ($item) {
        return $item !== '';
    }));
}

function normalize_tokens($value) {
    $raw = is_array($value) ? $value : csv_tokens($value);
    $out = [];
    foreach ($raw as $token) {
        $token = strtoupper(trim((string)$token));
        if ($token !== '' && !in_array($token, $out, true)) {
            $out[] = $token;
        }
    }
    return $out;
}

function parse_attendance_date($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'n/j/Y', 'm/d/Y', 'j/n/Y', 'd/m/Y', 'd-m-Y', 'j-m-Y'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $ok = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        if ($ok) {
            return $dt;
        }
    }

    $ts = strtotime($value);
    if ($ts !== false) {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    return null;
}

function percent_value($present, $total) {
    if ((int)$total <= 0) {
        return null;
    }
    return ((int)$present * 100) / (int)$total;
}

function percent_display($value) {
    if ($value === null) {
        return '-';
    }
    return number_format((float)$value, 2) . '%';
}

function present_enrollment_set($presentNo, $idToEnrollment) {
    $set = [];
    foreach (csv_tokens($presentNo) as $token) {
        if (isset($idToEnrollment[$token])) {
            $token = $idToEnrollment[$token];
        }
        $token = trim((string)$token);
        if ($token !== '') {
            $set[$token] = true;
        }
    }
    return $set;
}

function download_attendance_analysis_excel($rows, $filters, $students_count) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Attendance Analysis');

    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', 'Attendance Analysis Report');
    $sheet->mergeCells('A2:G2');
    $subject_line = ($filters['subject'] !== '') ? '    Subject: ' . $filters['subject'] : '    Subject: All Subjects';
    $sheet->setCellValue('A2', 'Semester: ' . $filters['sem'] . '    Class: ' . $filters['class'] . $subject_line);
    $sheet->mergeCells('A3:G3');
    $sheet->setCellValue('A3', 'Date Range: ' . $filters['start_date'] . ' to ' . $filters['end_date'] . '    Students: ' . $students_count);

    $headerRow = 5;
    $sheet->setCellValue("A{$headerRow}", 'Enrollment');
    $sheet->setCellValue("B{$headerRow}", 'Name');
    $sheet->setCellValue("C{$headerRow}", 'Class');
    $sheet->setCellValue("D{$headerRow}", 'Lab Attendance %');
    $sheet->setCellValue("E{$headerRow}", 'Lecture Attendance %');
    $sheet->setCellValue("F{$headerRow}", 'Tutorial Attendance %');
    $sheet->setCellValue("G{$headerRow}", 'Total Attendance %');

    $rowNum = $headerRow + 1;
    foreach ($rows as $row) {
        $sheet->setCellValueExplicit("A{$rowNum}", (string)$row['enrollment'], DataType::TYPE_STRING);
        $sheet->setCellValue("B{$rowNum}", $row['name']);
        $sheet->setCellValue("C{$rowNum}", $row['class']);
        $sheet->setCellValue("D{$rowNum}", percent_display($row['lab_pct']));
        $sheet->setCellValue("E{$rowNum}", percent_display($row['lec_pct']));
        $sheet->setCellValue("F{$rowNum}", percent_display($row['tut_pct']));
        $sheet->setCellValue("G{$rowNum}", percent_display($row['total_pct']));
        $rowNum++;
    }

    $lastDataRow = max($headerRow, $rowNum - 1);

    $sheet->getStyle('A1:A3')->getFont()->setBold(true);
    $sheet->getStyle('A1')->getFont()->setSize(14);
    $sheet->getStyle("A1:G3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A1:G3")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true);
    $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
    $sheet->getStyle("A{$headerRow}:G{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$headerRow}:G{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A{$headerRow}:A{$lastDataRow}")->getNumberFormat()->setFormatCode('@');

    $sheet->getColumnDimension('A')->setWidth(18);
    $sheet->getColumnDimension('B')->setWidth(28);
    $sheet->getColumnDimension('C')->setWidth(10);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(18);
    $sheet->getColumnDimension('F')->setWidth(18);
    $sheet->getColumnDimension('G')->setWidth(18);
    $sheet->freezePane('A6');

    $subject_part = ($filters['subject'] !== '') ? '_' . $filters['subject'] : '';
    $filename = 'attendance_analysis_sem' . $filters['sem'] . '_class' . $filters['class'] . $subject_part . '_' . $filters['start_date'] . '_to_' . $filters['end_date'] . '.xlsx';
    $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function download_subject_wise_attendance_excel($detailRows, $summaryRows, $filters, $students_count) {
    $spreadsheet = new Spreadsheet();
    $titleFill = '1F4E78';
    $headerFill = 'D9EAF7';

    $detailSheet = $spreadsheet->getActiveSheet();
    $detailSheet->setTitle('Subject-wise Detail');
    $detailSheet->mergeCells('A1:H1');
    $detailSheet->setCellValue('A1', 'Subject-wise Detailed Attendance Analysis');
    $detailSheet->mergeCells('A2:H2');
    $detailSheet->setCellValue('A2', 'Semester: ' . $filters['sem'] . '    Class: ' . $filters['class'] . '    Date Range: ' . $filters['start_date'] . ' to ' . $filters['end_date'] . '    Students: ' . $students_count);
    $detailHeaders = ['Enrollment', 'Name', 'Class', 'Subject', 'Lab %', 'Lecture %', 'Tutorial %', 'Subject Overall %'];
    $detailColumns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    foreach ($detailHeaders as $column => $label) {
        $detailSheet->setCellValue($detailColumns[$column] . '4', $label);
    }
    $rowNumber = 5;
    foreach ($detailRows as $row) {
        $detailSheet->setCellValueExplicit("A{$rowNumber}", (string)$row['enrollment'], DataType::TYPE_STRING);
        $detailSheet->setCellValue("B{$rowNumber}", $row['name']);
        $detailSheet->setCellValue("C{$rowNumber}", $row['class']);
        $detailSheet->setCellValue("D{$rowNumber}", $row['subject']);
        $detailSheet->setCellValue("E{$rowNumber}", percent_display($row['lab_pct']));
        $detailSheet->setCellValue("F{$rowNumber}", percent_display($row['lec_pct']));
        $detailSheet->setCellValue("G{$rowNumber}", percent_display($row['tut_pct']));
        $detailSheet->setCellValue("H{$rowNumber}", percent_display($row['subject_total_pct']));
        $rowNumber++;
    }
    $detailLastRow = max(4, $rowNumber - 1);
    $detailSheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $detailSheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($titleFill);
    $detailSheet->getStyle('A1:H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $detailSheet->getStyle('A4:H4')->getFont()->setBold(true);
    $detailSheet->getStyle('A4:H4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($headerFill);
    $detailSheet->getStyle("A4:H{$detailLastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $detailSheet->getStyle("A4:H{$detailLastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $detailSheet->getStyle("A5:A{$detailLastRow}")->getNumberFormat()->setFormatCode('@');
    $detailSheet->setAutoFilter("A4:H{$detailLastRow}");
    $detailSheet->freezePane('A5');
    foreach (['A' => 18, 'B' => 28, 'C' => 10, 'D' => 32, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 19] as $column => $width) {
        $detailSheet->getColumnDimension($column)->setWidth($width);
    }

    $summarySheet = $spreadsheet->createSheet();
    $summarySheet->setTitle('Overall Summary');
    $summarySheet->mergeCells('A1:G1');
    $summarySheet->setCellValue('A1', 'Overall Attendance Summary');
    $summarySheet->mergeCells('A2:G2');
    $summarySheet->setCellValue('A2', 'Semester: ' . $filters['sem'] . '    Class: ' . $filters['class'] . '    Date Range: ' . $filters['start_date'] . ' to ' . $filters['end_date'] . '    Students: ' . $students_count);
    $summaryHeaders = ['Enrollment', 'Name', 'Class', 'Lab Overall %', 'Lecture Overall %', 'Tutorial Overall %', 'Combined Overall %'];
    $summaryColumns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    foreach ($summaryHeaders as $column => $label) {
        $summarySheet->setCellValue($summaryColumns[$column] . '4', $label);
    }
    $rowNumber = 5;
    foreach ($summaryRows as $row) {
        $summarySheet->setCellValueExplicit("A{$rowNumber}", (string)$row['enrollment'], DataType::TYPE_STRING);
        $summarySheet->setCellValue("B{$rowNumber}", $row['name']);
        $summarySheet->setCellValue("C{$rowNumber}", $row['class']);
        $summarySheet->setCellValue("D{$rowNumber}", percent_display($row['lab_pct']));
        $summarySheet->setCellValue("E{$rowNumber}", percent_display($row['lec_pct']));
        $summarySheet->setCellValue("F{$rowNumber}", percent_display($row['tut_pct']));
        $summarySheet->setCellValue("G{$rowNumber}", percent_display($row['total_pct']));
        $rowNumber++;
    }
    $summaryLastRow = max(4, $rowNumber - 1);
    $summarySheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($titleFill);
    $summarySheet->getStyle('A1:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $summarySheet->getStyle('A4:G4')->getFont()->setBold(true);
    $summarySheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($headerFill);
    $summarySheet->getStyle("A4:G{$summaryLastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $summarySheet->getStyle("A4:G{$summaryLastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $summarySheet->getStyle("A5:A{$summaryLastRow}")->getNumberFormat()->setFormatCode('@');
    $summarySheet->setAutoFilter("A4:G{$summaryLastRow}");
    $summarySheet->freezePane('A5');
    foreach (['A' => 18, 'B' => 28, 'C' => 10, 'D' => 18, 'E' => 20, 'F' => 20, 'G' => 20] as $column => $width) {
        $summarySheet->getColumnDimension($column)->setWidth($width);
    }

    $spreadsheet->setActiveSheetIndex(0);
    $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', 'subject_wise_attendance_sem' . $filters['sem'] . '_class' . $filters['class'] . '_' . $filters['start_date'] . '_to_' . $filters['end_date'] . '.xlsx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

$sem = trim((string)($_GET['sem'] ?? ''));
$class = strtoupper(trim((string)($_GET['class'] ?? '')));
$subject = trim((string)($_GET['subject'] ?? ''));   // '' = all subjects (overall analysis)
$start_date = trim((string)($_GET['start_date'] ?? ''));
$end_date = trim((string)($_GET['end_date'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$msg = '';
$rows = [];
$students_count = 0;

$sem_result = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");
$allowed_classes = ['A', 'B', 'C', 'D'];

// Subjects for the dropdown (filtered client-side by the chosen semester)
$subject_options = [];
$subject_opt_result = $conn->query("SELECT subjectName, subjectCode, sem FROM subjects WHERE status = 1 ORDER BY sem, subjectName");
if ($subject_opt_result) {
    while ($subject_opt_row = $subject_opt_result->fetch_assoc()) {
        $subject_options[] = [
            'subjectName' => (string)$subject_opt_row['subjectName'],
            'subjectCode' => (string)$subject_opt_row['subjectCode'],
            'sem'         => (string)$subject_opt_row['sem'],
        ];
    }
}

$hasFilterInput = ($sem !== '' || $class !== '' || $start_date !== '' || $end_date !== '');
if ($hasFilterInput) {
    if ($sem === '' || $class === '' || $start_date === '' || $end_date === '') {
        $msg = 'Please select Semester, Class, Start Date, and End Date.';
    } elseif (!in_array($class, $allowed_classes, true)) {
        $msg = 'Invalid class selected.';
    } else {
        $startDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
        $endDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $end_date);

        if (!$startDateObj || !$endDateObj || $startDateObj > $endDateObj) {
            $msg = 'Please provide a valid date range.';
        } else {
            $students = [];
            $studentOrder = [];
            $studentStats = [];
            $idToEnrollment = [];
            $labStudentsByBatch = [];
            $tutStudentsByBatch = [];

            $stuStmt = $conn->prepare("SELECT id, enrollmentNo, name, class, labBatch, tutBatch FROM students WHERE sem = ? AND class = ? ORDER BY enrollmentNo, name");
            $stuStmt->bind_param('ss', $sem, $class);
            $stuStmt->execute();
            $stuRes = $stuStmt->get_result();
            // Natural order so CO-1, CO-2 … CO-10 appear in sequence.
            $stuRows = attendance_sort_students_naturally($stuRes->fetch_all(MYSQLI_ASSOC));
            foreach ($stuRows as $student) {
                $enrollment = trim((string)$student['enrollmentNo']);
                if ($enrollment === '') {
                    continue;
                }

                $students[$enrollment] = [
                    'enrollment' => $enrollment,
                    'name' => trim((string)$student['name']),
                    'class' => trim((string)$student['class']),
                    'labBatch' => strtoupper(trim((string)$student['labBatch'])),
                    'tutBatch' => strtoupper(trim((string)$student['tutBatch'])),
                ];
                $studentOrder[] = $enrollment;
                $studentStats[$enrollment] = [
                    'lec_total' => 0,
                    'lec_present' => 0,
                    'lab_total' => 0,
                    'lab_present' => 0,
                    'tut_total' => 0,
                    'tut_present' => 0,
                ];

                $id = trim((string)$student['id']);
                if ($id !== '') {
                    $idToEnrollment[$id] = $enrollment;
                }

                if ($students[$enrollment]['labBatch'] !== '') {
                    if (!isset($labStudentsByBatch[$students[$enrollment]['labBatch']])) {
                        $labStudentsByBatch[$students[$enrollment]['labBatch']] = [];
                    }
                    $labStudentsByBatch[$students[$enrollment]['labBatch']][] = $enrollment;
                }

                if ($students[$enrollment]['tutBatch'] !== '') {
                    if (!isset($tutStudentsByBatch[$students[$enrollment]['tutBatch']])) {
                        $tutStudentsByBatch[$students[$enrollment]['tutBatch']] = [];
                    }
                    $tutStudentsByBatch[$students[$enrollment]['tutBatch']][] = $enrollment;
                }
            }
            $stuStmt->close();

            $students_count = count($studentOrder);
            if ($students_count === 0) {
                $msg = 'No students found for selected semester and class.';
            } else {
                if ($subject !== '') {
                    $lecStmt = $conn->prepare("SELECT date, presentNo FROM lecattendance WHERE sem = ? AND class = ? AND subject = ? ORDER BY id ASC");
                    $lecStmt->bind_param('sss', $sem, $class, $subject);
                } else {
                    $lecStmt = $conn->prepare("SELECT date, presentNo FROM lecattendance WHERE sem = ? AND class = ? ORDER BY id ASC");
                    $lecStmt->bind_param('ss', $sem, $class);
                }
                $lecStmt->execute();
                $lecRes = $lecStmt->get_result();
                while ($lec = $lecRes->fetch_assoc()) {
                    $sessionDate = parse_attendance_date($lec['date'] ?? '');
                    if (!$sessionDate || $sessionDate < $startDateObj || $sessionDate > $endDateObj) {
                        continue;
                    }

                    $presentSet = present_enrollment_set($lec['presentNo'] ?? '', $idToEnrollment);
                    foreach ($studentOrder as $enrollment) {
                        $studentStats[$enrollment]['lec_total']++;
                        if (isset($presentSet[$enrollment])) {
                            $studentStats[$enrollment]['lec_present']++;
                        }
                    }
                }
                $lecStmt->close();

                if ($subject !== '') {
                    $labStmt = $conn->prepare("SELECT date, batch, presentNo FROM labattendance WHERE sem = ? AND subject = ? AND COALESCE(TRIM(labNo), '') <> '' ORDER BY id ASC");
                    $labStmt->bind_param('ss', $sem, $subject);
                } else {
                    $labStmt = $conn->prepare("SELECT date, batch, presentNo FROM labattendance WHERE sem = ? AND COALESCE(TRIM(labNo), '') <> '' ORDER BY id ASC");
                    $labStmt->bind_param('s', $sem);
                }
                $labStmt->execute();
                $labRes = $labStmt->get_result();
                while ($lab = $labRes->fetch_assoc()) {
                    $sessionDate = parse_attendance_date($lab['date'] ?? '');
                    if (!$sessionDate || $sessionDate < $startDateObj || $sessionDate > $endDateObj) {
                        continue;
                    }

                    $rowBatches = normalize_tokens($lab['batch'] ?? '');
                    if (empty($rowBatches)) {
                        continue;
                    }

                    $presentSet = present_enrollment_set($lab['presentNo'] ?? '', $idToEnrollment);
                    foreach ($rowBatches as $batch) {
                        if (!isset($labStudentsByBatch[$batch])) {
                            continue;
                        }
                        foreach ($labStudentsByBatch[$batch] as $enrollment) {
                            $studentStats[$enrollment]['lab_total']++;
                            if (isset($presentSet[$enrollment])) {
                                $studentStats[$enrollment]['lab_present']++;
                            }
                        }
                    }
                }
                $labStmt->close();

                if ($subject !== '') {
                    $tutStmt = $conn->prepare("SELECT date, batch, presentNo FROM tutattendance WHERE sem = ? AND subject = ? ORDER BY id ASC");
                    $tutStmt->bind_param('ss', $sem, $subject);
                } else {
                    $tutStmt = $conn->prepare("SELECT date, batch, presentNo FROM tutattendance WHERE sem = ? ORDER BY id ASC");
                    $tutStmt->bind_param('s', $sem);
                }
                $tutStmt->execute();
                $tutRes = $tutStmt->get_result();
                while ($tut = $tutRes->fetch_assoc()) {
                    $sessionDate = parse_attendance_date($tut['date'] ?? '');
                    if (!$sessionDate || $sessionDate < $startDateObj || $sessionDate > $endDateObj) {
                        continue;
                    }

                    $rowBatches = normalize_tokens($tut['batch'] ?? '');
                    if (empty($rowBatches)) {
                        continue;
                    }

                    $presentSet = present_enrollment_set($tut['presentNo'] ?? '', $idToEnrollment);
                    foreach ($rowBatches as $batch) {
                        if (!isset($tutStudentsByBatch[$batch])) {
                            continue;
                        }
                        foreach ($tutStudentsByBatch[$batch] as $enrollment) {
                            $studentStats[$enrollment]['tut_total']++;
                            if (isset($presentSet[$enrollment])) {
                                $studentStats[$enrollment]['tut_present']++;
                            }
                        }
                    }
                }
                $tutStmt->close();

                foreach ($studentOrder as $enrollment) {
                    $s = $students[$enrollment];
                    $st = $studentStats[$enrollment];

                    $lecPct = percent_value($st['lec_present'], $st['lec_total']);
                    $labPct = percent_value($st['lab_present'], $st['lab_total']);
                    $tutPct = percent_value($st['tut_present'], $st['tut_total']);

                    $totalPresent = $st['lec_present'] + $st['lab_present'] + $st['tut_present'];
                    $totalConducted = $st['lec_total'] + $st['lab_total'] + $st['tut_total'];
                    $totalPct = percent_value($totalPresent, $totalConducted);

                    $rows[] = [
                        'enrollment' => $s['enrollment'],
                        'name' => $s['name'],
                        'class' => $s['class'],
                        'lec_pct' => $lecPct,
                        'lab_pct' => $labPct,
                        'tut_pct' => $tutPct,
                        'total_pct' => $totalPct,
                    ];
                }

                if ($export === 'excel' && !empty($rows)) {
                    download_attendance_analysis_excel($rows, [
                        'sem' => $sem,
                        'class' => $class,
                        'subject' => $subject,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                    ], $students_count);
                }
            }
        }
    }
}

// Subject-wise detailed analysis is intentionally independent of the existing
// report above, so the original single-subject/all-subject analysis remains unchanged.
$detail_sem = trim((string)($_GET['detail_sem'] ?? ''));
$detail_class = strtoupper(trim((string)($_GET['detail_class'] ?? '')));
$detail_start_date = trim((string)($_GET['detail_start_date'] ?? ''));
$detail_end_date = trim((string)($_GET['detail_end_date'] ?? ''));
$detail_requested = (string)($_GET['detail'] ?? '') === '1';
$detail_export = strtolower(trim((string)($_GET['detail_export'] ?? '')));
$detail_msg = '';
$detail_rows = [];
$detail_summary_rows = [];
$detail_students_count = 0;
$detail_sem_result = $conn->query("SELECT sem FROM semester WHERE status = 1 ORDER BY sem");

if ($detail_requested) {
    if ($detail_sem === '' || $detail_class === '' || $detail_start_date === '' || $detail_end_date === '') {
        $detail_msg = 'Please select Semester, Class, Start Date, and End Date for the subject-wise report.';
    } elseif (!in_array($detail_class, $allowed_classes, true)) {
        $detail_msg = 'Invalid class selected for the subject-wise report.';
    } else {
        $detail_start = DateTimeImmutable::createFromFormat('!Y-m-d', $detail_start_date);
        $detail_end = DateTimeImmutable::createFromFormat('!Y-m-d', $detail_end_date);
        if (!$detail_start || !$detail_end || $detail_start > $detail_end) {
            $detail_msg = 'Please provide a valid date range for the subject-wise report.';
        } else {
            $detail_students = [];
            $detail_order = [];
            $detail_id_to_enrollment = [];
            $detail_lab_by_batch = [];
            $detail_tut_by_batch = [];
            $detail_stats = [];
            $detail_subjects = [];

            $detail_student_stmt = $conn->prepare("SELECT id, enrollmentNo, name, class, labBatch, tutBatch FROM students WHERE sem = ? AND class = ? ORDER BY enrollmentNo, name");
            $detail_student_stmt->bind_param('ss', $detail_sem, $detail_class);
            $detail_student_stmt->execute();
            $detail_student_rows = attendance_sort_students_naturally($detail_student_stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $detail_student_stmt->close();

            foreach ($detail_student_rows as $student) {
                $enrollment = trim((string)$student['enrollmentNo']);
                if ($enrollment === '') continue;
                $detail_students[$enrollment] = [
                    'enrollment' => $enrollment,
                    'name' => trim((string)$student['name']),
                    'class' => trim((string)$student['class']),
                ];
                $detail_order[] = $enrollment;
                $detail_id_to_enrollment[(string)$student['id']] = $enrollment;
                $lab_batch = strtoupper(trim((string)$student['labBatch']));
                $tut_batch = strtoupper(trim((string)$student['tutBatch']));
                if ($lab_batch !== '') $detail_lab_by_batch[$lab_batch][] = $enrollment;
                if ($tut_batch !== '') $detail_tut_by_batch[$tut_batch][] = $enrollment;
            }

            $detail_students_count = count($detail_order);
            if ($detail_students_count === 0) {
                $detail_msg = 'No students found for the selected semester and class.';
            } else {
                $record_session = static function ($subject, $component, $enrollments, $presentNo) use (&$detail_stats, &$detail_subjects, $detail_id_to_enrollment) {
                    $subject = trim((string)$subject);
                    if ($subject === '') $subject = 'Unspecified Subject';
                    $detail_subjects[$subject] = true;
                    $present_set = present_enrollment_set($presentNo, $detail_id_to_enrollment);
                    foreach ($enrollments as $enrollment) {
                        if (!isset($detail_stats[$enrollment])) $detail_stats[$enrollment] = [];
                        if (!isset($detail_stats[$enrollment][$subject])) {
                            $detail_stats[$enrollment][$subject] = ['lec_total' => 0, 'lec_present' => 0, 'lab_total' => 0, 'lab_present' => 0, 'tut_total' => 0, 'tut_present' => 0];
                        }
                        $detail_stats[$enrollment][$subject][$component . '_total']++;
                        if (isset($present_set[$enrollment])) $detail_stats[$enrollment][$subject][$component . '_present']++;
                    }
                };

                $lecture_stmt = $conn->prepare("SELECT date, subject, presentNo FROM lecattendance WHERE sem = ? AND class = ? ORDER BY id ASC");
                $lecture_stmt->bind_param('ss', $detail_sem, $detail_class);
                $lecture_stmt->execute();
                $lecture_result = $lecture_stmt->get_result();
                while ($record = $lecture_result->fetch_assoc()) {
                    $record_date = parse_attendance_date($record['date'] ?? '');
                    if ($record_date && $record_date >= $detail_start && $record_date <= $detail_end) {
                        $record_session($record['subject'] ?? '', 'lec', $detail_order, $record['presentNo'] ?? '');
                    }
                }
                $lecture_stmt->close();

                foreach (['lab' => ['labattendance', 'labNo'], 'tut' => ['tutattendance', null]] as $component => $table_info) {
                    $sql = "SELECT date, subject, batch, presentNo FROM {$table_info[0]} WHERE sem = ?" . ($table_info[1] ? " AND COALESCE(TRIM({$table_info[1]}), '') <> ''" : '') . " ORDER BY id ASC";
                    $component_stmt = $conn->prepare($sql);
                    $component_stmt->bind_param('s', $detail_sem);
                    $component_stmt->execute();
                    $component_result = $component_stmt->get_result();
                    while ($record = $component_result->fetch_assoc()) {
                        $record_date = parse_attendance_date($record['date'] ?? '');
                        if (!$record_date || $record_date < $detail_start || $record_date > $detail_end) continue;
                        $eligible = [];
                        $batch_map = $component === 'lab' ? $detail_lab_by_batch : $detail_tut_by_batch;
                        foreach (normalize_tokens($record['batch'] ?? '') as $batch) {
                            if (isset($batch_map[$batch])) $eligible = array_merge($eligible, $batch_map[$batch]);
                        }
                        if (!empty($eligible)) $record_session($record['subject'] ?? '', $component, array_values(array_unique($eligible)), $record['presentNo'] ?? '');
                    }
                    $component_stmt->close();
                }

                $detail_subject_names = array_keys($detail_subjects);
                natcasesort($detail_subject_names);
                foreach ($detail_order as $enrollment) {
                    $overall = ['lec_total' => 0, 'lec_present' => 0, 'lab_total' => 0, 'lab_present' => 0, 'tut_total' => 0, 'tut_present' => 0];
                    foreach ($detail_subject_names as $subject_name) {
                        $stats = $detail_stats[$enrollment][$subject_name] ?? ['lec_total' => 0, 'lec_present' => 0, 'lab_total' => 0, 'lab_present' => 0, 'tut_total' => 0, 'tut_present' => 0];
                        foreach ($overall as $key => $unused) $overall[$key] += $stats[$key];
                        $subject_present = $stats['lec_present'] + $stats['lab_present'] + $stats['tut_present'];
                        $subject_total = $stats['lec_total'] + $stats['lab_total'] + $stats['tut_total'];
                        $detail_rows[] = [
                            'enrollment' => $detail_students[$enrollment]['enrollment'], 'name' => $detail_students[$enrollment]['name'], 'class' => $detail_students[$enrollment]['class'], 'subject' => $subject_name,
                            'lec_pct' => percent_value($stats['lec_present'], $stats['lec_total']), 'lab_pct' => percent_value($stats['lab_present'], $stats['lab_total']), 'tut_pct' => percent_value($stats['tut_present'], $stats['tut_total']), 'subject_total_pct' => percent_value($subject_present, $subject_total),
                        ];
                    }
                    $overall_present = $overall['lec_present'] + $overall['lab_present'] + $overall['tut_present'];
                    $overall_total = $overall['lec_total'] + $overall['lab_total'] + $overall['tut_total'];
                    $detail_summary_rows[] = [
                        'enrollment' => $detail_students[$enrollment]['enrollment'], 'name' => $detail_students[$enrollment]['name'], 'class' => $detail_students[$enrollment]['class'],
                        'lec_pct' => percent_value($overall['lec_present'], $overall['lec_total']), 'lab_pct' => percent_value($overall['lab_present'], $overall['lab_total']), 'tut_pct' => percent_value($overall['tut_present'], $overall['tut_total']), 'total_pct' => percent_value($overall_present, $overall_total),
                    ];
                }

                if ($detail_export === 'excel' && !empty($detail_rows)) {
                    download_subject_wise_attendance_excel($detail_rows, $detail_summary_rows, ['sem' => $detail_sem, 'class' => $detail_class, 'start_date' => $detail_start_date, 'end_date' => $detail_end_date], $detail_students_count);
                }
                if (empty($detail_rows) && $detail_msg === '') $detail_msg = 'No attendance records found in the selected range.';
            }
        }
    }
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
            <h1 class="app-page-title"><i class="bi bi-bar-chart-line me-2"></i>Attendance Analysis</h1>

            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <form method="GET" action="attendanceAnalysis.php">
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-md-3">
                                <label class="form-label">Semester</label>
                                <select name="sem" class="form-control" required>
                                    <option value="">Select Semester</option>
                                    <?php while ($semRow = $sem_result->fetch_assoc()) { ?>
                                        <option value="<?= htmlspecialchars($semRow['sem']); ?>" <?= ((string)$semRow['sem'] === $sem) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($semRow['sem']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Class</label>
                                <select name="class" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($allowed_classes as $classOption) { ?>
                                        <option value="<?= $classOption; ?>" <?= ($classOption === $class) ? 'selected' : ''; ?>><?= $classOption; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Subject</label>
                                <select name="subject" id="subjectFilter" class="form-control">
                                    <option value="">All Subjects (Overall)</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date); ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date); ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-graph-up me-1"></i>Generate Analysis
                                </button>
                            </div>
                        </div>
                    </form>
                    <p class="text-muted mb-0 mt-3" style="font-size:0.85rem;">
                        Total attendance % is calculated from combined conducted sessions (lecture + lab + tutorial). Components with zero conducted sessions are not treated as 0%.
                        Select a subject to analyse only that subject's lectures, labs, and tutorials; leave it on "All Subjects" for the overall analysis.
                    </p>
                </div>
            </div>

            <?php if ($msg !== ''): ?>
                <div class="alert alert-warning"><?= htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <?php if (!empty($rows)): ?>
                <div class="app-card shadow-sm">
                    <div class="app-card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                            <h4 class="mb-0">
                                Result
                                <?php if ($subject !== ''): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size:0.72rem; vertical-align:middle;"><?= htmlspecialchars($subject); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1" style="font-size:0.72rem; vertical-align:middle;">All Subjects</span>
                                <?php endif; ?>
                            </h4>
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <span class="text-muted" style="font-size:0.875rem;">Students: <?= (int)$students_count; ?></span>
                                <a href="attendanceAnalysis.php?<?= htmlspecialchars(http_build_query([
                                    'sem' => $sem,
                                    'class' => $class,
                                    'subject' => $subject,
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                    'export' => 'excel',
                                ])); ?>" class="btn btn-success btn-sm">
                                    <i class="bi bi-file-earmark-excel me-1"></i>Download Excel
                                </a>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Enroll</th>
                                        <th>Name</th>
                                        <th>Class</th>
                                        <th>Lab Attendance %</th>
                                        <th>Lecture Attendance %</th>
                                        <th>Tutorial Attendance %</th>
                                        <th>Total Attendance %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['enrollment']); ?></td>
                                            <td><?= htmlspecialchars($row['name']); ?></td>
                                            <td><?= htmlspecialchars($row['class']); ?></td>
                                            <td><?= htmlspecialchars(percent_display($row['lab_pct'])); ?></td>
                                            <td><?= htmlspecialchars(percent_display($row['lec_pct'])); ?></td>
                                            <td><?= htmlspecialchars(percent_display($row['tut_pct'])); ?></td>
                                            <td><strong><?= htmlspecialchars(percent_display($row['total_pct'])); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif ($hasFilterInput && $msg === ''): ?>
                <div class="alert alert-info">No attendance records found in selected range.</div>
            <?php endif; ?>

            <hr class="my-5">

            <section aria-labelledby="subject-wise-analysis-title">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h2 id="subject-wise-analysis-title" class="h3 mb-0"><i class="bi bi-table me-2"></i>Subject-wise Detailed Analysis</h2>
                    <span class="badge text-bg-info">New</span>
                </div>
                <p class="text-muted">Generate one row per student and subject, with Lab, Lecture, Tutorial, and subject overall percentages. The overall summary below combines every subject for each student.</p>

                <div class="app-card shadow-sm mb-3">
                    <div class="app-card-body">
                        <form method="GET" action="attendanceAnalysis.php">
                            <input type="hidden" name="detail" value="1">
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Semester</label>
                                    <select name="detail_sem" class="form-control" required>
                                        <option value="">Select Semester</option>
                                        <?php if ($detail_sem_result): while ($semRow = $detail_sem_result->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($semRow['sem']); ?>" <?= ((string)$semRow['sem'] === $detail_sem) ? 'selected' : ''; ?>><?= htmlspecialchars($semRow['sem']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Class</label>
                                    <select name="detail_class" class="form-control" required>
                                        <option value="">Select Class</option>
                                        <?php foreach ($allowed_classes as $classOption): ?>
                                            <option value="<?= $classOption; ?>" <?= ($classOption === $detail_class) ? 'selected' : ''; ?>><?= $classOption; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="detail_start_date" class="form-control" value="<?= htmlspecialchars($detail_start_date); ?>" required>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label">End Date</label>
                                    <input type="date" name="detail_end_date" class="form-control" value="<?= htmlspecialchars($detail_end_date); ?>" required>
                                </div>
                                <div class="col-12 col-md-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-file-bar-graph me-1"></i>Generate Report</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($detail_msg !== ''): ?>
                    <div class="alert alert-warning"><?= htmlspecialchars($detail_msg); ?></div>
                <?php endif; ?>

                <?php if (!empty($detail_rows)): ?>
                    <div class="app-card shadow-sm mb-3">
                        <div class="app-card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <h3 class="h5 mb-0">Subject-wise Detail</h3>
                                <a href="attendanceAnalysis.php?<?= htmlspecialchars(http_build_query(['detail' => '1', 'detail_sem' => $detail_sem, 'detail_class' => $detail_class, 'detail_start_date' => $detail_start_date, 'detail_end_date' => $detail_end_date, 'detail_export' => 'excel'])); ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Download Formatted Excel</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light"><tr><th>Enroll</th><th>Name</th><th>Class</th><th>Subject</th><th>Lab %</th><th>Lecture %</th><th>Tutorial %</th><th>Subject Overall %</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($detail_rows as $row): ?>
                                            <tr><td><?= htmlspecialchars($row['enrollment']); ?></td><td><?= htmlspecialchars($row['name']); ?></td><td><?= htmlspecialchars($row['class']); ?></td><td><?= htmlspecialchars($row['subject']); ?></td><td><?= htmlspecialchars(percent_display($row['lab_pct'])); ?></td><td><?= htmlspecialchars(percent_display($row['lec_pct'])); ?></td><td><?= htmlspecialchars(percent_display($row['tut_pct'])); ?></td><td><strong><?= htmlspecialchars(percent_display($row['subject_total_pct'])); ?></strong></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h3 class="h5 mb-3">Overall Student Summary</h3>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light"><tr><th>Enroll</th><th>Name</th><th>Class</th><th>Lab Overall %</th><th>Lecture Overall %</th><th>Tutorial Overall %</th><th>Combined Overall %</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($detail_summary_rows as $row): ?>
                                            <tr><td><?= htmlspecialchars($row['enrollment']); ?></td><td><?= htmlspecialchars($row['name']); ?></td><td><?= htmlspecialchars($row['class']); ?></td><td><?= htmlspecialchars(percent_display($row['lab_pct'])); ?></td><td><?= htmlspecialchars(percent_display($row['lec_pct'])); ?></td><td><?= htmlspecialchars(percent_display($row['tut_pct'])); ?></td><td><strong><?= htmlspecialchars(percent_display($row['total_pct'])); ?></strong></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const subjectsData = <?= json_encode($subject_options, JSON_UNESCAPED_UNICODE) ?>;
    const selectedSubject = <?= json_encode($subject, JSON_UNESCAPED_UNICODE) ?>;

    const semSelect = document.querySelector('select[name="sem"]');
    const subjectSelect = document.getElementById('subjectFilter');
    if (!semSelect || !subjectSelect) return;

    function refreshSubjects() {
        const sem = semSelect.value;
        const current = subjectSelect.value || selectedSubject;
        subjectSelect.innerHTML = '';
        const allOpt = document.createElement('option');
        allOpt.value = '';
        allOpt.textContent = 'All Subjects (Overall)';
        subjectSelect.appendChild(allOpt);
        subjectsData.forEach(function (s) {
            if (sem !== '' && String(s.sem) !== String(sem)) return;
            const opt = document.createElement('option');
            opt.value = s.subjectName;
            opt.textContent = s.subjectName + (s.subjectCode ? ' (' + s.subjectCode + ')' : '');
            if (String(s.subjectName) === String(current)) opt.selected = true;
            subjectSelect.appendChild(opt);
        });
    }

    semSelect.addEventListener('change', refreshSubjects);
    refreshSubjects();
});
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>

<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$error = '';
$currentDoctorId = (int)($_SESSION['user_id'] ?? 0);


/* เตรียมคอลัมน์ ADL แบบ idempotent เพื่อไม่ให้เกิด Duplicate column name */
function ensureAssignAdlColumn(mysqli $conn, string $columnName, string $definition): bool
{
    $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);
    $check = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM adl_assessment LIKE '" . mysqli_real_escape_string($conn, $safeName) . "'"
    );

    if ($check && mysqli_num_rows($check) > 0) {
        return true;
    }

    return (bool) mysqli_query($conn, "ALTER TABLE adl_assessment ADD COLUMN `$safeName` $definition");
}

function syncAssignedPatientAdl(mysqli $conn, int $patientId, int $caregiverId): bool
{
    $adlTable = mysqli_query($conn, "SHOW TABLES LIKE 'adl_assessment'");
    if (!$adlTable || mysqli_num_rows($adlTable) === 0) {
        return true;
    }

    if (!ensureAssignAdlColumn($conn, 'handoff_status', "VARCHAR(40) NOT NULL DEFAULT 'รอส่งต่อ'")) {
        return false;
    }
    if (!ensureAssignAdlColumn($conn, 'caregiver_completed_at', 'DATETIME NULL')) {
        return false;
    }

    $syncStmt = mysqli_prepare(
        $conn,
        "UPDATE adl_assessment
         SET caregiver_user_id=?, handoff_status='รอแคร์กิฟเวอร์ประเมินต่อ'
         WHERE patient_id=? AND (caregiver_user_id IS NULL OR caregiver_user_id=0)"
    );
    if (!$syncStmt) {
        return false;
    }

    mysqli_stmt_bind_param($syncStmt, 'ii', $caregiverId, $patientId);
    $ok = mysqli_stmt_execute($syncStmt);
    mysqli_stmt_close($syncStmt);
    return $ok;
}

$createSql = "CREATE TABLE IF NOT EXISTS patient_caregiver_assignment (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    caregiver_user_id INT NOT NULL,
    doctor_user_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    care_status VARCHAR(30) NOT NULL DEFAULT 'กำลังดูแล',
    care_pause_reason VARCHAR(120) NULL,
    care_end_reason VARCHAR(120) NULL,
    care_status_note VARCHAR(255) NULL,
    care_status_updated_at DATETIME NULL,
    next_visit_date DATE NULL,
    UNIQUE KEY uq_patient_assignment (patient_id),
    KEY idx_caregiver_user (caregiver_user_id),
    KEY idx_doctor_user (doctor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $createSql)) {
    $error = 'ไม่สามารถเตรียมตารางการมอบหมายผู้สูงอายุได้: ' . mysqli_error($conn);
}

/* รองรับฐานข้อมูลเดิม โดยเพิ่มเฉพาะคอลัมน์ที่ยังไม่มี */
if ($error === '') {
    $careColumns = [
        'care_status' => "VARCHAR(30) NOT NULL DEFAULT 'กำลังดูแล'",
        'care_pause_reason' => 'VARCHAR(120) NULL',
        'care_end_reason' => 'VARCHAR(120) NULL',
        'care_status_note' => 'VARCHAR(255) NULL',
        'care_status_updated_at' => 'DATETIME NULL',
        'next_visit_date' => 'DATE NULL',
    ];

    foreach ($careColumns as $columnName => $definition) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM patient_caregiver_assignment LIKE '" . mysqli_real_escape_string($conn, $columnName) . "'");
        if ($check && mysqli_num_rows($check) === 0) {
            if (!mysqli_query($conn, "ALTER TABLE patient_caregiver_assignment ADD COLUMN `$columnName` $definition")) {
                $error = 'ไม่สามารถเตรียมข้อมูลสถานะการดูแลได้: ' . mysqli_error($conn);
                break;
            }
        }
    }
}

/* =========================================================
   บันทึก / เปลี่ยนผู้ดูแล
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $caregiverId = (int)($_POST['caregiver_user_id'] ?? 0);
        $nextVisitDate = trim((string)($_POST['next_visit_date'] ?? ''));

        if ($patientId <= 0 || $caregiverId <= 0 || $currentDoctorId <= 0) {
            $error = 'กรุณาเลือกผู้สูงอายุและแคร์กิฟเวอร์ให้ครบ';
        } elseif ($nextVisitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextVisitDate)) {
            $error = 'กรุณากำหนดวันที่ต้องเข้าเยี่ยม';
        } else {
            $patientCheck = mysqli_prepare($conn, "SELECT Patient_id FROM patient WHERE Patient_id=? LIMIT 1");
            mysqli_stmt_bind_param($patientCheck, 'i', $patientId);
            mysqli_stmt_execute($patientCheck);
            $validPatient = mysqli_fetch_assoc(mysqli_stmt_get_result($patientCheck));
            mysqli_stmt_close($patientCheck);

            $caregiverCheck = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id=? AND role='caregiver' LIMIT 1");
            mysqli_stmt_bind_param($caregiverCheck, 'i', $caregiverId);
            mysqli_stmt_execute($caregiverCheck);
            $validCaregiver = mysqli_fetch_assoc(mysqli_stmt_get_result($caregiverCheck));
            mysqli_stmt_close($caregiverCheck);

            if (!$validPatient) {
                $error = 'ไม่พบข้อมูลผู้สูงอายุที่เลือก';
            } elseif (!$validCaregiver) {
                $error = 'ไม่พบบัญชีแคร์กิฟเวอร์ที่เลือก';
            } else {
                /* ห้ามมอบหมายผู้สูงอายุซ้ำ */
                $duplicateStmt = mysqli_prepare(
                    $conn,
                    "SELECT
                        a.assignment_id,
                        a.caregiver_user_id,
                        cg.username AS caregiver_username,
                        cg.display_name AS caregiver_name
                     FROM patient_caregiver_assignment a
                     LEFT JOIN users cg
                        ON cg.user_id=a.caregiver_user_id
                        AND cg.role='caregiver'
                     WHERE a.patient_id=?
                     LIMIT 1"
                );

                $existingAssignment = null;

                if ($duplicateStmt) {
                    mysqli_stmt_bind_param($duplicateStmt, 'i', $patientId);
                    mysqli_stmt_execute($duplicateStmt);
                    $existingAssignment = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateStmt));
                    mysqli_stmt_close($duplicateStmt);
                }

                if ($existingAssignment) {
                    $existingCaregiverId = (int)($existingAssignment['caregiver_user_id'] ?? 0);
                    $existingUsername = trim((string)($existingAssignment['caregiver_username'] ?? ''));
                    $existingName = trim((string)($existingAssignment['caregiver_name'] ?? ''));

                    $existingLabel = $existingUsername;
                    if ($existingName !== '') {
                        $existingLabel .= ($existingLabel !== '' ? ' - ' : '') . $existingName;
                    }

                    /* ถ้าการกดครั้งก่อนบันทึก assignment สำเร็จแล้วแต่หยุดที่ ALTER TABLE
                       ให้กดซ้ำได้และซิงก์ ADL ให้ครบ โดยไม่สร้าง assignment ซ้ำ */
                    if ($existingCaregiverId === $caregiverId) {
                        $visitStmt = mysqli_prepare($conn, "UPDATE patient_caregiver_assignment SET next_visit_date=? WHERE assignment_id=?");
                        if ($visitStmt) {
                            $existingAssignmentId = (int)($existingAssignment['assignment_id'] ?? 0);
                            mysqli_stmt_bind_param($visitStmt, 'si', $nextVisitDate, $existingAssignmentId);
                            mysqli_stmt_execute($visitStmt);
                            mysqli_stmt_close($visitStmt);
                        }
                        if (syncAssignedPatientAdl($conn, $patientId, $caregiverId)) {
                            $message = 'มอบหมายผู้สูงอายุให้แคร์กิฟเวอร์เรียบร้อยแล้ว พร้อมกำหนดวันที่เข้าเยี่ยม และส่งรายการ ADL ที่รอประเมินต่อให้แคร์กิฟเวอร์แล้ว';
                        } else {
                            $error = 'มอบหมายผู้ดูแลแล้ว แต่ไม่สามารถเชื่อมรายการ ADL ได้: ' . mysqli_error($conn);
                        }
                    } else {
                        $error = 'ไม่สามารถมอบหมายซ้ำได้ ผู้สูงอายุรายนี้มีผู้ดูแลอยู่แล้ว'
                               . ($existingLabel !== '' ? ' (' . $existingLabel . ')' : '')
                               . ' กรุณายกเลิกการมอบหมายเดิมก่อน';
                    }
                } else {
                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO patient_caregiver_assignment
                            (patient_id, caregiver_user_id, doctor_user_id, assigned_at, care_status, next_visit_date)
                         VALUES (?,?,?,NOW(),'กำลังดูแล',?)"
                    );

                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'iiis', $patientId, $caregiverId, $currentDoctorId, $nextVisitDate);

                        if (mysqli_stmt_execute($stmt)) {
                            // เชื่อมรายการ ADL ที่รอมอบหมายเข้ากับแคร์กิฟเวอร์คนนี้ทันที
                            if (syncAssignedPatientAdl($conn, $patientId, $caregiverId)) {
                                $message = 'มอบหมายผู้สูงอายุให้แคร์กิฟเวอร์เรียบร้อยแล้ว พร้อมกำหนดวันที่เข้าเยี่ยม และส่งรายการ ADL ที่รอประเมินต่อให้แคร์กิฟเวอร์แล้ว';
                            } else {
                                $error = 'มอบหมายผู้ดูแลแล้ว แต่ไม่สามารถเชื่อมรายการ ADL ได้: ' . mysqli_error($conn);
                            }
                        } else {
                            if ((int)mysqli_stmt_errno($stmt) === 1062) {
                                $error = 'ไม่สามารถมอบหมายซ้ำได้ ผู้สูงอายุรายนี้มีผู้ดูแลอยู่แล้ว กรุณายกเลิกการมอบหมายเดิมก่อน';
                            } else {
                                $error = 'บันทึกไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                            }
                        }

                        mysqli_stmt_close($stmt);
                    } else {
                        $error = 'ไม่สามารถเตรียมคำสั่งบันทึกการมอบหมายได้: ' . mysqli_error($conn);
                    }
                }
            }
        }
    } elseif ($action === 'unassign') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);

        if ($assignmentId <= 0) {
            $error = 'ไม่พบรายการมอบหมายที่ต้องการยกเลิก';
        } else {
            $stmt = mysqli_prepare(
                $conn,
                'DELETE FROM patient_caregiver_assignment WHERE assignment_id=? AND doctor_user_id=?'
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $assignmentId, $currentDoctorId);

                if (mysqli_stmt_execute($stmt)) {
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        $message = 'ยกเลิกการมอบหมายเรียบร้อยแล้ว';
                    } else {
                        $error = 'ไม่สามารถยกเลิกรายการนี้ได้ หรือรายการไม่ได้อยู่ภายใต้หมอผู้ใช้งานปัจจุบัน';
                    }
                } else {
                    $error = 'ยกเลิกการมอบหมายไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);
            } else {
                $error = 'ไม่สามารถเตรียมคำสั่งยกเลิกการมอบหมายได้: ' . mysqli_error($conn);
            }
        }
    } elseif ($action === 'update_visit_date') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $nextVisitDate = trim((string)($_POST['next_visit_date'] ?? ''));

        if ($assignmentId <= 0) {
            $error = 'ไม่พบรายการมอบหมายที่ต้องการกำหนดวันเข้าเยี่ยม';
        } elseif ($nextVisitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextVisitDate)) {
            $error = 'กรุณากำหนดวันที่ต้องเข้าเยี่ยม';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE patient_caregiver_assignment SET next_visit_date=? WHERE assignment_id=? AND doctor_user_id=? AND care_status='กำลังดูแล'");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sii', $nextVisitDate, $assignmentId, $currentDoctorId);
                if (mysqli_stmt_execute($stmt)) {
                    $message = 'บันทึกวันที่ต้องเข้าเยี่ยมเรียบร้อยแล้ว';
                } else {
                    $error = 'บันทึกวันที่เข้าเยี่ยมไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = 'ไม่สามารถเตรียมคำสั่งบันทึกวันที่เข้าเยี่ยมได้: ' . mysqli_error($conn);
            }
        }
    } elseif ($action === 'end_care') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $endReason = trim((string)($_POST['care_end_reason'] ?? ''));
        $statusNote = trim((string)($_POST['care_status_note'] ?? ''));
        $allowedEndReasons = [
            'หายดี / ไม่จำเป็นต้องดูแลต่อ',
            'เสียชีวิต',
            'ย้ายออกนอกพื้นที่',
            'เข้ารับการดูแลในโรงพยาบาล / สถานดูแล',
            'ครอบครัวรับดูแลต่อ',
            'เปลี่ยนผู้ดูแล',
            'อื่น ๆ'
        ];

        if ($assignmentId <= 0) {
            $error = 'ไม่พบรายการการดูแลที่ต้องการสิ้นสุด';
        } elseif (!in_array($endReason, $allowedEndReasons, true)) {
            $error = 'กรุณาระบุเหตุผลที่สิ้นสุดการดูแล';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE patient_caregiver_assignment SET care_status='สิ้นสุดการดูแล', care_pause_reason=NULL, care_end_reason=?, care_status_note=NULLIF(?,''), care_status_updated_at=NOW() WHERE assignment_id=? AND doctor_user_id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssii', $endReason, $statusNote, $assignmentId, $currentDoctorId);
                if (mysqli_stmt_execute($stmt)) {
                    $message = 'สิ้นสุดการดูแลเรียบร้อยแล้ว';
                } else {
                    $error = 'สิ้นสุดการดูแลไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = 'ไม่สามารถเตรียมคำสั่งสิ้นสุดการดูแลได้: ' . mysqli_error($conn);
            }
        }
    }
}

/* =========================================================
   ข้อมูลสำหรับแบบฟอร์ม
========================================================= */
$patients = [];
$res = mysqli_query($conn, "SELECT Patient_id, Fullname, Age FROM patient ORDER BY Fullname ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $patients[] = $r;
    }
}

$caregivers = [];
$res = mysqli_query(
    $conn,
    "SELECT user_id, username, display_name
     FROM users
     WHERE role='caregiver'
     ORDER BY CAST(SUBSTRING(username, 2) AS UNSIGNED) ASC, username ASC, display_name ASC"
);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $caregivers[] = $r;
    }
}

$doctors = [];
$res = mysqli_query(
    $conn,
    "SELECT user_id, username, display_name
     FROM users
     WHERE role='doctor'
     ORDER BY CAST(SUBSTRING(username, 2) AS UNSIGNED) ASC, username ASC, display_name ASC"
);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $doctors[] = $r;
    }
}

/* =========================================================
   รายการการมอบหมาย
   แสดงทั้ง หมอ -> ผู้สูงอายุ -> แคร์กิฟเวอร์
========================================================= */
$assignments = [];
$sql = "SELECT
            a.assignment_id,
            a.patient_id,
            a.caregiver_user_id,
            a.doctor_user_id,
            a.assigned_at,
            a.care_status,
            a.care_pause_reason,
            a.care_end_reason,
            a.care_status_note,
            a.care_status_updated_at,
            a.next_visit_date,
            p.Fullname,
            p.Age,
            p.Photo,
            cg.username AS caregiver_username,
            cg.display_name AS caregiver_name,
            dr.username AS doctor_username,
            dr.display_name AS doctor_name,
            adl.adl_id,
            adl.assessment_date,
            adl.total_score AS doctor_adl_score,
            adl.caregiver_total_score AS caregiver_adl_score,
            adl.result_returned_to_doctor,
            adl.result_returned_at,
            adl.regular_caregiver,
            adl.welfare_status,
            adl.club_membership,
            adl.note AS adl_note
        FROM patient_caregiver_assignment a
        JOIN patient p ON p.Patient_id=a.patient_id
        JOIN users cg ON cg.user_id=a.caregiver_user_id AND cg.role='caregiver'
        LEFT JOIN users dr ON dr.user_id=a.doctor_user_id AND dr.role='doctor'
        LEFT JOIN adl_assessment adl ON adl.adl_id = (
            SELECT MAX(a2.adl_id)
            FROM adl_assessment a2
            WHERE a2.patient_id=a.patient_id
              AND a2.doctor_user_id=a.doctor_user_id
        )
        ORDER BY a.assigned_at DESC, a.assignment_id DESC";
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $assignments[] = $r;
    }
} elseif ($error === '') {
    $error = 'ไม่สามารถดึงข้อมูลการมอบหมายได้: ' . mysqli_error($conn);
}

/* =========================================================
   ตัวกรอง
========================================================= */
$filterDoctor = (int)($_GET['doctor_id'] ?? 0);
$filterCaregiver = (int)($_GET['caregiver_id'] ?? 0);
$filterKeyword = trim((string)($_GET['q'] ?? ''));

$filteredAssignments = array_values(array_filter($assignments, static function ($row) use ($filterDoctor, $filterCaregiver, $filterKeyword) {
    if ($filterDoctor > 0 && (int)$row['doctor_user_id'] !== $filterDoctor) {
        return false;
    }
    if ($filterCaregiver > 0 && (int)$row['caregiver_user_id'] !== $filterCaregiver) {
        return false;
    }
    if ($filterKeyword !== '') {
        $haystack = implode(' ', [
            (string)($row['Fullname'] ?? ''),
            (string)($row['doctor_username'] ?? ''),
            (string)($row['doctor_name'] ?? ''),
            (string)($row['caregiver_username'] ?? ''),
            (string)($row['caregiver_name'] ?? ''),
        ]);
        if (mb_stripos($haystack, $filterKeyword, 0, 'UTF-8') === false) {
            return false;
        }
    }
    return true;
}));

/* =========================================================
   แบ่งหน้า: แสดง 6 การ์ดต่อหน้า (2 คอลัมน์ x 3 แถว)
========================================================= */
$assignmentPerPage = 6;
$assignmentPage = max(1, (int)($_GET['page'] ?? 1));
$filteredAssignmentTotal = count($filteredAssignments);
$assignmentTotalPages = max(1, (int)ceil($filteredAssignmentTotal / $assignmentPerPage));
if ($assignmentPage > $assignmentTotalPages) {
    $assignmentPage = $assignmentTotalPages;
}
$assignmentOffset = ($assignmentPage - 1) * $assignmentPerPage;
$pagedAssignments = array_slice($filteredAssignments, $assignmentOffset, $assignmentPerPage);

function assignmentPageUrl(int $page, int $filterDoctor, int $filterCaregiver, string $filterKeyword): string
{
    $params = [];
    if ($filterDoctor > 0) $params['doctor_id'] = $filterDoctor;
    if ($filterCaregiver > 0) $params['caregiver_id'] = $filterCaregiver;
    if ($filterKeyword !== '') $params['q'] = $filterKeyword;
    if ($page > 1) $params['page'] = $page;
    return 'assign_patient.php' . ($params ? '?' . http_build_query($params) : '');
}

function assignmentPaginationItems(int $current, int $total): array
{
    if ($total <= 7) return range(1, $total);
    $items = [1];
    $start = max(2, $current - 1);
    $end = min($total - 1, $current + 1);
    if ($start > 2) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total - 1) $items[] = '...';
    $items[] = $total;
    return $items;
}

$totalAssignments = count($assignments);
$currentDoctorAssignments = 0;
$assignedCaregiverIds = [];
$assignedPatientIds = [];

foreach ($assignments as $row) {
    if ((int)$row['doctor_user_id'] === $currentDoctorId) {
        $currentDoctorAssignments++;
    }

    $assignedCaregiverIds[(int)$row['caregiver_user_id']] = true;
    $assignedPatientIds[(int)$row['patient_id']] = true;
}

$totalActiveCaregivers = count($assignedCaregiverIds);

/* ภาพรวมสำหรับข้อมูลจำนวนมาก */
$totalPatients = count($patients);
$totalCaregivers = count($caregivers);
$unassignedPatients = max(0, $totalPatients - $totalAssignments);
$assignmentPercent = $totalPatients > 0
    ? min(100, round(($totalAssignments / $totalPatients) * 100))
    : 0;

function assignmentPersonLabel(array $row, string $codeKey, string $nameKey, string $fallback): string
{
    $code = trim((string)($row[$codeKey] ?? ''));
    $name = trim((string)($row[$nameKey] ?? ''));

    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }
    if ($code !== '') {
        return $code;
    }
    if ($name !== '') {
        return $name;
    }
    return $fallback;
}

function assignmentThaiDate(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    if (!$ts) return '-';
    return date('d/m/Y', $ts);
}

function assignmentThaiDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $timestamp = strtotime($value);
    if (!$timestamp) {
        return (string)$value;
    }

    $year = (int)date('Y', $timestamp) + 543;
    return date('d/m/', $timestamp) . $year . date(' H:i', $timestamp) . ' น.';
}

function assignmentAdlGroup(?int $score): string
{
    if ($score === null) return '-';
    if ($score >= 12) return 'กลุ่มติดสังคม';
    if ($score >= 5) return 'กลุ่มติดบ้าน';
    return 'กลุ่มติดเตียง';
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>มอบหมายผู้สูงอายุให้แคร์กิฟเวอร์ดูแล | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
        .assignment-form-card{margin-bottom:18px;border:1px solid rgba(45,113,88,.16);box-shadow:0 10px 28px rgba(48,92,72,.07);padding:22px}
        .assignment-form-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}
        .assignment-form-head h2{margin:0 0 5px;color:#243c33;font-size:20px}
        .assignment-form-head p{margin:0;color:#6f8078;font-size:13px}
        .doctor-badge{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#eef9f5;border:1px solid #d0ebe0;border-radius:13px;padding:10px 13px;color:#315f4d;font-size:12px}
        .doctor-badge-label{color:#7a8e85}
        .doctor-badge strong{display:inline-flex;padding:4px 9px;border-radius:999px;background:#dff3ea;color:#225c46}
        .assignment-select-grid{display:grid;grid-template-columns:minmax(0,1fr) 42px minmax(0,1fr) minmax(175px,.55fr) auto;gap:12px;align-items:end}
        .patient-search-field{align-self:stretch}
        .patient-search-wrap{position:relative;margin-bottom:0}
        .patient-search-input{width:100%;height:44px;border:1px solid #cfe4dc;border-radius:12px;background:#fff;padding:0 42px 0 40px;font:inherit;color:#243c33;outline:none;transition:border-color .18s ease,box-shadow .18s ease}
        .patient-search-input:focus{border-color:#63c5c9;box-shadow:0 0 0 3px rgba(99,197,201,.16)}
        .patient-search-input::placeholder{color:#94a49d}
        .patient-search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#5caeaa;font-size:20px;line-height:1;pointer-events:none}
        .patient-search-clear{display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);width:28px;height:28px;border:0;border-radius:50%;background:#edf7f4;color:#54756a;font-size:18px;line-height:1;cursor:pointer}
        .patient-search-clear.is-visible{display:block}
        .patient-search-clear:hover{background:#dff1ec}
        .patient-search-empty{margin-top:7px;color:#a06d44;font-size:12px}
        .assign-arrow{height:44px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#65a995}
        .assignment-submit{display:flex;align-items:flex-end}
        .assignment-main-btn{min-height:44px;white-space:nowrap;padding-left:20px;padding-right:20px}
        .required-mark{color:#b74b4b}.field-note{margin-top:6px;color:#72827d;font-size:11px;line-height:1.45}.visit-date-inline-form{display:flex;align-items:center;gap:6px;min-width:220px}.visit-date-inline-form input[type=date]{min-width:135px;padding:7px 8px;border:1px solid #cfe2dc;border-radius:9px;background:#fff;font:inherit;font-size:12px}.visit-date-save-btn{border:0;border-radius:9px;background:#59bfc1;color:#fff;padding:8px 10px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}.visit-date-save-btn:hover{background:#49aaad}
        .assignment-help{margin-top:12px;padding:10px 12px;border-radius:11px;background:#fff9e8;border:1px solid #f1e4b8;color:#766530;font-size:12px}
        .assignment-overview{margin:0 0 18px;background:#fff;border:1px solid rgba(45,113,88,.14);border-radius:18px;padding:18px;box-shadow:0 8px 24px rgba(48,92,72,.05)}
        .assignment-overview-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}
        .assignment-overview-title{margin:0;color:#243c33;font-size:18px}
        .assignment-overview-subtitle{margin-top:4px;color:#7a8983;font-size:12px}
        .assignment-doctor-summary{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;background:#eef8f4;border:1px solid #d5ebe2;color:#315f4d;font-size:12px}
        .assignment-doctor-summary strong{font-size:14px}
        .assignment-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .assignment-stat{background:#fbfefd;border:1px solid #dcebe5;border-radius:14px;padding:13px 15px;min-height:92px}
        .assignment-stat .stat-label{font-size:12px;color:#62766e;margin-bottom:6px}
        .assignment-stat .stat-value{font-size:26px;font-weight:800;line-height:1;color:#243c33}
        .assignment-stat .stat-note{font-size:11px;color:#85918d;margin-top:7px;line-height:1.45}
        .assignment-stat.unassigned{background:#fffdf5;border-color:#eee2b8}
        .assignment-stat.caregiver{background:#f7fcfa}
        .assignment-progress{margin-top:14px;padding-top:14px;border-top:1px solid #e5efeb}
        .assignment-progress-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;font-size:12px;color:#5f746b}
        .assignment-progress-top strong{font-size:14px;color:#245f48}
        .assignment-progress-track{height:9px;border-radius:999px;background:#edf2f0;overflow:hidden}
        .assignment-progress-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,#62c8b6,#26977f);transition:width .2s ease}
        .check-flow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-weight:700}
        .role-chip{display:inline-flex;align-items:center;justify-content:center;min-width:42px;padding:5px 9px;border-radius:999px;background:#e8f6ef;border:1px solid #cbe9da;color:#245f48;font-size:12px;font-weight:800}
        .role-chip.caregiver{background:#fff6d9;border-color:#f1e1a9;color:#725b16}
        .flow-arrow{color:#94a39d;font-weight:700}
        .person-name{margin-top:4px;color:#354b42;font-size:13px}
        .muted{color:#7b8a84;font-size:12px}
        .status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;border:1px solid #cbe9da;background:#e8f6ef;color:#245f48}
        .status-badge::before{content:'';width:7px;height:7px;border-radius:50%;background:#5ba47f;display:block}
        .status-badge.paused{background:#fff8e7;border-color:#ead7a4;color:#7b6221}.status-badge.paused::before{background:#d1a83a}
        .status-badge.ended{background:#f4f5f5;border-color:#d9dfdc;color:#68736e}.status-badge.ended::before{background:#9aa39f}
        .status-cell{min-width:190px}
        .status-panel{display:flex;flex-direction:column;align-items:flex-start;gap:9px;padding:12px 13px;border:1px solid #d9e8e3;border-radius:14px;background:#fbfefd}
        .status-panel.is-paused{background:#fffdf7;border-color:#eadfb9}
        .status-panel.is-ended{background:#fafbfb;border-color:#dde4e1}
        .status-reason-box{width:100%;padding:8px 10px;border-radius:10px;background:#fff;border:1px solid #e5eeeb;color:#455b54;font-size:12px;line-height:1.55;box-sizing:border-box}
        .status-reason-label{display:block;margin-bottom:2px;color:#73817c;font-size:10px;font-weight:700;letter-spacing:.02em}
        .status-reason-value{display:block;color:#314a42;font-weight:700;word-break:break-word}
        .status-note-box{width:100%;padding:8px 10px;border-radius:10px;background:#f7faf9;border-left:3px solid #a9cfc5;color:#51635d;font-size:12px;line-height:1.55;box-sizing:border-box}
        .status-edit-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 13px;border-radius:10px;border:1px solid #bcdcd4;background:#fff;color:#285d53;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:.18s ease;box-shadow:0 2px 8px rgba(41,86,73,.05)}
        .status-edit-btn:hover{background:#eef8f5;border-color:#9dcfc3;transform:translateY(-1px)}
        .filter-card{padding-bottom:14px}
        .filter-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:12px;align-items:end}
        .action-toggle{margin:0 0 18px;background:#fff;border:1px solid rgba(45,113,88,.14);border-radius:18px;overflow:hidden;box-shadow:0 8px 24px rgba(48,92,72,.05)}
        .action-toggle summary{cursor:pointer;list-style:none;padding:17px 20px;font-weight:800;color:#294b3e;background:#f7fbf8}
        .action-toggle summary::-webkit-details-marker{display:none}
        .action-toggle summary::after{content:'+';float:right;font-size:22px;line-height:18px;color:#668478}
        .action-toggle[open] summary::after{content:'−'}
        .action-toggle .action-body{padding:18px 20px 20px;border-top:1px solid rgba(45,113,88,.10)}
        .table-assignment td{vertical-align:middle}
        .assignment-empty{padding:34px 16px!important;text-align:center;color:#7b8a84}
        .btn-soft{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 15px;border-radius:11px;border:1px solid #d8e6df;background:#fff;color:#355b4b;text-decoration:none;font-weight:700;cursor:pointer}
        .btn-soft:hover{background:#f5faf7}
        .current-doctor-note{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;background:#edf8f2;color:#315f4d;font-size:12px;margin-top:8px}
        .page-head.assignment-page-head{display:flex;align-items:center;justify-content:space-between;gap:20px}
        .page-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .assignment-list-link{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:9px 16px;border-radius:12px;background:#fff;border:1px solid #bfe2d8;color:#285d4c;text-decoration:none;font-weight:800;box-shadow:0 5px 16px rgba(48,92,72,.06)}
        .assignment-list-link:hover{background:#eef9f5}
        .assignment-list-link .count{display:inline-flex;align-items:center;justify-content:center;min-width:25px;height:25px;padding:0 7px;border-radius:999px;background:#dff3ea;color:#245f48;font-size:12px}
        #assignment-list{scroll-margin-top:18px}
        .assignment-list-card{margin-bottom:18px}
        .assignment-list-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
        .assignment-list-head h2{margin:0 0 4px}
        .assignment-search-panel{padding:16px;border-radius:14px;background:#f8fbfa;border:1px solid rgba(45,113,88,.10);margin-bottom:16px}
        .assignment-top-action{display:flex;justify-content:flex-end;align-items:center;margin:0 0 16px}
        .assignment-top-action-clean{padding-top:2px}
        .assignment-add-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:10px 18px;border:0;border-radius:12px;background:#61c2c6;color:#fff;font:inherit;font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(56,161,166,.18)}
        .assignment-add-btn:hover{filter:brightness(.98)}
        .assignment-modal{display:none;position:fixed;inset:0;z-index:5000;background:rgba(24,45,38,.34);padding:24px;align-items:center;justify-content:center}
        .assignment-modal.is-open{display:flex}
        .assignment-modal-dialog{width:min(1180px,100%);max-height:calc(100vh - 48px);overflow:auto;position:relative}
        .assignment-modal-close{position:absolute;right:14px;top:12px;z-index:2;width:38px;height:38px;border:1px solid #d6e7df;border-radius:50%;background:#fff;color:#416457;font-size:24px;line-height:1;cursor:pointer}
        .adl-score-badge{display:inline-flex;align-items:center;justify-content:center;min-width:54px;padding:6px 10px;border-radius:999px;background:#edf8f5;border:1px solid #cfe8e0;color:#235f50;font-weight:800;white-space:nowrap}
        .adl-score-badge.caregiver{background:#eef7fb;border-color:#d4e7ef;color:#295d70}
        .adl-group-text{margin-top:5px;font-size:11px;color:#6c8078;line-height:1.4}
        .adl-result-status{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:#fff8e7;border:1px solid #efdfaa;color:#7d6420;font-size:11px;font-weight:800;white-space:nowrap}
        .adl-result-status.returned{background:#eaf7f1;border-color:#cde9dc;color:#245f48}
        .adl-subinfo{margin-top:5px;font-size:11px;color:#75877f;line-height:1.45}
        .adl-edit-link{display:inline-flex;align-items:center;justify-content:center;width:92px;min-width:92px;height:38px;min-height:38px;padding:0 12px;border-radius:10px;border:1px solid #cfe3dc;background:#fff;color:#315d50;text-decoration:none;font-size:12px;font-weight:800;margin:0;box-sizing:border-box;white-space:nowrap}
        .assignment-cancel-form{margin:0;display:inline-flex;vertical-align:middle;width:92px;min-width:92px}
        .assignment-cancel-btn{display:inline-flex;align-items:center;justify-content:center;width:92px;min-width:92px;height:38px;min-height:38px;padding:0 12px;border-radius:10px;border:1px solid #efcaca;background:#fff5f5;color:#a34f4f;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:.18s ease;white-space:nowrap;box-sizing:border-box}
        .assignment-cancel-btn:hover{background:#ffe9e9;border-color:#e8b3b3;transform:translateY(-1px)}
        .assignment-actions{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;min-width:192px}
        .assignment-modal .assignment-form-card{margin:0;padding-top:24px}
        .status-edit-form{display:grid;gap:8px;min-width:230px}
        .status-edit-form select,.status-edit-form input{width:100%;box-sizing:border-box;min-height:36px;padding:7px 10px;border:1px solid #d4e6df;border-radius:9px;background:#fff;color:#2d5145;font:inherit;font-size:12px}
        .status-reason-wrap{display:none;gap:6px}
        .status-reason-wrap.is-visible{display:grid}
        .status-note-label{font-size:11px;font-weight:700;color:#647a71}
        .status-save-btn{min-height:42px;border:0;border-radius:11px;padding:9px 18px;background:#62c2c6;color:#fff;font:inherit;font-size:13px;font-weight:800;cursor:pointer}
        .status-edit-btn{margin-top:9px;min-height:34px;border:1px solid #cfe4dd;border-radius:9px;padding:7px 12px;background:#fff;color:#315f53;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
        .status-edit-btn:hover{background:#eef9f5;border-color:#a9d6ca}
        .status-modal{display:none;position:fixed;inset:0;z-index:7000;background:rgba(22,48,42,.34);padding:20px;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
        .status-modal.is-open{display:flex}
        .status-modal-card{width:min(520px,100%);background:#fff;border:1px solid #cfe4dd;border-radius:20px;box-shadow:0 24px 60px rgba(31,73,63,.22);padding:24px;position:relative}
        .status-modal-head{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px}
        .status-modal-head h3{margin:0;color:#213f37;font-size:20px}
        .status-modal-close{width:38px;height:38px;border:1px solid #d5e6e0;border-radius:50%;background:#f8fcfb;color:#41675e;font-size:22px;cursor:pointer}
        .status-modal .status-field{display:grid;gap:7px;margin-bottom:14px}
        .status-modal .status-field label{font-weight:800;color:#294b42;font-size:13px}
        .status-modal .status-field select,.status-modal .status-field input{width:100%;box-sizing:border-box;min-height:44px;border:1px solid #cfe3de;border-radius:11px;padding:8px 12px;background:#fff;font:inherit;color:#254640}
        .status-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
        .status-cancel-btn{min-height:42px;border:1px solid #d8e6e1;border-radius:11px;padding:9px 16px;background:#fff;color:#4d6b62;font:inherit;font-weight:800;cursor:pointer}
        .status-detail{margin-top:7px;font-size:11px;line-height:1.5;color:#697c75}
        .status-detail strong{color:#3c5e54}
        @media(max-width:900px){.page-head.assignment-page-head{align-items:flex-start;flex-direction:column}.page-head-actions{width:100%}.assignment-list-link{width:100%}.assignment-form-head{flex-direction:column}.assignment-select-grid{grid-template-columns:1fr}.assign-arrow{height:auto;transform:rotate(90deg)}.assignment-submit{display:block}.assignment-main-btn{width:100%}.assignment-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:1fr 1fr}.filter-grid .filter-keyword{grid-column:1/-1}.filter-grid .filter-action{grid-column:1/-1}.assignment-modal{padding:12px}.assignment-top-action{margin-bottom:12px}}
        @media(max-width:620px){.filter-grid{grid-template-columns:1fr}.filter-grid .filter-keyword,.filter-grid .filter-action{grid-column:auto}.assignment-summary{grid-template-columns:1fr}.assignment-stat .stat-value{font-size:24px}.assignment-overview{padding:14px}}
    
/* ===== Assignment page premium refresh v4 ===== */
.assignment-hero-shell{display:grid;gap:18px;margin:4px 0 20px}
.assignment-hero-card{
    display:grid;grid-template-columns:minmax(0,1.35fr) auto;gap:22px;align-items:center;
    padding:26px 28px;border-radius:28px;border:1px solid #d6ece9;
    background:linear-gradient(135deg,#eef9fb 0%,#f6fcfc 48%,#eaf8f5 100%);
    box-shadow:0 20px 48px rgba(36,108,115,.08);overflow:hidden;position:relative
}
.assignment-hero-card:before{content:'';position:absolute;right:-60px;top:-50px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle at center,rgba(88,191,192,.16) 0%,rgba(88,191,192,0) 68%)}
.assignment-hero-copy,.assignment-hero-actions{position:relative;z-index:1}
.assignment-hero-kicker,.assignment-section-kicker{display:inline-block;font-size:12px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#6b8b86;margin-bottom:10px}
.assignment-hero-copy h1{margin:0 0 8px;color:#173f45;font-size:34px;line-height:1.12;letter-spacing:-.4px}
.assignment-hero-copy p{margin:0;color:#68817d;font-size:15px;line-height:1.65;max-width:760px}
.assignment-hero-tags{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.hero-tag{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:12px;font-weight:800;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.hero-tag.success{background:#eef9f4;color:#24624e;border-color:#d3eadf}
.assignment-hero-actions{display:grid;gap:12px;justify-items:end}
.assignment-hero-coverage{min-width:220px;padding:18px 18px 16px;border-radius:22px;border:1px solid #d6ebe7;background:rgba(255,255,255,.88);box-shadow:0 10px 24px rgba(36,108,115,.06);text-align:left}
.coverage-label{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#75908c}
.coverage-value{margin-top:6px;font-size:38px;line-height:1;font-weight:900;color:#1c5960}
.coverage-sub{margin-top:7px;font-size:13px;color:#6b8580;line-height:1.45}
.assignment-add-btn{min-height:50px!important;padding:12px 22px!important;border-radius:16px!important;background:linear-gradient(135deg,#59bfc0 0%,#44a9aa 100%)!important;box-shadow:0 16px 30px rgba(88,191,192,.24)!important}
.assignment-add-btn:hover{transform:translateY(-1px);filter:none!important;background:linear-gradient(135deg,#4eb5b6 0%,#3f9ea0 100%)!important}
.assignment-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.assignment-kpi-card{position:relative;padding:18px 18px 16px;border-radius:22px;background:#fff;border:1px solid #d8ece8;box-shadow:0 12px 28px rgba(36,108,115,.06);overflow:hidden}
.assignment-kpi-card:before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#58bfc0,#9adfdc)}
.assignment-kpi-card.active:before{background:linear-gradient(90deg,#4bb9a7,#7ad4be)}
.assignment-kpi-card.waiting:before{background:linear-gradient(90deg,#f0b25e,#f6d39d)}
.assignment-kpi-card.info:before{background:linear-gradient(90deg,#7db9df,#9fd4ed)}
.kpi-label{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#77908b}
.kpi-value{margin-top:8px;font-size:34px;line-height:1;font-weight:900;color:#1a565c}
.kpi-sub{margin-top:8px;font-size:13px;color:#6f8782;line-height:1.45}
.filter-card,.assignment-table-card{border:1px solid #d8ece8!important;border-radius:26px!important;box-shadow:0 16px 38px rgba(36,108,115,.07)!important;padding:22px 22px 20px!important}
.assignment-filter-head,.assignment-list-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.assignment-filter-head h2,.assignment-list-heading h2{margin:2px 0 6px;color:#183f45;font-size:22px;letter-spacing:-.2px}
.assignment-filter-head p{margin:0;color:#738a86;font-size:14px;line-height:1.55;max-width:720px}
.assignment-filter-chip,.list-mini-chip{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 14px;border-radius:999px;background:#f4fbfb;border:1px solid #d9ece9;color:#285d60;font-size:12px;font-weight:900;white-space:nowrap}
.assignment-list-mini-stats{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.list-mini-chip.success{background:#edf8f4;border-color:#d5eadf;color:#23614d}
.filter-grid{gap:14px!important;padding-top:4px}
.filter-grid .field label{font-size:13px;font-weight:800;color:#23474a;margin-bottom:8px}
.filter-grid input,.filter-grid select{min-height:46px!important;border-radius:14px!important;border:1px solid #d6e8e5!important;background:#fff!important;box-shadow:0 6px 16px rgba(36,108,115,.04)!important}
.filter-grid .btn{min-height:46px!important;padding:10px 20px!important;border-radius:14px!important;font-weight:900!important}
.filter-grid .btn-primary{background:linear-gradient(135deg,#59bfc0 0%,#4aaeb0 100%)!important;color:#fff!important;border-color:transparent!important;box-shadow:0 12px 22px rgba(88,191,192,.18)!important}
.filter-grid .btn-primary:hover{background:linear-gradient(135deg,#4db5b6 0%,#419fa1 100%)!important}
.assignment-table-card .table-wrap{border:1px solid #dcecea!important;border-radius:20px!important;overflow:auto!important;background:#fff!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.7)}
.table-assignment{min-width:1720px!important}
.table-assignment thead th{position:sticky;top:0;z-index:2;background:linear-gradient(180deg,#ecf7f8 0%,#e4f2f4 100%)!important;color:#204c50!important;font-size:13px!important;font-weight:900!important;border-bottom:1px solid #d7e8e8!important}
.table-assignment tbody tr{transition:background-color .16s ease, transform .16s ease}
.table-assignment tbody tr:nth-child(even){background:#fbfefe}
.table-assignment tbody tr:hover{background:#f5fbfb}
.table-assignment td{padding:14px 14px!important;border-bottom:1px solid #edf3f2!important;vertical-align:top!important;color:#244846!important}
.person-name{margin-top:6px;font-size:13px;font-weight:700;color:#355b58;line-height:1.45}
.role-chip{padding:7px 12px!important;border-radius:999px!important;font-size:12px!important;font-weight:900!important;border:1px solid #cde6da!important;background:linear-gradient(180deg,#eef9f2 0%,#e5f5ec 100%)!important;color:#245f48!important;box-shadow:0 4px 12px rgba(36,95,72,.06)}
.role-chip.caregiver{background:linear-gradient(180deg,#fff8e7 0%,#fef3cf 100%)!important;border-color:#f0dfaa!important;color:#8a661c!important}
.muted{color:#718580!important;line-height:1.5!important}
.visit-date-inline-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.visit-date-inline-form input[type="date"]{min-height:38px;border-radius:12px;border:1px solid #d6e7e4;padding:7px 10px;box-shadow:0 4px 12px rgba(36,108,115,.04)}
.visit-date-save-btn{min-height:38px;padding:8px 12px;border-radius:12px;border:0;background:linear-gradient(135deg,#59bfc0 0%,#49aeb0 100%);color:#fff;font:inherit;font-size:12px;font-weight:900;cursor:pointer;box-shadow:0 8px 16px rgba(88,191,192,.16)}
.adl-score-badge{min-width:72px!important;padding:8px 12px!important;border-radius:999px!important;background:linear-gradient(135deg,#edf8f5 0%,#f8fdfb 100%)!important;color:#1f6051!important;border:1px solid #d1e7df!important;box-shadow:0 6px 12px rgba(36,108,115,.05)!important}
.adl-score-badge.caregiver{background:linear-gradient(135deg,#eef7fb 0%,#f9fcff 100%)!important;border-color:#d6e8ef!important;color:#2b6072!important}
.adl-group-text{font-size:12px!important;color:#6f8680!important;margin-top:7px!important}
.adl-result-status{padding:7px 10px!important;border-radius:999px!important;font-size:12px!important;font-weight:900!important}
.adl-subinfo{margin-top:7px!important;font-size:12px!important;color:#748781!important}
.status-edit-btn{min-height:38px!important;padding:8px 12px!important;border-radius:12px!important;font-size:12px!important;font-weight:900!important;box-shadow:0 6px 14px rgba(36,108,115,.05)}
.adl-edit-link{width:92px!important;min-width:92px!important;height:38px!important;min-height:38px!important;padding:0 12px!important;border-radius:10px!important;font-size:12px!important;font-weight:900!important;box-sizing:border-box!important;margin:0!important;box-shadow:0 6px 14px rgba(36,108,115,.05)}
.assignment-cancel-form{width:92px!important;min-width:92px!important}
.assignment-cancel-btn{width:92px!important;min-width:92px!important;height:38px!important;min-height:38px!important;padding:0 12px!important;border-radius:10px!important;box-sizing:border-box!important}
.status-edit-btn:hover,.adl-edit-link:hover{transform:translateY(-1px)}
.assignment-empty{padding:42px 16px!important;font-size:14px!important;color:#748782!important;background:#fbfefe}
.assignment-modal-close,.status-modal-close{box-shadow:0 8px 18px rgba(36,108,115,.08)}
.assignment-modal .assignment-form-card{border-radius:26px!important;box-shadow:0 24px 60px rgba(24,58,61,.16)!important}
.assignment-form-card{padding:26px!important}
.assignment-form-head h2{font-size:24px!important;letter-spacing:-.2px}
.doctor-badge{border-radius:16px!important;background:linear-gradient(135deg,#eef9f5 0%,#f5fcf9 100%)!important;box-shadow:0 8px 18px rgba(36,108,115,.05)}
.assignment-select-grid .field label{font-size:13px!important;font-weight:800!important;color:#26484c!important}
.assignment-select-grid input,.assignment-select-grid select{min-height:46px!important;border-radius:14px!important;border:1px solid #d6e8e5!important;box-shadow:0 6px 16px rgba(36,108,115,.04)!important}
.assignment-main-btn{border-radius:14px!important;box-shadow:0 12px 22px rgba(88,191,192,.18)!important}
@media(max-width:1100px){
  .assignment-hero-card{grid-template-columns:1fr}
  .assignment-hero-actions{justify-items:start}
  .assignment-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:900px){
  .assignment-hero-card{padding:22px 20px;border-radius:24px}
  .assignment-hero-copy h1{font-size:28px}
  .assignment-kpi-grid{grid-template-columns:1fr 1fr}
  .filter-card,.assignment-table-card{padding:18px!important;border-radius:22px!important}
}
@media(max-width:640px){
  .assignment-hero-copy h1{font-size:24px}
  .assignment-kpi-grid{grid-template-columns:1fr}
  .assignment-hero-tags{gap:8px}
  .hero-tag,.assignment-filter-chip,.list-mini-chip{width:100%;justify-content:center}
}

    

/* ===== Assignment modal premium refinement ===== */
.assignment-modal{background:rgba(26,61,64,.32)!important;backdrop-filter:blur(5px)}
.assignment-modal-dialog{width:min(1180px,calc(100vw - 36px))!important;max-height:calc(100vh - 36px)!important;overflow:auto!important;border-radius:28px!important;box-shadow:0 28px 70px rgba(31,79,84,.22)!important}
.assignment-modal .assignment-form-card{margin:0!important;padding:0!important;border:1px solid #d5e9e6!important;border-radius:28px!important;overflow:hidden!important;background:#fff!important;box-shadow:none!important}
.assignment-modal-close{width:40px!important;height:40px!important;right:16px!important;top:16px!important;border-radius:14px!important;border:1px solid #cfe6e2!important;background:#fff!important;color:#356a6d!important;box-shadow:0 8px 18px rgba(49,105,109,.10)!important;font-size:24px!important;z-index:5!important}
.assignment-form-head{position:relative;display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:24px!important;align-items:center!important;margin:0!important;padding:26px 74px 24px 28px!important;background:linear-gradient(135deg,#e7f7f4 0%,#f4fbfa 52%,#eaf5fa 100%)!important;border-bottom:1px solid #d9ece9!important}
.assignment-form-head:after{content:'';position:absolute;left:28px;bottom:0;width:74px;height:4px;border-radius:999px;background:linear-gradient(90deg,#58bfc0,#88d7d2)}
.assignment-form-head h2{margin:0 0 7px!important;font-size:25px!important;line-height:1.2!important;color:#1f555b!important;letter-spacing:-.2px}
.assignment-form-head p{margin:0!important;color:#708987!important;font-size:13px!important;line-height:1.6!important}
.assignment-form-kicker{display:inline-block;margin-bottom:8px;font-size:11px;font-weight:900;letter-spacing:.13em;text-transform:uppercase;color:#6e9290}
.doctor-badge{display:grid!important;gap:5px!important;min-width:210px!important;padding:13px 15px!important;border-radius:16px!important;background:rgba(255,255,255,.86)!important;border:1px solid #d4e9e6!important;color:#355f60!important;box-shadow:0 8px 20px rgba(54,116,118,.06)!important}
.doctor-badge-label{font-size:10px!important;text-transform:uppercase!important;letter-spacing:.08em!important;color:#809795!important;font-weight:800!important}
.doctor-badge strong{display:inline-flex!important;width:max-content!important;padding:5px 10px!important;border-radius:999px!important;background:#e1f4ef!important;color:#235f58!important;font-size:12px!important}
.assignment-form{padding:24px 28px 26px!important}
.assignment-select-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:10px!important;align-items:end!important}
.assignment-select-grid>.field:nth-of-type(1),.assignment-select-grid>.field:nth-of-type(2),.assignment-select-grid>.field:nth-of-type(3){grid-column:auto!important;min-width:0!important}
.assignment-select-grid>.assignment-submit{grid-column:1/-1!important;display:flex!important;justify-content:flex-end!important;align-self:end!important;margin-top:2px!important}
.assignment-select-grid>.assignment-submit .assignment-main-btn{width:auto!important;min-width:210px!important}
.assignment-select-grid .field label{display:flex!important;align-items:center!important;gap:7px!important;margin-bottom:8px!important;font-size:13px!important;font-weight:800!important;color:#285053!important}
.assignment-select-grid .field label:before{content:'';width:7px;height:7px;border-radius:50%;background:#69c5c2;box-shadow:0 0 0 4px rgba(105,197,194,.10)}
.patient-search-input,.assignment-select-grid select,.assignment-select-grid input[type=date]{height:48px!important;border:1px solid #d2e7e4!important;border-radius:14px!important;background:#fff!important;color:#284b4e!important;box-shadow:0 6px 16px rgba(49,105,109,.04)!important;transition:.18s ease!important}
.patient-search-input{padding-left:43px!important;padding-right:42px!important}
.assignment-select-grid select,.assignment-select-grid input[type=date]{padding:0 13px!important;font:inherit!important}
.patient-search-input:focus,.assignment-select-grid select:focus,.assignment-select-grid input[type=date]:focus{border-color:#6bc7c6!important;box-shadow:0 0 0 4px rgba(107,199,198,.13)!important;outline:none!important}
.patient-search-icon{left:15px!important;color:#55afb0!important;font-size:19px!important}
.assign-arrow{display:none!important}
.field-note{margin-top:7px!important;color:#839795!important;font-size:11px!important}
.assignment-main-btn{width:100%!important;min-height:48px!important;padding:11px 20px!important;border-radius:14px!important;border:1px solid #56b7ba!important;background:linear-gradient(135deg,#6ccbc7 0%,#51afb4 100%)!important;color:#fff!important;font-weight:900!important;box-shadow:0 12px 24px rgba(81,175,180,.20)!important}
.assignment-main-btn:hover{transform:translateY(-1px)!important;background:linear-gradient(135deg,#60c2be 0%,#489fa6 100%)!important}
.assignment-help{display:flex!important;align-items:flex-start!important;gap:10px!important;margin-top:18px!important;padding:13px 14px!important;border-radius:14px!important;background:#f7fbfb!important;border:1px solid #dcecea!important;color:#667f7c!important;line-height:1.6!important}
.assignment-help:before{content:'i';flex:0 0 22px;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#dff3f0;color:#2e7775;font-weight:900;font-size:12px}
.assignment-flow-note{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:2px}
.assignment-flow-step{padding:11px 12px;border-radius:13px;background:#fbfefe;border:1px solid #deecea;color:#5f7774;font-size:11px;line-height:1.45}
.assignment-flow-step strong{display:block;margin-bottom:3px;color:#2c5a5c;font-size:12px}
@media(max-width:900px){.assignment-form-head{grid-template-columns:1fr!important;padding:22px 58px 20px 20px!important}.doctor-badge{min-width:0!important}.assignment-form{padding:20px!important}.assignment-select-grid{grid-template-columns:1fr!important;gap:12px!important}.assign-arrow{display:none!important}.assignment-select-grid>.field,.assignment-select-grid>.assignment-submit{grid-column:auto!important}.assignment-select-grid>.assignment-submit{justify-content:stretch!important}.assignment-select-grid>.assignment-submit .assignment-main-btn{width:100%!important;min-width:0!important}.assignment-flow-note{grid-template-columns:1fr!important}}


/* ===== Assignment equal 3-column refinement ===== */
.assignment-modal-dialog{width:min(1020px,calc(100vw - 36px))!important;}
.assignment-form{padding:22px 24px 24px!important;}
.assignment-select-grid{
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    column-gap:8px!important;
    row-gap:8px!important;
    align-items:start!important;
}
.assignment-select-grid>.field{margin:0!important;min-width:0!important;width:100%!important;}
.assignment-select-grid .patient-search-wrap,
.assignment-select-grid select,
.assignment-select-grid input[type=date]{width:100%!important;min-width:0!important;}
.assignment-select-grid .field label{margin-bottom:6px!important;min-height:38px!important;align-items:flex-end!important;}
.assignment-select-grid>.assignment-submit{margin-top:4px!important;}
.assignment-flow-note{gap:8px!important;}
@media(max-width:900px){
    .assignment-modal-dialog{width:min(720px,calc(100vw - 24px))!important;}
    .assignment-select-grid{grid-template-columns:1fr!important;row-gap:10px!important;}
    .assignment-select-grid .field label{min-height:0!important;}
}

/* FINAL: assignment action centered, steps removed */
.assignment-select-grid > .assignment-submit{
    grid-column:1 / -1!important;
    display:flex!important;
    justify-content:center!important;
    align-items:center!important;
    margin-top:16px!important;
    width:100%!important;
}
.assignment-select-grid > .assignment-submit .assignment-main-btn{
    width:320px!important;
    max-width:100%!important;
    min-width:0!important;
    margin:0 auto!important;
}
.assignment-flow-note{display:none!important;}
@media(max-width:900px){
    .assignment-select-grid > .assignment-submit{justify-content:center!important;}
    .assignment-select-grid > .assignment-submit .assignment-main-btn{width:100%!important;max-width:360px!important;}
}

/* ===== Assignment cards: compact, clean and easier to scan ===== */
.assignment-list-card.assignment-table-card{background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important;overflow:visible!important}
.assignment-table-card .table-wrap{border:0!important;background:transparent!important;box-shadow:none!important;border-radius:0!important;overflow:visible!important}
.assignment-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:stretch}
.assignment-card-item{position:relative;display:grid;gap:13px;padding:18px;border:1px solid #d8ebe8;border-radius:22px;background:linear-gradient(180deg,#ffffff 0%,#fbfefe 100%);box-shadow:0 10px 26px rgba(36,108,115,.055);min-width:0}
.assignment-card-item:hover{border-color:#c6e3df;box-shadow:0 16px 34px rgba(36,108,115,.09);transform:translateY(-1px)}
.assignment-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
.assignment-elderly{min-width:0}.assignment-elderly-name{margin:0;color:#183f45;font-size:19px;font-weight:900;line-height:1.35}.assignment-elderly-meta{margin-top:5px;color:#718986;font-size:12px;line-height:1.55}.assignment-doctor-line{margin-top:8px;color:#5f7773;font-size:12px;line-height:1.55}
.assignment-status-pill{display:inline-flex;align-items:center;justify-content:center;min-height:0;padding:0;border-radius:0;background:transparent!important;border:0!important;outline:0!important;box-shadow:none!important;color:#24604f;font-size:13px;font-weight:900;white-space:nowrap;margin:0 auto}
.assignment-status-pill.ended{background:transparent!important;border:0!important;outline:0!important;color:#a15252}
.assignment-card-caregiver{display:grid;grid-template-columns:44px minmax(0,1fr);gap:11px;align-items:center;padding:12px 13px;border:1px solid #e1efed;border-radius:16px;background:#f8fcfb}
.assignment-card-caregiver .caregiver-avatar-mini{width:44px;height:44px;border-radius:14px;margin:0}.assignment-card-caregiver .caregiver-profile-name{font-size:14px;line-height:1.35}.assignment-card-caregiver .caregiver-profile-meta{margin-top:5px}
.assignment-card-info{display:grid;grid-template-columns:1fr;gap:10px}
.assignment-info-box{padding:12px 14px;border:1px solid #e1efed;border-radius:15px;background:#fff;min-width:0}.assignment-info-label{display:block;color:#78908b;font-size:10px;font-weight:700;margin-bottom:5px}.assignment-info-value{display:block;color:#254e52;font-size:13px;font-weight:900;line-height:1.5;overflow-wrap:anywhere}.assignment-info-note{display:block;margin-top:4px;color:#7e928e;font-size:10px;line-height:1.45}
.assignment-card-actions{display:flex;gap:9px;flex-wrap:wrap;padding-top:1px}.assignment-card-actions .table-secondary-btn,.assignment-card-actions .status-edit-btn{flex:1 1 180px;min-height:42px;border-radius:13px!important;margin:0!important}.assignment-card-actions .table-secondary-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.assignment-card-actions .compact-ended-label{flex:1 1 180px;display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:0;background:transparent!important;border:0!important;color:#758884;font-weight:800}
.assignment-card-empty{grid-column:1/-1;padding:44px 18px;border:1px dashed #d7e7e4;border-radius:22px;background:#fbfefe;text-align:center;color:#7a8e8a;font-size:14px}
.assignment-card-visit-form{display:flex;gap:7px;align-items:center}.assignment-card-visit-form input[type=date]{width:100%;min-width:0;min-height:38px;border:1px solid #d8e9e6;border-radius:10px;padding:7px 8px;background:#fff;color:#294d50}.assignment-card-visit-form .visit-date-save-btn{min-height:38px;border-radius:10px;white-space:nowrap}

.assignment-pagination-wrap{display:flex;justify-content:center;align-items:center;margin-top:22px;padding-top:4px}
.assignment-pagination{display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap}
.assignment-page-link,.assignment-page-current{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 11px;border-radius:11px;border:1px solid #d6e8e5;background:#fff;color:#315f5f;text-decoration:none;font-size:13px;font-weight:900;box-shadow:0 5px 14px rgba(36,108,115,.04)}
.assignment-page-link:hover{background:#eef9f7;border-color:#c8e4df;transform:translateY(-1px)}
.assignment-page-current{background:linear-gradient(135deg,#59bfc0,#49aaac);border-color:#49aaac;color:#fff;box-shadow:0 8px 18px rgba(88,191,192,.22)}
.assignment-page-ellipsis{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:38px;color:#78908b;font-weight:900}
.assignment-page-link.is-disabled{pointer-events:none;opacity:.38}
.assignment-list-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;color:#6f8782;font-size:12px;font-weight:700}
.assignment-list-summary strong{color:#234f53}
@media(max-width:1200px){.assignment-card-grid{grid-template-columns:1fr}}
@media(max-width:700px){.assignment-card-item{padding:16px;border-radius:20px}.assignment-card-top{display:grid;grid-template-columns:1fr}.assignment-status-pill{justify-self:start}.assignment-card-info{grid-template-columns:1fr}.assignment-card-actions{display:grid;grid-template-columns:1fr}.assignment-card-actions .table-secondary-btn,.assignment-card-actions .status-edit-btn,.assignment-card-actions .compact-ended-label{width:100%;flex:none}.assignment-card-visit-form{display:grid;grid-template-columns:1fr}}


/* FINAL: รายการมอบหมายแบบตาราง ใช้เฉพาะคอลัมน์สำคัญ */
.assignment-table-wrap{width:100%;overflow:auto;border:0;background:transparent}
.assignment-data-table{width:100%;min-width:920px;border-collapse:collapse;background:#fff;table-layout:fixed}
.assignment-data-table th{padding:14px 16px;background:#e8f5f4;color:#24565a;font-size:12px;font-weight:900;text-align:left;border-bottom:1px solid #d7e8e5;white-space:nowrap}
.assignment-data-table td{padding:15px 16px;color:#294f52;font-size:13px;vertical-align:middle;border-bottom:1px solid #e8f0ee;background:#fff}
.assignment-data-table tbody tr:hover td{background:#f8fcfb}
.assignment-data-table .center{text-align:center;vertical-align:middle}
.assignment-table-name,.assignment-table-caregiver,.assignment-table-date{display:block;color:#183f45;font-weight:900;line-height:1.4}
.assignment-table-meta{display:block;margin-top:4px;color:#80918d;font-size:11px}
.assignment-table-muted{color:#879793}.assignment-data-table th:nth-child(1){width:20%}.assignment-data-table th:nth-child(2){width:10%}.assignment-data-table th:nth-child(3){width:18%}.assignment-data-table th:nth-child(4){width:14%}.assignment-data-table th:nth-child(5){width:18%}.assignment-data-table th:nth-child(6){width:20%}
.assignment-table-empty{padding:38px 16px!important;text-align:center;color:#7b8f8b!important}
.assignment-table-actions{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap}
.assignment-table-actions .table-secondary-btn,.assignment-table-actions .status-edit-btn{min-height:38px!important;padding:8px 12px!important;border-radius:10px!important;margin:0!important;white-space:nowrap}
.assignment-table-visit-form{display:flex;align-items:center;gap:7px;min-width:210px}
.assignment-table-visit-form input[type=date]{width:145px;min-height:38px;border:1px solid #d8e9e6;border-radius:10px;padding:7px 8px;background:#fff;color:#294d50}
.assignment-table-visit-form .visit-date-save-btn{min-height:38px;border-radius:10px;white-space:nowrap}

/* FINAL: หมอผู้มอบหมายแบบเรียบ ไม่ใส่กรอบ */
.doctor-badge{display:flex!important;align-items:baseline!important;justify-content:flex-end!important;gap:8px!important;flex-wrap:wrap!important;min-width:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;color:#355f60!important}
.doctor-badge-label{font-size:12px!important;text-transform:none!important;letter-spacing:0!important;color:#78908c!important;font-weight:700!important}
.doctor-badge strong{display:inline!important;width:auto!important;padding:0!important;border-radius:0!important;background:transparent!important;color:#214f54!important;font-size:14px!important;font-weight:900!important}
.doctor-badge > span:last-child{color:#5f7773!important;font-size:12px!important}
@media(max-width:760px){
  .assignment-data-table{min-width:760px}
  .doctor-badge{justify-content:flex-start!important}
}

.assignment-status-pill,.assignment-status-pill.ended,.assignment-card-actions .compact-ended-label{box-shadow:none!important}

/* FINAL OVERRIDE: status should be text only */
.assignment-status-pill,
.assignment-status-pill.ended,
.assignment-card-actions .compact-ended-label{
    display:inline!important;
    min-height:0!important;
    height:auto!important;
    width:auto!important;
    padding:0!important;
    margin:0!important;
    border:0!important;
    outline:0!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
    white-space:nowrap!important;
}
.assignment-status-pill{color:#24604f!important;font-size:13px!important;font-weight:900!important}
.assignment-status-pill.ended{color:#a15252!important}
.assignment-card-actions .compact-ended-label{color:#758884!important;font-size:13px!important;font-weight:800!important}

</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>

<main class="main">
    <?php renderUserTopbar(); ?>

    <?php if ($message): ?>
        <div class="alert alert-ok"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="assignment-top-action assignment-top-action-clean">
        <button type="button" class="assignment-add-btn" id="openAssignmentModal">+ เพิ่มการมอบหมาย</button>
    </div>

    <form method="get" class="assignment-name-search assignment-name-search-outside" id="assignmentNameSearchForm">
        <?php if ($filterDoctor > 0): ?><input type="hidden" name="doctor_id" value="<?= (int)$filterDoctor ?>"><?php endif; ?>
        <?php if ($filterCaregiver > 0): ?><input type="hidden" name="caregiver_id" value="<?= (int)$filterCaregiver ?>"><?php endif; ?>
        <div class="assignment-name-search-field">
            <span class="assignment-name-search-icon" aria-hidden="true">⌕</span>
            <input
                type="search"
                name="q"
                id="assignmentNameSearchInput"
                value="<?= e($filterKeyword) ?>"
                placeholder="ค้นหาชื่อผู้สูงอายุ..."
                aria-label="ค้นหาชื่อผู้สูงอายุ"
                autocomplete="off"
            >
            <?php if ($filterKeyword !== ''): ?>
                <a href="assign_patient.php<?= $filterDoctor > 0 || $filterCaregiver > 0 ? '?' . http_build_query(array_filter(['doctor_id'=>$filterDoctor ?: null,'caregiver_id'=>$filterCaregiver ?: null])) : '' ?>" class="assignment-search-clear">ล้าง</a>
            <?php endif; ?>
            <button type="submit" class="assignment-search-btn">ค้นหา</button>
        </div>
    </form>

    <section class="card assignment-list-card assignment-table-card">
        <?php if ($filteredAssignmentTotal > 0): ?>
            <div class="assignment-list-summary">
                <span>แสดง <strong><?= number_format($assignmentOffset + 1) ?>–<?= number_format(min($assignmentOffset + $assignmentPerPage, $filteredAssignmentTotal)) ?></strong> จาก <?= number_format($filteredAssignmentTotal) ?> รายการ</span>
                <span>หน้า <?= number_format($assignmentPage) ?> / <?= number_format($assignmentTotalPages) ?></span>
            </div>
        <?php endif; ?>
        <div class="assignment-table-wrap">
            <table class="assignment-data-table">
                <thead>
                    <tr>
                        <th>ผู้สูงอายุ</th>
                        <th class="center">อายุ</th>
                        <th>ผู้ดูแล</th>
                        <th class="center">สถานะ</th>
                        <th>นัดครั้งถัดไป</th>
                        <th class="center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$pagedAssignments): ?>
                        <tr>
                            <td colspan="6" class="assignment-table-empty">ไม่พบรายการการมอบหมายตามเงื่อนไขที่เลือก</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($pagedAssignments as $a): ?>
                        <?php
                            $doctorUsername = trim((string)($a['doctor_username'] ?? ''));
                            $doctorName = trim((string)($a['doctor_name'] ?? ''));
                            $caregiverUsername = trim((string)($a['caregiver_username'] ?? ''));
                            $caregiverName = trim((string)($a['caregiver_name'] ?? ''));
                            $careStatus = trim((string)($a['care_status'] ?? 'กำลังดูแล'));

                            $caregiverPrimary = $caregiverName !== '' ? $caregiverName : ($caregiverUsername !== '' ? $caregiverUsername : 'ยังไม่ระบุชื่อผู้ดูแล');
                            $nextVisitText = !empty($a['next_visit_date']) ? assignmentThaiDate($a['next_visit_date']) : 'ยังไม่กำหนด';
                        ?>
                        <tr>
                            <td>
                                <strong class="assignment-table-name"><?= e($a['Fullname']) ?></strong>
                            </td>
                            <td class="center">
                                <?= !empty($a['Age']) ? (int)$a['Age'] . ' ปี' : '-' ?>
                            </td>
                            <td>
                                <strong class="assignment-table-caregiver"><?= e($caregiverPrimary) ?></strong>
                                <?php if ($caregiverUsername !== '' && $caregiverUsername !== $caregiverPrimary): ?>
                                    <small class="assignment-table-meta"><?= e($caregiverUsername) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <span class="assignment-status-pill <?= $careStatus === 'สิ้นสุดการดูแล' ? 'ended' : '' ?>"><?= e($careStatus) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($a['next_visit_date'])): ?>
                                    <strong class="assignment-table-date"><?= e($nextVisitText) ?></strong>
                                <?php elseif ($careStatus === 'กำลังดูแล' && (int)($a['doctor_user_id'] ?? 0) === $currentDoctorId): ?>
                                    <form method="post" class="assignment-table-visit-form">
                                        <input type="hidden" name="action" value="update_visit_date">
                                        <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                                        <input type="date" name="next_visit_date" min="<?= date('Y-m-d') ?>" required aria-label="กำหนดวันที่ต้องเข้าเยี่ยม">
                                        <button type="submit" class="visit-date-save-btn">บันทึก</button>
                                    </form>
                                <?php else: ?>
                                    <span class="assignment-table-muted">ยังไม่กำหนด</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="assignment-table-actions">
                                    <a class="table-secondary-btn" href="assignment_detail.php?id=<?= (int)$a['assignment_id'] ?>">รายละเอียด</a>
                                    <?php if ($careStatus !== 'สิ้นสุดการดูแล'): ?>
                                        <button type="button"
                                                class="status-edit-btn js-open-end-care-modal"
                                                data-assignment-id="<?= (int)$a['assignment_id'] ?>"
                                                data-patient-name="<?= e($a['Fullname']) ?>">สิ้นสุดการดูแล</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($assignmentTotalPages > 1): ?>
            <div class="assignment-pagination-wrap" aria-label="แบ่งหน้ารายการการมอบหมาย">
                <nav class="assignment-pagination">
                    <?php if ($assignmentPage > 1): ?>
                        <a class="assignment-page-link" href="<?= e(assignmentPageUrl($assignmentPage - 1, $filterDoctor, $filterCaregiver, $filterKeyword)) ?>" aria-label="หน้าก่อนหน้า">&lt;</a>
                    <?php else: ?>
                        <span class="assignment-page-link is-disabled" aria-hidden="true">&lt;</span>
                    <?php endif; ?>

                    <?php foreach (assignmentPaginationItems($assignmentPage, $assignmentTotalPages) as $pageItem): ?>
                        <?php if ($pageItem === '...'): ?>
                            <span class="assignment-page-ellipsis">…</span>
                        <?php elseif ((int)$pageItem === $assignmentPage): ?>
                            <span class="assignment-page-current" aria-current="page"><?= (int)$pageItem ?></span>
                        <?php else: ?>
                            <a class="assignment-page-link" href="<?= e(assignmentPageUrl((int)$pageItem, $filterDoctor, $filterCaregiver, $filterKeyword)) ?>"><?= (int)$pageItem ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($assignmentPage < $assignmentTotalPages): ?>
                        <a class="assignment-page-link" href="<?= e(assignmentPageUrl($assignmentPage + 1, $filterDoctor, $filterCaregiver, $filterKeyword)) ?>" aria-label="หน้าถัดไป">&gt;</a>
                    <?php else: ?>
                        <span class="assignment-page-link is-disabled" aria-hidden="true">&gt;</span>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </section>
    <div class="assignment-detail-modal" id="assignmentDetailModal" aria-hidden="true">
        <div class="assignment-detail-card assignment-detail-card-formal">
            <div class="assignment-detail-hero">
                <div class="assignment-detail-profile">
                    <div class="assignment-detail-photo-shell">
                        <img src="" alt="รูปภาพผู้สูงอายุ" id="detailPhoto" class="assignment-detail-photo" hidden>
                        <div class="assignment-detail-photo-placeholder" id="detailPhotoPlaceholder">
                            <div class="assignment-detail-photo-icon">👤</div>
                            <div class="assignment-detail-photo-text">ยังไม่มีรูปภาพผู้สูงอายุ</div>
                        </div>
                    </div>
                    <div class="assignment-detail-hero-copy">
                        <div class="assignment-detail-kicker">ข้อมูลการมอบหมายโดยละเอียด</div>
                        <h3 id="detailHeroName">-</h3>
                        <div class="assignment-detail-subtitle" id="assignmentDetailSubtitle">แสดงรายละเอียดเชิงลึกของรายการที่เลือก</div>
                        <div class="assignment-detail-hero-meta">
                            <span>อายุ <strong id="detailHeroAge">-</strong></span>
                            <span>วันนัดเยี่ยมครั้งถัดไป <strong id="detailHeroNextVisit">-</strong></span>
                        </div>
                        <div class="assignment-detail-chip-row">
                            <span class="assignment-detail-chip" id="detailChipCareStatus">สถานะการดูแล: -</span>
                            <span class="assignment-detail-chip soft" id="detailChipResultStatus">สถานะการประเมิน: -</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="status-modal-close" id="closeAssignmentDetailModal" aria-label="ปิด">×</button>
            </div>

            <div class="assignment-detail-body">
                <section class="assignment-detail-section">
                    <div class="assignment-detail-section-title">ข้อมูลบุคคลและการมอบหมาย</div>
                    <div class="assignment-detail-grid formal-grid">
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผู้สูงอายุ</span><strong id="detailPatient">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">อายุ</span><strong id="detailAge">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">แพทย์ผู้มอบหมาย</span><strong id="detailDoctor">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผู้ดูแลที่ได้รับมอบหมาย</span><strong id="detailCaregiver">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">วันที่มอบหมาย</span><strong id="detailAssignedAt">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">วันนัดเยี่ยมครั้งถัดไป</span><strong id="detailNextVisit">-</strong></div>
                    </div>
                </section>

                <section class="assignment-detail-section">
                    <div class="assignment-detail-section-title">ข้อมูลผลการประเมิน ADL</div>
                    <div class="assignment-detail-grid formal-grid">
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผลการประเมินครั้งที่ 1 โดยแพทย์</span><strong id="detailDoctorAdl">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผลการประเมินครั้งที่ 2 โดยแคร์กิฟเวอร์</span><strong id="detailCaregiverAdl">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผลสรุปกลุ่ม</span><strong id="detailGroup">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">สถานะการประเมิน</span><strong id="detailResultStatus">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">ผู้ดูแลประจำ</span><strong id="detailRegularCaregiver">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">สิทธิเงินสงเคราะห์</span><strong id="detailWelfare">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">สมาชิกชมรม</span><strong id="detailClub">-</strong></div>
                        <div class="assignment-detail-item full"><span class="assignment-detail-label">หมายเหตุการประเมิน</span><strong id="detailNote">-</strong></div>
                    </div>
                </section>

                <section class="assignment-detail-section">
                    <div class="assignment-detail-section-title">สถานะการดูแล</div>
                    <div class="assignment-detail-grid formal-grid">
                        <div class="assignment-detail-item"><span class="assignment-detail-label">สถานะการดูแล</span><strong id="detailCareStatus">-</strong></div>
                        <div class="assignment-detail-item"><span class="assignment-detail-label">เหตุผลการสิ้นสุดการดูแล</span><strong id="detailCareEndReason">-</strong></div>
                        <div class="assignment-detail-item full"><span class="assignment-detail-label">หมายเหตุเพิ่มเติมเกี่ยวกับสถานะการดูแล</span><strong id="detailCareStatusNote">-</strong></div>
                    </div>
                </section>
            </div>

            <div class="assignment-detail-actions formal-actions">
                <button type="button" class="status-save-btn" id="closeAssignmentDetailModalBtn">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>

    <div class="status-modal" id="endCareModal" aria-hidden="true">
        <div class="status-modal-card">
            <div class="status-modal-head">
                <div>
                    <h3>สิ้นสุดการดูแล</h3>
                    <div class="muted" id="endCarePatientName"></div>
                </div>
                <button type="button" class="status-modal-close" id="closeEndCareModal" aria-label="ปิด">×</button>
            </div>
            <form method="post" id="endCareModalForm">
                <input type="hidden" name="action" value="end_care">
                <input type="hidden" name="assignment_id" id="end_assignment_id" value="">

                <div class="status-field">
                    <label for="end_care_reason">เหตุผลที่สิ้นสุดการดูแล</label>
                    <select name="care_end_reason" id="end_care_reason" required>
                        <option value="">-- เลือกเหตุผล --</option>
                        <?php foreach (['หายดี / ไม่จำเป็นต้องดูแลต่อ','เสียชีวิต','ย้ายออกนอกพื้นที่','เข้ารับการดูแลในโรงพยาบาล / สถานดูแล','ครอบครัวรับดูแลต่อ','เปลี่ยนผู้ดูแล','อื่น ๆ'] as $reason): ?>
                            <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="status-field">
                    <label for="end_care_note">หมายเหตุเพิ่มเติม</label>
                    <input type="text" name="care_status_note" id="end_care_note" maxlength="255" placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)">
                </div>

                <div class="status-modal-actions">
                    <button type="button" class="status-cancel-btn" id="cancelEndCareModal">ยกเลิก</button>
                    <button type="submit" class="status-save-btn">ยืนยันสิ้นสุดการดูแล</button>
                </div>
            </form>
        </div>
    </div>

    <div class="assignment-modal" id="assignmentModal" aria-hidden="true">
        <div class="assignment-modal-dialog">
            <button type="button" class="assignment-modal-close" id="closeAssignmentModal" aria-label="ปิด">×</button>
    <section class="card assignment-form-card">
        <div class="assignment-form-head">
            <div>
                <h2>มอบหมายผู้ดูแล</h2>
            </div>
            <div class="doctor-badge">
                <span class="doctor-badge-label">หมอผู้มอบหมาย</span>
                <strong><?= e((string)($_SESSION['username'] ?? 'Doctor')) ?></strong>
                <?php if (!empty($_SESSION['display_name'])): ?>
                    <span><?= e((string)$_SESSION['display_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" class="assignment-form" id="assignmentForm">
            <input type="hidden" name="action" value="assign">
            <div class="assignment-select-grid">
                <div class="field patient-search-field">
                    <label>ผู้สูงอายุที่ต้องการมอบหมาย</label>
                    <div class="patient-search-wrap">
                        <span class="patient-search-icon" aria-hidden="true">⌕</span>
                        <input
                            type="search"
                            id="patientSearchInput"
                            class="patient-search-input"
                            list="patientOptions"
                            placeholder="พิมพ์เพื่อค้นหาและเลือกผู้สูงอายุ..."
                            autocomplete="off"
                            aria-label="ค้นหาและเลือกผู้สูงอายุ"
                            required
                        >
                        <button type="button" id="clearPatientSearch" class="patient-search-clear" aria-label="ล้างคำค้นหา" title="ล้างคำค้นหา">×</button>
                        <input type="hidden" name="patient_id" id="patientIdInput" value="">
                        <datalist id="patientOptions">
                            <?php
                                $availablePatientCount = 0;

                                foreach ($patients as $p):
                                    $patientOptionId = (int)$p['Patient_id'];

                                    if (isset($assignedPatientIds[$patientOptionId])) {
                                        continue;
                                    }

                                    $availablePatientCount++;
                                    $patientOptionLabel = (string)$p['Fullname'] . (!empty($p['Age']) ? ' • อายุ ' . (int)$p['Age'] . ' ปี' : '');
                            ?>
                                <option value="<?= e($patientOptionLabel) ?>" data-patient-id="<?= $patientOptionId ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <?php if ($availablePatientCount === 0): ?>
                        <div class="patient-search-empty">ไม่มีผู้สูงอายุที่สามารถมอบหมายเพิ่มได้</div>
                    <?php else: ?>
                        <div class="field-note field-note-placeholder" aria-hidden="true">&nbsp;</div>
                    <?php endif; ?>
                </div>


                <div class="field">
                    <label>แคร์กิฟเวอร์ผู้รับผิดชอบ</label>
                    <select name="caregiver_user_id" required>
                        <option value="">-- เลือกแคร์กิฟเวอร์ --</option>
                        <?php foreach ($caregivers as $c): ?>
                            <?php
                                $caregiverUsername = trim((string)($c['username'] ?? ''));
                                $caregiverName = trim((string)($c['display_name'] ?? ''));
                                $caregiverLabel = $caregiverUsername;
                                if ($caregiverName !== '') {
                                    $caregiverLabel .= ($caregiverLabel !== '' ? ' - ' : '') . $caregiverName;
                                }
                            ?>
                            <option value="<?= (int)$c['user_id'] ?>"><?= e($caregiverLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-note field-note-placeholder" aria-hidden="true">&nbsp;</div>
                </div>

                <div class="field">
                    <label for="next_visit_date">วันที่ต้องเข้าเยี่ยม <span class="required-mark" style="color:#d93025!important">*</span></label>
                    <input type="date" name="next_visit_date" id="next_visit_date" min="<?= date('Y-m-d') ?>" required>
                    <div class="field-note">หมอต้องกำหนดวันเข้าเยี่ยมก่อนบันทึกการมอบหมาย</div>
                </div>

                <div class="assignment-submit">
                    <button class="btn btn-primary assignment-main-btn" type="submit">บันทึกการมอบหมาย</button>
                </div>
            </div>

        </form>
    </section>

        </div>
    </div>

    <script>
        (function () {
            const endCareModal = document.getElementById('endCareModal');
            const endAssignmentId = document.getElementById('end_assignment_id');
            const endCarePatientName = document.getElementById('endCarePatientName');
            const endCareReason = document.getElementById('end_care_reason');
            const endCareNote = document.getElementById('end_care_note');
            const closeEndCareBtn = document.getElementById('closeEndCareModal');
            const cancelEndCareBtn = document.getElementById('cancelEndCareModal');

            function openEndCareModal(btn){
                if (!endCareModal) return;
                endAssignmentId.value = btn.dataset.assignmentId || '';
                if (endCarePatientName) endCarePatientName.textContent = 'ผู้สูงอายุ: ' + (btn.dataset.patientName || '-');
                if (endCareReason) endCareReason.value = '';
                if (endCareNote) endCareNote.value = '';
                endCareModal.classList.add('is-open');
                endCareModal.setAttribute('aria-hidden','false');
                document.body.style.overflow = 'hidden';
            }
            function closeEndCareModal(){
                if (!endCareModal) return;
                endCareModal.classList.remove('is-open');
                endCareModal.setAttribute('aria-hidden','true');
                document.body.style.overflow = '';
            }
            document.querySelectorAll('.js-open-end-care-modal').forEach(function(btn){
                btn.addEventListener('click', function(){ openEndCareModal(btn); });
            });
            if (closeEndCareBtn) closeEndCareBtn.addEventListener('click', closeEndCareModal);
            if (cancelEndCareBtn) cancelEndCareBtn.addEventListener('click', closeEndCareModal);
            if (endCareModal) endCareModal.addEventListener('click', function(event){ if (event.target === endCareModal) closeEndCareModal(); });

            const detailModal = document.getElementById('assignmentDetailModal');
            const detailSubtitle = document.getElementById('assignmentDetailSubtitle');
            const detailHeroName = document.getElementById('detailHeroName');
            const detailHeroAge = document.getElementById('detailHeroAge');
            const detailHeroNextVisit = document.getElementById('detailHeroNextVisit');
            const detailChipCareStatus = document.getElementById('detailChipCareStatus');
            const detailChipResultStatus = document.getElementById('detailChipResultStatus');
            const detailPhoto = document.getElementById('detailPhoto');
            const detailPhotoPlaceholder = document.getElementById('detailPhotoPlaceholder');
            const closeDetailBtn = document.getElementById('closeAssignmentDetailModal');
            const closeDetailBtn2 = document.getElementById('closeAssignmentDetailModalBtn');

            function setDetailValue(id, value) {
                const el = document.getElementById(id);
                if (el) el.textContent = value && String(value).trim() !== '' ? value : '-';
            }

            function setDetailPhoto(photoUrl, patientName) {
                if (!detailPhoto || !detailPhotoPlaceholder) return;
                if (photoUrl && String(photoUrl).trim() !== '') {
                    detailPhoto.src = photoUrl;
                    detailPhoto.alt = 'รูปภาพผู้สูงอายุ ' + (patientName || '');
                    detailPhoto.hidden = false;
                    detailPhotoPlaceholder.hidden = true;
                } else {
                    detailPhoto.src = '';
                    detailPhoto.hidden = true;
                    detailPhotoPlaceholder.hidden = false;
                }
            }

            function openDetailModal(btn) {
                if (!detailModal) return;
                const patientName = btn.dataset.patient || '-';
                const age = btn.dataset.age || '-';
                const nextVisit = btn.dataset.nextVisit || '-';
                const careStatus = btn.dataset.careStatus || '-';
                const resultStatus = btn.dataset.resultStatus || '-';

                setDetailValue('detailPatient', patientName);
                setDetailValue('detailAge', age);
                setDetailValue('detailDoctor', btn.dataset.doctor || '-');
                setDetailValue('detailCaregiver', btn.dataset.caregiver || '-');
                setDetailValue('detailAssignedAt', btn.dataset.assignedAt || '-');
                setDetailValue('detailNextVisit', nextVisit);
                setDetailValue('detailDoctorAdl', btn.dataset.doctorAdl || '-');
                setDetailValue('detailCaregiverAdl', btn.dataset.caregiverAdl || '-');
                setDetailValue('detailGroup', btn.dataset.group || '-');
                setDetailValue('detailResultStatus', resultStatus);
                setDetailValue('detailRegularCaregiver', btn.dataset.regularCaregiver || '-');
                setDetailValue('detailWelfare', btn.dataset.welfare || '-');
                setDetailValue('detailClub', btn.dataset.club || '-');
                setDetailValue('detailNote', btn.dataset.note || '-');
                setDetailValue('detailCareStatus', careStatus);
                setDetailValue('detailCareEndReason', btn.dataset.careEndReason || '-');
                setDetailValue('detailCareStatusNote', btn.dataset.careStatusNote || '-');
                if (detailHeroName) detailHeroName.textContent = patientName;
                if (detailHeroAge) detailHeroAge.textContent = age;
                if (detailHeroNextVisit) detailHeroNextVisit.textContent = nextVisit;
                if (detailChipCareStatus) detailChipCareStatus.textContent = 'สถานะการดูแล: ' + careStatus;
                if (detailChipResultStatus) detailChipResultStatus.textContent = 'สถานะการประเมิน: ' + resultStatus;
                if (detailSubtitle) detailSubtitle.textContent = 'รายละเอียดของผู้สูงอายุ: ' + patientName;
                setDetailPhoto(btn.dataset.photo || '', patientName);
                detailModal.classList.add('is-open');
                detailModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeDetailModal() {
                if (!detailModal) return;
                detailModal.classList.remove('is-open');
                detailModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            document.querySelectorAll('.js-open-detail-modal').forEach(function(btn){
                btn.addEventListener('click', function(){ openDetailModal(btn); });
            });
            if (closeDetailBtn) closeDetailBtn.addEventListener('click', closeDetailModal);
            if (closeDetailBtn2) closeDetailBtn2.addEventListener('click', closeDetailModal);
            if (detailModal) detailModal.addEventListener('click', function(event){ if (event.target === detailModal) closeDetailModal(); });

            const patientSearchInput = document.getElementById('patientSearchInput');
            const patientIdInput = document.getElementById('patientIdInput');
            const patientOptions = document.getElementById('patientOptions');
            const clearPatientSearch = document.getElementById('clearPatientSearch');
            const assignmentForm = document.getElementById('assignmentForm');

            function normalizeSearchText(value) {
                return (value || '').toLocaleLowerCase('th-TH').replace(/\s+/g, ' ').trim();
            }

            function syncSelectedPatient() {
                if (!patientSearchInput || !patientIdInput || !patientOptions) return false;

                const typedValue = normalizeSearchText(patientSearchInput.value);
                const options = Array.from(patientOptions.options);
                const matchedOption = options.find(function(option) {
                    return normalizeSearchText(option.value) === typedValue;
                });

                patientIdInput.value = matchedOption ? (matchedOption.dataset.patientId || '') : '';
                patientSearchInput.setCustomValidity('');

                if (clearPatientSearch) {
                    clearPatientSearch.classList.toggle('is-visible', typedValue !== '');
                }

                return patientIdInput.value !== '';
            }

            if (patientSearchInput) {
                patientSearchInput.addEventListener('input', syncSelectedPatient);
                patientSearchInput.addEventListener('change', syncSelectedPatient);
            }

            if (clearPatientSearch) {
                clearPatientSearch.addEventListener('click', function() {
                    patientSearchInput.value = '';
                    patientIdInput.value = '';
                    patientSearchInput.setCustomValidity('');
                    clearPatientSearch.classList.remove('is-visible');
                    patientSearchInput.focus();
                });
            }

            if (assignmentForm) {
                assignmentForm.addEventListener('submit', function(event) {
                    if (!syncSelectedPatient()) {
                        event.preventDefault();
                        patientSearchInput.setCustomValidity('กรุณาค้นหาและเลือกผู้สูงอายุจากรายการ');
                        patientSearchInput.reportValidity();
                        patientSearchInput.focus();
                    }
                });
            }

            const modal = document.getElementById('assignmentModal');
            const openBtn = document.getElementById('openAssignmentModal');
            const closeBtn = document.getElementById('closeAssignmentModal');

            function openModal() {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                if (patientSearchInput) {
                    window.setTimeout(function(){ patientSearchInput.focus(); }, 80);
                }
            }

            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            openBtn.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && detailModal && detailModal.classList.contains('is-open')) {
                    closeDetailModal();
                } else if (event.key === 'Escape' && endCareModal && endCareModal.classList.contains('is-open')) {
                    closeEndCareModal();
                } else if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'assign') && $error !== ''): ?>
            openModal();
            <?php endif; ?>
        })();
    </script>

</main>
</body>
</html>
<style>
/* ===== FINAL FORCE: three fields exactly equal in width and height ===== */
.assignment-form{padding:22px 24px 24px!important;}
.assignment-select-grid{
    display:grid!important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)!important;
    column-gap:10px!important;
    row-gap:10px!important;
    width:100%!important;
    align-items:start!important;
}
.assignment-select-grid > .field{
    display:grid!important;
    grid-template-rows:38px 48px 34px!important;
    align-content:start!important;
    width:100%!important;
    min-width:0!important;
    max-width:none!important;
    margin:0!important;
    padding:0!important;
    box-sizing:border-box!important;
    align-self:start!important;
}
.assignment-select-grid > .field:nth-child(1),
.assignment-select-grid > .field:nth-child(2),
.assignment-select-grid > .field:nth-child(3){
    grid-column:auto!important;
    grid-row:1!important;
}
.assignment-select-grid > .field label{
    display:flex!important;
    align-items:flex-end!important;
    width:100%!important;
    min-width:0!important;
    min-height:0!important;
    height:38px!important;
    margin:0!important;
    padding:0 0 6px!important;
    box-sizing:border-box!important;
    white-space:nowrap!important;
}
.assignment-select-grid .patient-search-wrap,
.assignment-select-grid select,
.assignment-select-grid input[type="date"]{
    display:block!important;
    width:100%!important;
    min-width:0!important;
    max-width:none!important;
    height:48px!important;
    min-height:48px!important;
    max-height:48px!important;
    margin:0!important;
    box-sizing:border-box!important;
}
.assignment-select-grid .patient-search-wrap{position:relative!important;grid-row:2!important;}
.assignment-select-grid .patient-search-input{
    display:block!important;
    width:100%!important;
    min-width:0!important;
    max-width:none!important;
    height:48px!important;
    min-height:48px!important;
    max-height:48px!important;
    margin:0!important;
    box-sizing:border-box!important;
}
.assignment-select-grid > .field > select,
.assignment-select-grid > .field > input[type="date"]{grid-row:2!important;}
.assignment-select-grid .field-note,
.assignment-select-grid .patient-search-empty{
    grid-row:3!important;
    width:100%!important;
    min-width:0!important;
    height:34px!important;
    min-height:34px!important;
    margin:0!important;
    padding-top:6px!important;
    box-sizing:border-box!important;
    overflow:hidden!important;
}
.assignment-select-grid .field-note-placeholder{visibility:hidden!important;}
.assignment-select-grid > .assignment-submit{
    grid-column:1 / -1!important;
    grid-row:2!important;
    width:100%!important;
    display:flex!important;
    justify-content:flex-end!important;
    margin:0!important;
    padding:0!important;
}
.assignment-select-grid > .assignment-submit .assignment-main-btn{
    width:calc((100% - 20px) / 3)!important;
    min-width:0!important;
    max-width:none!important;
    box-sizing:border-box!important;
}
.assignment-flow-note{
    display:grid!important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)!important;
    gap:10px!important;
}
.assignment-flow-note > *{
    min-width:0!important;
    width:100%!important;
    box-sizing:border-box!important;
}
@media(max-width:900px){
    .assignment-select-grid,
    .assignment-flow-note{grid-template-columns:1fr!important;}
    .assignment-select-grid > .field{grid-template-rows:auto 48px auto!important;}
    .assignment-select-grid > .field:nth-child(1),
    .assignment-select-grid > .field:nth-child(2),
    .assignment-select-grid > .field:nth-child(3){grid-row:auto!important;}
    .assignment-select-grid > .field label{height:auto!important;padding-bottom:6px!important;white-space:normal!important;}
    .assignment-select-grid > .assignment-submit{grid-row:auto!important;grid-column:1!important;}
    .assignment-select-grid > .assignment-submit .assignment-main-btn{width:100%!important;}
    .assignment-select-grid .field-note-placeholder{display:none!important;}
}

/* ===== Compact assignment list + detail modal ===== */
.assignment-list-heading h2{margin-bottom:6px!important}
.table-assignment-five th:nth-child(1){min-width:250px}
.table-assignment-five th:nth-child(2){min-width:210px}
.table-assignment-five th:nth-child(3){min-width:175px}
.table-assignment-five th:nth-child(4){min-width:195px}
.table-assignment-five th:nth-child(5){min-width:360px}
.compact-person strong{display:block;font-size:17px;line-height:1.4;color:#1f4d54}
.compact-subline{margin-top:6px;color:#7d9291;font-size:12px;line-height:1.45}
.compact-caregiver-box .person-name{margin-top:8px}
.compact-date-main{font-weight:800;color:#1e5057;line-height:1.5}
.assignment-detail-cell{vertical-align:top!important}
.assignment-quick-summary{display:grid;gap:10px}
.assignment-quick-badges{display:flex;flex-wrap:wrap;gap:8px}
.info-inline-chip{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid transparent}
.info-inline-chip.success{background:#edf8f5;border-color:#cfe7e1;color:#1f6166}
.info-inline-chip.warning{background:#fff6e7;border-color:#ecd7a8;color:#946d1a}
.info-inline-chip.neutral{background:#f3f7f7;border-color:#dde7e7;color:#6f8588}
.compact-action-stack-inline{grid-template-columns:repeat(auto-fit,minmax(155px,1fr));align-items:stretch}
.compact-action-stack-inline form{margin:0}
.compact-action-stack-inline .table-secondary-btn,.compact-action-stack-inline .compact-action-btn{width:100%!important}
.compact-action-only-detail{grid-template-columns:1fr!important}
.compact-action-only-detail .table-secondary-btn{width:100%!important;min-height:44px!important}
.compact-visit-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.compact-visit-form input[type=date]{min-width:135px}
.assessment-summary-box{display:grid;gap:8px}
.assessment-status-note{line-height:1.45}
.compact-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid #d8ebe6;background:#edf8f5;color:#215f63}
.compact-status-badge.ended{background:#fff1f1;border-color:#f1cccc;color:#9e4f4f}
.compact-status-badge.active{background:#edf8f5;border-color:#cfe7e1;color:#1f6166}
.table-secondary-btn{display:inline-flex;align-items:center;justify-content:center;min-width:104px;padding:10px 16px;border-radius:12px;border:1px solid #cfe3e0;background:#ffffff;color:#215a60;font-weight:800;cursor:pointer;transition:all .18s ease}
.table-secondary-btn:hover{transform:translateY(-1px);background:#f7fbfb}
.compact-action-cell{vertical-align:top!important}
.compact-action-stack{display:grid;gap:8px;min-width:150px}
.compact-action-btn{width:100%!important;min-width:0!important;text-align:center!important;justify-content:center!important}
.assignment-detail-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(15,39,43,.42);z-index:1100}
.assignment-detail-modal.is-open{display:flex}
.assignment-detail-card{width:min(980px,calc(100vw - 32px));max-height:min(88vh,860px);overflow:auto;border-radius:24px;background:#fff;border:1px solid #d9ebe8;box-shadow:0 22px 48px rgba(15,39,43,.18);padding:0}
.assignment-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:22px}
.assignment-detail-item{padding:14px 16px;border-radius:16px;border:1px solid #e3efed;background:#fbfefe;display:grid;gap:7px}
.assignment-detail-item.full{grid-column:1 / -1}
.assignment-detail-label{font-size:12px;font-weight:800;color:#728b8e}
.assignment-detail-item strong{font-size:14px;line-height:1.65;color:#224f55;white-space:pre-wrap;word-break:break-word}
.assignment-detail-actions{display:flex;justify-content:flex-end;padding:0 22px 22px}
@media(max-width:980px){.assignment-detail-grid{grid-template-columns:1fr}.table-assignment-compact th,.table-assignment-compact td{white-space:normal!important}}
@media(max-width:760px){.compact-action-stack{min-width:128px}.table-secondary-btn,.compact-action-btn{padding:9px 12px;font-size:12px}}

/* ===== Refined formal layout for assignment table ===== */
.assignment-list-card{border-radius:26px!important;overflow:hidden!important}
.assignment-list-heading{padding-right:2px}
.assignment-list-heading .muted{font-size:13px;line-height:1.6}
.assignment-list-mini-stats{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.assignment-table-refined{min-width:1280px!important;table-layout:fixed;border-collapse:separate;border-spacing:0}
.assignment-table-refined col.col-elderly{width:27%}
.assignment-table-refined col.col-caregiver{width:23%}
.assignment-table-refined col.col-assigned-date{width:19%}
.assignment-table-refined col.col-next-visit{width:19%}
.assignment-table-refined col.col-detail-action{width:12%}
.assignment-table-refined thead th{padding:15px 16px!important;line-height:1.35;text-align:left}
.assignment-table-refined thead th.th-center{text-align:center}
.assignment-table-refined td{padding:18px 16px!important}
.assignment-table-refined tbody tr:nth-child(odd){background:#ffffff}
.assignment-table-refined tbody tr:nth-child(even){background:#fbfdfd}
.assignment-table-refined tbody tr:hover{background:#f3fbfb}
.assignment-table-refined td:nth-child(3),
.assignment-table-refined td:nth-child(4){white-space:nowrap}
.compact-person strong{font-size:16px!important;line-height:1.45;color:#173f45}
.compact-person .muted{margin-top:4px;font-size:13px}
.compact-subline{margin-top:7px;line-height:1.55;color:#6f8688}
.compact-caregiver-box{display:grid;gap:8px;align-content:start}
.compact-caregiver-box .person-name{margin-top:0!important;line-height:1.5}
.compact-date-main{font-size:15px;font-weight:900;line-height:1.45;color:#1a4950}
.assignment-detail-cell-centered{text-align:center;vertical-align:middle!important}
.assignment-detail-only{display:flex;justify-content:center;align-items:center;min-height:92px}
.compact-action-only-detail{width:100%;max-width:210px;margin:0 auto}
.compact-action-only-detail .table-secondary-btn{min-height:46px!important;border-radius:14px!important;padding:11px 16px!important;font-size:13px!important;box-shadow:0 4px 10px rgba(24,63,69,.04)}
.compact-action-only-detail .table-secondary-btn:hover{background:#f4faf9;border-color:#c9e1dd}
.table-wrap{overflow:auto hidden}
@media(max-width:1100px){
  .assignment-table-refined{min-width:1120px!important}
  .assignment-table-refined col.col-elderly{width:29%}
  .assignment-table-refined col.col-caregiver{width:24%}
  .assignment-table-refined col.col-assigned-date{width:18%}
  .assignment-table-refined col.col-next-visit{width:17%}
  .assignment-table-refined col.col-detail-action{width:12%}
}

/* ===== Compact table refinement + end-care action ===== */
.assignment-table-refined{min-width:1040px!important}
.assignment-table-refined col.col-elderly{width:27%}
.assignment-table-refined col.col-caregiver{width:22%}
.assignment-table-refined col.col-assigned-date{width:17%}
.assignment-table-refined col.col-next-visit{width:17%}
.assignment-table-refined col.col-detail-action{width:17%}
.assignment-table-refined thead th{padding:12px 14px!important;font-size:12px!important}
.assignment-table-refined td{padding:13px 14px!important;font-size:13px!important}
.compact-person strong{font-size:15px!important}
.compact-person .muted,.compact-subline{font-size:11.5px!important}
.compact-date-main{font-size:14px!important}
.compact-caregiver-box{gap:5px!important}
.compact-caregiver-box .person-name{font-size:12.5px!important}
.role-chip.caregiver{padding:6px 10px!important;font-size:11.5px!important}
.assignment-detail-only{min-height:72px!important}
.compact-action-only-detail{max-width:180px!important;display:grid!important;gap:8px!important}
.compact-action-only-detail .table-secondary-btn,
.compact-action-only-detail .compact-end-care-btn{min-height:38px!important;padding:8px 12px!important;border-radius:11px!important;font-size:12px!important;width:100%!important}
.compact-end-care-btn{background:#fff!important;border:1px solid #e6c7c7!important;color:#a04e4e!important;box-shadow:none!important}
.compact-end-care-btn:hover{background:#fff5f5!important;border-color:#ddb5b5!important}
.compact-ended-label{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 10px;border-radius:11px;background:#f3f5f5;color:#7e8e90;font-size:11.5px;font-weight:800;border:1px solid #e2e8e8}
.assignment-list-card{padding:18px!important}
.assignment-list-heading{margin-bottom:12px!important}
.assignment-list-heading h2{font-size:20px!important}
.assignment-list-mini-stats .list-mini-chip{min-height:34px!important;padding:7px 12px!important;font-size:11.5px!important}
@media(max-width:1100px){
  .assignment-table-refined{min-width:980px!important}
  .assignment-table-refined col.col-elderly{width:28%}
  .assignment-table-refined col.col-caregiver{width:22%}
  .assignment-table-refined col.col-assigned-date{width:16%}
  .assignment-table-refined col.col-next-visit{width:16%}
  .assignment-table-refined col.col-detail-action{width:18%}
}

/* ===== Formal assignment detail modal with photo ===== */
.assignment-detail-card-formal{width:min(1080px,calc(100vw - 34px));max-height:min(90vh,920px);border-radius:28px;background:#fff;overflow:auto;border:1px solid #d7ebe8;box-shadow:0 26px 56px rgba(17,58,64,.18)}
.assignment-detail-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:24px 26px;background:linear-gradient(135deg,#f1fbfb 0%,#fbfefe 100%);border-bottom:1px solid #e2efed}
.assignment-detail-profile{display:flex;align-items:center;gap:18px;min-width:0;flex:1}
.assignment-detail-photo-shell{width:118px;height:118px;border-radius:24px;background:#eef7f6;border:1px solid #d3e8e4;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;box-shadow:inset 0 0 0 1px rgba(255,255,255,.7)}
.assignment-detail-photo{width:100%;height:100%;object-fit:cover;display:block}
.assignment-detail-photo-placeholder{display:grid;place-items:center;width:100%;height:100%;padding:10px;text-align:center;color:#6f8789;background:linear-gradient(135deg,#f2faf9,#edf7f6)}
.assignment-detail-photo-icon{font-size:28px;line-height:1}
.assignment-detail-photo-text{margin-top:6px;font-size:11px;font-weight:700;line-height:1.45}
.assignment-detail-hero-copy{min-width:0;display:grid;gap:8px;align-content:center}
.assignment-detail-kicker{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#6d8b8e}
.assignment-detail-hero-copy h3{margin:0;color:#1a4950;font-size:28px;line-height:1.2}
.assignment-detail-subtitle{color:#70888b;font-size:13px;line-height:1.65}
.assignment-detail-hero-meta{display:flex;flex-wrap:wrap;gap:10px 18px;color:#5f777a;font-size:13px;line-height:1.6}
.assignment-detail-hero-meta strong{color:#214f55}
.assignment-detail-chip-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:2px}
.assignment-detail-chip{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:999px;background:#eaf7f5;border:1px solid #cfe6e1;color:#215f63;font-size:12px;font-weight:900}
.assignment-detail-chip.soft{background:#f4f9f9;color:#5c7376;border-color:#d9e8e6}
.assignment-detail-body{padding:22px}
.assignment-detail-section{display:grid;gap:12px;margin-bottom:18px}
.assignment-detail-section:last-child{margin-bottom:0}
.assignment-detail-section-title{padding:11px 14px;border-radius:14px;background:#eef8f7;border-left:4px solid #67c7c1;color:#24585f;font-size:14px;font-weight:900}
.assignment-detail-grid.formal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.assignment-detail-item{padding:15px 17px;border-radius:18px;border:1px solid #dfeeed;background:#fcfefe;box-shadow:0 6px 16px rgba(35,95,102,.03);display:grid;gap:8px}
.assignment-detail-item.full{grid-column:1 / -1}
.assignment-detail-label{font-size:12px;font-weight:800;color:#769093;line-height:1.45}
.assignment-detail-item strong{font-size:18px;line-height:1.5;color:#173f45;font-weight:900;white-space:pre-wrap;word-break:break-word}
.assignment-detail-actions.formal-actions{padding:0 22px 22px;justify-content:flex-end}
.assignment-detail-actions.formal-actions .status-save-btn{min-width:144px;border-radius:14px}
@media(max-width:860px){.assignment-detail-hero{padding:20px;flex-direction:column}.assignment-detail-profile{align-items:flex-start}.assignment-detail-photo-shell{width:100px;height:100px}.assignment-detail-hero-copy h3{font-size:24px}.assignment-detail-grid.formal-grid{grid-template-columns:1fr}}
@media(max-width:560px){.assignment-detail-body{padding:16px}.assignment-detail-section-title{font-size:13px}.assignment-detail-item strong{font-size:16px}.assignment-detail-chip-row{gap:8px}}

/* ===== Assignment name search ===== */
.assignment-table-card{position:relative;padding-top:0!important}
.assignment-name-search{display:block;width:100%;margin:0 0 18px}
.assignment-name-search-outside{max-width:none;width:100%}
.assignment-name-search-field{display:flex;align-items:center;gap:12px;position:relative;width:100%;max-width:none;padding:0;border:0;border-radius:0;background:transparent;box-shadow:none}
.assignment-name-search-icon{position:absolute;left:26px;top:50%;transform:translateY(-50%);color:#73a0a4;font-size:20px;pointer-events:none}
.assignment-name-search-field input[type=search]{flex:1;min-width:0;min-height:50px;padding:12px 16px 12px 46px;border:1px solid #d5e6e3;border-radius:14px;background:#fbfefe;color:#22484d;font:inherit;outline:none;box-shadow:none}
.assignment-name-search-field input[type=search]:focus{border-color:#63c3c1;background:#fff;box-shadow:0 0 0 3px rgba(99,195,193,.10)}
.assignment-search-btn{min-width:110px;min-height:50px;padding:10px 24px;border:0;border-radius:14px;background:linear-gradient(135deg,#59bfc0,#47aeb0);color:#fff;font:inherit;font-weight:900;cursor:pointer;white-space:nowrap;box-shadow:0 8px 18px rgba(71,174,176,.14)}
.assignment-search-btn:hover{background:linear-gradient(135deg,#4fb6b7,#409fa2)}
.assignment-search-clear{min-width:72px;min-height:50px;display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #d8e7e5;background:#fff;color:#60787b;text-decoration:none;font-size:13px;font-weight:800;white-space:nowrap}
@media(max-width:760px){.assignment-name-search-field{max-width:none;flex-wrap:wrap}.assignment-name-search-field input[type=search]{flex:1 1 100%}.assignment-search-btn,.assignment-search-clear{flex:1}}

/* ===== Caregiver cell refinement ===== */
.caregiver-profile-cell{display:flex!important;align-items:center!important;gap:12px!important;min-width:0}
.caregiver-avatar-mini{width:42px;height:42px;flex:0 0 42px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#e9f7f5,#dff2f0);border:1px solid #cce6e2;color:#236368;font-size:18px;font-weight:900;box-shadow:0 4px 10px rgba(36,108,115,.06)}
.caregiver-profile-copy{min-width:0;display:grid;gap:6px}
.caregiver-profile-name{color:#173f45;font-size:14px;font-weight:900;line-height:1.45;word-break:break-word}
.caregiver-profile-meta{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.caregiver-role-label{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#edf8f5;border:1px solid #d2e9e4;color:#2d6868;font-size:10.5px;font-weight:900;line-height:1}
.caregiver-account-label{color:#768b8d;font-size:11px;font-weight:700;line-height:1.3}
@media(max-width:760px){.caregiver-avatar-mini{width:38px;height:38px;flex-basis:38px;border-radius:12px}.caregiver-profile-name{font-size:13px}}
.assignment-status-pill,.assignment-status-pill.ended,.assignment-card-actions .compact-ended-label{box-shadow:none!important}

/* FINAL OVERRIDE: status should be text only */
.assignment-status-pill,
.assignment-status-pill.ended,
.assignment-card-actions .compact-ended-label{
    display:inline!important;
    min-height:0!important;
    height:auto!important;
    width:auto!important;
    padding:0!important;
    margin:0!important;
    border:0!important;
    outline:0!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
    white-space:nowrap!important;
}
.assignment-status-pill{color:#24604f!important;font-size:13px!important;font-weight:900!important}
.assignment-status-pill.ended{color:#a15252!important}
.assignment-card-actions .compact-ended-label{color:#758884!important;font-size:13px!important;font-weight:800!important}

</style>

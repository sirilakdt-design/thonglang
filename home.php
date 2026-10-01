<?php
require_once __DIR__ . '/connect.php';

requireLogin();
mysqli_set_charset($conn, 'utf8mb4');

function dashTableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function dashColumnExists(mysqli $conn, string $table, string $column): bool
{
    if (!dashTableExists($conn, $table)) return false;
    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function dashScalar(mysqli $conn, string $sql, array $params = [], string $types = ''): int
{
    if (!$params) {
        $res = mysqli_query($conn, $sql);
        if (!$res) return 0;
        $row = mysqli_fetch_row($res);
        return (int)($row[0] ?? 0);
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row[0] ?? 0);
}

function dashRows(mysqli $conn, string $sql, array $params = [], string $types = ''): array
{
    $rows = [];
    if (!$params) {
        $res = mysqli_query($conn, $sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}

function dashRoleCount(mysqli $conn, string $role): int
{
    if (!dashTableExists($conn, 'users')) return 0;
    return dashScalar($conn, "SELECT COUNT(*) FROM users WHERE role=?", [$role], 's');
}

function dashThaiDate(?string $value, bool $withTime = false): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $text = (int)date('j',$ts).' '.$months[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
    if ($withTime) $text .= ' '.date('H:i',$ts).' น.';
    return $text;
}

function dashAdlGroup($score): array
{
    if ($score === null || $score === '') return ['ยังไม่มีผล', 'neutral'];
    $score = (int)$score;
    if ($score >= 12) return ['ติดสังคม', 'good'];
    if ($score >= 5) return ['ติดบ้าน', 'warn'];
    return ['ติดเตียง', 'danger'];
}

function dashH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = currentRole();
if ($role === 'director') { header('Location: ' . appUrl('director/executive.php')); exit; }
$userId = (int)($_SESSION['user_id'] ?? 0);
$displayName = trim((string)($_SESSION['fullname'] ?? $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'ผู้ใช้งาน'));
$todayText = dashThaiDate(date('Y-m-d'));

$patientCount = dashTableExists($conn, 'patient') ? dashScalar($conn, "SELECT COUNT(*) FROM patient") : 0;
$villageCount = dashTableExists($conn, 'village') ? dashScalar($conn, "SELECT COUNT(*) FROM village") : 0;
$caregiverCount = dashRoleCount($conn, 'caregiver');
$doctorCount = dashRoleCount($conn, 'doctor');
if ($caregiverCount === 0 && dashTableExists($conn, 'caregiver')) {
    $caregiverCount = dashScalar($conn, "SELECT COUNT(*) FROM caregiver");
}

$hasAssignment = dashTableExists($conn, 'patient_caregiver_assignment');
$hasAdl = dashTableExists($conn, 'adl_assessment');

/* ตัวกรองรายเดือนสำหรับ Dashboard ผู้ดูแลระบบ */
$adminMonth = trim((string)($_GET['month'] ?? ''));
if ($adminMonth !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $adminMonth)) $adminMonth = '';
$adminMonthStart = $adminMonth !== '' ? $adminMonth . '-01' : '';
$adminMonthEnd = $adminMonth !== '' ? date('Y-m-d', strtotime($adminMonthStart . ' +1 month')) : '';
$adminMonthLabel = 'ทุกเดือน';
if ($adminMonth !== '') {
    $adminMonthNames = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
    $adminMonthLabel = ($adminMonthNames[(int)substr($adminMonth,5,2)] ?? '') . ' ' . ((int)substr($adminMonth,0,4) + 543);
}

/* ADL ล่าสุดของแต่ละคน ใช้ร่วมกับ Admin */
$adlGroups = ['ติดสังคม'=>0, 'ติดบ้าน'=>0, 'ติดเตียง'=>0];
if ($hasAdl && dashColumnExists($conn, 'adl_assessment', 'patient_id') && dashColumnExists($conn, 'adl_assessment', 'total_score')) {
    $adlPeriodWhere = '';
    $adlPeriodParams = [];
    $adlPeriodTypes = '';
    if (($role === 'admin' || $role === 'doctor') && $adminMonth !== '' && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
        $adlPeriodWhere = ' WHERE assessment_date >= ? AND assessment_date < ?';
        $adlPeriodParams = [$adminMonthStart, $adminMonthEnd];
        $adlPeriodTypes = 'ss';
    }
    $latest = dashRows($conn, "SELECT a.total_score
        FROM adl_assessment a
        INNER JOIN (
            SELECT patient_id, MAX(adl_id) latest_id
            FROM adl_assessment" . $adlPeriodWhere . "
            GROUP BY patient_id
        ) x ON x.latest_id=a.adl_id", $adlPeriodParams, $adlPeriodTypes);
    foreach ($latest as $r) {
        [$g] = dashAdlGroup($r['total_score'] ?? null);
        if (isset($adlGroups[$g])) $adlGroups[$g]++;
    }
}
$adlTotal = array_sum($adlGroups);

/* ========================== ADMIN ========================== */
$adminUnassigned = 0;
$adminIncomplete = 0;
$adminVillageRows = [];
$adminRecentPatients = [];

if ($role === 'admin' || $role === 'doctor') {
    /* เมื่อเลือกเดือน ให้ทุก KPI/กราฟอิงผู้สูงอายุที่มีผล ADL ภายในเดือนนั้น */
    if ($adminMonth !== '' && $hasAdl && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
        $patientCount = dashScalar($conn, "SELECT COUNT(DISTINCT patient_id) FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ?", [$adminMonthStart,$adminMonthEnd], 'ss');

        if (dashColumnExists($conn, 'adl_assessment', 'caregiver_user_id')) {
            $caregiverCount = dashScalar($conn, "SELECT COUNT(DISTINCT caregiver_user_id) FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ? AND caregiver_user_id IS NOT NULL AND caregiver_user_id<>0", [$adminMonthStart,$adminMonthEnd], 'ss');
        }
        if (dashColumnExists($conn, 'adl_assessment', 'doctor_user_id')) {
            $doctorCount = dashScalar($conn, "SELECT COUNT(DISTINCT doctor_user_id) FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ? AND doctor_user_id IS NOT NULL AND doctor_user_id<>0", [$adminMonthStart,$adminMonthEnd], 'ss');
        }
        if (dashTableExists($conn, 'patient')) {
            $villageCount = dashScalar($conn, "SELECT COUNT(DISTINCT p.Village_id)
                FROM patient p JOIN (SELECT DISTINCT patient_id FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ?) m ON m.patient_id=p.Patient_id
                WHERE p.Village_id IS NOT NULL", [$adminMonthStart,$adminMonthEnd], 'ss');
        }
    }

    if ($hasAssignment) {
        if ($adminMonth !== '' && $hasAdl && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
            $adminUnassigned = dashScalar($conn, "SELECT COUNT(DISTINCT p.Patient_id)
                FROM patient p
                JOIN (SELECT DISTINCT patient_id FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ?) m ON m.patient_id=p.Patient_id
                LEFT JOIN patient_caregiver_assignment a ON a.patient_id=p.Patient_id
                WHERE a.assignment_id IS NULL", [$adminMonthStart,$adminMonthEnd], 'ss');
        } else {
            $adminUnassigned = dashScalar($conn, "SELECT COUNT(*)
                FROM patient p
                LEFT JOIN patient_caregiver_assignment a ON a.patient_id=p.Patient_id
                WHERE a.assignment_id IS NULL");
        }
    } else {
        $adminUnassigned = $patientCount;
    }

    if (dashTableExists($conn, 'patient')) {
        $parts = [];
        if (dashColumnExists($conn, 'patient', 'Phone')) $parts[] = "Phone IS NULL OR TRIM(Phone)=''";
        if (dashColumnExists($conn, 'patient', 'Latitude')) $parts[] = "Latitude IS NULL";
        if (dashColumnExists($conn, 'patient', 'Longitude')) $parts[] = "Longitude IS NULL";
        if ($parts) {
            if ($adminMonth !== '' && $hasAdl && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
                $adminIncomplete = dashScalar($conn, "SELECT COUNT(DISTINCT p.Patient_id) FROM patient p
                    JOIN (SELECT DISTINCT patient_id FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ?) m ON m.patient_id=p.Patient_id
                    WHERE (".implode(' OR ', array_map(fn($x) => 'p.'.$x, $parts)).")", [$adminMonthStart,$adminMonthEnd], 'ss');
            } else {
                $adminIncomplete = dashScalar($conn, "SELECT COUNT(*) FROM patient WHERE ".implode(' OR ', $parts));
            }
        }
    }

    if (dashTableExists($conn, 'village') && dashTableExists($conn, 'patient')) {
        if ($adminMonth !== '' && $hasAdl && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
            $adminVillageRows = dashRows($conn, "SELECT v.village_id, v.villagename, COUNT(DISTINCT m.patient_id) total
                FROM village v
                LEFT JOIN patient p ON p.Village_id=v.village_id
                LEFT JOIN (SELECT DISTINCT patient_id FROM adl_assessment WHERE assessment_date >= ? AND assessment_date < ?) m ON m.patient_id=p.Patient_id
                GROUP BY v.village_id,v.villagename
                ORDER BY v.village_id ASC", [$adminMonthStart,$adminMonthEnd], 'ss');
        } else {
            $adminVillageRows = dashRows($conn, "SELECT v.village_id, v.villagename, COUNT(p.Patient_id) total
                FROM village v
                LEFT JOIN patient p ON p.Village_id=v.village_id
                GROUP BY v.village_id,v.villagename
                ORDER BY v.village_id ASC");
        }
    }

    if (dashTableExists($conn, 'patient')) {
        $adminRecentPatients = dashRows($conn, "SELECT Patient_id, Fullname, Age, Gender, Disease
            FROM patient ORDER BY Patient_id DESC LIMIT 6");
    }
}

/* หมอ */
$doctorAssessed = 0;
$doctorAdlTotal = 0;
$doctorUnassessed = $patientCount;
$doctorAssigned = 0;
$doctorUnassigned = $patientCount;
$doctorRecentAdl = [];
$doctorRecentAssignments = [];
$doctorTotalAssignments = 0;
$doctorActiveCaregivers = 0;
$doctorCoverage = 0;
$doctorVisitTotal = 0;
$doctorVisitReferral = 0;
$doctorRecentVisits = [];

if ($role === 'doctor') {
    $doctorMonthStart = $adminMonthStart;
    $doctorMonthEnd = $adminMonthEnd;
    $doctorHasMonth = $adminMonth !== '';

    if ($hasAdl && dashColumnExists($conn, 'adl_assessment', 'doctor_user_id')) {
        $doctorAdlWhere = "doctor_user_id=?";
        $doctorAdlParams = [$userId];
        $doctorAdlTypes = 'i';
        if ($doctorHasMonth && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
            $doctorAdlWhere .= " AND assessment_date>=? AND assessment_date<?";
            $doctorAdlParams[] = $doctorMonthStart;
            $doctorAdlParams[] = $doctorMonthEnd;
            $doctorAdlTypes .= 'ss';
        }

        $doctorAssessed = dashScalar($conn,
            "SELECT COUNT(DISTINCT patient_id) FROM adl_assessment WHERE {$doctorAdlWhere}",
            $doctorAdlParams, $doctorAdlTypes);
        $doctorAdlTotal = dashScalar($conn,
            "SELECT COUNT(*) FROM adl_assessment WHERE {$doctorAdlWhere}",
            $doctorAdlParams, $doctorAdlTypes);

        $doctorRecentAdl = dashRows($conn, "SELECT a.adl_id,a.patient_id,a.assessment_date,a.total_score,p.Fullname
            FROM adl_assessment a
            JOIN patient p ON p.Patient_id=a.patient_id
            WHERE a.{$doctorAdlWhere}
            ORDER BY COALESCE(a.assessment_date,'1900-01-01') DESC,a.adl_id DESC
            LIMIT 6", $doctorAdlParams, $doctorAdlTypes);
    }

    if ($hasAssignment) {
        $doctorAssignedAll = dashScalar($conn,
            "SELECT COUNT(DISTINCT patient_id) FROM patient_caregiver_assignment WHERE doctor_user_id=?",
            [$userId], 'i');

        $doctorAssignWhere = "doctor_user_id=?";
        $doctorAssignParams = [$userId];
        $doctorAssignTypes = 'i';
        if ($doctorHasMonth && dashColumnExists($conn, 'patient_caregiver_assignment', 'assigned_at')) {
            $doctorAssignWhere .= " AND assigned_at>=? AND assigned_at<?";
            $doctorAssignParams[] = $doctorMonthStart;
            $doctorAssignParams[] = $doctorMonthEnd;
            $doctorAssignTypes .= 'ss';
        }
        $doctorAssigned = dashScalar($conn,
            "SELECT COUNT(DISTINCT patient_id) FROM patient_caregiver_assignment WHERE {$doctorAssignWhere}",
            $doctorAssignParams, $doctorAssignTypes);

        if ($doctorHasMonth && $hasAdl && dashColumnExists($conn, 'adl_assessment', 'assessment_date')) {
            $doctorUnassigned = dashScalar($conn, "SELECT COUNT(DISTINCT aa.patient_id)
                FROM adl_assessment aa
                LEFT JOIN patient_caregiver_assignment a ON a.patient_id=aa.patient_id
                WHERE aa.doctor_user_id=? AND aa.assessment_date>=? AND aa.assessment_date<? AND a.assignment_id IS NULL",
                [$userId,$doctorMonthStart,$doctorMonthEnd], 'iss');
        } else {
            $doctorUnassigned = dashScalar($conn, "SELECT COUNT(*)
                FROM patient p
                LEFT JOIN patient_caregiver_assignment a ON a.patient_id=p.Patient_id
                WHERE a.assignment_id IS NULL");
        }

        $doctorTotalAssignments = dashScalar($conn, "SELECT COUNT(*) FROM patient_caregiver_assignment");
        $doctorActiveCaregivers = dashScalar($conn, "SELECT COUNT(DISTINCT caregiver_user_id) FROM patient_caregiver_assignment WHERE caregiver_user_id IS NOT NULL AND caregiver_user_id<>0");
        $doctorCoverage = $patientCount > 0 ? min(100, (int)round(($doctorTotalAssignments / $patientCount) * 100)) : 0;

        $doctorRecentAssignments = dashRows($conn, "SELECT a.assigned_at,a.care_status,p.Fullname,
                cg.display_name caregiver_name,cg.username caregiver_username
            FROM patient_caregiver_assignment a
            JOIN patient p ON p.Patient_id=a.patient_id
            LEFT JOIN users cg ON cg.user_id=a.caregiver_user_id
            WHERE {$doctorAssignWhere}
            ORDER BY a.assigned_at DESC LIMIT 6", $doctorAssignParams, $doctorAssignTypes);

        if (dashTableExists($conn, 'caregiver_visit_record')) {
            $doctorVisitWhere = "a.doctor_user_id=?";
            $doctorVisitParams = [$userId];
            $doctorVisitTypes = 'i';
            if ($doctorHasMonth && dashColumnExists($conn, 'caregiver_visit_record', 'visit_date')) {
                $doctorVisitWhere .= " AND vr.visit_date>=? AND vr.visit_date<?";
                $doctorVisitParams[] = $doctorMonthStart;
                $doctorVisitParams[] = $doctorMonthEnd;
                $doctorVisitTypes .= 'ss';
            }

            $doctorVisitTotal = dashScalar($conn, "SELECT COUNT(*)
                FROM caregiver_visit_record vr
                JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
                WHERE {$doctorVisitWhere}", $doctorVisitParams, $doctorVisitTypes);
            $doctorVisitReferral = dashScalar($conn, "SELECT COUNT(*)
                FROM caregiver_visit_record vr
                JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
                WHERE {$doctorVisitWhere} AND vr.doctor_referral=1", $doctorVisitParams, $doctorVisitTypes);
            $doctorRecentVisits = dashRows($conn, "SELECT vr.visit_date,vr.visit_time,vr.visit_type,vr.general_condition,vr.doctor_referral,vr.referral_type,
                    p.Fullname,cg.display_name caregiver_name,cg.username caregiver_username
                FROM caregiver_visit_record vr
                JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
                JOIN patient p ON p.Patient_id=vr.patient_id
                LEFT JOIN users cg ON cg.user_id=vr.caregiver_user_id
                WHERE {$doctorVisitWhere}
                ORDER BY vr.visit_date DESC,COALESCE(vr.visit_time,'00:00:00') DESC,vr.visit_id DESC
                LIMIT 6", $doctorVisitParams, $doctorVisitTypes);
        }

        $doctorUnassessed = max(0, $doctorAssignedAll - $doctorAssessed);
    } else {
        $doctorUnassessed = max(0, $patientCount - $doctorAssessed);
    }
}

/* แคร์กิฟเวอร์ */
$cgAssigned = 0;
$cgActive = 0;
$cgPaused = 0;
$cgEnded = 0;
$cgPendingAdl = 0;
$cgCompletedToday = 0;
$cgPatients = [];

$cgVisitTotal = 0;
$cgAdlCompleted = 0;
if ($role === 'caregiver') {
    $cgHasMonth = $adminMonth !== '';
    if ($hasAssignment) {
        $assignWhere = "caregiver_user_id=?";
        $assignParams = [$userId];
        $assignTypes = 'i';
        if ($cgHasMonth && dashColumnExists($conn, 'patient_caregiver_assignment', 'assigned_at')) {
            $assignWhere .= " AND assigned_at>=? AND assigned_at<?";
            $assignParams[] = $adminMonthStart; $assignParams[] = $adminMonthEnd; $assignTypes .= 'ss';
        }
        $cgAssigned = dashScalar($conn, "SELECT COUNT(*) FROM patient_caregiver_assignment WHERE {$assignWhere}", $assignParams, $assignTypes);
        $cgActive = dashScalar($conn, "SELECT COUNT(*) FROM patient_caregiver_assignment WHERE caregiver_user_id=? AND care_status='กำลังดูแล'", [$userId], 'i');
        $cgPaused = dashScalar($conn, "SELECT COUNT(*) FROM patient_caregiver_assignment WHERE caregiver_user_id=? AND care_status='พักการดูแล'", [$userId], 'i');
        $cgEnded = dashScalar($conn, "SELECT COUNT(*) FROM patient_caregiver_assignment WHERE caregiver_user_id=? AND care_status='สิ้นสุดการดูแล'", [$userId], 'i');

        $adlJoin = $hasAdl
            ? "LEFT JOIN adl_assessment adl ON adl.adl_id=(SELECT MAX(a2.adl_id) FROM adl_assessment a2 WHERE a2.patient_id=p.Patient_id)"
            : "";
        $adlSelect = $hasAdl ? ", adl.total_score, adl.assessment_date" : ", NULL total_score, NULL assessment_date";
        $patientWhere = "a.caregiver_user_id=?";
        $patientParams = [$userId]; $patientTypes = 'i';
        if ($cgHasMonth && dashColumnExists($conn, 'patient_caregiver_assignment', 'assigned_at')) {
            $patientWhere .= " AND a.assigned_at>=? AND a.assigned_at<?";
            $patientParams[]=$adminMonthStart; $patientParams[]=$adminMonthEnd; $patientTypes.='ss';
        }
        $cgPatients = dashRows($conn, "SELECT a.assignment_id,a.assigned_at,a.care_status,p.Patient_id,p.Fullname,p.Age,p.Gender,p.Phone,p.Disease,p.Latitude,p.Longitude,v.villagename
                {$adlSelect}
            FROM patient_caregiver_assignment a
            JOIN patient p ON p.Patient_id=a.patient_id
            LEFT JOIN village v ON v.village_id=p.Village_id
            {$adlJoin}
            WHERE {$patientWhere}
            ORDER BY COALESCE(adl.assessment_date, a.assigned_at) DESC,a.assignment_id DESC
            LIMIT 8", $patientParams, $patientTypes);

        if (dashTableExists($conn, 'caregiver_visit_record')) {
            $visitWhere = "a.caregiver_user_id=?"; $visitParams=[$userId]; $visitTypes='i';
            if ($cgHasMonth && dashColumnExists($conn, 'caregiver_visit_record', 'visit_date')) {
                $visitWhere .= " AND vr.visit_date>=? AND vr.visit_date<?";
                $visitParams[]=$adminMonthStart; $visitParams[]=$adminMonthEnd; $visitTypes.='ss';
            }
            $cgVisitTotal = dashScalar($conn, "SELECT COUNT(*) FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id WHERE {$visitWhere}", $visitParams, $visitTypes);
        }
    }

    if ($hasAdl && dashColumnExists($conn, 'adl_assessment', 'caregiver_user_id')) {
        $adlCgWhere="caregiver_user_id=?"; $adlCgParams=[$userId]; $adlCgTypes='i';
        $dateCol = dashColumnExists($conn, 'adl_assessment', 'caregiver_completed_at') ? 'caregiver_completed_at' : (dashColumnExists($conn, 'adl_assessment', 'assessment_date') ? 'assessment_date' : '');
        if ($cgHasMonth && $dateCol!=='') { $adlCgWhere .= " AND {$dateCol}>=? AND {$dateCol}<?"; $adlCgParams[]=$adminMonthStart; $adlCgParams[]=$adminMonthEnd; $adlCgTypes.='ss'; }
        if (dashColumnExists($conn, 'adl_assessment', 'handoff_status')) {
            $cgPendingAdl = dashScalar($conn, "SELECT COUNT(*) FROM adl_assessment WHERE caregiver_user_id=? AND handoff_status='รอแคร์กิฟเวอร์ประเมินต่อ'", [$userId], 'i');
        }
        $cgAdlCompleted = dashScalar($conn, "SELECT COUNT(*) FROM adl_assessment WHERE {$adlCgWhere}", $adlCgParams, $adlCgTypes);
        if (dashColumnExists($conn, 'adl_assessment', 'caregiver_completed_at')) {
            $cgCompletedToday = dashScalar($conn, "SELECT COUNT(*) FROM adl_assessment WHERE caregiver_user_id=? AND DATE(caregiver_completed_at)=CURDATE()", [$userId], 'i');
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ภาพรวม | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.dashboard-page .main{padding-bottom:42px}
.caregiver-date-only{justify-content:flex-end;width:100%}
.caregiver-date-only .dash-date{margin-left:auto;align-self:flex-end}
.dash-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:0 0 20px;padding:0 2px}
.dash-heading h1{margin:0 0 6px;font-size:26px;color:#24494D}
.dash-heading p{margin:0;color:#71898C;font-size:13px}
.dash-date{padding:9px 13px;border:1px solid #D4E9E7;border-radius:12px;background:#fff;color:#527276;font-size:12px;white-space:nowrap}
.dash-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.dash-kpi{position:relative;overflow:hidden;min-height:126px;padding:19px 20px;border:1px solid #D5E9E7;border-radius:18px;background:#fff;box-shadow:0 9px 24px rgba(55,118,121,.07)}
.dash-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:#65C8C2}
.dash-kpi.warn:before{background:#E8C86A}.dash-kpi.danger:before{background:#DD8C8C}.dash-kpi.blue:before{background:#86C9E5}
.dash-kpi-label{font-size:13px;color:#647D80;margin-bottom:8px}.dash-kpi-value{font-size:31px;line-height:1;font-weight:800;color:#24494D;margin-bottom:9px}.dash-kpi-note{font-size:11px;line-height:1.55;color:#8A9C9E}
.dash-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px;margin-bottom:18px}
.dash-panel{overflow:hidden;border:1px solid #D5E9E7;border-radius:20px;background:#fff;box-shadow:0 10px 28px rgba(55,118,121,.07)}
.dash-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;background:#DDF5F1;border-bottom:1px solid #CDE8E4}.dash-panel-head h2{margin:0;font-size:17px;color:#1F555A!important}.dash-panel-head a{font-size:12px;color:#246C73;text-decoration:none;font-weight:700}.dash-panel-body{padding:16px 18px}
.quick-list{display:grid;gap:10px}.quick-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 13px;border:1px solid #E0EEEC;border-radius:13px;background:#FBFDFD}.quick-item strong{display:block;color:#284F53;font-size:13px}.quick-item small{display:block;color:#829598;margin-top:3px}.quick-count{font-size:20px;font-weight:800;color:#246C73;white-space:nowrap}
.dash-table-wrap{overflow:auto}.dash-table{width:100%;border-collapse:collapse;min-width:680px}.dash-table th,.dash-table td{padding:11px 12px;border-bottom:1px solid #E8F0EF;text-align:left;vertical-align:middle;font-size:12px}.dash-table th{background:#F3FAF8;color:#42686C;white-space:nowrap}.dash-table tr:last-child td{border-bottom:0}.dash-table td{color:#425E61}
.status{display:inline-block;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#EEF3F3;color:#567174}.status.good{background:#E3F5EC;color:#35775E}.status.warn{background:#FFF4D8;color:#8D6E1E}.status.danger{background:#FBE8E8;color:#A45C5C}.status.blue{background:#E7F3FA;color:#477B98}
.action-row{display:flex;gap:8px;flex-wrap:wrap}.mini-btn{display:inline-block;padding:7px 10px;border:1px solid #CFE6E3;border-radius:9px;background:#F2FAF8;color:#246C73;text-decoration:none;font-size:11px;font-weight:700}.mini-btn:hover{background:#E2F5F1}.mini-btn.map{background:#EEF7FB;border-color:#D5EAF4;color:#39708F}
.empty-box{padding:28px 16px;text-align:center;color:#829598;font-size:12px}
.adl-summary{display:grid;gap:10px}.adl-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:12px;border:1px solid #E0EEEC;border-radius:13px}.adl-row span{font-size:13px;color:#536F72}.adl-row strong{font-size:19px;color:#2C5B5F}.notice{padding:13px 15px;border:1px solid #F0DFAD;border-radius:13px;background:#FFF9E8;color:#705D2A;font-size:12px;line-height:1.55;margin-bottom:18px}

.doctor-hero-shell{display:grid;gap:18px;margin-bottom:18px}
.doctor-hero-card{display:grid;grid-template-columns:1fr;gap:18px;align-items:center;padding:26px 28px;border-radius:28px;border:1px solid #d6ece9;background:linear-gradient(135deg,#eef9fb 0%,#f6fcfc 48%,#eaf8f5 100%);box-shadow:0 20px 48px rgba(36,108,115,.08);overflow:hidden;position:relative}
.doctor-hero-card:before{content:"";position:absolute;right:-60px;top:-50px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle at center,rgba(88,191,192,.16) 0%,rgba(88,191,192,0) 68%)}
.doctor-hero-copy,.doctor-hero-actions{position:relative;z-index:1}
.doctor-hero-kicker{display:inline-block;font-size:12px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#6b8b86;margin-bottom:10px}
.doctor-hero-copy h2{margin:0 0 8px;color:#173f45;font-size:34px;line-height:1.12;letter-spacing:-.4px}
.doctor-hero-copy p{margin:0;color:#68817d;font-size:15px;line-height:1.65;max-width:920px}
.doctor-hero-tags{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.doctor-hero-tag{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:12px;font-weight:800;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.doctor-hero-tag.success{background:#eef9f4;color:#24624e;border-color:#d3eadf}
.doctor-hero-actions{display:grid;gap:12px;justify-items:end}
.doctor-coverage-box{min-width:220px;padding:18px 18px 16px;border-radius:22px;border:1px solid #d6ebe7;background:rgba(255,255,255,.88);box-shadow:0 10px 24px rgba(36,108,115,.06);text-align:left}
.doctor-coverage-label{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#75908c}
.doctor-coverage-value{margin-top:6px;font-size:38px;line-height:1;font-weight:900;color:#1c5960}
.doctor-coverage-sub{margin-top:7px;font-size:13px;color:#6b8580;line-height:1.45}
.doctor-hero-actions .mini-btn{min-height:48px;padding:11px 18px;border-radius:14px;background:linear-gradient(135deg,#59bfc0 0%,#4aaeb0 100%);border-color:transparent;color:#fff;box-shadow:0 14px 26px rgba(88,191,192,.20)}
.doctor-hero-actions .mini-btn:hover{background:linear-gradient(135deg,#4db5b6 0%,#419fa1 100%)}
.doctor-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.doctor-kpi-card{position:relative;padding:18px 18px 16px;border-radius:22px;background:#fff;border:1px solid #d8ece8;box-shadow:0 12px 28px rgba(36,108,115,.06);overflow:hidden}
.doctor-kpi-card:before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#58bfc0,#9adfdc)}
.doctor-kpi-card.active:before{background:linear-gradient(90deg,#4bb9a7,#7ad4be)}
.doctor-kpi-card.waiting:before{background:linear-gradient(90deg,#f0b25e,#f6d39d)}
.doctor-kpi-card.info:before{background:linear-gradient(90deg,#7db9df,#9fd4ed)}
.doctor-kpi-label{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#77908b}
.doctor-kpi-value{margin-top:8px;font-size:34px;line-height:1;font-weight:900;color:#1a565c}
.doctor-kpi-sub{margin-top:8px;font-size:13px;color:#6f8782;line-height:1.45}

.admin-modern-dashboard{display:grid;gap:18px}
.admin-modern-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;padding:24px 26px;border:1px solid #d6ebe7;border-radius:28px;background:linear-gradient(135deg,#ffffff 0%,#eef9f7 100%);box-shadow:0 18px 38px rgba(36,108,115,.08)}
.admin-modern-pill{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 15px;border-radius:999px;background:#e4f6f3;color:#1d6668;font-size:12px;font-weight:900;margin-bottom:10px}
.admin-modern-hero h1{margin:0;color:#214f54;font-size:34px;line-height:1.08}
.admin-modern-hero p{margin:10px 0 0;max-width:760px;color:#6e878b;font-size:14px;line-height:1.7}
.admin-modern-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.admin-modern-date{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 16px;border-radius:12px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:12px;font-weight:900;box-shadow:0 8px 18px rgba(36,108,115,.05)}
.admin-modern-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:12px;text-decoration:none;font-size:13px;font-weight:900;border:1px solid transparent}
.admin-modern-btn.primary{background:linear-gradient(135deg,#20AFA6 0%,#68ccc7 100%);color:#fff;box-shadow:0 14px 26px rgba(32,175,166,.18)}
.admin-modern-btn.secondary{background:#fff;color:#246c73;border-color:#d7ebe8}
.admin-modern-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.admin-modern-stat-card{position:relative;overflow:hidden;min-height:126px;padding:20px 22px 18px;border:1px solid #d6ebe7;border-radius:22px;background:#fff;box-shadow:0 12px 28px rgba(36,108,115,.07);display:flex;flex-direction:column;justify-content:center}
.admin-modern-stat-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,#20AFA6 0%,#68ccc7 100%)}
.admin-modern-stat-card.blue::before{background:linear-gradient(180deg,#7dc7e6 0%,#b4e3f2 100%)}
.admin-modern-stat-card.warn::before{background:linear-gradient(180deg,#efcc74 0%,#f3dfac 100%)}
.admin-modern-stat-card.danger::before{background:linear-gradient(180deg,#e59a9a 0%,#f2c8c8 100%)}
.admin-modern-stat-card .label{font-size:13px;color:#6f878b;font-weight:800;line-height:1.45;min-height:0}
.admin-modern-stat-card .value{margin-top:14px;font-size:38px;line-height:1;font-weight:900;color:#214f54}
.admin-modern-stat-card .unit{margin-top:6px;font-size:11px;color:#8ca2a4;font-weight:700}
.admin-modern-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(390px,1fr);gap:20px;align-items:stretch}
.admin-modern-side{display:grid;gap:18px;height:100%}
.admin-modern-bottom{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px}
.admin-modern-panel{overflow:hidden;border:1px solid #d6ebe7;border-radius:24px;background:#fff;box-shadow:0 14px 30px rgba(36,108,115,.07);height:100%}
.admin-modern-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e5f0ee;background:linear-gradient(180deg,#f8fdfd 0%,#eef9f7 100%)}
.admin-modern-panel-head h2{margin:0;font-size:17px;color:#1f5459}.admin-modern-panel-head a{font-size:12px;color:#246c73;text-decoration:none;font-weight:900}
.admin-modern-panel-body{padding:20px}
.admin-modern-village-bars{display:grid;gap:12px}
.admin-modern-village-row{display:grid;grid-template-columns:150px 1fr 34px;gap:12px;align-items:center}
.admin-modern-village-name{font-size:13px;font-weight:800;color:#254f54;line-height:1.35}
.admin-modern-track{height:12px;border-radius:999px;background:#e8f3f2;overflow:hidden}.admin-modern-track i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#20AFA6 0%,#68ccc7 100%)}
.admin-modern-village-value{text-align:right;font-size:12px;font-weight:900;color:#246c73}
.admin-modern-ring-layout{display:grid;grid-template-columns:180px 1fr;gap:22px;align-items:center;min-height:350px}
.admin-modern-ring{position:relative;width:168px;height:168px;border-radius:50%;margin:0 auto}
.admin-modern-ring::after{content:"";position:absolute;inset:20px;border-radius:50%;background:#fff;box-shadow:inset 0 0 0 1px #e1efed}
.admin-modern-ring-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1;text-align:center}.admin-modern-ring-center strong{font-size:32px;line-height:1;color:#214f54}.admin-modern-ring-center span{margin-top:4px;font-size:12px;color:#6f888b;font-weight:800}
.admin-modern-legend{display:grid;gap:10px}
.admin-modern-legend-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e5f0ee;border-radius:14px;background:#fbfefe}.admin-modern-legend-item i{width:11px;height:11px;border-radius:50%;margin-top:4px;flex:0 0 auto}.admin-modern-legend-item strong{display:block;font-size:13px;color:#214f54}.admin-modern-legend-item span{display:block;margin-top:2px;font-size:12px;color:#6f888b}
.admin-modern-shortcuts{display:grid;gap:10px}.admin-modern-shortcut{display:block;padding:14px 15px;border:1px solid #e2efed;border-radius:16px;background:#fbfefe;text-decoration:none}.admin-modern-shortcut strong{display:block;font-size:14px;color:#214f54}.admin-modern-shortcut span{display:block;margin-top:6px;font-size:12px;color:#6f888b;line-height:1.5}.admin-modern-shortcut:hover{background:#f4fbfa}
.admin-modern-patient-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.admin-modern-patient-card{padding:15px 16px;border:1px solid #e2efed;border-radius:16px;background:#fbfefe}.admin-modern-patient-card strong{display:block;font-size:14px;color:#214f54;line-height:1.45}.admin-modern-patient-card span{display:block;margin-top:6px;font-size:12px;color:#6f888b;line-height:1.5}
.admin-modern-mini-grid{display:grid;gap:12px}.admin-modern-mini-card{padding:16px;border:1px solid #e2efed;border-radius:18px;background:#fbfefe}.admin-modern-mini-card .mini-label{font-size:12px;color:#6f888b;font-weight:800;line-height:1.45}.admin-modern-mini-card .mini-value{margin-top:8px;font-size:30px;font-weight:900;line-height:1;color:#214f54}.admin-modern-mini-card .mini-note{margin-top:6px;font-size:12px;color:#6f888b;line-height:1.55}
.admin-modern-empty{padding:28px 16px;text-align:center;color:#819596;font-size:12px;border:1px dashed #d6ebe7;border-radius:16px;background:#fbfefe}
@media(max-width:1320px){.admin-modern-stats{grid-template-columns:repeat(4,minmax(0,1fr))}.admin-modern-grid{grid-template-columns:minmax(0,1.3fr) minmax(340px,1fr)}.admin-modern-bottom,.admin-modern-ring-layout{grid-template-columns:1fr}.admin-modern-ring-layout{min-height:0}.admin-modern-patient-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1050px){.admin-modern-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-modern-grid{grid-template-columns:1fr}.admin-modern-panel{height:auto}.admin-modern-ring-layout{grid-template-columns:180px 1fr;min-height:0}}
@media(max-width:760px){.admin-modern-hero{padding:20px}.admin-modern-hero h1{font-size:28px}.admin-modern-stats,.admin-modern-patient-grid{grid-template-columns:1fr}.admin-modern-village-row{grid-template-columns:120px 1fr 28px}}

.doctor-dashboard-clean{display:grid;gap:18px}.doctor-date-only{display:flex;justify-content:flex-end;align-items:center}.doctor-date-pill{display:inline-flex;align-items:center;min-height:42px;padding:0 16px;border-radius:999px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:13px;font-weight:900;box-shadow:0 8px 18px rgba(36,108,115,.05)}.doctor-summary-row{display:grid;grid-template-columns:1fr;gap:18px}.doctor-clean-grid-single{grid-template-columns:1fr!important}.doctor-clean-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(320px,.92fr);gap:18px}.doctor-clean-panel{overflow:hidden;border:1px solid #d6ebe7;border-radius:24px;background:#fff;box-shadow:0 12px 30px rgba(36,108,115,.06)}.doctor-clean-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e5f0ee;background:linear-gradient(180deg,#f8fdfd 0%,#f2faf9 100%)}.doctor-clean-head h2{margin:0;color:#1f5459;font-size:18px}.doctor-clean-head a{color:#246c73;text-decoration:none;font-weight:800;font-size:12px}.doctor-clean-body{padding:16px 18px}.doctor-record-list{display:grid;gap:12px}.doctor-record-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:14px 15px;border:1px solid #e1efed;border-radius:16px;background:#fbfefe}.doctor-record-main{min-width:0}.doctor-record-title{font-size:16px;font-weight:900;color:#1f5054;line-height:1.4}.doctor-record-meta{margin-top:5px;font-size:12px;color:#738884;line-height:1.65}.doctor-record-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}.doctor-record-score{display:inline-flex;align-items:center;justify-content:center;min-width:88px;min-height:52px;padding:8px 14px;border-radius:16px;background:linear-gradient(135deg,#e9f8f6,#f5fbfb);box-shadow:inset 0 0 0 1px #d0e9e5;color:#1f5d5c;font-size:26px;font-weight:900;line-height:1}.doctor-record-side{display:grid;gap:8px;justify-items:end}.doctor-record-status{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 12px;border-radius:999px;background:#eef8f7;border:1px solid #d7ebe8;color:#296465;font-size:12px;font-weight:900;white-space:nowrap}.doctor-mini-list{display:grid;gap:10px}.doctor-mini-item{padding:13px 14px;border:1px solid #e2efed;border-radius:16px;background:#fbfefe}.doctor-mini-top{display:flex;align-items:center;justify-content:space-between;gap:12px}.doctor-mini-title{font-size:15px;font-weight:900;color:#215258}.doctor-mini-meta{margin-top:6px;color:#758986;font-size:12px;line-height:1.65}.doctor-mini-badge{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:7px 12px;border-radius:999px;background:#fff;border:1px solid #d6e8e5;color:#2a6668;font-size:11px;font-weight:900}.doctor-quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.doctor-quick-card{padding:15px 16px;border:1px solid #e0eeec;border-radius:18px;background:#fbfefe}.doctor-quick-label{font-size:12px;font-weight:800;color:#77908b;text-transform:uppercase;letter-spacing:.06em}.doctor-quick-value{margin-top:8px;font-size:28px;font-weight:900;color:#1b575d;line-height:1}.doctor-quick-note{margin-top:6px;font-size:12px;color:#6f8782;line-height:1.55}.doctor-summary-table{width:100%;border-collapse:collapse;min-width:0}.doctor-summary-table th,.doctor-summary-table td{padding:12px 13px;border-bottom:1px solid #e6efee;text-align:left;vertical-align:middle;font-size:12px}.doctor-summary-table th{background:#f3faf8;color:#42686c;font-weight:900}.doctor-summary-table td{color:#425e61}.doctor-summary-table tr:last-child td{border-bottom:0}.doctor-summary-table .summary-value{text-align:right;font-size:16px;font-weight:900;color:#1b575d;white-space:nowrap}.doctor-summary-table .summary-note{color:#738884;font-size:11px;line-height:1.5}.doctor-summary-table .summary-label{font-weight:800;color:#274f54}.doctor-summary-table .date-value{font-size:13px;font-weight:900;color:#24575c;white-space:nowrap}.doctor-empty{padding:30px 16px;border:1px dashed #d8e9e6;border-radius:16px;background:#fbfefe;text-align:center;color:#7f9490;font-size:12px}.doctor-shortcuts{display:flex;flex-wrap:wrap;gap:10px}.doctor-shortcut{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:13px;background:#fff;border:1px solid #d4e8e5;color:#246c73;text-decoration:none;font-size:13px;font-weight:900}.doctor-shortcut.primary{background:linear-gradient(135deg,#58bfc0 0%,#4aaeb0 100%);border-color:transparent;color:#fff;box-shadow:0 14px 26px rgba(88,191,192,.18)}.doctor-shortcut:hover{background:#f2fbf8}.doctor-shortcut.primary:hover{background:linear-gradient(135deg,#4db5b6 0%,#419fa1 100%)}

.caregiver-dashboard{display:grid;gap:18px}
.caregiver-hero{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(320px,.7fr);gap:18px;padding:24px 26px;border:1px solid #d6ece9;border-radius:28px;background:linear-gradient(135deg,#effaf8 0%,#f7fcfc 52%,#edf8fb 100%);box-shadow:0 20px 48px rgba(36,108,115,.08);position:relative;overflow:hidden}
.caregiver-hero:before{content:'';position:absolute;right:-80px;top:-80px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle at center,rgba(88,191,192,.14) 0%,rgba(88,191,192,0) 70%)}
.caregiver-hero-main,.caregiver-hero-side{position:relative;z-index:1}
.caregiver-kicker{display:inline-block;font-size:12px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#6d8b87;margin-bottom:10px}
.caregiver-hero h1{margin:0 0 10px;font-size:34px;line-height:1.14;letter-spacing:-.4px;color:#173f45}
.caregiver-hero p{margin:0;color:#68817d;font-size:15px;line-height:1.68;max-width:760px}
.caregiver-hero-tags{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.caregiver-hero-tag{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:12px;font-weight:800;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.caregiver-hero-tag.pending{background:#fff8eb;color:#966b1f;border-color:#f0dcc0}
.caregiver-hero-side{display:grid;gap:12px;align-content:start}
.caregiver-highlight{padding:18px 18px 16px;border-radius:22px;background:rgba(255,255,255,.88);border:1px solid #d6ebe7;box-shadow:0 10px 24px rgba(36,108,115,.06)}
.caregiver-highlight-label{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#75908c}
.caregiver-highlight-value{margin-top:6px;font-size:38px;line-height:1;font-weight:900;color:#1c5960}
.caregiver-highlight-sub{margin-top:7px;font-size:13px;color:#6b8580;line-height:1.5}
.caregiver-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.caregiver-action-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:11px 14px;border-radius:14px;text-decoration:none;font-weight:900;font-size:14px;border:1px solid #cfe6e3;background:#fff;color:#246c73;box-shadow:0 8px 18px rgba(36,108,115,.05)}
.caregiver-action-btn.primary{background:linear-gradient(135deg,#59bfc0 0%,#4aaeb0 100%);border-color:transparent;color:#fff;box-shadow:0 14px 26px rgba(88,191,192,.20)}
.caregiver-action-btn:hover{background:#f3fbf9}
.caregiver-action-btn.primary:hover{background:linear-gradient(135deg,#4db5b6 0%,#419fa1 100%)}
.caregiver-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.caregiver-kpi-card{position:relative;padding:18px 18px 16px;border-radius:22px;background:#fff;border:1px solid #d8ece8;box-shadow:0 12px 28px rgba(36,108,115,.06);overflow:hidden}
.caregiver-kpi-card:before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#58bfc0,#9adfdc)}
.caregiver-kpi-card.pending:before{background:linear-gradient(90deg,#f0b25e,#f6d39d)}
.caregiver-kpi-card.info:before{background:linear-gradient(90deg,#7db9df,#9fd4ed)}
.caregiver-kpi-card.success:before{background:linear-gradient(90deg,#4bb9a7,#7ad4be)}
.caregiver-kpi-label{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#77908b}
.caregiver-kpi-value{margin-top:8px;font-size:34px;line-height:1;font-weight:900;color:#1a565c}
.caregiver-kpi-sub{margin-top:8px;font-size:13px;color:#6f8782;line-height:1.45}
.caregiver-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px}
.care-priority-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}
.care-priority-card{padding:12px 14px;border:1px solid #e0eeec;border-radius:15px;background:#fbfdfd}
.care-priority-card strong{display:block;font-size:12px;color:#567174;margin-bottom:6px}
.care-priority-card span{display:block;font-size:24px;font-weight:900;color:#234b50}
.care-priority-card small{display:block;margin-top:4px;color:#819597;font-size:12px;line-height:1.45}
.care-alert{padding:14px 15px;border:1px solid #f0dcc0;border-radius:14px;background:#fff9ec;color:#725b28;font-size:13px;line-height:1.6}
.care-alert strong{color:#8d6e1e}
.care-aside-stack{display:grid;gap:18px}
.care-mini-grid{display:grid;gap:10px}
.care-mini-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:12px 13px;border:1px solid #e0eeec;border-radius:13px;background:#fbfdfd}
.care-mini-row span{font-size:13px;color:#536f72}.care-mini-row strong{font-size:20px;color:#2c5b5f}
.care-action-list{display:grid;gap:10px}.care-link-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 14px;border-radius:12px;border:1px solid #d7e7e2;background:#fff;color:#335f57;text-decoration:none;font-weight:800;font-size:13px}.care-link-btn:hover{background:#f4faf8}
.caregiver-dashboard-minimal{gap:18px!important}
.caregiver-layout-minimal{grid-template-columns:minmax(0,1.45fr) minmax(280px,.55fr)!important}
.caregiver-status-panel{align-self:start}
@media(max-width:1200px){.caregiver-layout-minimal{grid-template-columns:1fr!important}}

/* Admin dashboard month filter — Thai month picker */
.admin-month-filter{display:flex;align-items:flex-start;justify-content:flex-end;gap:10px;margin:0 0 16px;position:relative;z-index:30}
.thai-month-picker{position:relative;min-width:210px;max-width:292px}
.thai-month-trigger{width:100%;height:40px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 12px 0 14px;border:1px solid #d4e8e5;border-radius:12px;background:#fff;color:#285d60;font:inherit;font-size:13px;font-weight:800;outline:none;box-shadow:0 6px 16px rgba(36,108,115,.04);cursor:pointer}
.thai-month-trigger:hover,.thai-month-trigger:focus,.thai-month-picker.is-open .thai-month-trigger{border-color:#66c4c4;box-shadow:0 0 0 3px rgba(89,191,192,.12)}
.thai-month-calendar-icon{font-size:15px;line-height:1;color:#285d60}
.thai-month-popover{position:absolute;top:0;left:calc(100% + 12px);width:292px;max-width:none;margin-top:0;padding:12px;border:1px solid #d4e8e5;border-radius:14px;background:#fff;box-shadow:0 14px 30px rgba(36,108,115,.12);display:none;z-index:80;box-sizing:border-box}
.thai-month-picker.is-open .thai-month-popover{display:block}
.thai-month-yearbar{display:grid;grid-template-columns:36px 1fr 36px;align-items:center;gap:6px;margin-bottom:8px}
.thai-month-nav{height:34px;border:0;border-radius:9px;background:#f2f8f7;color:#2d6467;font:inherit;font-size:18px;font-weight:900;cursor:pointer}
.thai-month-year{text-align:center;font-size:14px;font-weight:900;color:#264f52}
.thai-month-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.thai-month-option{height:38px;border:1px solid transparent;border-radius:9px;background:#fff;color:#285d60;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.thai-month-option:hover{background:#eef8f7}
.thai-month-option.is-selected{background:#55bfc0;color:#fff;border-color:#55bfc0}
.thai-month-actions{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:9px;border-top:1px solid #edf4f2}
.thai-month-action{border:0;background:transparent;color:#2c777a;font:inherit;font-size:12px;font-weight:900;cursor:pointer;padding:5px 4px}
.thai-month-action:hover{text-decoration:underline}
@media(max-width:900px){.thai-month-popover{top:calc(100% + 8px);left:0;width:292px;max-width:calc(100vw - 36px)}}
@media(max-width:650px){.admin-month-filter{align-items:stretch;flex-direction:column}.thai-month-picker{width:100%;max-width:none}.thai-month-popover{top:calc(100% + 8px);left:0;width:292px;max-width:calc(100vw - 36px)}}

/* Admin home: compact summary only */
.admin-overview-simple{display:grid;gap:18px}
.admin-kpis-simple{margin-bottom:0}
.admin-kpis-simple .dash-kpi{min-height:106px;padding:18px 20px;display:flex;flex-direction:column;justify-content:center}
.admin-kpis-simple .dash-kpi-label{margin-bottom:7px}
.admin-kpis-simple .dash-kpi-value{margin-bottom:0;font-size:30px}
.admin-summary-simple{display:grid;grid-template-columns:1fr;gap:18px}
.admin-compact-panel .dash-panel-head{padding:14px 18px}
.admin-check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px 18px}
.admin-check-item,.admin-adl-item{display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:72px;padding:14px 16px;border:1px solid #E0EEEC;border-radius:14px;background:#FBFDFD}
.admin-check-item span,.admin-adl-item span{font-size:13px;font-weight:700;color:#506F73}
.admin-check-item strong,.admin-adl-item strong{font-size:24px;line-height:1;font-weight:800;color:#246C73}
.admin-adl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:16px 18px}
.admin-adl-item{min-height:72px;padding:12px;flex-direction:column;justify-content:center;text-align:center;gap:7px}
.admin-adl-item strong{font-size:22px}
.admin-village-chart-panel{min-width:0}
.village-chart-wrap{position:relative;padding:18px 18px 14px 54px;min-height:305px}
.village-chart-y-title{position:absolute;left:8px;top:50%;transform:translateY(-50%) rotate(-90deg);transform-origin:center;white-space:nowrap;font-size:11px;font-weight:700;color:#678185}
.village-chart-area{position:relative;height:270px;padding-left:30px}
.village-chart-grid{position:absolute;inset:0 0 58px 0;display:flex;flex-direction:column;justify-content:space-between;pointer-events:none}
.village-grid-row{display:grid;grid-template-columns:24px 1fr;align-items:center;gap:6px;font-size:10px;color:#809396}
.village-grid-row i{display:block;border-top:1px dashed #deebea}
.village-bars{position:absolute;left:30px;right:0;top:0;bottom:0;display:flex;align-items:flex-end;gap:10px;padding:0 6px 0 4px}
.village-bar-item{height:100%;min-width:0;flex:1;display:grid;grid-template-rows:24px minmax(0,1fr) 54px;align-items:end;text-align:center}
.village-bar-value{align-self:end;padding-bottom:4px;font-size:12px;font-weight:900;color:#17646b}
.village-bar-track{position:relative;height:100%;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #bfd6d4}
.village-bar-fill{width:min(44px,72%);min-height:0;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#79cec3 0%,#65beb5 100%);box-shadow:0 5px 12px rgba(55,151,145,.14)}
.village-bar-label{height:54px;padding-top:8px;overflow:hidden;font-size:10px;line-height:1.3;font-weight:700;color:#5e777a;word-break:break-word}
.village-chart-empty{position:absolute;left:0;right:0;top:45%;text-align:center;color:#819597;font-size:12px}
@media(max-width:1050px){.admin-summary-simple{grid-template-columns:1fr}.admin-check-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.village-chart-wrap{min-height:320px}}
@media(max-width:650px){.admin-check-grid,.admin-adl-grid{grid-template-columns:1fr}.admin-check-item,.admin-adl-item{flex-direction:row;text-align:left;min-height:60px}}

@media(max-width:1200px){.dash-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-grid{grid-template-columns:1fr}.doctor-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.doctor-hero-card{grid-template-columns:1fr}.doctor-hero-actions{justify-items:start}.doctor-summary-row{grid-template-columns:1fr}.doctor-clean-grid{grid-template-columns:1fr}.caregiver-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.caregiver-hero{grid-template-columns:1fr}.caregiver-layout{grid-template-columns:1fr}.care-priority-strip{grid-template-columns:1fr}}
@media(max-width:650px){.dash-kpis{grid-template-columns:1fr}.dash-heading{align-items:flex-start;flex-direction:column}.dash-date{width:100%;text-align:center}.doctor-kpi-grid{grid-template-columns:1fr}.doctor-hero-copy h2{font-size:26px}.doctor-hero-card{padding:22px 20px;border-radius:24px}.doctor-hero-tag{width:100%;justify-content:center}.doctor-quick-grid{grid-template-columns:1fr}.doctor-record-item,.doctor-mini-top{flex-direction:column;align-items:flex-start}.doctor-record-side{justify-items:start}.doctor-record-score{min-width:78px;font-size:22px}.caregiver-kpi-grid{grid-template-columns:1fr}.caregiver-hero{padding:22px 20px;border-radius:24px}.caregiver-hero h1{font-size:27px}.caregiver-actions{grid-template-columns:1fr}.caregiver-hero-tag{width:100%;justify-content:center}}

.doctor-visit-panel{margin-top:24px!important;}
@media(max-width:900px){.doctor-visit-panel{margin-top:18px!important;}}


/* Keep caregiver date badge aligned to the right on every screen size */
@media(max-width:650px){
  .dash-heading.caregiver-date-only{align-items:flex-end!important;flex-direction:row!important;}
  .dash-heading.caregiver-date-only .dash-date{width:auto!important;margin-left:auto!important;text-align:right!important;}
}

/* Caregiver dashboard: simplified, low-information layout */
.caregiver-dashboard-simple{display:grid;gap:16px}
.caregiver-summary-panel,.caregiver-patient-panel{overflow:hidden}
.caregiver-summary-table,.caregiver-simple-table{min-width:100%}
.caregiver-summary-table th:nth-child(1){width:18%}
.caregiver-summary-table th:nth-child(2){width:52%}
.caregiver-summary-table th:nth-child(3){width:30%}
.caregiver-summary-table .group-row td{background:#f8fcfb;color:#1f555a;font-weight:900;border-top:1px solid #e2eeec}
.caregiver-summary-table .group-row:first-child td{border-top:0}
.caregiver-summary-table td strong{color:#234f54}
.caregiver-simple-table th:nth-child(1){width:34%}
.caregiver-simple-table th:nth-child(2){width:24%}
.caregiver-simple-table th:nth-child(3){width:22%}
.caregiver-simple-table th:nth-child(4){width:20%}

/* Caregiver dashboard: only the information needed for day-to-day work. */
.caregiver-essential{display:grid;gap:16px}
.caregiver-essential-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.caregiver-essential-heading h1{margin:0;color:#204e52;font-size:25px;line-height:1.3}
.caregiver-essential-heading p{margin:4px 0 0;color:#6d8688;font-size:13px}
.caregiver-essential .caregiver-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.caregiver-essential .caregiver-kpi-card{position:relative;min-height:126px;padding:17px 18px;border:1px solid #d8ece8;border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(36,108,115,.045);overflow:hidden}
.caregiver-essential .caregiver-kpi-card:before{content:'';position:absolute;left:0;top:0;right:0;height:3px;background:#55b8ac}
.caregiver-essential .caregiver-kpi-card.warn:before{background:#dea95a}
.caregiver-essential .caregiver-kpi-card.info:before{background:#82bcd6}
.caregiver-essential .caregiver-kpi-label{font-size:13px;font-weight:700;color:#587578}
.caregiver-essential .caregiver-kpi-value{margin:8px 0 6px;font-size:33px;font-weight:800;line-height:1.1;color:#20585a}
.caregiver-essential .caregiver-kpi-sub{font-size:12px;color:#829598;line-height:1.45}
.caregiver-essential-panel{overflow:hidden;border:1px solid #d5e9e7;border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(36,108,115,.045)}
.caregiver-essential-panel .panel-heading{padding:16px 18px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;background:#e7f8f5;border-bottom:1px solid #d5e9e7}
.caregiver-essential-panel .panel-heading-text{display:grid;gap:4px}
.caregiver-essential-panel .panel-heading h2{margin:0;font-size:17px;color:#1f555a}
.caregiver-essential-panel .panel-heading a{font-size:12px;font-weight:700;text-decoration:none;color:#246c73;white-space:nowrap}
.caregiver-essential-table{min-width:720px;width:100%;border-collapse:collapse}
.caregiver-essential-table th,.caregiver-essential-table td{padding:14px 16px;text-align:left;vertical-align:middle;border-bottom:1px solid #edf1f0;font-size:13px}
.caregiver-essential-table th{background:#f7fbfa;color:#466a6b;font-size:12px}
.caregiver-essential-table tr:last-child td{border-bottom:0}
.caregiver-essential-table td strong{display:block;color:#244c50}
.caregiver-essential-table td small{display:block;color:#789092;font-size:11px;margin-top:2px}
.caregiver-essential-table .mini-btn{white-space:nowrap}
@media(max-width:1050px){.caregiver-essential .caregiver-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.caregiver-essential .caregiver-kpi-grid{grid-template-columns:1fr}.caregiver-essential-heading h1{font-size:22px}}

/* Doctor home — use the same visual system as the Admin dashboard */
.doctor-admin-style{gap:18px}
.doctor-admin-style .admin-modern-stats{margin-bottom:0;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.doctor-admin-style .admin-modern-stat-card{min-height:132px;padding:20px 22px}
.doctor-admin-style .admin-modern-stat-card .value{margin-top:10px}
.doctor-admin-grid{display:grid;grid-template-columns:1fr;gap:18px}
@media(max-width:1050px){.doctor-admin-style .admin-modern-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.doctor-admin-grid{grid-template-columns:1fr}}
@media(max-width:760px){.doctor-admin-style .admin-modern-stats{grid-template-columns:1fr}.doctor-admin-style .admin-modern-stat-card{min-height:116px}}
.doctor-admin-summary-list{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.doctor-admin-summary-row{display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:12px;min-height:112px;padding:16px 18px;border:1px solid #deecea;border-radius:15px;background:#fbfefe}
.doctor-admin-summary-row:last-child{border-bottom:1px solid #deecea}
.doctor-admin-summary-row b{margin-top:auto}
@media(max-width:1100px){.doctor-admin-summary-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.doctor-admin-summary-list{grid-template-columns:1fr}}
.doctor-admin-summary-row>div{min-width:0}
.doctor-admin-summary-row strong{display:block;color:#244f54;font-size:14px;font-weight:900;line-height:1.35}
.doctor-admin-summary-row span{display:block;margin-top:5px;color:#7b9092;font-size:11px;line-height:1.45}
.doctor-admin-summary-row b{flex:0 0 auto;color:#1e555b;font-size:16px;font-weight:900;white-space:nowrap}
.doctor-admin-bottom-panel{margin-top:0}
.doctor-admin-latest-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.doctor-admin-latest-item{padding:15px 16px;border:1px solid #deecea;border-radius:15px;background:#fbfefe}
.doctor-admin-latest-item span{display:block;color:#7a8f91;font-size:11px;font-weight:800;margin-bottom:7px}
.doctor-admin-latest-item strong{display:block;color:#22545a;font-size:14px;font-weight:900;line-height:1.45}
@media(max-width:900px){.doctor-admin-latest-grid{grid-template-columns:1fr}.doctor-admin-summary-row{align-items:flex-start}.doctor-admin-summary-row b{font-size:14px}}

/* Doctor dashboard — polished monthly layout */
.doctor-admin-style{max-width:1280px;margin:0 auto;display:grid;gap:20px}
.doctor-month-filter{margin:0 0 2px;justify-content:flex-start;z-index:45}
.doctor-month-filter .thai-month-picker{min-width:235px}
.doctor-admin-style .admin-modern-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.doctor-admin-style .admin-modern-stat-card{min-height:122px;padding:20px 22px}
.doctor-admin-grid-single{display:block!important}
.doctor-admin-grid-single .admin-modern-panel{width:100%}
.doctor-admin-summary-list{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.doctor-admin-summary-row{display:flex!important;min-height:118px;padding:17px 18px!important;border:1px solid #dcecea!important;border-radius:16px!important;background:#fbfefd!important;flex-direction:column;align-items:flex-start!important;justify-content:space-between!important;gap:12px!important}
.doctor-admin-summary-row + .doctor-admin-summary-row{border-top:1px solid #dcecea!important}
.doctor-admin-summary-row div{display:grid;gap:5px}
.doctor-admin-summary-row div strong{font-size:14px;color:#244f53}
.doctor-admin-summary-row div span{font-size:11px;line-height:1.45;color:#839496}
.doctor-admin-summary-row b{font-size:20px;color:#155f66}
.doctor-admin-bottom-panel{margin-top:0!important}
.doctor-admin-latest-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.doctor-admin-latest-item{min-height:86px;padding:14px 16px;border:1px solid #dcecea;border-radius:14px;background:#fbfefd}
@media(max-width:1050px){.doctor-admin-summary-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.doctor-admin-style .admin-modern-stats,.doctor-admin-summary-list,.doctor-admin-latest-grid{grid-template-columns:1fr}.doctor-month-filter{justify-content:stretch}.doctor-month-filter .thai-month-picker{width:100%;max-width:none}}

/* Unified caregiver home — same visual system as admin/doctor */
.caregiver-admin-style{display:grid;gap:20px}
.caregiver-role-stats{grid-template-columns:repeat(4,minmax(0,1fr))!important}
.caregiver-role-panel{width:100%}
.caregiver-role-panel .caregiver-essential-table{width:100%;border-collapse:collapse;min-width:760px}
.caregiver-role-panel .caregiver-essential-table th,.caregiver-role-panel .caregiver-essential-table td{padding:13px 14px;border-bottom:1px solid #e6f0ee;text-align:left;font-size:12px;vertical-align:middle}
.caregiver-role-panel .caregiver-essential-table th{background:#f1faf8;color:#42686c;font-weight:900}
.caregiver-role-panel .caregiver-essential-table td strong{display:block;color:#214f54}.caregiver-role-panel .caregiver-essential-table td small{display:block;margin-top:4px;color:#829598}
@media(max-width:1050px){.caregiver-role-stats{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:650px){.caregiver-role-stats{grid-template-columns:1fr!important}}

</style>
<link rel="stylesheet" href="assets/inspired_layout.css?v=20260929-1">
<link rel="stylesheet" href="assets/unified_home_report.css?v=20260929-1">
</head>
<body class="role-page dashboard-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<section class="deeden-home-heading"><h1>ภาพรวมข้อมูล</h1></section>

<?php if ($role === 'admin'): ?>
<?php
    $adminAdlPending = max(0, $patientCount - $adlTotal);
    $adminDonutTotal = max(1, $patientCount);
    $adminDegSocial = (($adlGroups['ติดสังคม'] ?? 0) / $adminDonutTotal) * 360;
    $adminDegHome = (($adlGroups['ติดบ้าน'] ?? 0) / $adminDonutTotal) * 360;
    $adminDegBed = (($adlGroups['ติดเตียง'] ?? 0) / $adminDonutTotal) * 360;
    $adminStop1 = $adminDegSocial;
    $adminStop2 = $adminStop1 + $adminDegHome;
    $adminStop3 = $adminStop2 + $adminDegBed;
    $adminTopVillage = $adminVillageRows[0] ?? ['villagename' => '-', 'total' => 0];
?>
<section class="admin-modern-dashboard">
    <form class="admin-month-filter" method="get" action="home.php" id="adminMonthFilter" autocomplete="off">
        <div class="thai-month-picker" id="thaiMonthPicker">
            <input type="hidden" id="dashboardMonth" name="month" value="<?= dashH($adminMonth) ?>">
            <button type="button" class="thai-month-trigger" id="thaiMonthTrigger" aria-haspopup="dialog" aria-expanded="false" aria-label="เลือกเดือนสำหรับกรองข้อมูล">
                <span id="thaiMonthText"><?= dashH($adminMonthLabel) ?></span>
                <span class="thai-month-calendar-icon" aria-hidden="true">▦</span>
            </button>
            <div class="thai-month-popover" id="thaiMonthPopover" role="dialog" aria-label="เลือกเดือน">
                <div class="thai-month-yearbar">
                    <button type="button" class="thai-month-nav" id="thaiMonthPrevYear" aria-label="ปีก่อนหน้า">‹</button>
                    <div class="thai-month-year" id="thaiMonthYear"></div>
                    <button type="button" class="thai-month-nav" id="thaiMonthNextYear" aria-label="ปีถัดไป">›</button>
                </div>
                <div class="thai-month-grid" id="thaiMonthGrid"></div>
                <div class="thai-month-actions">
                    <button type="button" class="thai-month-action" id="thaiMonthClear">ทุกเดือน</button>
                    <button type="button" class="thai-month-action" id="thaiMonthThis">เดือนนี้</button>
                </div>
            </div>
        </div>
    </form>
    <section class="admin-modern-stats">
        <article class="admin-modern-stat-card"><div class="label"><?= $adminMonth !== '' ? 'ผู้สูงอายุที่มีการประเมิน' : 'ผู้สูงอายุทั้งหมด' ?></div><div class="value"><?= number_format($patientCount) ?></div><div class="unit">คน</div></article>
        <article class="admin-modern-stat-card blue"><div class="label"><?= $adminMonth !== '' ? 'แคร์กิฟเวอร์ที่มีการประเมิน' : 'แคร์กิฟเวอร์' ?></div><div class="value"><?= number_format($caregiverCount) ?></div><div class="unit">คน</div></article>
        <article class="admin-modern-stat-card"><div class="label"><?= $adminMonth !== '' ? 'หมอที่มีการประเมิน' : 'หมอ' ?></div><div class="value"><?= number_format($doctorCount) ?></div><div class="unit">คน</div></article>
        <article class="admin-modern-stat-card blue"><div class="label"><?= $adminMonth !== '' ? 'หมู่บ้านที่มีการประเมิน' : 'หมู่บ้าน' ?></div><div class="value"><?= number_format($villageCount) ?></div><div class="unit">แห่ง</div></article>
    </section>

    <section class="admin-modern-grid">
        <article class="admin-modern-panel">
            <div class="admin-modern-panel-head"><h2>สถิติจำนวนผู้สูงอายุจำแนกตามหมู่บ้าน</h2><a href="admin/village.php">ข้อมูลหมู่บ้าน</a></div>
            <div class="admin-modern-panel-body">
                <?php
                    $villageMax = 0;
                    foreach ($adminVillageRows as $villageRow) {
                        $villageMax = max($villageMax, (int)($villageRow['total'] ?? 0));
                    }
                    $villageScaleMax = max(1, $villageMax);
                ?>
                <div class="admin-modern-village-bars">
                    <?php foreach ($adminVillageRows as $villageRow):
                        $count = (int)($villageRow['total'] ?? 0);
                        $width = $villageScaleMax > 0 ? ($count / $villageScaleMax) * 100 : 0;
                    ?>
                        <div class="admin-modern-village-row">
                            <div class="admin-modern-village-name"><?= dashH($villageRow['villagename'] ?? '-') ?></div>
                            <div class="admin-modern-track"><i style="width:<?= max(0,min(100,$width)) ?>%"></i></div>
                            <div class="admin-modern-village-value"><?= number_format($count) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$adminVillageRows): ?>
                        <div class="admin-modern-empty">ยังไม่มีข้อมูลหมู่บ้าน</div>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <aside class="admin-modern-side">
            <article class="admin-modern-panel">
                <div class="admin-modern-panel-head"><h2>ภาพรวมผล ADL ล่าสุด</h2><a href="admin/statistics.php">ดูรายงาน</a></div>
                <div class="admin-modern-panel-body">
                    <div class="admin-modern-ring-layout">
                        <div class="admin-modern-ring" style="background:conic-gradient(#20AFA6 0deg <?= $adminStop1 ?>deg,#83d5d1 <?= $adminStop1 ?>deg <?= $adminStop2 ?>deg,#7dc7e6 <?= $adminStop2 ?>deg <?= $adminStop3 ?>deg,#dff5f0 <?= $adminStop3 ?>deg 360deg)">
                            <div class="admin-modern-ring-center"><strong><?= number_format($patientCount) ?></strong><span>คน</span></div>
                        </div>
                        <div class="admin-modern-legend">
                            <div class="admin-modern-legend-item"><i style="background:#20AFA6"></i><div><strong>ติดสังคม</strong><span><?= number_format($adlGroups['ติดสังคม']) ?> คน</span></div></div>
                            <div class="admin-modern-legend-item"><i style="background:#83d5d1"></i><div><strong>ติดบ้าน</strong><span><?= number_format($adlGroups['ติดบ้าน']) ?> คน</span></div></div>
                            <div class="admin-modern-legend-item"><i style="background:#7dc7e6"></i><div><strong>ติดเตียง</strong><span><?= number_format($adlGroups['ติดเตียง']) ?> คน</span></div></div>
                            <div class="admin-modern-legend-item"><i style="background:#dff5f0"></i><div><strong>ยังไม่มีผล</strong><span><?= number_format($adminAdlPending) ?> คน</span></div></div>
                        </div>
                    </div>
                </div>
            </article>

        </aside>
    </section>

</section>

<?php elseif ($role === 'doctor'): ?>
<section class="admin-modern-dashboard doctor-admin-style">
    <form class="admin-month-filter doctor-month-filter" method="get" action="home.php" id="adminMonthFilter" autocomplete="off">
        <div class="thai-month-picker" id="thaiMonthPicker">
            <input type="hidden" id="dashboardMonth" name="month" value="<?= dashH($adminMonth) ?>">
            <button type="button" class="thai-month-trigger" id="thaiMonthTrigger" aria-haspopup="dialog" aria-expanded="false" aria-label="เลือกเดือนสำหรับกรองข้อมูล">
                <span id="thaiMonthText"><?= dashH($adminMonthLabel) ?></span>
                <span class="thai-month-calendar-icon" aria-hidden="true">▦</span>
            </button>
            <div class="thai-month-popover" id="thaiMonthPopover" role="dialog" aria-label="เลือกเดือน">
                <div class="thai-month-yearbar">
                    <button type="button" class="thai-month-nav" id="thaiMonthPrevYear" aria-label="ปีก่อนหน้า">‹</button>
                    <div class="thai-month-year" id="thaiMonthYear"></div>
                    <button type="button" class="thai-month-nav" id="thaiMonthNextYear" aria-label="ปีถัดไป">›</button>
                </div>
                <div class="thai-month-grid" id="thaiMonthGrid"></div>
                <div class="thai-month-actions">
                    <button type="button" class="thai-month-action" id="thaiMonthClear">ทุกเดือน</button>
                    <button type="button" class="thai-month-action" id="thaiMonthThis">เดือนนี้</button>
                </div>
            </div>
        </div>
    </form>
    <section class="admin-modern-stats">
        <article class="admin-modern-stat-card">
            <div class="label"><?= $adminMonth !== '' ? 'มอบหมายในเดือนนี้' : 'มอบหมายโดยฉันแล้ว' ?></div>
            <div class="value"><?= number_format($doctorAssigned) ?></div>
            <div class="unit">คน</div>
        </article>
        <article class="admin-modern-stat-card blue">
            <div class="label">ยังไม่มีผู้ดูแล</div>
            <div class="value"><?= number_format($doctorUnassigned) ?></div>
            <div class="unit">คน</div>
        </article>
        <article class="admin-modern-stat-card">
            <div class="label"><?= $adminMonth !== '' ? 'บันทึกการเข้าเยี่ยมเดือนนี้' : 'บันทึกการเข้าเยี่ยม' ?></div>
            <div class="value"><?= number_format($doctorVisitTotal) ?></div>
            <div class="unit">รายการ</div>
        </article>
    </section>

    <section class="admin-modern-grid doctor-admin-grid doctor-admin-grid-single">
        <article class="admin-modern-panel">
            <div class="admin-modern-panel-head">
                <h2>สรุปการประเมิน ADL</h2>
                <a href="doctor/adl.php">ดูการประเมิน</a>
            </div>
            <div class="admin-modern-panel-body">
                <div class="doctor-admin-summary-list">
                    <div class="doctor-admin-summary-row">
                        <div><strong>ประเมินทั้งหมด</strong><span>จำนวนครั้งที่บันทึกผล ADL</span></div>
                        <b><?= number_format($doctorAdlTotal) ?> ครั้ง</b>
                    </div>
                    <div class="doctor-admin-summary-row">
                        <div><strong>ผู้สูงอายุที่ประเมินแล้ว</strong><span>นับแบบไม่ซ้ำรายบุคคล</span></div>
                        <b><?= number_format($doctorAssessed) ?> คน</b>
                    </div>
                    <div class="doctor-admin-summary-row">
                        <div><strong>ยังไม่ประเมิน</strong><span>ผู้สูงอายุที่ยังไม่มีผลจากบัญชีนี้</span></div>
                        <b><?= number_format($doctorUnassessed) ?> คน</b>
                    </div>
                    <div class="doctor-admin-summary-row">
                        <div><strong>ประเมินล่าสุด</strong><span>วันที่บันทึกผล ADL ล่าสุด</span></div>
                        <b><?= $doctorRecentAdl ? dashH(dashThaiDate($doctorRecentAdl[0]['assessment_date'] ?? null)) : '-' ?></b>
                    </div>
                </div>
            </div>
        </article>

    </section>

    <section class="admin-modern-panel doctor-admin-bottom-panel">
        <div class="admin-modern-panel-head">
            <h2>ข้อมูลล่าสุด</h2>
            <a href="doctor/doctor_visit_summary.php">ดูสรุปการเข้าเยี่ยม</a>
        </div>
        <div class="admin-modern-panel-body doctor-admin-latest-grid">
            <div class="doctor-admin-latest-item">
                <span>มอบหมายล่าสุด</span>
                <strong><?= $doctorRecentAssignments ? dashH(dashThaiDate($doctorRecentAssignments[0]['assigned_at'] ?? null,true)) : '-' ?></strong>
            </div>
            <div class="doctor-admin-latest-item">
                <span>เยี่ยมล่าสุด</span>
                <strong><?= $doctorRecentVisits ? dashH(dashThaiDate($doctorRecentVisits[0]['visit_date'] ?? null)) : '-' ?></strong>
            </div>
            <div class="doctor-admin-latest-item">
                <span>รายการปกติ</span>
                <strong><?= number_format(max(0, $doctorVisitTotal - $doctorVisitReferral)) ?> รายการ</strong>
            </div>
        </div>
    </section>
</section>

<?php else: ?>
<section class="admin-modern-dashboard caregiver-admin-style">
    <form class="admin-month-filter caregiver-month-filter" method="get" action="home.php" id="adminMonthFilter" autocomplete="off">
        <div class="thai-month-picker" id="thaiMonthPicker">
            <input type="hidden" id="dashboardMonth" name="month" value="<?= dashH($adminMonth) ?>">
            <button type="button" class="thai-month-trigger" id="thaiMonthTrigger" aria-haspopup="dialog" aria-expanded="false"><span id="thaiMonthText"><?= dashH($adminMonthLabel) ?></span><span class="thai-month-calendar-icon" aria-hidden="true">▦</span></button>
            <div class="thai-month-popover" id="thaiMonthPopover" role="dialog" aria-label="เลือกเดือน">
                <div class="thai-month-yearbar"><button type="button" class="thai-month-nav" id="thaiMonthPrevYear">‹</button><div class="thai-month-year" id="thaiMonthYear"></div><button type="button" class="thai-month-nav" id="thaiMonthNextYear">›</button></div>
                <div class="thai-month-grid" id="thaiMonthGrid"></div>
                <div class="thai-month-actions"><button type="button" class="thai-month-action" id="thaiMonthClear">ทุกเดือน</button><button type="button" class="thai-month-action" id="thaiMonthThis">เดือนนี้</button></div>
            </div>
        </div>
    </form>

    <section class="admin-modern-stats caregiver-role-stats">
        <article class="admin-modern-stat-card"><div class="label"><?= $adminMonth!=='' ? 'ได้รับมอบหมายในเดือนนี้' : 'ผู้สูงอายุในความดูแล' ?></div><div class="value"><?= number_format($cgAssigned) ?></div><div class="unit">คน</div></article>
        <article class="admin-modern-stat-card blue"><div class="label">กำลังดูแล</div><div class="value"><?= number_format($cgActive) ?></div><div class="unit">คน</div></article>
        <article class="admin-modern-stat-card"><div class="label"><?= $adminMonth!=='' ? 'เข้าเยี่ยมเดือนนี้' : 'บันทึกการเข้าเยี่ยม' ?></div><div class="value"><?= number_format($cgVisitTotal) ?></div><div class="unit">รายการ</div></article>
        <article class="admin-modern-stat-card blue"><div class="label"><?= $adminMonth!=='' ? 'ประเมิน ADL เดือนนี้' : 'ประเมิน ADL แล้ว' ?></div><div class="value"><?= number_format($cgAdlCompleted) ?></div><div class="unit">รายการ</div></article>
    </section>

    <section class="admin-modern-panel caregiver-role-panel">
        <div class="admin-modern-panel-head"><h2>ผู้สูงอายุในความดูแล</h2><a href="caregiver/caregiver_patients.php">ดูทั้งหมด</a></div>
        <div class="admin-modern-panel-body">
            <div class="dash-table-wrap"><table class="caregiver-essential-table">
                <thead><tr><th>ชื่อผู้สูงอายุ</th><th>หมู่บ้าน</th><th>ADL ล่าสุด</th><th>สถานะการดูแล</th><th>การทำงาน</th></tr></thead>
                <tbody>
                <?php foreach ($cgPatients as $row): [$group,$cls]=dashAdlGroup($row['total_score'] ?? null); ?>
                    <tr><td><strong><?= dashH($row['Fullname'] ?: '-') ?></strong><small><?= dashH(($row['Age'] ?? '-') . ' ปี') ?></small></td><td><?= dashH($row['villagename'] ?: '-') ?></td><td><span class="status <?= dashH($cls) ?>"><?= dashH($group) ?></span></td><td><span class="status blue"><?= dashH($row['care_status'] ?: '-') ?></span></td><td><a class="mini-btn" href="caregiver/caregiver_adl.php">ประเมิน ADL</a></td></tr>
                <?php endforeach; ?>
                <?php if (!$cgPatients): ?><tr><td colspan="5" class="empty-box">ยังไม่มีข้อมูลในช่วงที่เลือก</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </section>
</section>
<?php endif; ?>

</main>

<script>
(function(){
    const form=document.getElementById('adminMonthFilter');
    const picker=document.getElementById('thaiMonthPicker');
    if(!form||!picker)return;
    const hidden=document.getElementById('dashboardMonth');
    const trigger=document.getElementById('thaiMonthTrigger');
    const text=document.getElementById('thaiMonthText');
    const yearEl=document.getElementById('thaiMonthYear');
    const grid=document.getElementById('thaiMonthGrid');
    const prev=document.getElementById('thaiMonthPrevYear');
    const next=document.getElementById('thaiMonthNextYear');
    const clear=document.getElementById('thaiMonthClear');
    const thisMonth=document.getElementById('thaiMonthThis');
    const names=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const now=new Date();
    const selected=(hidden.value||'').match(/^(\d{4})-(\d{2})$/);
    let viewYear=selected?parseInt(selected[1],10):now.getFullYear();

    function selectedParts(){
        const m=(hidden.value||'').match(/^(\d{4})-(\d{2})$/);
        return m?{year:parseInt(m[1],10),month:parseInt(m[2],10)}:null;
    }
    function render(){
        yearEl.textContent='พ.ศ. '+(viewYear+543);
        grid.innerHTML='';
        const sp=selectedParts();
        names.forEach((name,i)=>{
            const b=document.createElement('button');
            b.type='button'; b.className='thai-month-option'; b.textContent=name;
            if(sp&&sp.year===viewYear&&sp.month===i+1)b.classList.add('is-selected');
            b.addEventListener('click',()=>{
                hidden.value=viewYear+'-'+String(i+1).padStart(2,'0');
                text.textContent=name+' '+(viewYear+543);
                closePicker();
                form.submit();
            });
            grid.appendChild(b);
        });
    }
    function openPicker(){picker.classList.add('is-open');trigger.setAttribute('aria-expanded','true');render();}
    function closePicker(){picker.classList.remove('is-open');trigger.setAttribute('aria-expanded','false');}
    trigger.addEventListener('click',()=>picker.classList.contains('is-open')?closePicker():openPicker());
    prev.addEventListener('click',()=>{viewYear--;render();});
    next.addEventListener('click',()=>{viewYear++;render();});
    clear.addEventListener('click',()=>{hidden.value='';text.textContent='ทุกเดือน';closePicker();form.submit();});
    thisMonth.addEventListener('click',()=>{
        const y=now.getFullYear(),m=now.getMonth()+1;
        hidden.value=y+'-'+String(m).padStart(2,'0');
        text.textContent=names[m-1]+' '+(y+543);
        closePicker();form.submit();
    });
    document.addEventListener('click',(e)=>{if(!picker.contains(e.target))closePicker();});
    document.addEventListener('keydown',(e)=>{if(e.key==='Escape')closePicker();});
})();
</script>

</body>
</html>

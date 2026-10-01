<?php
if (!function_exists('statTableExists')) {
function statTableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function statColumnExists(mysqli $conn, string $table, string $column): bool
{
    if (!statTableExists($conn, $table)) return false;
    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function statScalar(mysqli $conn, string $sql): int
{
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    return (int)($row[0] ?? 0);
}

function statRows(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function statAdlGroup($score): string
{
    if ($score === null || $score === '') return 'ยังไม่ประเมิน';
    $score = (int)$score;
    if ($score >= 12) return 'ติดสังคม';
    if ($score >= 5) return 'ติดบ้าน';
    return 'ติดเตียง';
}

function statH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statThaiDate(string $date): string
{
    $ts = strtotime($date);
    if (!$ts) return $date;
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

function statPercent(int $part, int $whole): int
{
    if ($whole <= 0) return 0;
    return (int)round(($part / $whole) * 100);
}
}

$pageErrors = [];

$hasPatient = statTableExists($conn, 'patient');
$hasVillage = statTableExists($conn, 'village');
$hasAssignment = statTableExists($conn, 'patient_caregiver_assignment');
$hasAdl = statTableExists($conn, 'adl_assessment')
    && statColumnExists($conn, 'adl_assessment', 'patient_id')
    && statColumnExists($conn, 'adl_assessment', 'total_score');
$hasUsers = statTableExists($conn, 'users');

if (!$hasPatient || !$hasVillage) {
    $pageErrors[] = 'บางข้อมูลของหน้าออกรายงานยังแสดงได้ไม่ครบ เพราะไม่พบตารางข้อมูลที่จำเป็นในฐานข้อมูล';
}
if (!$hasAssignment) {
    $pageErrors[] = 'ไม่พบตารางการมอบหมายผู้ดูแล ระบบจะแสดงค่าการมอบหมายเป็น 0 โดยอัตโนมัติ';
}
if (!$hasAdl) {
    $pageErrors[] = 'ไม่พบข้อมูลผลประเมิน ADL หรือโครงสร้างตารางยังไม่ครบ ระบบจะแสดงค่าการประเมินเป็น 0 โดยอัตโนมัติ';
}

$totalPatients = $hasPatient ? statScalar($conn, 'SELECT COUNT(*) FROM patient') : 0;
$totalVillages = $hasVillage ? statScalar($conn, 'SELECT COUNT(*) FROM village') : 0;
$totalCaregivers = $hasUsers ? statScalar($conn, "SELECT COUNT(*) FROM users WHERE role='caregiver'") : 0;
$totalDoctors = $hasUsers ? statScalar($conn, "SELECT COUNT(*) FROM users WHERE role='doctor'") : 0;
$totalAssigned = 0;
if ($hasPatient && $hasAssignment) {
    $totalAssigned = statScalar($conn, 'SELECT COUNT(DISTINCT p.Patient_id) FROM patient p INNER JOIN patient_caregiver_assignment a ON a.patient_id=p.Patient_id');
}
$totalUnassigned = max(0, $totalPatients - $totalAssigned);

$adlCounts = ['ติดสังคม' => 0, 'ติดบ้าน' => 0, 'ติดเตียง' => 0, 'ยังไม่ประเมิน' => 0];
$latestAdlByPatient = [];
if ($hasAdl) {
    $latestRows = statRows($conn, "SELECT a.patient_id, a.total_score
        FROM adl_assessment a
        INNER JOIN (
            SELECT patient_id, MAX(adl_id) latest_id
            FROM adl_assessment
            GROUP BY patient_id
        ) x ON x.latest_id=a.adl_id");
    foreach ($latestRows as $r) {
        $pid = (int)($r['patient_id'] ?? 0);
        if ($pid <= 0) continue;
        $group = statAdlGroup($r['total_score'] ?? null);
        $latestAdlByPatient[$pid] = $group;
        if (isset($adlCounts[$group])) $adlCounts[$group]++;
    }
}
$adlCounts['ยังไม่ประเมิน'] = max(0, $totalPatients - count($latestAdlByPatient));
$totalAssessed = $totalPatients - $adlCounts['ยังไม่ประเมิน'];
$assessmentRate = statPercent($totalAssessed, $totalPatients);
$assignmentRate = statPercent($totalAssigned, $totalPatients);

$villageRows = [];
if ($hasVillage && $hasPatient) {
    $villageRows = statRows($conn, "SELECT v.village_id, v.villagename,
            COUNT(p.Patient_id) AS total
        FROM village v
        LEFT JOIN patient p ON p.Village_id=v.village_id
        GROUP BY v.village_id, v.villagename
        ORDER BY COUNT(p.Patient_id) DESC, v.village_id ASC");
}

$assignedSet = [];
if ($hasAssignment) {
    foreach (statRows($conn, 'SELECT DISTINCT patient_id FROM patient_caregiver_assignment') as $r) {
        $assignedSet[(int)$r['patient_id']] = true;
    }
}

$patientsByVillage = [];
if ($hasPatient) {
    foreach (statRows($conn, 'SELECT Patient_id, Village_id FROM patient') as $r) {
        $vid = (int)($r['Village_id'] ?? 0);
        $pid = (int)($r['Patient_id'] ?? 0);
        if (!isset($patientsByVillage[$vid])) $patientsByVillage[$vid] = [];
        $patientsByVillage[$vid][] = $pid;
    }
}

$fullyCoveredVillages = 0;
$villagesWithoutElderly = 0;
$villagesWithUnassessed = 0;
$villagesWithUnassigned = 0;
foreach ($villageRows as &$v) {
    $vid = (int)$v['village_id'];
    $ids = $patientsByVillage[$vid] ?? [];
    $v['assigned'] = 0;
    $v['unassigned'] = 0;
    $v['social'] = 0;
    $v['home'] = 0;
    $v['bed'] = 0;
    $v['unassessed'] = 0;
    foreach ($ids as $pid) {
        if (isset($assignedSet[$pid])) $v['assigned']++; else $v['unassigned']++;
        $g = $latestAdlByPatient[$pid] ?? 'ยังไม่ประเมิน';
        if ($g === 'ติดสังคม') $v['social']++;
        elseif ($g === 'ติดบ้าน') $v['home']++;
        elseif ($g === 'ติดเตียง') $v['bed']++;
        else $v['unassessed']++;
    }
    $v['assessed'] = $v['social'] + $v['home'] + $v['bed'];
    $v['coverage'] = (int)$v['total'] > 0 ? statPercent((int)$v['assigned'], (int)$v['total']) : 0;
    $v['assessment_rate'] = (int)$v['total'] > 0 ? statPercent((int)$v['assessed'], (int)$v['total']) : 0;
    if ((int)$v['total'] === 0) $villagesWithoutElderly++;
    if ((int)$v['total'] > 0 && (int)$v['assigned'] === (int)$v['total']) $fullyCoveredVillages++;
    if ((int)$v['unassessed'] > 0) $villagesWithUnassessed++;
    if ((int)$v['unassigned'] > 0) $villagesWithUnassigned++;
}
unset($v);

$largestVillage = $villageRows[0] ?? null;
$maxVillageTotal = 1;
foreach ($villageRows as $tmpVillageRow) {
    $maxVillageTotal = max($maxVillageTotal, (int)($tmpVillageRow['total'] ?? 0));
}
unset($tmpVillageRow);

$todayText = statThaiDate(date('Y-m-d'));
$reportTitle = systemSetting($conn, 'report_title', 'รายงานสรุปข้อมูลผู้สูงอายุ');
$showReportDate = systemSetting($conn, 'show_report_date', '1') === '1';

$adlHeadlineItems = [
    'ติดสังคม' => (int)$adlCounts['ติดสังคม'],
    'ติดบ้าน' => (int)$adlCounts['ติดบ้าน'],
    'ติดเตียง' => (int)$adlCounts['ติดเตียง'],
    'ยังไม่ประเมิน' => (int)$adlCounts['ยังไม่ประเมิน'],
];
$topAdlLabel = 'ยังไม่มีข้อมูล';
$topAdlCount = 0;
foreach ($adlHeadlineItems as $label => $count) {
    if ($count > $topAdlCount) {
        $topAdlLabel = $label;
        $topAdlCount = $count;
    }
}
$largestVillageName = trim((string)($largestVillage['villagename'] ?? ''));
if ($largestVillageName === '') $largestVillageName = 'ไม่ระบุชื่อหมู่บ้าน';
?>

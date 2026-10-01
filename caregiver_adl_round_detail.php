<?php
require_once __DIR__ . '/connect.php';
requireRole('caregiver');
mysqli_set_charset($conn, 'utf8mb4');

$caregiverId = (int)($_SESSION['user_id'] ?? 0);

$adlItems = [
    'feeding' => ['title' => '1. การรับประทานอาหาร', 'subtitle' => 'รับประทานอาหารเมื่อเตรียมสำรับไว้ให้เรียบร้อยต่อหน้า', 'options' => [0 => 'ไม่สามารถตักอาหารเข้าปากได้ ต้องมีคนป้อนให้', 1 => 'ตักอาหารเองได้แต่ต้องมีคนช่วย เช่น ช่วยเตรียมอาหารให้', 2 => 'ตักอาหารและช่วยตัวเองได้เป็นปกติ']],
    'grooming' => ['title' => '2. การดูแลตนเอง', 'subtitle' => 'ล้างหน้า หวีผม แปรงฟัน โกนหนวด', 'options' => [0 => 'ต้องการความช่วยเหลือ', 1 => 'ทำเองได้ รวมทั้งกรณีที่เตรียมอุปกรณ์ไว้ให้']],
    'transfer' => ['title' => '3. การลุกนั่งและเคลื่อนย้าย', 'subtitle' => 'ลุกนั่งจากที่นอน หรือจากเตียงไปยังเก้าอี้', 'options' => [0 => 'ไม่สามารถนั่งได้ หรือต้องใช้คนสองคนช่วยยก', 1 => 'ต้องการความช่วยเหลืออย่างมาก', 2 => 'ต้องการความช่วยเหลือบ้างหรือดูแลเพื่อความปลอดภัย', 3 => 'ทำได้เอง']],
    'toilet_use' => ['title' => '4. การใช้ห้องน้ำ', 'subtitle' => 'ใช้ห้องน้ำ', 'options' => [0 => 'ช่วยตัวเองไม่ได้', 1 => 'ทำเองได้บ้าง แต่ต้องการความช่วยเหลือบางสิ่ง', 2 => 'ช่วยตัวเองได้ดี']],
    'mobility' => ['title' => '5. การเคลื่อนที่', 'subtitle' => 'การเคลื่อนที่ภายในห้องหรือบ้าน', 'options' => [0 => 'เคลื่อนที่ไปไหนไม่ได้', 1 => 'ใช้รถเข็นและเคลื่อนที่ด้วยตนเองได้', 2 => 'เดินหรือเคลื่อนที่โดยมีคนช่วย', 3 => 'เดินหรือเคลื่อนที่ได้เอง']],
    'dressing' => ['title' => '6. การสวมใส่เสื้อผ้า', 'subtitle' => 'การสวมใส่เสื้อผ้า', 'options' => [0 => 'ต้องมีคนสวมใส่ให้ ช่วยตัวเองได้น้อย', 1 => 'ช่วยตัวเองได้ประมาณร้อยละ 50', 2 => 'ช่วยตัวเองได้ดี']],
    'stairs' => ['title' => '7. การขึ้นลงบันได', 'subtitle' => 'การขึ้นลงบันได 1 ชั้น', 'options' => [0 => 'ไม่สามารถทำได้', 1 => 'ต้องการคนช่วย', 2 => 'ขึ้นลงได้เอง']],
    'bathing' => ['title' => '8. การอาบน้ำ', 'subtitle' => 'การอาบน้ำ', 'options' => [0 => 'ต้องมีคนช่วยหรือทำให้', 1 => 'อาบน้ำเองได้']],
    'bowels' => ['title' => '9. การกลั้นอุจจาระ', 'subtitle' => 'การกลั้นการถ่ายอุจจาระในระยะ 1 สัปดาห์ที่ผ่านมา', 'options' => [0 => 'กลั้นไม่ได้ หรือต้องสวนอุจจาระอยู่เสมอ', 1 => 'กลั้นไม่ได้บางครั้ง', 2 => 'กลั้นได้เป็นปกติ']],
    'bladder' => ['title' => '10. การกลั้นปัสสาวะ', 'subtitle' => 'การกลั้นปัสสาวะในระยะ 1 สัปดาห์ที่ผ่านมา', 'options' => [0 => 'กลั้นไม่ได้ หรือใส่สายสวนแต่ดูแลเองไม่ได้', 1 => 'กลั้นไม่ได้บางครั้ง', 2 => 'กลั้นได้เป็นปกติ']],
];

function adlGroupCaregiver(int $score): string
{
    if ($score >= 12) return 'กลุ่มติดสังคม';
    if ($score >= 5) return 'กลุ่มติดบ้าน';
    return 'กลุ่มติดเตียง';
}

function summaryValue($value, string $suffix = '', string $fallback = '-') : string
{
    if ($value === null) return $fallback;
    $text = trim((string)$value);
    if ($text === '') return $fallback;
    return $text . $suffix;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function adlThaiDate(?string $value): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

function adlThaiDateTime(?string $value): string
{
    if (!$value) return '-';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) return adlThaiDate($value);
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    return adlThaiDate(date('Y-m-d', $ts)) . ' ' . date('H:i', $ts) . ' น.';
}

$rows = [];
$sql = "SELECT a.*, p.Fullname, p.Age, p.Gender, p.Height_cm, p.Weight_kg, v.villagename,
               d.display_name AS doctor_name, d.username AS doctor_username,
               cg.display_name AS caregiver_name, cg.username AS caregiver_username
        FROM adl_assessment a
        JOIN patient p ON p.Patient_id = a.patient_id
        LEFT JOIN village v ON v.village_id = p.Village_id
        LEFT JOIN users d ON d.user_id = a.doctor_user_id AND d.role='doctor'
        LEFT JOIN users cg ON cg.user_id = a.caregiver_user_id AND cg.role='caregiver'
        WHERE a.caregiver_user_id = ?
          AND a.caregiver_total_score IS NOT NULL
        ORDER BY COALESCE(a.result_returned_at, a.caregiver_completed_at, a.assessment_date) DESC, a.adl_id DESC";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $caregiverId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$selectedId = (int)($_GET['adl_id'] ?? 0);
$selected = null;
foreach ($rows as $row) {
    if ((int)$row['adl_id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
if ($selected === null && !empty($rows)) {
    $selected = $rows[0];
    $selectedId = (int)$selected['adl_id'];
}

$selectedName = $selected['Fullname'] ?? '';
$doctorDisplay = trim((string)($selected['doctor_name'] ?? $selected['doctor_username'] ?? ''));
$caregiverDisplay = trim((string)($selected['caregiver_name'] ?? $selected['caregiver_username'] ?? ($_SESSION['fullname'] ?? $_SESSION['username'] ?? '')));

// Fallback doctor name for signature when this assessment row has no doctor_user_id
if ($doctorDisplay === '') {
    $doctorFallbackSql = "SELECT display_name, username FROM users WHERE role='doctor' ORDER BY user_id ASC LIMIT 1";
    $doctorFallbackRes = mysqli_query($conn, $doctorFallbackSql);
    if ($doctorFallbackRes && ($doctorFallbackRow = mysqli_fetch_assoc($doctorFallbackRes))) {
        $doctorDisplay = trim((string)($doctorFallbackRow['display_name'] ?? $doctorFallbackRow['username'] ?? ''));
    }
    if ($doctorFallbackRes) mysqli_free_result($doctorFallbackRes);
}


$rounds = [];
if ($selected !== null) {
    $doctorScore = (int)($selected['total_score'] ?? 0);
    $rounds[1] = [
        'round' => 1,
        'role' => 'doctor',
        'label' => 'ครั้งที่ 1 โดยหมอ',
        'score' => $doctorScore,
        'group' => adlGroupCaregiver($doctorScore),
        'assessed_at' => adlAssessmentRecordedDateTime($selected['assessment_date'] ?? null, $selected['created_at'] ?? null),
        'assessor' => $doctorDisplay !== '' ? $doctorDisplay : '-',
        'fields' => [
            'feeding' => $selected['feeding'] ?? null,
            'grooming' => $selected['grooming'] ?? null,
            'transfer' => $selected['transfer'] ?? null,
            'toilet_use' => $selected['toilet_use'] ?? null,
            'mobility' => $selected['mobility'] ?? null,
            'dressing' => $selected['dressing'] ?? null,
            'stairs' => $selected['stairs'] ?? null,
            'bathing' => $selected['bathing'] ?? null,
            'bowels' => $selected['bowels'] ?? null,
            'bladder' => $selected['bladder'] ?? null,
        ],
        'health' => [
            'assessment_date' => null,
            'weight_kg' => null,
            'bmi' => null,
            'waist_cm' => null,
            'bp' => null,
        ],
        'social' => [
            'regular_caregiver' => null,
            'welfare_status' => null,
            'club_membership' => null,
            'note' => null,
        ],
    ];

    if (tableExists($conn, 'caregiver_adl_history')) {
        $histSql = "SELECT *
                    FROM caregiver_adl_history
                    WHERE adl_id = ?
                      AND (caregiver_user_id = ? OR caregiver_user_id IS NULL)
                      AND COALESCE(assessment_round, 0) >= 2
                    ORDER BY assessment_round ASC, assessed_at ASC, history_id ASC";
        $histStmt = mysqli_prepare($conn, $histSql);
        if ($histStmt) {
            mysqli_stmt_bind_param($histStmt, 'ii', $selectedId, $caregiverId);
            mysqli_stmt_execute($histStmt);
            $histResult = mysqli_stmt_get_result($histStmt);
            while ($hist = mysqli_fetch_assoc($histResult)) {
                $roundNo = (int)($hist['assessment_round'] ?? 0);
                if ($roundNo < 2) {
                    continue;
                }
                $roundScore = (int)($hist['caregiver_total_score'] ?? 0);
                $rounds[$roundNo] = [
                    'round' => $roundNo,
                    'role' => 'caregiver',
                    'label' => 'ครั้งที่ ' . $roundNo . ' โดยแคร์กิฟเวอร์',
                    'score' => $roundScore,
                    'group' => trim((string)($hist['caregiver_group'] ?? '')) !== '' ? trim((string)$hist['caregiver_group']) : adlGroupCaregiver($roundScore),
                    'assessed_at' => $hist['assessed_at'] ?? null,
                    'assessor' => $caregiverDisplay !== '' ? $caregiverDisplay : '-',
                    'fields' => [
                        'feeding' => $hist['feeding'] ?? null,
                        'grooming' => $hist['grooming'] ?? null,
                        'transfer' => $hist['transfer_score'] ?? null,
                        'toilet_use' => $hist['toilet_use'] ?? null,
                        'mobility' => $hist['mobility'] ?? null,
                        'dressing' => $hist['dressing'] ?? null,
                        'stairs' => $hist['stairs'] ?? null,
                        'bathing' => $hist['bathing'] ?? null,
                        'bowels' => $hist['bowels'] ?? null,
                        'bladder' => $hist['bladder'] ?? null,
                    ],
                    'health' => [
                        'assessment_date' => $hist['health_assessment_date'] ?? null,
                        'weight_kg' => $hist['health_weight_kg'] ?? null,
                        'bmi' => $hist['health_bmi'] ?? null,
                        'waist_cm' => $hist['health_waist_cm'] ?? null,
                        'bp' => $hist['health_bp'] ?? null,
                    ],
                    'social' => [
                        'regular_caregiver' => $hist['regular_caregiver'] ?? null,
                        'welfare_status' => $hist['welfare_status'] ?? null,
                        'club_membership' => $hist['club_membership'] ?? null,
                        'note' => $hist['note'] ?? null,
                    ],
                ];
            }
            mysqli_stmt_close($histStmt);
        }
    }
    ksort($rounds);
}

$roundNumbers = array_keys($rounds);
$latestRound = !empty($roundNumbers) ? end($roundNumbers) : 0;
$selectedRound = (int)($_GET['round'] ?? 0);
if ($selectedRound <= 0 || !isset($rounds[$selectedRound])) {
    $selectedRound = (int)$latestRound;
}
$currentRound = ($selectedRound > 0 && isset($rounds[$selectedRound])) ? $rounds[$selectedRound] : null;

if ($currentRound !== null && (int)$selectedRound >= 2 && tableExists($conn, 'caregiver_adl_history')) {
    $healthStmt = mysqli_prepare($conn, "SELECT health_assessment_date,health_weight_kg,health_waist_cm,health_hip_cm,health_waist_hip_ratio,health_body_fat_pct,health_visceral_fat,health_bmr,health_bmi,health_body_age,health_subfat_total,health_subfat_trunk,health_subfat_arms,health_subfat_legs,health_muscle_total,health_muscle_trunk,health_muscle_arms,health_muscle_legs,health_fat_free_mass,health_dtx,health_bp FROM caregiver_adl_history WHERE adl_id=? AND assessment_round=? AND (caregiver_user_id=? OR caregiver_user_id IS NULL) ORDER BY assessed_at DESC, history_id DESC LIMIT 1");
    if ($healthStmt) {
        mysqli_stmt_bind_param($healthStmt,'iii',$selectedId,$selectedRound,$caregiverId);
        mysqli_stmt_execute($healthStmt);
        $healthRes = mysqli_stmt_get_result($healthStmt);
        $hr = $healthRes ? mysqli_fetch_assoc($healthRes) : null;
        if ($hr) {
            $currentRound['health'] = [
                'assessment_date'=>$hr['health_assessment_date']??null,'weight_kg'=>$hr['health_weight_kg']??null,'waist_cm'=>$hr['health_waist_cm']??null,'hip_cm'=>$hr['health_hip_cm']??null,'waist_hip_ratio'=>$hr['health_waist_hip_ratio']??null,
                'body_fat_pct'=>$hr['health_body_fat_pct']??null,'visceral_fat'=>$hr['health_visceral_fat']??null,'bmr'=>$hr['health_bmr']??null,'bmi'=>$hr['health_bmi']??null,'body_age'=>$hr['health_body_age']??null,
                'subfat_total'=>$hr['health_subfat_total']??null,'subfat_trunk'=>$hr['health_subfat_trunk']??null,'subfat_arms'=>$hr['health_subfat_arms']??null,'subfat_legs'=>$hr['health_subfat_legs']??null,
                'muscle_total'=>$hr['health_muscle_total']??null,'muscle_trunk'=>$hr['health_muscle_trunk']??null,'muscle_arms'=>$hr['health_muscle_arms']??null,'muscle_legs'=>$hr['health_muscle_legs']??null,
                'fat_free_mass'=>$hr['health_fat_free_mass']??null,'dtx'=>$hr['health_dtx']??null,'bp'=>$hr['health_bp']??null
            ];
        }
        mysqli_stmt_close($healthStmt);
    }
}
$printMode = isset($_GET['print']) && $_GET['print'] === '1' && $selected !== null;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(appDocumentTitle($selected ? ('ผลสรุปการประเมิน ' . $selectedName) : 'ผลสรุปการประเมิน')) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.summary-view-shell{display:grid;gap:18px;padding-bottom:24px}.top-actions{display:flex;justify-content:flex-start}.summary-panel{background:transparent;border:0;border-radius:0;box-shadow:none;overflow:visible}.panel-section{padding:0;border-top:0;margin-top:18px}.panel-section:first-child{margin-top:0}.report-heading{text-align:center;padding-bottom:10px}.report-title{margin:0;color:#173f3d;font-size:24px;font-weight:900;letter-spacing:.2px}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.summary-card{border:1px solid #d7e1de;border-radius:10px;background:#fff;padding:16px 18px;box-shadow:none}.summary-card span{display:block;color:#6e817d;font-size:12px;font-weight:700;margin-bottom:6px}.summary-card strong{display:block;color:#173f3d;font-size:18px;line-height:1.35;font-weight:800}.summary-inline-text{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline}.summary-inline-text .inline-label{display:inline;color:#6e817d;font-size:12px;font-weight:700;margin:0}.summary-inline-text .inline-value{display:inline;color:#173f3d;font-size:18px;font-weight:800}.assessment-block{background:transparent}.assessment-section-card{background:#fff;border:1px solid #d3dfdb;border-radius:12px;padding:18px 20px;box-shadow:none}.assessment-head{display:block;margin-bottom:10px}.assessment-head h3{margin:0;color:#183f3d;font-size:18px;font-weight:900}.badge{display:inline-flex;align-items:center;padding:5px 9px;border:1px solid #d3dfdb;border-radius:6px;background:#fff;color:#355752;font-size:11px;font-weight:800}.section-panel{background:transparent;border:0;border-radius:0;box-shadow:none;padding:0}.adl-item-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.adl-item-card{border:1px solid #dbe5e2;border-radius:10px;background:#fff;padding:14px 16px}.adl-item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.adl-item-title-wrap{min-width:0}.adl-item-title{color:#173f3d;font-size:14px;font-weight:800;line-height:1.4}.adl-item-subtitle{margin-top:4px;color:#748985;font-size:11px;line-height:1.45;font-weight:600}.adl-item-score{flex:0 0 auto;min-width:34px;text-align:center;padding:4px 8px;border-radius:6px;background:#f8faf9;color:#173f3d;font-size:13px;font-weight:800;border:1px solid #d8e2df}.body-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.body-card{border:1px solid #dbe5e2;border-radius:10px;background:#fff;padding:14px 16px}.body-card span{display:block;color:#748985;font-size:11px;font-weight:700;margin-bottom:5px}.body-card strong{display:block;color:#173f3d;font-size:15px;font-weight:800;line-height:1.35}.report-signatures{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px;margin-top:20px;padding:18px 8px 4px;text-align:center;border-top:1px solid #dce7e4}.report-signature .sig-line{border-top:1px solid #40514f;margin:38px auto 6px;width:86%}.report-signature .sig-name{font-size:13px;font-weight:800;color:#173f3d}.report-signature .sig-role{font-size:11px;color:#667b77;margin-top:3px}.report-signature .sig-date{font-size:10px;color:#71837f;margin-top:5px}.footer-actions{display:flex;justify-content:flex-end;gap:8px;padding:12px 0 0}.print-btn,.secondary-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:10px 16px;border-radius:10px;font:inherit;font-weight:800;cursor:pointer}.print-btn{border:0;background:#4f9fa5;color:#fff}.secondary-btn{border:1px solid #cfd9d6;background:#fff;color:#315d50}.empty-card{background:#fff;border:1px solid #d9e8e4;border-radius:16px;padding:34px 28px;color:#6d8480;text-align:center}.section-chip{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0;background:transparent;border:0;color:#173f3d;font-size:18px;font-weight:800}.section-chip .section-text{display:block}.section-chip small{font-size:11px;font-weight:700;color:#58706b;border:1px solid #cfdad7;padding:4px 8px;border-radius:6px;background:#fff;white-space:nowrap}.section-divider{height:1px;background:#e4ece9;margin:6px 0 2px}.adl-summary-bottom{margin-top:14px;gap:12px}.body-grid{margin-top:14px}.body-card strong{word-break:break-word}
@media(max-width:1050px){.summary-grid,.body-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.report-signatures{grid-template-columns:1fr;gap:10px}.report-signature .sig-line{margin-top:20px}.summary-grid,.body-grid,.adl-item-grid{grid-template-columns:1fr}.assessment-section-card{padding:16px}.footer-actions{padding-top:4px}.assessment-head{margin-bottom:12px}.section-chip{flex-direction:column;align-items:flex-start}.report-title{font-size:21px}}@page{size:A4 portrait;margin:3mm}@media print{html,body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.sidebar,.user-topbar,.top-actions,.footer-actions{display:none!important}.main{margin:0!important;padding:0!important;width:100%!important;max-width:none!important}.summary-view-shell{gap:8px!important;padding:0!important}.summary-panel{box-shadow:none!important;border:0!important;border-radius:0!important;background:#fff!important;overflow:visible!important}.panel-section{margin-top:8px!important;padding:0!important}.report-heading{padding-bottom:12px!important}.report-title{font-size:19px!important;font-weight:800!important;letter-spacing:0!important}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}.summary-card{padding:4px 4px 6px!important;border-radius:0!important;box-shadow:none!important;border:0!important;background:transparent!important;break-inside:avoid}.summary-card span{font-size:9.6px!important;margin-bottom:2px!important;color:#5d706b!important}.summary-card strong{font-size:14px!important;line-height:1.26!important;font-weight:700!important}.summary-inline-text{gap:8px!important}.summary-inline-text .inline-label{font-size:9.6px!important;color:#5d706b!important;font-weight:700!important}.summary-inline-text .inline-value{font-size:14px!important;color:#173f3d!important;font-weight:700!important}.assessment-section-card{padding:11px 12px!important;border-radius:0!important;box-shadow:none!important;border:1px solid #b8c9c4!important;break-inside:avoid}.assessment-head{margin-bottom:6px!important}.section-chip{display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:space-between!important;font-size:12px!important;font-weight:800!important;color:#173f3d!important}.section-chip small{font-size:8px!important;color:#5f726d!important;border:0!important;padding:0!important;border-radius:0!important;background:transparent!important}.section-divider{margin:5px 0 0!important;background:#dce5e2!important}.adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:6px!important;margin-top:7px!important}.adl-item-card{padding:7px 8px!important;border-radius:0!important;break-inside:avoid;border:1px solid #c9d7d3!important;min-height:34px!important}.adl-item-title{font-size:10.2px!important;line-height:1.2!important;font-weight:700!important}.adl-item-subtitle{font-size:7.6px!important;line-height:1.18!important;margin-top:1px!important;color:#627771!important}.adl-item-score{min-width:22px!important;padding:2px 4px!important;border-radius:0!important;font-size:10px!important;background:#fff!important;border:1px solid #c9d7d3!important;color:#173f3d!important}.adl-summary-bottom{margin-top:6px!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}.body-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:6px!important;margin-top:7px!important}.body-card{padding:7px 8px!important;border-radius:0!important;break-inside:avoid;border:1px solid #c9d7d3!important;min-height:34px!important}.body-card span{font-size:7.6px!important;margin-bottom:2px!important;color:#627771!important}.body-card strong{font-size:9.6px!important;line-height:1.2!important;font-weight:700!important}.report-signatures{gap:16px!important;margin-top:8px!important;padding:6px 4px 0!important;break-inside:avoid}.report-signature .sig-line{margin:18px auto 3px!important}.report-signature .sig-name{font-size:9px!important}.report-signature .sig-role,.report-signature .sig-date{font-size:7.5px!important}.print-btn,.secondary-btn{display:none!important}}

/* Thonglang 16 — unboxed ADL detail, in the existing green/teal system style. */
.summary-view-shell{gap:14px;max-width:1480px}
.summary-panel{background:transparent!important;border:0!important;box-shadow:none!important}
.report-heading{text-align:left;padding:6px 0 2px}
.report-title{font-size:23px;line-height:1.4}
.panel-section{margin-top:21px}
.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:38px;row-gap:17px}
.summary-card,.body-card{
    background:transparent!important;border:0!important;border-radius:0!important;
    box-shadow:none!important;padding:2px 0!important;min-width:0
}
.summary-inline-text{display:flex;flex-direction:column;align-items:flex-start;gap:2px}
.summary-inline-text .inline-label,.summary-card>span,.body-card>span{
    display:block;color:#728883;font-size:12px;font-weight:650;line-height:1.5;margin:0 0 2px
}
.summary-inline-text .inline-value,.summary-card>strong,.body-card>strong{
    color:#224c4b;font-size:16px;line-height:1.5;font-weight:800;overflow-wrap:anywhere
}
.assessment-block{background:transparent!important}
.assessment-section-card{
    border:0!important;border-radius:0!important;box-shadow:none!important;
    background:transparent!important;padding:3px 0 8px!important
}
.assessment-head{margin:0 0 11px}
.section-chip{font-size:19px;line-height:1.45;gap:16px}
.section-chip small{border:0!important;background:transparent!important;border-radius:0!important;padding:0!important;color:#718b84;font-size:12px}
.section-divider{height:1px;background:#dbe9e5;margin:10px 0 3px}
.adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:34px;row-gap:0;margin-top:4px}
.adl-item-card{
    border:0!important;border-bottom:1px solid #e4eeeb!important;
    background:transparent!important;border-radius:0!important;box-shadow:none!important;
    min-width:0;padding:13px 0!important
}
.adl-item-top{align-items:center;gap:17px}
.adl-item-title{font-size:15px;line-height:1.4;font-weight:800;color:#234b4a}
.adl-item-subtitle{font-size:12px;line-height:1.55;margin-top:3px;color:#748a85;font-weight:500}
.adl-item-score{
    border:0!important;border-radius:0!important;background:transparent!important;
    color:#236c68!important;font-size:18px!important;line-height:1.3;font-weight:850;
    min-width:24px;padding:0!important;text-align:right
}
.adl-summary-bottom{margin-top:17px;padding:15px 0 0;border-top:1px solid #dbe9e5;row-gap:10px}
.adl-summary-bottom .summary-card>strong{font-size:20px}
.body-grid{grid-template-columns:repeat(3,minmax(0,1fr));column-gap:30px;row-gap:20px;margin-top:18px}
.body-card{align-self:start}
.body-card>strong{font-size:16px}
@media(max-width:1050px){
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .body-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
    .summary-grid,.body-grid,.adl-item-grid{grid-template-columns:1fr;gap:12px}
    .adl-item-grid{gap:0}
    .adl-item-card{padding:12px 0!important}
    .assessment-section-card{padding:2px 0 8px!important}
    .section-chip{align-items:flex-start;flex-direction:column;gap:3px}
    .report-title{font-size:21px}
    .adl-summary-bottom{grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
}
/* Prevent the older print stylesheet from bringing the boxes back. */
@media print{
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:7px 15px!important}
    .summary-card,.body-card{border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;padding:2px 0!important;min-height:0!important}
    .summary-inline-text{display:flex!important;flex-direction:column!important;gap:1px!important}
    .assessment-section-card{border:0!important;border-radius:0!important;background:transparent!important;padding:7px 0!important;box-shadow:none!important}
    .adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:15px!important;row-gap:0!important}
    .adl-item-card{border:0!important;border-bottom:1px solid #e4eeeb!important;background:transparent!important;padding:5px 0!important;min-height:0!important}
    .adl-item-score{border:0!important;background:transparent!important;padding:0!important;min-width:14px!important;color:#234b4a!important}
    .body-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:7px 12px!important}
    .section-chip small{border:0!important;background:transparent!important;padding:0!important}
    .adl-summary-bottom{border-top:1px solid #dbe9e5!important;padding-top:8px!important}
}


/* Thonglang 17 — bring back a subtle outer frame behind the text layout. */
.summary-view-shell{gap:18px!important}
.summary-panel{background:#fff!important;border:1px solid #d8e6e1!important;border-radius:18px!important;box-shadow:0 8px 24px rgba(40,93,86,.05)!important;padding:18px 22px 22px!important}
.report-heading{padding:2px 0 0!important}
.report-title{font-size:24px!important;font-weight:900!important;color:#173f3d!important}
.panel-section{margin-top:18px!important}
.summary-panel>.panel-section:not(.assessment-block) .summary-grid{
    background:#f9fcfb!important;
    border:1px solid #e1ece8!important;
    border-radius:16px!important;
    padding:18px 22px!important;
}
.summary-card,.body-card{padding:4px 0!important}
.assessment-section-card{
    background:#fbfdfc!important;
    border:1px solid #dfeae6!important;
    border-radius:16px!important;
    box-shadow:none!important;
    padding:18px 22px 16px!important;
}
.assessment-head{margin:0 0 12px!important}
.section-chip{font-size:20px!important;line-height:1.4!important}
.section-chip small{color:#6f8681!important;font-size:12px!important}
.section-divider{background:#dfe9e6!important;margin:10px 0 4px!important}
.adl-item-grid{column-gap:40px!important;margin-top:6px!important}
.adl-item-card{padding:13px 0!important;border-bottom:1px solid #e1ece8!important}
.adl-summary-bottom{margin-top:16px!important;padding:16px 0 0!important;border-top:1px solid #dfe9e6!important}
.body-grid{margin-top:16px!important;column-gap:32px!important;row-gap:18px!important}
.footer-actions{padding-top:6px!important}
@media(max-width:700px){
    .summary-panel{padding:16px 16px 18px!important;border-radius:16px!important}
    .summary-panel>.panel-section:not(.assessment-block) .summary-grid,
    .assessment-section-card{padding:14px 14px 12px!important;border-radius:14px!important}
    .report-title{font-size:22px!important}
}
@media print{
    .summary-panel{border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;background:#fff!important}
    .summary-panel>.panel-section:not(.assessment-block) .summary-grid,
    .assessment-section-card{background:transparent!important;border:1px solid #b8c9c4!important;border-radius:0!important;padding:10px 12px!important;box-shadow:none!important}
}



/* ===== Unified assessment summary layout: match other roles ===== */
.summary-view-shell{max-width:1180px;margin:0 auto;gap:16px!important;padding:8px 0 30px!important}
.top-actions{margin-bottom:2px}
.summary-panel{background:#fff!important;border:1px solid #d9e8e4!important;border-radius:22px!important;box-shadow:0 12px 30px rgba(36,108,115,.07)!important;padding:24px 26px 26px!important;overflow:visible!important}
.report-heading{padding:0 0 4px!important;text-align:left!important}
.report-title{font-size:28px!important;line-height:1.25!important;color:#173f3d!important}
.panel-section{margin-top:20px!important;padding:0!important}
.summary-panel>.panel-section:not(.assessment-block) .summary-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:14px!important;background:#f8fcfb!important;border:1px solid #dfece8!important;border-radius:18px!important;padding:18px 20px!important}
.summary-card{border:0!important;background:transparent!important;border-radius:0!important;padding:0!important;box-shadow:none!important}
.summary-inline-text{display:flex!important;flex-direction:column!important;gap:4px!important}
.summary-inline-text .inline-label,.summary-card>span{font-size:12px!important;color:#758985!important;font-weight:700!important;margin:0!important}
.summary-inline-text .inline-value,.summary-card>strong{font-size:17px!important;color:#204d4a!important;font-weight:900!important;line-height:1.45!important}
.assessment-section-card{background:#fbfdfc!important;border:1px solid #dfeae6!important;border-radius:18px!important;padding:20px 22px 18px!important;box-shadow:none!important}
.assessment-head{margin:0 0 12px!important}.section-chip{font-size:20px!important}.section-chip small{border:0!important;background:transparent!important;padding:0!important;color:#728984!important;font-size:12px!important}
.section-divider{height:1px!important;background:#dfe9e6!important;margin:10px 0 4px!important}
.adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:34px!important;row-gap:0!important;margin-top:4px!important}
.adl-item-card{border:0!important;border-bottom:1px solid #e3ece9!important;border-radius:0!important;background:transparent!important;padding:13px 0!important;box-shadow:none!important}
.adl-item-title{font-size:14px!important}.adl-item-subtitle{font-size:11px!important;line-height:1.5!important}.adl-item-score{border:0!important;background:#edf8f6!important;border-radius:8px!important;color:#206a66!important;font-size:16px!important;min-width:34px!important;padding:5px 8px!important}
.adl-summary-bottom{grid-template-columns:repeat(2,minmax(0,1fr))!important;margin-top:16px!important;padding-top:15px!important;border-top:1px solid #dfe9e6!important;gap:22px!important}
.body-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px 24px!important;margin-top:16px!important}
.body-card{border:0!important;background:transparent!important;border-radius:0!important;padding:0 0 12px!important;border-bottom:1px solid #e6efec!important;box-shadow:none!important;min-width:0!important}
.body-card span{font-size:11px!important;color:#758985!important;margin-bottom:4px!important;line-height:1.4!important}.body-card strong{font-size:15px!important;color:#204d4a!important;line-height:1.4!important}
.report-signatures{margin-top:22px!important;padding:14px 4px 0!important;border-top:1px solid #dfe9e6!important;gap:30px!important}
.footer-actions{padding-top:16px!important}
@media(max-width:980px){.summary-panel>.panel-section:not(.assessment-block) .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.body-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:700px){.summary-panel{padding:18px!important}.report-title{font-size:23px!important}.summary-panel>.panel-section:not(.assessment-block) .summary-grid,.adl-item-grid,.body-grid{grid-template-columns:1fr!important}.assessment-section-card{padding:16px!important}.report-signatures{grid-template-columns:1fr!important}}

/* One-page compact print report while keeping caregiver-specific data */
@page{size:A4 portrait;margin:6mm!important}
@media print{
  html,body{background:#fff!important}
  .sidebar,.user-topbar,.top-actions,.footer-actions{display:none!important}
  .main{margin:0!important;padding:0!important;width:100%!important;max-width:none!important}
  .summary-view-shell{max-width:none!important;padding:0!important;display:block!important}
  .summary-panel{border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;background:#fff!important}
  .report-heading{text-align:center!important;padding:0 0 3px!important}
  .report-title{font-size:16px!important;line-height:1.1!important}
  .panel-section{margin-top:5px!important}
  .summary-panel>.panel-section:not(.assessment-block) .summary-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:5px 10px!important;padding:6px 8px!important;border-radius:0!important;border:1px solid #aebfba!important;background:#fff!important}
  .summary-inline-text{gap:0!important}.summary-inline-text .inline-label,.summary-card>span{font-size:7px!important}.summary-inline-text .inline-value,.summary-card>strong{font-size:9px!important;line-height:1.15!important}
  .assessment-section-card{padding:6px 8px!important;border-radius:0!important;border:1px solid #aebfba!important;background:#fff!important;break-inside:avoid!important}
  .assessment-head{margin:0 0 2px!important}.section-chip{font-size:10px!important;line-height:1.2!important}.section-chip small{font-size:6.5px!important}.section-divider{margin:2px 0 0!important}
  .adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:12px!important;margin-top:1px!important}
  .adl-item-card{padding:3px 0!important;min-height:0!important;border-bottom:1px solid #dfe8e5!important;break-inside:avoid!important}
  .adl-item-title{font-size:7.6px!important;line-height:1.1!important}.adl-item-subtitle{font-size:5.7px!important;line-height:1.15!important;margin-top:1px!important}.adl-item-score{font-size:7.5px!important;min-width:16px!important;padding:1px 3px!important;border-radius:0!important;background:#fff!important;border:1px solid #c5d3cf!important}
  .adl-summary-bottom{margin-top:3px!important;padding-top:3px!important;gap:8px!important}.adl-summary-bottom .summary-card>strong{font-size:9px!important}
  .body-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:2px 8px!important;margin-top:3px!important}
  .body-card{padding:2px 0!important;min-height:0!important;border-bottom:1px solid #e2eae7!important;break-inside:avoid!important}.body-card span{font-size:5.6px!important;line-height:1.1!important;margin:0!important}.body-card strong{font-size:7.1px!important;line-height:1.15!important;margin-top:1px!important}
  .report-signatures{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px!important;margin-top:5px!important;padding:3px 2px 0!important;break-inside:avoid!important;border-top:0!important}
  .report-signature .sig-line{margin:11px auto 2px!important;width:80%!important}.report-signature .sig-name{font-size:6.5px!important}.report-signature .sig-role,.report-signature .sig-date{font-size:5.3px!important;margin-top:1px!important}
}


/* ===== Assessment summary: same clean A4 report style as visit summary ===== */
.visit-style-report{max-width:900px!important;margin:0 auto!important;background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important}
.report-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}
.report-toolbar-actions{display:flex;align-items:center;gap:8px}
.report-back-btn,.report-download-btn,.report-print-btn{min-height:38px;padding:0 14px;border-radius:8px;border:1px solid #cbdad7;background:#fff;color:#244e50;font:inherit;font-size:13px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.report-print-btn{background:#50b8b8;border-color:#50b8b8;color:#fff}.report-download-btn:hover,.report-back-btn:hover{background:#f6fbfa}.report-print-btn:hover{background:#43a9aa}
.a4-report-sheet{width:100%;max-width:794px;min-height:1120px;margin:0 auto;background:#fff;border:1px solid #d8dfdd;box-shadow:0 3px 10px rgba(20,50,52,.10);padding:48px 52px 42px;color:#142e30;font-size:12px;line-height:1.45;box-sizing:border-box}
.a4-report-header{text-align:center;margin-bottom:16px}.a4-report-header h1{margin:0;font-size:23px;line-height:1.25;color:#0f2f31}.a4-report-header p{margin:3px 0 13px;font-size:12px;color:#6d7e7d}.report-date-row{display:flex;justify-content:space-between;gap:20px;font-size:10.5px;color:#354e4f;text-align:left}
.simple-report-section{margin-top:14px}.simple-report-section h2{margin:0 0 8px;padding-bottom:4px;border-bottom:1.5px solid #2f4142;font-size:14px;color:#123638}
.simple-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px 18px}.simple-info-grid>div,.health-report-grid>div{display:grid;grid-template-columns:92px 1fr;gap:7px;min-width:0}.simple-info-grid span,.health-report-grid span,.followup-lines span{color:#526967;font-size:10.5px}.simple-info-grid strong,.health-report-grid strong,.followup-lines strong{color:#173e40;font-size:11px;min-width:0;overflow-wrap:anywhere}
.adl-summary-line{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:7px}.adl-summary-line>div{display:grid;grid-template-columns:90px 1fr;gap:7px}.adl-summary-line span{font-size:10.5px;color:#526967}.adl-summary-line strong{font-size:11px;color:#173e40}
.simple-adl-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:28px}.simple-adl-row{display:grid;grid-template-columns:1fr 26px;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #e0e6e4}.simple-adl-row span{font-size:10px;color:#213e40}.simple-adl-row strong{text-align:center;font-size:11px;color:#173e40}
.health-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 14px}.health-report-grid>div{grid-template-columns:1fr;gap:1px;padding-bottom:3px;border-bottom:1px solid #dfe7e5}.health-report-grid span{font-size:9px}.health-report-grid strong{font-size:10.5px}
.followup-lines{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px}.followup-lines>div{display:grid;grid-template-columns:105px 1fr;gap:7px}.followup-lines>div:last-child{grid-column:1/-1}
.simple-signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:44px;padding:0 6px}.simple-signature{text-align:center;font-size:10px;color:#4d6060}.signature-line{border-top:1.3px solid #1f3435;width:92%;margin:64px auto 5px}.simple-signature strong{display:block;font-size:10.5px;color:#173536}.simple-signature span{display:block;margin-top:2px}
@media(max-width:850px){.a4-report-sheet{min-height:0;padding:30px 24px}.simple-info-grid,.health-report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.report-date-row{flex-direction:column;gap:3px}}
@media(max-width:560px){.report-toolbar{align-items:stretch}.report-toolbar-actions{margin-left:auto}.a4-report-sheet{padding:24px 18px}.simple-info-grid,.health-report-grid,.simple-adl-grid,.adl-summary-line,.followup-lines{grid-template-columns:1fr}.followup-lines>div:last-child{grid-column:auto}.simple-signatures{grid-template-columns:1fr;gap:40px}}
@page{size:A4 portrait;margin:8mm}
@media print{html,body{background:#fff!important}.sidebar,.user-topbar,.top-actions,.no-print,.footer-actions{display:none!important}.main{margin:0!important;padding:0!important;width:100%!important}.summary-view-shell{padding:0!important;max-width:none!important}.visit-style-report{max-width:none!important}.a4-report-sheet{width:100%!important;max-width:none!important;min-height:auto!important;margin:0!important;border:0!important;box-shadow:none!important;padding:3mm 5mm!important;font-size:9px!important}.a4-report-header{margin-bottom:3mm!important}.a4-report-header h1{font-size:16px!important}.a4-report-header p{font-size:8px!important;margin:1mm 0 2mm!important}.report-date-row{font-size:7px!important}.simple-report-section{margin-top:2.5mm!important}.simple-report-section h2{font-size:10px!important;margin-bottom:1mm!important;padding-bottom:.5mm!important}.simple-info-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:1mm 3mm!important}.simple-info-grid>div{grid-template-columns:20mm 1fr!important;gap:1mm!important}.simple-info-grid span,.health-report-grid span,.followup-lines span,.adl-summary-line span{font-size:6.5px!important}.simple-info-grid strong,.health-report-grid strong,.followup-lines strong,.adl-summary-line strong{font-size:7.2px!important}.adl-summary-line{gap:5mm!important;margin-bottom:1mm!important}.simple-adl-grid{grid-template-columns:1fr 1fr!important;column-gap:5mm!important}.simple-adl-row{padding:.7mm 0!important}.simple-adl-row span{font-size:6.4px!important}.simple-adl-row strong{font-size:7px!important}.health-report-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:.8mm 3mm!important}.health-report-grid>div{padding-bottom:.5mm!important}.health-report-grid span{font-size:5.8px!important}.health-report-grid strong{font-size:6.6px!important}.followup-lines{grid-template-columns:1fr 1fr!important;gap:1mm 5mm!important}.followup-lines>div:last-child{grid-column:1/-1!important}.simple-signatures{grid-template-columns:1fr 1fr!important;gap:18mm!important;margin-top:8mm!important}.signature-line{margin:14mm auto 1mm!important;width:92%!important}.simple-signature strong{font-size:6.8px!important}.simple-signature span{font-size:6px!important}}

/* Refined follow-up and signature section */
.followup-section{margin-top:18px!important}
.followup-section h2{margin-bottom:10px!important}
.followup-lines{display:grid!important;grid-template-columns:1fr 1fr!important;gap:0 34px!important;border-top:1px solid #dce5e3!important;border-bottom:1px solid #dce5e3!important;padding:4px 0!important}
.followup-lines>div{display:grid!important;grid-template-columns:112px 1fr!important;align-items:start!important;gap:10px!important;padding:8px 0!important;border-bottom:1px solid #eef2f1!important}
.followup-lines>div:nth-last-child(-n+2){border-bottom:0!important}
.followup-lines>div:last-child{grid-column:auto!important}
.followup-lines span{font-size:10.5px!important;color:#617675!important}
.followup-lines strong{font-size:11px!important;color:#173e40!important;font-weight:700!important}
.simple-signatures{display:grid!important;grid-template-columns:1fr 1fr!important;gap:34px!important;margin-top:18px!important;padding:0 10px 4px!important}
.simple-signature{text-align:center!important;color:#405758!important}
.simple-signature .signature-label{display:block!important;font-size:10px!important;color:#5d7070!important;margin-bottom:22px!important}
.signature-line{border-top:1px solid #243b3c!important;width:92%!important;margin:64px auto 5px!important}
.simple-signature strong{display:block!important;font-size:10.5px!important;color:#173536!important;font-weight:700!important;min-height:16px!important}
.simple-signature .signature-role{display:block!important;margin-top:1px!important;font-size:9.5px!important;color:#607171!important}
.simple-signature .signature-date{display:block!important;margin-top:3px!important;font-size:9px!important;color:#7a8888!important}
@media(max-width:560px){.followup-lines{grid-template-columns:1fr!important}.followup-lines>div{grid-template-columns:105px 1fr!important}.followup-lines>div:nth-last-child(-n+2){border-bottom:1px solid #eef2f1!important}.followup-lines>div:last-child{border-bottom:0!important}.simple-signatures{grid-template-columns:1fr!important;gap:34px!important;padding:0!important}}
@media print{.followup-section{margin-top:2mm!important}.followup-lines{grid-template-columns:1fr 1fr!important;gap:0 5mm!important;padding:0!important}.followup-lines>div{grid-template-columns:22mm 1fr!important;gap:1mm!important;padding:1mm 0!important}.followup-lines span{font-size:6px!important}.followup-lines strong{font-size:6.8px!important}.simple-signatures{grid-template-columns:1fr 1fr!important;gap:10mm!important;margin-top:3mm!important;padding:0 3mm!important}.simple-signature .signature-label{font-size:5.8px!important;margin-bottom:5mm!important}.signature-line{width:92%!important;margin:14mm auto .7mm!important}.simple-signature strong{font-size:6.5px!important}.simple-signature .signature-role{font-size:5.7px!important}.simple-signature .signature-date{font-size:5.2px!important;margin-top:.6mm!important}}

</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="summary-view-shell">
    
    <?php if ($selected === null || $currentRound === null): ?>
        <div class="empty-card">ไม่พบข้อมูลผลการประเมิน</div>
    <?php else: ?>
        <section class="summary-panel visit-style-report">
            <div class="report-toolbar no-print">
                <a class="report-back-btn" href="caregiver_adl_summary_view.php?adl_id=<?= (int)$selectedId ?>">ย้อนกลับ</a>
                <div class="report-toolbar-actions">
                    <button type="button" id="downloadCaregiverPdf" class="report-download-btn">ดาวน์โหลด PDF</button>
                    <button type="button" class="report-print-btn" onclick="window.print()">พิมพ์</button>
                </div>
            </div>

            <article class="a4-report-sheet">
                <header class="a4-report-header">
                    <h1>รายงานสรุปผลการประเมินผู้สูงอายุ</h1>
                    <p>ระบบบันทึกสุขภาพผู้สูงอายุ <?= e(appName()) ?></p>
                    <div class="report-date-row">
                        <span>วันที่ประเมิน <?= e(adlThaiDateTime($currentRound['assessed_at'] ?? null)) ?></span>
                        <span>วันที่ออกรายงาน <?= e(adlThaiDate(date('Y-m-d'))) ?></span>
                    </div>
                </header>

                <section class="simple-report-section">
                    <h2>ข้อมูลผู้สูงอายุ</h2>
                    <div class="simple-info-grid">
                        <div><span>ชื่อ–นามสกุล</span><strong><?= e($selected['Fullname'] ?? '-') ?></strong></div>
                        <div><span>อายุ</span><strong><?= e(summaryValue($selected['Age'] ?? null,' ปี')) ?></strong></div>
                        <div><span>เพศ</span><strong><?= e(summaryValue($selected['Gender'] ?? null)) ?></strong></div>
                        <div><span>หมู่บ้าน</span><strong><?= e(summaryValue($selected['villagename'] ?? null)) ?></strong></div>
                        <div><span>รอบการประเมิน</span><strong><?= e($currentRound['label'] ?? '-') ?></strong></div>
                        <div><span>ผู้ประเมิน</span><strong><?= e($currentRound['assessor'] ?? '-') ?></strong></div>
                    </div>
                </section>

                <section class="simple-report-section">
                    <h2>สรุปผลการประเมิน ADL</h2>
                    <div class="adl-summary-line">
                        <div><span>คะแนนรวม</span><strong><?= (int)($currentRound['score'] ?? 0) ?>/20 คะแนน</strong></div>
                        <div><span>ผลการจัดกลุ่ม</span><strong><?= e($currentRound['group'] ?? '-') ?></strong></div>
                    </div>
                    <div class="simple-adl-grid">
                        <?php foreach ($adlItems as $adlKey => $adlItem):
                            $adlScore = $currentRound['fields'][$adlKey] ?? null;
                        ?>
                        <div class="simple-adl-row">
                            <span><?= e($adlItem['title']) ?></span>
                            <strong><?= $adlScore !== null && $adlScore !== '' ? (int)$adlScore : '-' ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php $h = $currentRound['health'] ?? []; ?>
                <section class="simple-report-section">
                    <h2>สรุปผลการประเมินภาวะสุขภาพ</h2>
                    <div class="health-report-grid">
                        <div><span>วันที่ประเมิน</span><strong><?= e(summaryValue($h['assessment_date'] ?? null)) ?></strong></div>
                        <div><span>น้ำหนัก</span><strong><?= e(summaryValue($h['weight_kg'] ?? null,' กก.')) ?></strong></div>
                        <div><span>รอบเอว</span><strong><?= e(summaryValue($h['waist_cm'] ?? null,' ซม.')) ?></strong></div>
                        <div><span>รอบสะโพก</span><strong><?= e(summaryValue($h['hip_cm'] ?? null,' ซม.')) ?></strong></div>
                        <div><span>อัตราส่วนเอว/สะโพก</span><strong><?= e(summaryValue($h['waist_hip_ratio'] ?? null)) ?></strong></div>
                        <div><span>ไขมันร่างกาย</span><strong><?= e(summaryValue($h['body_fat_pct'] ?? null,' %')) ?></strong></div>
                        <div><span>ไขมันช่องท้อง</span><strong><?= e(summaryValue($h['visceral_fat'] ?? null)) ?></strong></div>
                        <div><span>BMR</span><strong><?= e(summaryValue($h['bmr'] ?? null,' kcal')) ?></strong></div>
                        <div><span>BMI</span><strong><?= e(summaryValue($h['bmi'] ?? null)) ?></strong></div>
                        <div><span>อายุร่างกาย</span><strong><?= e(summaryValue($h['body_age'] ?? null,' ปี')) ?></strong></div>
                        <div><span>ไขมันใต้ผิวหนังทั้งตัว</span><strong><?= e(summaryValue($h['subfat_total'] ?? null,' %')) ?></strong></div>
                        <div><span>ไขมันใต้ผิวหนังลำตัว</span><strong><?= e(summaryValue($h['subfat_trunk'] ?? null,' %')) ?></strong></div>
                        <div><span>ไขมันใต้ผิวหนังแขน</span><strong><?= e(summaryValue($h['subfat_arms'] ?? null,' %')) ?></strong></div>
                        <div><span>ไขมันใต้ผิวหนังขา</span><strong><?= e(summaryValue($h['subfat_legs'] ?? null,' %')) ?></strong></div>
                        <div><span>กล้ามเนื้อทั้งตัว</span><strong><?= e(summaryValue($h['muscle_total'] ?? null,' %')) ?></strong></div>
                        <div><span>กล้ามเนื้อลำตัว</span><strong><?= e(summaryValue($h['muscle_trunk'] ?? null,' %')) ?></strong></div>
                        <div><span>กล้ามเนื้อแขน</span><strong><?= e(summaryValue($h['muscle_arms'] ?? null,' %')) ?></strong></div>
                        <div><span>กล้ามเนื้อขา</span><strong><?= e(summaryValue($h['muscle_legs'] ?? null,' %')) ?></strong></div>
                        <div><span>มวลกายไร้ไขมัน</span><strong><?= e(summaryValue($h['fat_free_mass'] ?? null,' กก.')) ?></strong></div>
                        <div><span>น้ำตาลปลายนิ้ว (DTX)</span><strong><?= e(summaryValue($h['dtx'] ?? null,' mg/dL')) ?></strong></div>
                        <div><span>ความดันโลหิต (BP)</span><strong><?= e(summaryValue($h['bp'] ?? null)) ?></strong></div>
                    </div>
                </section>

                <section class="simple-report-section followup-section">
                    <h2>สรุปผลและข้อมูลประกอบ</h2>
                    <div class="followup-lines">
                        <div><span>ผลการประเมิน</span><strong><?= e($currentRound['group'] ?? '-') ?> (<?= (int)($currentRound['score'] ?? 0) ?>/20 คะแนน)</strong></div>
                        <div><span>ผู้ดูแลประจำ</span><strong><?= e(summaryValue($currentRound['social']['regular_caregiver'] ?? null)) ?></strong></div>
                        <div><span>สิทธิ / สวัสดิการ</span><strong><?= e(summaryValue($currentRound['social']['welfare_status'] ?? null)) ?></strong></div>
                        <div><span>หมายเหตุ</span><strong><?= e(summaryValue($currentRound['social']['note'] ?? null)) ?></strong></div>
                    </div>
                </section>

                <div class="simple-signatures" aria-label="พื้นที่ลงชื่อ">
                    <div class="simple-signature">
                        <div class="signature-line"></div>
                        <strong><?= e($caregiverDisplay !== '' ? $caregiverDisplay : '........................................') ?></strong>
                        <span class="signature-role">แคร์กิฟเวอร์</span>
                        <span class="signature-date">วันที่ ........../........../..........</span>
                    </div>
                    <div class="simple-signature">
                        <div class="signature-line"></div>
                        <strong><?= e($doctorDisplay !== '' ? $doctorDisplay : '........................................') ?></strong>
                        <span class="signature-role">หมอ</span>
                        <span class="signature-date">วันที่ ........../........../..........</span>
                    </div>
                </div>
            </article>
        </section>
    <?php endif; ?>
</div>
</main>
<script src="report_pdf_download.js?v=20260929sharp"></script>
<script>
const caregiverPdfData=<?=json_encode([
  'layout'=>'caregiver_assessment_summary',
  'org'=>appName(),
  'title'=>'รายงานสรุปผลการประเมินผู้สูงอายุ',
  'subtitle'=>$currentRound ? ($currentRound['label'].' · '.adlThaiDateTime($currentRound['assessed_at'] ?? null)) : '',
  'report_date'=>adlThaiDate(date('Y-m-d')),
  'sections'=>$currentRound ? [
    ['heading'=>'ข้อมูลผู้สูงอายุ','items'=>[
       'ชื่อ–นามสกุล: '.($selected['Fullname']??'-').' อายุ '.($selected['Age']??'-').' ปี',
       'หมู่บ้าน: '.($selected['villagename']??'-'),
       'ผู้ประเมิน: '.($currentRound['assessor']??'-')
    ]],
    ['heading'=>'ผลสรุปการประเมิน','items'=>[
       'คะแนนรวม '.(int)($currentRound['score']??0).'/20 คะแนน กลุ่ม '.($currentRound['group']??'-'),
       'รอบการประเมิน: '.($currentRound['label']??'-')
    ]],
    ['heading'=>'ผลการประเมินแต่ละกิจกรรม','items'=>array_map(
        static fn($key,$item)=>$item['title'].' : '.(isset($currentRound['fields'][$key])?$currentRound['fields'][$key]:'-').' คะแนน',
        array_keys($adlItems),array_values($adlItems)
    )],
    ['heading'=>'การติดตามและข้อมูลสุขภาพ','items'=>[
       'น้ำหนัก: '.summaryValue($currentRound['health']['weight_kg']??null,' กก.').' BMI: '.summaryValue($currentRound['health']['bmi']??null),
       'ความดัน: '.summaryValue($currentRound['health']['bp']??null),
       'หมายเหตุ: '.summaryValue($currentRound['social']['note']??null)
    ]]
  ] : [],
  'caregiver'=>$caregiverDisplay?:'แคร์กิฟเวอร์',
  'doctor'=>$doctorDisplay?:'หมอ',
  'director'=>directorName(),
  'patient'=>[
    'fullname'=>$selected['Fullname']??'-',
    'age'=>$selected['Age']??'-',
    'gender'=>$selected['Gender']??'-',
    'village'=>$selected['villagename']??'-',
    'assessor'=>$currentRound['assessor']??'-',
    'round'=>$currentRound['label']??'-',
    'assessed_at'=>adlThaiDateTime($currentRound['assessed_at']??null)
  ],
  'score'=>(int)($currentRound['score']??0),
  'group'=>$currentRound['group']??'-',
  'adl_items'=>array_map(
    static fn($key,$item)=>['label'=>$item['title'],'score'=>(isset($currentRound['fields'][$key])?$currentRound['fields'][$key]:'-')],
    array_keys($adlItems),array_values($adlItems)
  ),
  'health_items'=>[
    ['label'=>'วันที่ประเมิน','value'=>summaryValue($h['assessment_date']??null)],
    ['label'=>'น้ำหนัก','value'=>summaryValue($h['weight_kg']??null,' กก.')],
    ['label'=>'รอบเอว','value'=>summaryValue($h['waist_cm']??null,' ซม.')],
    ['label'=>'รอบสะโพก','value'=>summaryValue($h['hip_cm']??null,' ซม.')],
    ['label'=>'อัตราส่วนเอว/สะโพก','value'=>summaryValue($h['waist_hip_ratio']??null)],
    ['label'=>'ไขมันร่างกาย','value'=>summaryValue($h['body_fat_pct']??null,' %')],
    ['label'=>'ไขมันช่องท้อง','value'=>summaryValue($h['visceral_fat']??null)],
    ['label'=>'BMR','value'=>summaryValue($h['bmr']??null,' kcal')],
    ['label'=>'BMI','value'=>summaryValue($h['bmi']??null)],
    ['label'=>'อายุร่างกาย','value'=>summaryValue($h['body_age']??null,' ปี')],
    ['label'=>'ไขมันใต้ผิวหนังทั้งตัว','value'=>summaryValue($h['subfat_total']??null,' %')],
    ['label'=>'ไขมันใต้ผิวหนังลำตัว','value'=>summaryValue($h['subfat_trunk']??null,' %')],
    ['label'=>'ไขมันใต้ผิวหนังแขน','value'=>summaryValue($h['subfat_arms']??null,' %')],
    ['label'=>'ไขมันใต้ผิวหนังขา','value'=>summaryValue($h['subfat_legs']??null,' %')],
    ['label'=>'กล้ามเนื้อทั้งตัว','value'=>summaryValue($h['muscle_total']??null,' %')],
    ['label'=>'กล้ามเนื้อลำตัว','value'=>summaryValue($h['muscle_trunk']??null,' %')],
    ['label'=>'กล้ามเนื้อแขน','value'=>summaryValue($h['muscle_arms']??null,' %')],
    ['label'=>'กล้ามเนื้อขา','value'=>summaryValue($h['muscle_legs']??null,' %')],
    ['label'=>'มวลกายไร้ไขมัน','value'=>summaryValue($h['fat_free_mass']??null,' กก.')],
    ['label'=>'น้ำตาลปลายนิ้ว (DTX)','value'=>summaryValue($h['dtx']??null,' mg/dL')],
    ['label'=>'ความดันโลหิต (BP)','value'=>summaryValue($h['bp']??null)]
  ],
  'followup_items'=>[
    ['label'=>'ผลการประเมิน','value'=>($currentRound['group']??'-').' ('.(int)($currentRound['score']??0).'/20 คะแนน)'],
    ['label'=>'ผู้ดูแลประจำ','value'=>summaryValue($currentRound['social']['regular_caregiver']??null)],
    ['label'=>'สิทธิ / สวัสดิการ','value'=>summaryValue($currentRound['social']['welfare_status']??null)],
    ['label'=>'หมายเหตุ','value'=>summaryValue($currentRound['social']['note']??null)]
  ],
  'filename'=>'สรุป_ADL_'.(int)$selectedId.'_รอบ_'.(int)$selectedRound.'.pdf'
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const caregiverPdfButton=document.getElementById('downloadCaregiverPdf');
if(caregiverPdfButton)caregiverPdfButton.addEventListener('click',async function(){this.disabled=true;try{await downloadReportPdf(caregiverPdfData);}catch(err){alert('ไม่สามารถดาวน์โหลด PDF ได้: '+err.message);}finally{this.disabled=false;}});
</script>
</body>
</html>
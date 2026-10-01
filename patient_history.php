<?php
require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('admin', 'doctor');
mysqli_set_charset($conn, 'utf8mb4');

function pageTableExists(mysqli $conn, string $table): bool {
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}
function pageQueryAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    if ($types !== '' && $params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}
function pageThaiDate(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}
function pageThaiMonthYear(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    $months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    return $months[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}
function pageThaiDateTime(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    return pageThaiDate(date('Y-m-d', $ts)) . ' • ' . date('H:i', $ts) . ' น.';
}
function pageShow($value, string $fallback = '-'): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}
function pageMeasure($value, string $suffix = '', string $fallback = '-'): string {
    if ($value === null || $value === '') return $fallback;
    return trim((string)$value) . ($suffix !== '' ? ' ' . $suffix : '');
}
function pageVisitNote(array $row): string {
    $parts = [];
    $reason = trim((string)($row['referral_reason'] ?? ''));
    $note = trim((string)($row['note'] ?? ''));
    if ($reason !== '') $parts[] = $reason;
    if ($note !== '') $parts[] = $note;
    return $parts ? implode("\n", $parts) : '-';
}

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(400);
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>ไม่พบข้อมูล</title></head><body>ไม่พบรหัสผู้สูงอายุ</body></html>';
    exit;
}

$patientRows = pageQueryAll(
    $conn,
    "SELECT p.Patient_id,p.Firstname,p.Lastname,p.Fullname,p.Gender,p.Age,p.Phone,p.Disease,p.Photo,p.Address,p.Latitude,p.Longitude,v.villagename
     FROM patient p
     LEFT JOIN village v ON v.village_id = p.Village_id
     WHERE p.Patient_id = ? LIMIT 1",
    'i',
    [$patientId]
);
$patient = $patientRows[0] ?? null;
if (!$patient) {
    http_response_code(404);
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>ไม่พบข้อมูล</title></head><body>ไม่พบข้อมูลผู้สูงอายุ</body></html>';
    exit;
}

$doctorAdl = pageTableExists($conn, 'adl_assessment')
    ? pageQueryAll($conn, "SELECT a.assessment_date,a.total_score,a.note,a.regular_caregiver,a.welfare_status,a.club_membership,a.created_at,
                COALESCE(u.display_name,u.username,'-') AS doctor_name
         FROM adl_assessment a
         LEFT JOIN users u ON u.user_id = a.doctor_user_id
         WHERE a.patient_id = ?
         ORDER BY a.assessment_date ASC, a.adl_id ASC", 'i', [$patientId])
    : [];
$caregiverAdl = pageTableExists($conn, 'caregiver_adl_history')
    ? pageQueryAll($conn, "SELECT h.assessment_round,h.assessed_at,h.doctor_total_score,h.caregiver_total_score,h.caregiver_group,
                h.health_weight_kg,h.health_bmi,h.health_bp,h.regular_caregiver,h.note,
                COALESCE(u.display_name,u.username,'-') AS caregiver_name
         FROM caregiver_adl_history h
         LEFT JOIN users u ON u.user_id = h.caregiver_user_id
         WHERE h.patient_id = ?
         ORDER BY h.assessment_round ASC, h.assessed_at ASC, h.history_id ASC", 'i', [$patientId])
    : [];
$visitHistory = pageTableExists($conn, 'caregiver_visit_record')
    ? pageQueryAll($conn, "SELECT v.visit_date,v.visit_time,v.visit_no,v.visit_type,v.general_condition,v.bp_systolic,v.bp_diastolic,
                v.pulse,v.temperature,v.disease_snapshot,v.care_actions,v.referral_type,v.referral_reason,
                v.next_visit_date,v.note,v.created_at,
                COALESCE(u.display_name,u.username,'-') AS caregiver_name
         FROM caregiver_visit_record v
         LEFT JOIN users u ON u.user_id = v.caregiver_user_id
         WHERE v.patient_id = ?
         ORDER BY v.visit_date ASC, v.visit_id ASC", 'i', [$patientId])
    : [];
$visitHistoryByMonth = [];
foreach ($visitHistory as $visitIndex => $visitRow) {
    $monthKey = !empty($visitRow['visit_date']) ? date('Y-m', strtotime($visitRow['visit_date'])) : 'unknown';
    if (!isset($visitHistoryByMonth[$monthKey])) $visitHistoryByMonth[$monthKey] = [];
    $visitHistoryByMonth[$monthKey][$visitIndex] = $visitRow;
}
$healthHistory = pageTableExists($conn, 'health_assessment')
    ? pageQueryAll($conn, "SELECT h.assessment_date,h.height_cm,h.weight_kg,h.waist_cm,h.hip_cm,h.bmi,h.body_fat_percent,h.blood_pressure,h.note,h.created_at,
                COALESCE(u.display_name,u.username,'-') AS doctor_name
         FROM health_assessment h
         LEFT JOIN users u ON u.user_id = h.doctor_user_id
         WHERE h.patient_id = ?
         ORDER BY h.assessment_date ASC, h.health_id ASC", 'i', [$patientId])
    : [];
$photoUrl = thonglangUploadedImageUrl($patient['Photo'] ?? '');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ประวัติผู้สูงอายุ | <?= e(appName()) ?></title>
<link rel="stylesheet" href="assets/pastel_theme.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.history-page{padding-bottom:42px}.history-shell{display:grid;gap:18px}
.history-sections-grid{display:grid;grid-template-columns:1fr;gap:18px;align-items:start}
.page-topbar{display:flex;justify-content:flex-start;align-items:center;margin-bottom:2px}.back-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border-radius:16px;background:linear-gradient(135deg,#59bfc0 0%,#49aaac 100%);border:1px solid #49aaac;color:#fff;text-decoration:none;font-size:14px;font-weight:900;letter-spacing:.01em;box-shadow:0 12px 24px rgba(88,191,192,.22);transition:.18s ease}.back-btn:hover{transform:translateY(-1px);filter:brightness(.98);box-shadow:0 14px 28px rgba(88,191,192,.28)}
.patient-card{display:grid;grid-template-columns:130px minmax(0,1fr);gap:18px;padding:22px;border:1px solid #d8ebe8;border-radius:28px;background:#fff;box-shadow:0 12px 30px rgba(36,108,115,.05)}.patient-photo{width:130px;height:130px;border-radius:24px;overflow:hidden;border:1px solid #d9ebe8;background:linear-gradient(135deg,#eef9fb,#f4faf8);display:flex;align-items:center;justify-content:center;color:#6f8782;font-size:12px;font-weight:800;text-align:center}.patient-photo img{width:100%;height:100%;object-fit:cover;display:block}
.patient-main{display:grid;gap:14px}.patient-title{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}.patient-name{margin:0;color:#1c4d52;font-size:30px;line-height:1.2}.patient-subtitle{margin-top:6px;color:#7b908c;font-size:13px}.patient-info-panel{padding:16px;border:1px solid #dcebe8;border-radius:22px;background:linear-gradient(180deg,#f9fcfc 0%,#f5fbfb 100%);display:grid;gap:14px}.patient-chips{display:flex;gap:10px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;min-height:38px;padding:8px 14px;border-radius:999px;border:1px solid #dcebe8;background:#ffffff;color:#2a5d60;font-size:12px;font-weight:900}.chip.primary{background:#67c6c8;border-color:#67c6c8;color:#fff}.chip.warn{background:#fff7ee;border-color:#efdfc9;color:#8b6632}
.patient-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:0}.meta-box{padding:14px 15px;border:1px solid #deecea;border-radius:16px;background:#fff}.meta-box span{display:block;color:#78908b;font-size:11px;margin-bottom:6px}.meta-box strong{display:block;color:#244e48;font-size:14px;line-height:1.6}.meta-box.wide{grid-column:span 2}
.section-card{border:1px solid #d8ebe8;border-radius:26px;background:#fff;overflow:hidden;box-shadow:0 12px 30px rgba(36,108,115,.05)}.section-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;background:linear-gradient(180deg,#ebf7f5 0%,#e3f3f1 100%);border-bottom:1px solid #d7eae7}.section-head h2{margin:0;color:#214f55;font-size:21px;line-height:1.3}
.count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:84px;padding:9px 14px;border-radius:999px;background:#fff;border:1px solid #d5e8e4;color:#2e625c;font-weight:900;font-size:12px;white-space:nowrap}
.section-body{padding:18px}.record-actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;align-items:center}.record-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #d7e8e4;background:#f9fcfc;color:#285d60;font:inherit;font-size:13px;font-weight:800;cursor:pointer;transition:.18s ease}.record-btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(36,108,115,.07)}.record-btn.is-active{background:linear-gradient(135deg,#59bfc0 0%,#49aaac 100%);border-color:#49aaac;color:#fff;box-shadow:0 10px 22px rgba(88,191,192,.24)}
.record-more-link{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 16px;border-radius:12px;background:#f5f9ff;border:1px solid #cfe0f0;color:#355f85;text-decoration:none;font-size:13px;font-weight:900}
.detail-panel{display:none}.detail-panel.is-active{display:block}.detail-card{border:1px solid #dcebe8;border-radius:22px;background:linear-gradient(180deg,#fcfefe 0%,#f8fcfb 100%);overflow:hidden}
.detail-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start;padding:18px}.detail-kicker{font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#7a908d;margin-bottom:6px}.detail-title{margin:0;color:#204c51;font-size:18px;line-height:1.35}.detail-meta{margin-top:6px;color:#7a908d;font-size:12px;line-height:1.6}
.score-badge{display:inline-flex;align-items:center;justify-content:center;min-width:94px;min-height:48px;padding:10px 14px;border-radius:16px;background:#eaf8f5;color:#215e58;font-size:20px;font-weight:900}.preview-strip{display:flex;flex-wrap:wrap;gap:8px;padding:0 18px 14px}.preview-pill{display:inline-flex;align-items:center;min-height:34px;padding:7px 12px;border-radius:999px;border:1px solid #dcebe8;background:#fff;color:#345f5d;font-size:12px;font-weight:800;line-height:1.45}
.detail-grid-wrap{padding:0 18px 18px}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:16px;border-top:1px dashed #d9e9e6;background:#fff}.detail-field{padding:12px 13px;border:1px solid #e3efed;border-radius:13px;background:#fbfefe}.detail-field span{display:block;color:#7a908d;font-size:11px;margin-bottom:5px}.detail-field strong{display:block;color:#294f53;font-size:13px;line-height:1.6;font-weight:800}.detail-field.full{grid-column:1/-1}.empty-box{padding:40px 16px;text-align:center;color:#7a908d;border:1px dashed #d9e7e5;border-radius:18px;background:#fbfefe}
@media(max-width:1380px){.history-sections-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:1180px){.patient-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.meta-box.wide{grid-column:auto}}@media(max-width:980px){.history-sections-grid{grid-template-columns:1fr}}@media(max-width:760px){.patient-card,.detail-head{grid-template-columns:1fr}.patient-photo{width:108px;height:108px}.patient-meta,.detail-grid{grid-template-columns:1fr}.patient-name{font-size:26px}.back-btn{width:auto;min-width:140px}.page-topbar{justify-content:flex-start}}
.month-group{margin-bottom:24px}.month-group:last-child{margin-bottom:0}.month-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;padding:10px 14px;border-radius:14px;background:#f3faf9;color:#24595d;font-size:15px;font-weight:900}.month-count{font-size:12px;color:#6f8884;font-weight:800}
.history-table-wrap{overflow:auto;border:1px solid #dcebe8;border-radius:18px;background:#fff}.history-table{width:100%;min-width:820px;border-collapse:separate;border-spacing:0}.history-table th,.history-table td{padding:15px 14px;text-align:left;vertical-align:middle;border-bottom:1px solid #e5efed}.history-table th{background:#eaf6f5;color:#275d61;font-size:13px;font-weight:900}.history-table tbody tr.data-row:nth-child(4n+1) td{background:#fbfefe}.table-primary{font-weight:900;color:#214f55}.table-meta{margin-top:4px;color:#7b918d;font-size:12px}.table-score{display:inline-flex;align-items:center;justify-content:center;min-width:64px;padding:7px 11px;border-radius:999px;background:#eaf8f5;color:#215e58;font-weight:900}.detail-toggle-btn{border:0;background:transparent;color:#286267;font:inherit;font-size:13px;font-weight:900;cursor:pointer;padding:8px 0}.detail-toggle-btn:hover{text-decoration:underline;text-underline-offset:3px}.inline-detail-row{display:none}.inline-detail-row.is-open{display:table-row}.inline-detail-row td{padding:0!important;background:#fff!important}.inline-detail-card{padding:16px 18px 18px;background:#f9fcfc;border-top:1px solid #e2efed}.section-card{width:100%}

/* Consistent "รายละเอียดเพิ่มเติม" action */
.detail-toggle-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:46px!important;padding:0 18px!important;border:1px solid #bcdedb!important;border-radius:14px!important;background:#fff!important;color:#215a60!important;font-size:13px!important;font-weight:900!important;box-shadow:none!important;white-space:nowrap!important;text-decoration:none!important}
.detail-toggle-btn:hover{background:#f6fbfb!important;border-color:#9fd1cd!important;text-decoration:none!important}

</style>
</head>
<body class="role-page history-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="history-shell">
    <div class="page-topbar"><a class="back-btn" href="patient.php">ย้อนกลับ</a></div>

    <section class="patient-card">
        <div class="patient-photo"><?php if ($photoUrl !== ''): ?><img src="<?= e($photoUrl) ?>" alt="รูปผู้สูงอายุ"><?php else: ?><span>ไม่มีรูปภาพ</span><?php endif; ?></div>
        <div>
            <div class="patient-title">
                <div><h2 class="patient-name"><?= e($patient['Fullname']) ?></h2></div>
            </div>
            <div class="patient-meta">
                <div class="meta-box"><span>ชื่อ</span><strong><?= e(pageShow($patient['Firstname'])) ?></strong></div>
                <div class="meta-box"><span>สกุล</span><strong><?= e(pageShow($patient['Lastname'])) ?></strong></div>
                <div class="meta-box"><span>อายุ</span><strong><?= (int)($patient['Age'] ?? 0) ?> ปี</strong></div>
                <div class="meta-box"><span>เพศ</span><strong><?= e(pageShow($patient['Gender'])) ?></strong></div>
                <div class="meta-box"><span>หมู่บ้าน</span><strong><?= e(pageShow($patient['villagename'])) ?></strong></div>
                <div class="meta-box"><span>เบอร์โทร</span><strong><?= e(pageShow($patient['Phone'])) ?></strong></div>
                <div class="meta-box wide"><span>ที่อยู่</span><strong><?= e(pageShow($patient['Address'])) ?></strong></div>
                <div class="meta-box wide"><span>โรคประจำตัว</span><strong><?= e(pageShow($patient['Disease'])) ?></strong></div>
            </div>
        </div>
    </section>

    <div class="history-sections-grid">
<section class="section-card"><div class="section-head"><h2>ประวัติการประเมิน ADL โดยหมอ</h2><span class="count-badge"><?=number_format(count($doctorAdl))?> รายการ</span></div><div class="section-body"><?php if($doctorAdl):?><div class="history-table-wrap"><table class="history-table"><thead><tr><th>วันที่ประเมิน</th><th>ผู้ประเมิน</th><th>คะแนนรวม</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach(array_slice($doctorAdl,0,5,true) as $index=>$row):?><tr class="data-row"><td><div class="table-primary"><?=e(pageThaiDate($row['assessment_date']??null))?></div></td><td><?=e(pageShow($row['doctor_name']))?></td><td><span class="table-score"><?=isset($row['total_score'])?(int)$row['total_score'].'/20':'-'?></span></td><td><button type="button" class="detail-toggle-btn" data-detail="doctor-detail-<?=$index?>">รายละเอียดเพิ่มเติม</button></td></tr><tr id="doctor-detail-<?=$index?>" class="inline-detail-row"><td colspan="4"><div class="inline-detail-card"><div class="detail-grid"><div class="detail-field"><span>คะแนนรวม</span><strong><?= isset($row['total_score']) ? (int)$row['total_score'].'/20' : '-' ?></strong></div><div class="detail-field"><span>ผู้ประเมิน</span><strong><?= e(pageShow($row['doctor_name'])) ?></strong></div><div class="detail-field"><span>วันที่ประเมิน</span><strong><?= e(pageThaiDate($row['assessment_date'] ?? null)) ?></strong></div><div class="detail-field"><span>วันที่บันทึก</span><strong><?= e(pageThaiDateTime($row['created_at'] ?? null)) ?></strong></div><div class="detail-field"><span>ผู้ดูแลประจำ</span><strong><?= e(pageShow($row['regular_caregiver'])) ?></strong></div><div class="detail-field"><span>สิทธิ/สวัสดิการ</span><strong><?= e(pageShow($row['welfare_status'])) ?></strong></div><div class="detail-field"><span>สมาชิกชมรม</span><strong><?= e(pageShow($row['club_membership'])) ?></strong></div><div class="detail-field full"><span>หมายเหตุ</span><strong><?= nl2br(e(pageShow($row['note']))) ?></strong></div></div></div></td></tr><?php endforeach;?></tbody></table></div><?php if(count($doctorAdl)>5):?><div style="margin-top:14px"><a class="record-more-link" href="patient_history_more.php?patient_id=<?=(int)$patientId?>&section=doctor_adl">ดูเพิ่มเติม</a></div><?php endif;?><?php else:?><div class="empty-box">ยังไม่มีประวัติการประเมิน ADL โดยหมอ</div><?php endif;?></div></section>
<section class="section-card"><div class="section-head"><h2>ประวัติการประเมินครั้งถัดไป / แคร์กิฟเวอร์</h2><span class="count-badge"><?=number_format(count($caregiverAdl))?> รายการ</span></div><div class="section-body"><?php if($caregiverAdl):?><div class="history-table-wrap"><table class="history-table"><thead><tr><th>วันที่ประเมิน</th><th>ผู้ประเมิน</th><th>คะแนนรวม</th><th>กลุ่ม ADL</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach(array_slice($caregiverAdl,0,5,true) as $index=>$row):$roundLabel=(int)($row['assessment_round']??($index+1));?><tr class="data-row"><td><div class="table-primary"><?=e(pageThaiDateTime($row['assessed_at']??null))?></div><div class="table-meta">ครั้งที่ <?=$roundLabel?></div></td><td><?=e(pageShow($row['caregiver_name']))?></td><td><span class="table-score"><?=$row['caregiver_total_score']!==null?(int)$row['caregiver_total_score'].'/20':'-'?></span></td><td><?=e(pageShow($row['caregiver_group']))?></td><td><button type="button" class="detail-toggle-btn" data-detail="caregiver-detail-<?=$index?>">รายละเอียดเพิ่มเติม</button></td></tr><tr id="caregiver-detail-<?=$index?>" class="inline-detail-row"><td colspan="5"><div class="inline-detail-card"><div class="detail-grid"><div class="detail-field"><span>กลุ่ม ADL</span><strong><?= e(pageShow($row['caregiver_group'])) ?></strong></div><div class="detail-field"><span>ผู้ประเมิน</span><strong><?= e(pageShow($row['caregiver_name'])) ?></strong></div><div class="detail-field"><span>คะแนนครั้งนี้</span><strong><?= $row['caregiver_total_score'] !== null ? (int)$row['caregiver_total_score'].'/20' : '-' ?></strong></div><div class="detail-field"><span>คะแนนหมอครั้งแรก</span><strong><?= $row['doctor_total_score'] !== null ? (int)$row['doctor_total_score'].'/20' : '-' ?></strong></div><div class="detail-field"><span>น้ำหนัก</span><strong><?= e(pageMeasure($row['health_weight_kg'], 'กก.')) ?></strong></div><div class="detail-field"><span>BMI</span><strong><?= e(pageMeasure($row['health_bmi'])) ?></strong></div><div class="detail-field"><span>ความดัน</span><strong><?= e(pageShow($row['health_bp'])) ?></strong></div><div class="detail-field"><span>ผู้ดูแลประจำ</span><strong><?= e(pageShow($row['regular_caregiver'])) ?></strong></div><div class="detail-field"><span>วันเวลาที่ประเมิน</span><strong><?= e(pageThaiDateTime($row['assessed_at'] ?? null)) ?></strong></div><div class="detail-field full"><span>หมายเหตุ</span><strong><?= nl2br(e(pageShow($row['note']))) ?></strong></div></div></div></td></tr><?php endforeach;?></tbody></table></div><?php if(count($caregiverAdl)>5):?><div style="margin-top:14px"><a class="record-more-link" href="patient_history_more.php?patient_id=<?=(int)$patientId?>&section=caregiver_adl">ดูเพิ่มเติม</a></div><?php endif;?><?php else:?><div class="empty-box">ยังไม่มีประวัติการประเมินครั้งถัดไป</div><?php endif;?></div></section>
<section class="section-card"><div class="section-head"><h2>ประวัติการเข้าเยี่ยม</h2><span class="count-badge"><?=number_format(count($visitHistory))?> รายการ</span></div><div class="section-body"><?php if($visitHistory):?><?php foreach($visitHistoryByMonth as $monthKey=>$monthRows): $firstMonthRow=reset($monthRows); ?><div class="month-group"><div class="month-title"><span><?=e(pageThaiMonthYear($firstMonthRow['visit_date']??null))?></span><span class="month-count"><?=number_format(count($monthRows))?> รายการ</span></div><div class="history-table-wrap"><table class="history-table"><thead><tr><th>วันที่เยี่ยม</th><th>ประเภทการเยี่ยม</th><th>ผู้บันทึก</th><th>นัดครั้งถัดไป</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach($monthRows as $index=>$row):$visitLabel=!empty($row['visit_no'])?(int)$row['visit_no']:($index+1);?><tr class="data-row"><td><div class="table-primary"><?=e(pageThaiDate($row['visit_date']??null))?></div><div class="table-meta"><?=!empty($row['visit_time'])?e(substr((string)$row['visit_time'],0,5)).' น.':'-'?></div></td><td><div class="table-primary"><?=e(pageShow($row['visit_type'],'การเข้าเยี่ยม'))?></div><div class="table-meta">ครั้งที่ <?=$visitLabel?></div></td><td><?=e(pageShow($row['caregiver_name']))?></td><td><?=e(pageThaiDate($row['next_visit_date']??null))?></td><td><button type="button" class="detail-toggle-btn" data-detail="visit-detail-<?=$index?>">รายละเอียดเพิ่มเติม</button></td></tr><tr id="visit-detail-<?=$index?>" class="inline-detail-row"><td colspan="5"><div class="inline-detail-card"><div class="detail-grid"><div class="detail-field"><span>ผู้บันทึก</span><strong><?= e(pageShow($row['caregiver_name'])) ?></strong></div><div class="detail-field"><span>สภาพทั่วไป</span><strong><?= e(pageShow($row['general_condition'])) ?></strong></div><div class="detail-field"><span>วันที่เข้าเยี่ยม</span><strong><?= e(pageThaiDate($row['visit_date'] ?? null)) ?></strong></div><div class="detail-field"><span>วันที่บันทึก</span><strong><?= e(pageThaiDateTime($row['created_at'] ?? null)) ?></strong></div><div class="detail-field"><span>ความดัน</span><strong><?= e(($row['bp_systolic'] !== null && $row['bp_diastolic'] !== null) ? $row['bp_systolic'].'/'.$row['bp_diastolic'].' mmHg' : '-') ?></strong></div><div class="detail-field"><span>ชีพจร / อุณหภูมิ</span><strong><?= e(($row['pulse'] !== null ? $row['pulse'].' ครั้ง/นาที' : '-') . (($row['temperature'] !== null) ? ' • '.$row['temperature'].' °C' : '')) ?></strong></div><div class="detail-field"><span>โรค/อาการที่บันทึก</span><strong><?= e(pageShow($row['disease_snapshot'])) ?></strong></div><div class="detail-field"><span>การดูแลที่ให้</span><strong><?= e(pageShow($row['care_actions'])) ?></strong></div><div class="detail-field"><span>การส่งต่อ</span><strong><?= e(pageShow($row['referral_type'])) ?></strong></div><div class="detail-field"><span>นัดครั้งถัดไป</span><strong><?= e(pageThaiDate($row['next_visit_date'] ?? null)) ?></strong></div><div class="detail-field full"><span>เหตุผลส่งต่อ / หมายเหตุ</span><strong><?= nl2br(e(pageVisitNote($row))) ?></strong></div></div></div></td></tr><?php endforeach;?></tbody></table></div></div><?php endforeach;?><?php else:?><div class="empty-box">ยังไม่มีประวัติการเข้าเยี่ยม</div><?php endif;?></div></section>
<?php if($healthHistory):?><section class="section-card"><div class="section-head"><h2>ประวัติการประเมินสุขภาพ</h2><span class="count-badge"><?=number_format(count($healthHistory))?> รายการ</span></div><div class="section-body"><div class="history-table-wrap"><table class="history-table"><thead><tr><th>วันที่ประเมิน</th><th>ผู้บันทึก</th><th>BMI</th><th>ความดัน</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach(array_slice($healthHistory,0,5,true) as $index=>$row):?><tr class="data-row"><td><div class="table-primary"><?=e(pageThaiDate($row['assessment_date']??null))?></div></td><td><?=e(pageShow($row['doctor_name']))?></td><td><span class="table-score"><?=e(pageMeasure($row['bmi']))?></span></td><td><?=e(pageShow($row['blood_pressure']))?></td><td><button type="button" class="detail-toggle-btn" data-detail="health-detail-<?=$index?>">รายละเอียดเพิ่มเติม</button></td></tr><tr id="health-detail-<?=$index?>" class="inline-detail-row"><td colspan="5"><div class="inline-detail-card"><div class="detail-grid"><div class="detail-field"><span>ส่วนสูง</span><strong><?= e(pageMeasure($row['height_cm'], 'ซม.')) ?></strong></div><div class="detail-field"><span>น้ำหนัก</span><strong><?= e(pageMeasure($row['weight_kg'], 'กก.')) ?></strong></div><div class="detail-field"><span>BMI</span><strong><?= e(pageMeasure($row['bmi'])) ?></strong></div><div class="detail-field"><span>รอบเอว</span><strong><?= e(pageMeasure($row['waist_cm'], 'ซม.')) ?></strong></div><div class="detail-field"><span>รอบสะโพก</span><strong><?= e(pageMeasure($row['hip_cm'], 'ซม.')) ?></strong></div><div class="detail-field"><span>เปอร์เซ็นต์ไขมัน</span><strong><?= e(pageMeasure($row['body_fat_percent'], '%')) ?></strong></div><div class="detail-field"><span>ความดัน</span><strong><?= e(pageShow($row['blood_pressure'])) ?></strong></div><div class="detail-field"><span>ผู้บันทึก</span><strong><?= e(pageShow($row['doctor_name'])) ?></strong></div><div class="detail-field"><span>บันทึกเมื่อ</span><strong><?= e(pageThaiDateTime($row['created_at'] ?? null)) ?></strong></div><div class="detail-field full"><span>หมายเหตุ</span><strong><?= nl2br(e(pageShow($row['note']))) ?></strong></div></div></div></td></tr><?php endforeach;?></tbody></table></div><?php if(count($healthHistory)>5):?><div style="margin-top:14px"><a class="record-more-link" href="patient_history_more.php?patient_id=<?=(int)$patientId?>&section=health_history">ดูเพิ่มเติม</a></div><?php endif;?></div></section><?php endif;?>
    </div>
</div>
</main>
<script>(function(){document.querySelectorAll('.detail-toggle-btn').forEach(function(btn){btn.addEventListener('click',function(){var target=document.getElementById(btn.getAttribute('data-detail'));if(!target)return;var open=target.classList.toggle('is-open');btn.textContent=open?'ปิดรายละเอียด':'รายละเอียดเพิ่มเติม';});});})();</script>
</body>
</html>

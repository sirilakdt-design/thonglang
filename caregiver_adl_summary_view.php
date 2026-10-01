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
if (isset($_GET['round']) && $selected !== null && $selectedRound > 0 && isset($rounds[$selectedRound])) {
    header('Location: caregiver_adl_round_detail.php?adl_id=' . (int)$selectedId . '&round=' . (int)$selectedRound);
    exit;
}
$printMode = isset($_GET['print']) && $_GET['print'] === '1' && $selected !== null;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(appDocumentTitle($selected ? ('สรุปผล ' . $selectedName) : 'สรุปผลการประเมิน')) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.summary-view-shell{display:grid;gap:16px;padding-bottom:24px}.top-actions{display:flex;justify-content:flex-start}.summary-panel{background:#fff;border:1px solid #d9e8e4;border-radius:22px;box-shadow:0 10px 24px rgba(35,76,65,.06);overflow:hidden}.panel-section{padding:22px 26px;border-top:1px solid #ebf2f0}.panel-section:first-child{border-top:0}.section-title{margin:0 0 12px;color:#173f3d;font-size:17px;font-weight:900}.section-sub{margin:-4px 0 14px;color:#6c837f;font-size:13px}.info-grid-clean{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.info-card{border:1px solid #dfecea;border-radius:16px;background:#fff;padding:16px 18px}.info-card span{display:block;color:#728783;font-size:12px;font-weight:700;margin-bottom:6px}.info-card strong{display:block;color:#173f3d;font-size:17px;line-height:1.35}.round-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.round-card{display:block;text-decoration:none;border:1px solid #dce9e6;border-radius:16px;background:#fff;padding:16px 14px;color:inherit;transition:.18s ease;box-shadow:0 4px 10px rgba(37,78,66,.03)}.round-card:hover{transform:translateY(-1px);border-color:#9ed7cf;background:#f9fdfc;box-shadow:0 8px 18px rgba(37,78,66,.07)}.round-card.is-active{border-color:#67c6c8;background:#effaf9;box-shadow:0 10px 22px rgba(88,191,192,.12)}.round-card span{display:block;color:#607873;font-size:12px;font-weight:800}.round-card strong{display:block;margin:6px 0 5px;color:#194443;font-size:30px;line-height:1.05;font-weight:900}.round-card small{display:block;color:#275c58;font-size:12px;font-weight:800}.round-card em{display:block;margin-top:8px;color:#7a908b;font-size:11px;font-style:normal}.detail-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.detail-head h3{margin:0;color:#183f3d;font-size:18px;font-weight:900}.detail-meta{display:flex;gap:10px;flex-wrap:wrap}.detail-chip{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;border:1px solid #d7e9e5;background:#f6fbfa;color:#345f59;font-size:12px;font-weight:800}.report-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #dbe9e5;border-radius:16px;overflow:hidden}.report-table thead th{background:#eaf6f4;color:#204846;font-size:12px;font-weight:900;text-align:left;padding:12px 14px;border-bottom:1px solid #dbe9e5}.report-table tbody td{padding:14px;border-bottom:1px solid #edf4f2;color:#30524e;font-size:13px;vertical-align:top}.report-table tbody tr:last-child td{border-bottom:0}.report-table .col-no{width:48px;text-align:center}.report-table .col-score{width:78px;text-align:center;font-weight:900;color:#194443}.th-sub{display:block;color:#7a908b;font-weight:600;font-size:11px;margin-top:3px;line-height:1.45}.two-col-sections{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.mini-grid .info-card.wide{grid-column:1/-1}.footer-actions{display:flex;justify-content:flex-end;padding:0 26px 24px}.empty-card{background:#fff;border:1px solid #d9e8e4;border-radius:22px;box-shadow:0 10px 24px rgba(35,76,65,.06);padding:34px 28px;color:#6d8480;text-align:center}.action-btn,.print-btn,.secondary-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;padding:10px 16px;border-radius:11px;font:inherit;font-weight:800;cursor:pointer;white-space:nowrap}.action-btn,.print-btn{border:0;background:#59bfc1;color:#fff}.action-btn:hover,.print-btn:hover{background:#4aa9ab}.secondary-btn{border:1px solid #cfe1dc;background:#fff;color:#315d50}.secondary-btn:hover{background:#f6fbfa}
@media(max-width:1024px){.info-grid-clean,.mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.two-col-sections{grid-template-columns:1fr}}
@media(max-width:700px){.panel-section{padding:18px}.info-grid-clean,.mini-grid{grid-template-columns:1fr}.round-card strong{font-size:26px}.report-table thead th,.report-table tbody td{padding:10px 10px;font-size:12px}.footer-actions{padding:0 18px 18px}}
@media print{body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.sidebar,.user-topbar,.top-actions,.footer-actions,.print-hidden{display:none!important}.main{margin:0!important;padding:0!important}.summary-panel{box-shadow:none!important;border:1px solid #9ab0aa!important;border-radius:0!important}.panel-section{padding:14px 16px}.round-card{box-shadow:none!important}.report-table{border-radius:0}.section-sub{margin-bottom:10px}}

.results-only-panel{overflow:visible}.results-only-grid{grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}.results-only-grid .round-card{min-height:128px;display:flex;flex-direction:column;justify-content:center}.results-only-grid .round-card strong{font-size:32px}
@media(max-width:700px){.results-only-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="summary-view-shell">
    <div class="top-actions print-hidden">
        <a class="secondary-btn" href="caregiver_adl_summary.php">ย้อนกลับ</a>
    </div>

    <?php if ($selected === null): ?>
        <div class="empty-card">ไม่พบข้อมูลผลการประเมิน</div>
    <?php else: ?>
        <section class="summary-panel results-only-panel">
            <div class="panel-section">
                <h2 class="section-title">ผลการประเมินทั้งหมด</h2>
                <div class="round-grid results-only-grid">
                    <?php foreach ($rounds as $roundNo => $round): ?>
                        <a class="round-card" href="caregiver_adl_round_detail.php?adl_id=<?= (int)$selectedId ?>&round=<?= (int)$roundNo ?>" title="ดูรายละเอียดการประเมินครั้งที่ <?= (int)$roundNo ?>">
                            <span><?= e($round['label']) ?></span>
                            <strong><?= (int)($round['score'] ?? 0) ?>/20</strong>
                            <small><?= e($round['group'] ?? '-') ?></small>
                            <em><?= e(adlThaiDateTime($round['assessed_at'] ?? null)) ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
</main>
</body>
</html>

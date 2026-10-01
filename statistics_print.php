<?php
require_once __DIR__ . '/connect.php';
requireRole('admin', 'director');
mysqli_report(MYSQLI_REPORT_OFF);
if (isset($conn) && $conn instanceof mysqli) mysqli_set_charset($conn, 'utf8mb4');
require_once __DIR__ . '/admin/statistics_shared.php';

// This is an A4 *summary*: show at most 10 villages rather than silently
// clipping an unbounded list in a one-page printout.
$printVillages = array_slice($villageRows, 0, 10);
$otherVillageCount = max(0, count($villageRows) - count($printVillages));
$printAdl = [
    ['label' => 'ติดสังคม', 'value' => (int)$adlCounts['ติดสังคม'], 'color' => '#209f9a'],
    ['label' => 'ติดบ้าน', 'value' => (int)$adlCounts['ติดบ้าน'], 'color' => '#71cac4'],
    ['label' => 'ติดเตียง', 'value' => (int)$adlCounts['ติดเตียง'], 'color' => '#76bdda'],
    ['label' => 'ยังไม่ประเมิน', 'value' => (int)$adlCounts['ยังไม่ประเมิน'], 'color' => '#cfdddd'],
];
$printStatCards = [
    ['label' => 'ผู้สูงอายุทั้งหมด', 'value' => $totalPatients, 'unit' => 'คน', 'style' => 'teal'],
    ['label' => 'มอบหมายแคร์กิฟเวอร์แล้ว', 'value' => $totalAssigned, 'unit' => 'คน', 'style' => 'blue'],
    ['label' => 'รอมอบหมายผู้ดูแล', 'value' => $totalUnassigned, 'unit' => 'คน', 'style' => 'yellow'],
    ['label' => 'ประเมิน ADL แล้ว', 'value' => $totalAssessed, 'unit' => 'คน', 'style' => 'mint'],
    ['label' => 'ยังไม่ประเมิน ADL', 'value' => (int)$adlCounts['ยังไม่ประเมิน'], 'unit' => 'คน', 'style' => 'rose'],
    ['label' => 'บุคลากรในระบบ', 'value' => $totalCaregivers + $totalDoctors, 'unit' => 'คน', 'style' => 'sky'],
];
$maxPrintVillage = max(1, (int)($largestVillage['total'] ?? 0));

$caregiverAssessmentExists = false;
if ($hasAdl && statColumnExists($conn, 'adl_assessment', 'caregiver_user_id')) {
    $assessmentRoleRows = statRows($conn, "SELECT a.caregiver_user_id, a.doctor_user_id
        FROM adl_assessment a
        INNER JOIN (
            SELECT patient_id, MAX(adl_id) latest_id
            FROM adl_assessment
            GROUP BY patient_id
        ) x ON x.latest_id = a.adl_id");
    foreach ($assessmentRoleRows as $assessmentRoleRow) {
        if ((int)($assessmentRoleRow['caregiver_user_id'] ?? 0) > 0) {
            $caregiverAssessmentExists = true;
            break;
        }
    }
}
$signatureRoles = $caregiverAssessmentExists
    ? ['แคร์กิฟเวอร์', 'หมอ', 'ผู้อำนวยการ']
    : ['หมอ', 'ผู้อำนวยการ'];

$villagesWithElderly = max(0, $totalVillages - $villagesWithoutElderly);
$summaryNarrative = 'จากข้อมูลปัจจุบันในระบบ มีผู้สูงอายุทั้งหมด ' . number_format($totalPatients) . ' คน กระจายอยู่ใน ' . number_format($villagesWithElderly) . ' หมู่บ้าน จากหมู่บ้านที่บันทึกไว้ทั้งหมด ' . number_format($totalVillages) . ' หมู่บ้าน';
if ($totalPatients > 0) {
    if ($totalUnassigned === 0) {
        $summaryNarrative .= ' ผู้สูงอายุทั้ง ' . number_format($totalPatients) . ' คนได้รับการมอบหมายแคร์กิฟเวอร์แล้วครบทุกคน คิดเป็น 100%';
    } else {
        $summaryNarrative .= ' มีผู้สูงอายุได้รับการมอบหมายแคร์กิฟเวอร์แล้ว ' . number_format($totalAssigned) . ' คน คิดเป็น ' . number_format($assignmentRate) . '% และยังรอมอบหมายอีก ' . number_format($totalUnassigned) . ' คน';
    }
}
$summaryNarrative .= ' สำหรับการประเมิน ADL มีผู้ได้รับการประเมินแล้ว ' . number_format($totalAssessed) . ' คน คิดเป็น ' . number_format($assessmentRate) . '%';
if ($totalAssessed > 0) {
    $summaryNarrative .= ' แบ่งเป็นติดสังคม ' . number_format((int)$adlCounts['ติดสังคม']) . ' คน ติดบ้าน ' . number_format((int)$adlCounts['ติดบ้าน']) . ' คน และติดเตียง ' . number_format((int)$adlCounts['ติดเตียง']) . ' คน';
}
if ((int)$adlCounts['ยังไม่ประเมิน'] > 0) {
    $summaryNarrative .= ' ขณะเดียวกันยังมีผู้สูงอายุที่ยังไม่ได้รับการประเมิน ADL จำนวน ' . number_format((int)$adlCounts['ยังไม่ประเมิน']) . ' คน คิดเป็น ' . number_format(100 - $assessmentRate) . '% จึงควรติดตามให้ได้รับการประเมินครบถ้วน';
} elseif ($totalPatients > 0) {
    $summaryNarrative .= ' และผู้สูงอายุทุกคนได้รับการประเมิน ADL ครบถ้วนแล้ว';
}
if ($villagesWithoutElderly > 0) {
    $summaryNarrative .= ' ส่วนอีก ' . number_format($villagesWithoutElderly) . ' หมู่บ้านยังไม่มีข้อมูลผู้สูงอายุในระบบ จึงไม่ถือเป็นหมู่บ้านที่รอการมอบหมายผู้ดูแล';
}

$summaryRows = [
    [
        'label' => 'ภาพรวมผู้สูงอายุ',
        'text' => 'มีผู้สูงอายุทั้งหมด ' . number_format($totalPatients) . ' คน อยู่ใน ' . number_format($villagesWithElderly) . ' หมู่บ้าน จากหมู่บ้านในระบบทั้งหมด ' . number_format($totalVillages) . ' หมู่บ้าน',
    ],
    [
        'label' => 'การมอบหมายผู้ดูแล',
        'text' => 'มอบหมายแคร์กิฟเวอร์แล้ว ' . number_format($totalAssigned) . ' คน คิดเป็น ' . number_format($assignmentRate) . '% และยังรอมอบหมาย ' . number_format($totalUnassigned) . ' คน',
    ],
    [
        'label' => 'ผลการประเมิน ADL',
        'text' => 'ประเมินแล้ว ' . number_format($totalAssessed) . ' คน คิดเป็น ' . number_format($assessmentRate) . '% แบ่งเป็นติดสังคม ' . number_format((int)$adlCounts['ติดสังคม']) . ' คน ติดบ้าน ' . number_format((int)$adlCounts['ติดบ้าน']) . ' คน และติดเตียง ' . number_format((int)$adlCounts['ติดเตียง']) . ' คน',
    ],
    [
        'label' => 'การติดตามประเมิน',
        'text' => 'ยังไม่ประเมิน ADL ' . number_format((int)$adlCounts['ยังไม่ประเมิน']) . ' คน คิดเป็น ' . number_format(max(0, 100 - $assessmentRate)) . '%',
    ],
];
if ($villagesWithoutElderly > 0) {
    $summaryRows[] = [
        'label' => 'ข้อมูลพื้นที่',
        'text' => 'มี ' . number_format($villagesWithoutElderly) . ' หมู่บ้านที่ยังไม่มีข้อมูลผู้สูงอายุในระบบ',
    ];
}

$printData = [
    'org' => appName(),
    'title' => $reportTitle,
    'date' => $todayText,
    'showDate' => $showReportDate,
    'stats' => $printStatCards,
    'totalPatients' => $totalPatients,
    'totalVillages' => $totalVillages,
    'totalAssigned' => $totalAssigned,
    'totalAssessed' => $totalAssessed,
    'assignmentRate' => $assignmentRate,
    'assessmentRate' => $assessmentRate,
    'adl' => $printAdl,
    'villages' => array_map(static function ($row) {
        return ['name' => trim((string)($row['villagename'] ?? '')) ?: 'ไม่ระบุชื่อหมู่บ้าน', 'total' => (int)$row['total']];
    }, $printVillages),
    'otherVillageCount' => $otherVillageCount,
    'fullyCoveredVillages' => $fullyCoveredVillages,
    'villagesWithElderly' => $villagesWithElderly,
    'villagesWithoutElderly' => $villagesWithoutElderly,
    'dataWarnings' => $pageErrors,
    'unassigned' => $totalUnassigned,
    'unassessed' => (int)$adlCounts['ยังไม่ประเมิน'],
    'doctors' => $totalDoctors,
    'caregivers' => $totalCaregivers,
    'signatureRoles' => $signatureRoles,
    'caregiverAssessmentExists' => $caregiverAssessmentExists,
    'summaryNarrative' => $summaryNarrative,
    'summaryRows' => $summaryRows,
];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>พิมพ์รายงาน | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body.summary-report-page{background:#eef5f4}
.summary-report-page .main{min-height:100vh;padding:0 22px 40px}
.summary-report-wrap{max-width:1040px;margin:0 auto;padding:20px 0 26px}
.summary-report-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.summary-report-toolbar .tools{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.summary-report-toolbar .tool-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 17px;border:1px solid #cce3e0;border-radius:11px;background:#fff;color:#275a5d;text-decoration:none;font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.summary-report-toolbar .tool-btn.primary{background:#20afa6;border-color:#20afa6;color:#fff}
.summary-report-toolbar .tool-btn:disabled{opacity:.55;cursor:wait}
.summary-paper{box-sizing:border-box;width:210mm;height:297mm;margin:0 auto;padding:15mm 17mm 13mm;background:#fff;color:#111;box-shadow:0 15px 48px rgba(30,72,77,.14);overflow:hidden;font:11.3px/1.65 "Noto Sans Thai",Tahoma,sans-serif!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.summary-paper *{box-sizing:border-box}
.summary-header{display:grid;grid-template-columns:1fr;grid-template-areas:"org" "title" "meta";justify-items:center;align-items:center;text-align:center}
.summary-org{grid-area:org;font-size:11.5px;font-weight:800;color:#111;text-align:center}
.summary-header h1{grid-area:title;margin:8px 0 0;font-size:22px;line-height:1.35;color:#111;font-weight:900;text-align:center}
.summary-meta{grid-area:meta;font-size:10.5px;color:#222;text-align:center;margin-top:4px}
.summary-rule{border:0;border-top:1.5px solid #222;margin:10px 0 16px}
.summary-section{margin-top:14px}
.summary-section h2{font-size:13px;font-weight:900;margin:0 0 7px;color:#111}
.summary-lead{margin:0;color:#111;font-size:11.2px}
.summary-kv-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 54px;margin-top:4px}
.summary-kv-list{margin:0;padding:0;list-style:none}
.summary-kv-item{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:baseline;column-gap:6px;padding:6px 0;color:#111}
.summary-kv-label{font-weight:700;color:#111}
.summary-kv-value{font-weight:900;color:#111;font-size:11.7px}
.summary-kv-unit{color:#111;font-weight:600}
.summary-text strong{color:#111}
.summary-adl-list{display:grid;gap:0;margin-top:2px}
.summary-adl-row{display:grid;grid-template-columns:minmax(0,1.3fr) 90px 120px;align-items:center;gap:12px;padding:7px 2px}
.summary-adl-name{font-weight:700;color:#111}
.summary-adl-count{text-align:right;color:#111;white-space:nowrap}
.summary-adl-percent{text-align:right;color:#111;white-space:nowrap}
.summary-adl-count strong,.summary-adl-percent strong{font-weight:900;color:#111}
.summary-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px 48px;align-items:start;padding-left:0;margin-top:2px}
.summary-village-list{display:grid;gap:0}
.summary-village-row{display:grid;grid-template-columns:28px minmax(0,1fr) 58px;align-items:center;gap:8px;padding:7px 0}
.summary-village-rank{font-weight:800;color:#333;text-align:center}
.summary-village-name{font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.summary-village-count{text-align:right;font-weight:900;color:#111;white-space:nowrap}
.summary-text{margin:0;color:#111;text-align:justify;line-height:1.8}
.summary-result-rows{display:grid;gap:7px;margin-top:2px}
.summary-result-row{display:grid;grid-template-columns:145px 1fr;gap:14px;align-items:start;padding:2px 0}
.summary-result-label{font-weight:800;color:#111;white-space:nowrap}
.summary-result-text{color:#111;line-height:1.7}
.summary-note{margin:6px 0 0;font-size:10px;color:#333}
.summary-signatures{display:grid;gap:16px;margin-top:20px}
.summary-signatures.sign-3{grid-template-columns:repeat(3,1fr)}
.summary-signatures.sign-2{grid-template-columns:repeat(2,1fr)}
.summary-signature{text-align:center;font-size:10px;color:#111;line-height:1.7}
.summary-signature .line{height:34px;border-bottom:1px solid #222;margin:0 12px 3px}
.summary-signature .role{font-weight:800}
.summary-footer{margin-top:16px;padding-top:6px;border-top:1px solid #999;display:flex;justify-content:space-between;gap:8px;font-size:9px;color:#333}
.summary-alert{font-size:9.5px;padding-top:5px;color:#111}
@page{size:A4 portrait;margin:0}
@media print{
 html,body{width:210mm!important;min-width:0!important;max-width:210mm!important;min-height:297mm!important;margin:0!important;padding:0!important;background:#fff!important}
 body.summary-report-page .sidebar,body.summary-report-page .user-topbar,.summary-report-toolbar{display:none!important}
 body.summary-report-page .main{margin:0!important;padding:0!important;min-height:0!important;width:210mm!important}
 .summary-report-wrap{width:210mm!important;margin:0!important;padding:0!important;max-width:none!important;overflow:visible!important}
 .summary-paper{width:210mm!important;height:297mm!important;min-height:297mm!important;margin:0!important;box-shadow:none!important}
}
@media(max-width:1080px){.summary-report-page .main{padding:0 10px 28px}.summary-report-wrap{overflow-x:auto}}
</style>
</head>
<body class="role-page summary-report-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="summary-report-wrap">
    <div class="summary-report-toolbar">
                <div class="tools">
            <a class="tool-btn" href="statistics.php">ย้อนกลับ</a>
            <button class="tool-btn primary" type="button" id="download-statistics-pdf">ดาวน์โหลด PDF</button>
            <button class="tool-btn" type="button" id="print-statistics-report" onclick="window.print()">พิมพ์รายงาน</button>
        </div>
    </div>

    <article class="summary-paper" id="a4-paper" aria-label="รายงานสรุปขนาด A4 หนึ่งหน้า">
        <header class="summary-header">
            <div class="summary-org"><?= statH(appName()) ?></div>
            <div class="summary-meta"><?php if ($showReportDate): ?>ข้อมูล ณ วันที่ <?= statH($todayText) ?><?php endif; ?></div>
            <h1><?= statH($reportTitle) ?></h1>
        </header>
        <hr class="summary-rule">

        <section class="summary-section">
            <h2>1. ภาพรวมข้อมูลผู้สูงอายุ</h2>
            <div class="summary-kv-grid">
                <div class="summary-kv-list">
                    <div class="summary-kv-item"><span class="summary-kv-label">ผู้สูงอายุทั้งหมด</span><strong class="summary-kv-value"><?= number_format($totalPatients) ?></strong><span class="summary-kv-unit">คน</span></div>
                    <div class="summary-kv-item"><span class="summary-kv-label">หมู่บ้านในระบบ</span><strong class="summary-kv-value"><?= number_format($totalVillages) ?></strong><span class="summary-kv-unit">แห่ง</span></div>
                </div>
                <div class="summary-kv-list">
                    <div class="summary-kv-item"><span class="summary-kv-label">หมอในระบบ</span><strong class="summary-kv-value"><?= number_format($totalDoctors) ?></strong><span class="summary-kv-unit">คน</span></div>
                    <div class="summary-kv-item"><span class="summary-kv-label">แคร์กิฟเวอร์</span><strong class="summary-kv-value"><?= number_format($totalCaregivers) ?></strong><span class="summary-kv-unit">คน</span></div>
                </div>
            </div>
        </section>

        <section class="summary-section">
            <h2>2. สถานะการมอบหมายและการประเมิน</h2>
            <div class="summary-kv-grid">
                <div class="summary-kv-list">
                    <div class="summary-kv-item"><span class="summary-kv-label">มอบหมายผู้ดูแลแล้ว</span><strong class="summary-kv-value"><?= number_format($totalAssigned) ?></strong><span class="summary-kv-unit">คน</span></div>
                    <div class="summary-kv-item"><span class="summary-kv-label">ประเมิน ADL แล้ว</span><strong class="summary-kv-value"><?= number_format($totalAssessed) ?></strong><span class="summary-kv-unit">คน</span></div>
                </div>
                <div class="summary-kv-list">
                    <div class="summary-kv-item"><span class="summary-kv-label">ยังรอมอบหมาย</span><strong class="summary-kv-value"><?= number_format($totalUnassigned) ?></strong><span class="summary-kv-unit">คน</span></div>
                    <div class="summary-kv-item"><span class="summary-kv-label">ยังไม่ประเมิน ADL</span><strong class="summary-kv-value"><?= number_format((int)$adlCounts['ยังไม่ประเมิน']) ?></strong><span class="summary-kv-unit">คน</span></div>
                </div>
            </div>
        </section>

        <section class="summary-section">
            <h2>3. สรุปผลการประเมิน ADL ล่าสุด</h2>
            <div class="summary-adl-list">
                <?php foreach ($printAdl as $item): ?>
                <?php $adlPercent = $totalPatients > 0 ? (100 * $item['value'] / $totalPatients) : 0; ?>
                <div class="summary-adl-row">
                    <div class="summary-adl-name"><?= statH($item['label']) ?></div>
                    <div class="summary-adl-count"><strong><?= number_format($item['value']) ?></strong> คน</div>
                    <div class="summary-adl-percent"><strong><?= number_format($adlPercent, 1) ?>%</strong></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="summary-section">
            <h2>4. จำนวนผู้สูงอายุจำแนกตามหมู่บ้าน</h2>
            <div class="summary-columns">
                <div class="summary-village-list">
                    <?php foreach (array_slice($printVillages, 0, 5) as $i => $row): ?>
                    <div class="summary-village-row">
                        <span class="summary-village-rank"><?= ($i + 1) ?></span>
                        <span class="summary-village-name"><?= statH($row['villagename'] ?: 'ไม่ระบุชื่อหมู่บ้าน') ?></span>
                        <span class="summary-village-count"><?= number_format((int)$row['total']) ?> คน</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="summary-village-list">
                    <?php foreach (array_slice($printVillages, 5, 5) as $i => $row): ?>
                    <div class="summary-village-row">
                        <span class="summary-village-rank"><?= ($i + 6) ?></span>
                        <span class="summary-village-name"><?= statH($row['villagename'] ?: 'ไม่ระบุชื่อหมู่บ้าน') ?></span>
                        <span class="summary-village-count"><?= number_format((int)$row['total']) ?> คน</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!$printVillages): ?><p class="summary-note">ยังไม่มีข้อมูลหมู่บ้าน</p><?php endif; ?>
            <?php if ($otherVillageCount > 0): ?><p class="summary-note">หมายเหตุ: แสดง 10 หมู่บ้านแรกจากข้อมูลทั้งหมด อีก <?= number_format($otherVillageCount) ?> หมู่บ้านตรวจสอบได้ที่หน้าออกรายงาน</p><?php endif; ?>
        </section>

        <section class="summary-section">
            <h2>5. สรุปผลการดำเนินงาน</h2>
            <div class="summary-result-rows">
                <?php foreach ($summaryRows as $summaryRow): ?>
                <div class="summary-result-row">
                    <div class="summary-result-label"><?= statH($summaryRow['label']) ?></div>
                    <div class="summary-result-text"><?= statH($summaryRow['text']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($pageErrors): ?><div class="summary-alert">หมายเหตุ: ข้อมูลบางส่วนยังไม่ครบ กรุณาตรวจสอบข้อมูลในระบบก่อนใช้รายงานนี้</div><?php endif; ?>

        <div class="summary-signatures <?= $caregiverAssessmentExists ? 'sign-3' : 'sign-2' ?>" aria-label="ตำแหน่งลงนามรับรองรายงาน">
            <?php foreach ($signatureRoles as $signatureRole): ?>
            <div class="summary-signature"><div class="line"></div><div class="role"><?= statH($signatureRole) ?></div><div>วันที่ ........../........../..........</div></div>
            <?php endforeach; ?>
        </div>

        <footer class="summary-footer"><span>เอกสารสรุปจากข้อมูลที่บันทึกในระบบ ณ วันที่ <?= statH($todayText) ?></span><span>หน้า 1 / 1</span></footer>
    </article>
</div>
</main>
<script type="application/json" id="statistics-pdf-data"><?= json_encode($printData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="assets/statistics_onepage_pdf.js"></script>
</body>
</html>

<?php
require_once __DIR__ . '/director_tools.php';

$currentMonth = date('Y-m');
$month = trim((string)($_GET['month'] ?? $currentMonth));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = $currentMonth;
if ($month > $currentMonth) $month = $currentMonth;
$q = trim((string)($_GET['q'] ?? ''));

$thaiMonths = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$monthTs = strtotime($month . '-01');
$monthLabel = $thaiMonths[(int)date('n', $monthTs)] . ' ' . ((int)date('Y', $monthTs) + 543);

$metrics = dirPatientMetrics($conn);
$vm = dirVisitMetrics($conn, $month);
$am = dirAssessmentMetrics($conn);

$reports = [
    [
        'title' => 'รายงานสรุประบบ',
        'detail' => 'สรุปข้อมูลผู้สูงอายุ หมู่บ้าน และผลการประเมิน ADL',
        'count' => (int)$metrics['patients'] . ' คน',
        'href' => 'statistics.php',
        'button' => 'เปิดรายงาน',
        'print' => 'statistics_print.php',
    ],
    [
        'title' => 'ข้อมูลผู้สูงอายุ',
        'detail' => 'รายชื่อและข้อมูลสำคัญของผู้สูงอายุที่บันทึกในระบบ',
        'count' => (int)$metrics['patients'] . ' คน',
        'href' => 'director_patients.php',
        'button' => 'เปิดข้อมูล',
        'print' => '',
    ],
    [
        'title' => 'รายงานการเข้าเยี่ยม',
        'detail' => 'ประวัติการเข้าเยี่ยมและข้อมูลการติดตามดูแล',
        'count' => (int)$vm['monthTotal'] . ' ครั้ง',
        'href' => 'director_visits.php?month=' . urlencode($month),
        'button' => 'เปิดรายงาน',
        'print' => '',
    ],
    [
        'title' => 'รายงานผลการประเมิน ADL',
        'detail' => 'คะแนน ADL และสถานะล่าสุดของผู้สูงอายุแต่ละราย',
        'count' => (int)$am['done'] . ' คน',
        'href' => 'director_assessments.php',
        'button' => 'เปิดรายงาน',
        'print' => '',
    ],
];
if ($q !== '') {
    $reports = array_values(array_filter($reports, function($r) use ($q) {
        return mb_stripos($r['title'] . ' ' . $r['detail'], $q, 0, 'UTF-8') !== false;
    }));
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>รายงานสรุป | <?= e(appName()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/role_pages.css">
<link rel="stylesheet" href="assets/director_portal.css">
<style>
:root{--line:#cfe5e6;--ink:#114f70;--text:#234e5d;--muted:#7a929d;--soft:#f7fbfb;--soft2:#eaf8f8;--blue:#79aee0;--teal:#54babc}
*{box-sizing:border-box}
body.role-page{margin:0;background:#fff;color:var(--text);font-family:'Noto Sans Thai',sans-serif}
.director-main.report-clean-main{margin-left:285px;width:calc(100% - 285px);min-height:100vh;padding:30px 34px 48px}
.report-shell{max-width:1400px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin:14px 0 24px}
.page-title-wrap h1{margin:0;font-size:36px;line-height:1.25;font-weight:900;color:var(--ink)}
.page-title-wrap p{margin:7px 0 0;color:var(--muted);font-size:15px}
.month-filter{display:flex;align-items:center;gap:12px;min-width:270px;height:58px;padding:0 18px;border:1px solid var(--line);border-radius:18px;background:#fff}
.month-filter span{font-weight:800;color:#41646f;white-space:nowrap}.month-filter input{width:150px;border:0;outline:0;background:transparent;font:inherit;font-weight:800;color:#204d5a}
.section{border:1px solid var(--line);border-radius:22px;background:#fff;padding:22px;margin-bottom:18px}
.section-title{margin:0 0 16px;font-size:21px;font-weight:900;color:#164f69}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.kpi{padding:18px 19px;border:1px solid #d7e8e8;border-radius:17px;background:#fff;min-height:104px;display:flex;flex-direction:column;justify-content:center}
.kpi .label{font-size:14px;font-weight:700;color:var(--muted);margin-bottom:6px}.kpi .value{font-size:29px;line-height:1.15;font-weight:900;color:#0d5576}.kpi .sub{font-size:12px;color:#97a8af;margin-top:6px}
.report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.report-card{border:1px solid #d7e8e8;border-radius:18px;background:#fff;padding:20px;display:flex;flex-direction:column;min-height:170px}
.report-card-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}
.report-card h3{margin:0;color:#154f68;font-size:19px;font-weight:900}.report-card .count{white-space:nowrap;font-size:20px;font-weight:900;color:#0f5a78}
.report-card p{margin:8px 0 18px;color:#78909a;font-size:14px;line-height:1.6;max-width:620px}
.report-actions{margin-top:auto;display:flex;gap:9px;align-items:center;flex-wrap:wrap}.btn-open,.btn-print{min-height:42px;padding:0 17px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:14px;font-weight:900}.btn-open{background:var(--blue);color:#fff}.btn-print{border:1px solid #cfe0e5;background:#fff;color:#28566b}
.report-search{display:flex;gap:10px;margin-bottom:18px}.report-search input{flex:1;height:50px;border:1px solid var(--line);border-radius:14px;padding:0 16px;font:inherit;outline:0}.report-search input:focus{border-color:#77c9ca;box-shadow:0 0 0 3px rgba(84,186,188,.10)}.report-search button,.report-search a{height:50px;padding:0 20px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;font:inherit;font-weight:900;text-decoration:none}.report-search button{border:0;background:var(--teal);color:#fff;cursor:pointer}.report-search a{border:1px solid var(--line);background:#fff;color:#5d747c}
.empty{padding:32px;text-align:center;border:1px dashed var(--line);border-radius:16px;color:#80949c}
@media(max-width:1100px){.director-main.report-clean-main{margin-left:250px;width:calc(100% - 250px);padding:24px 22px}.kpi-grid{grid-template-columns:repeat(2,1fr)}.page-title-wrap h1{font-size:31px}}
@media(max-width:760px){.director-main.report-clean-main{margin-left:0;width:100%;padding:18px 14px}.page-head{flex-direction:column;align-items:stretch}.month-filter{min-width:0;width:100%}.kpi-grid,.report-grid{grid-template-columns:1fr}.report-search{flex-wrap:wrap}.report-search input{flex-basis:100%}.page-title-wrap h1{font-size:28px}}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="director-main report-clean-main">
<div class="report-shell">
    <div class="page-head">
        <div class="page-title-wrap">
            <h1>รายงานสรุปข้อมูลผู้สูงอายุ</h1>
            <p>ดูข้อมูลสำคัญของระบบและเลือกเปิดรายงานที่ต้องการ</p>
        </div>
        <form class="month-filter" method="get" action="director_reports.php">
            <?php if($q!==''): ?><input type="hidden" name="q" value="<?=dirH($q)?>"><?php endif; ?>
            <span>เดือน</span>
            <input type="month" name="month" max="<?=dirH($currentMonth)?>" value="<?=dirH($month)?>" onchange="this.form.submit()">
        </form>
    </div>

    <section class="section">
        <h2 class="section-title">ข้อมูลสำคัญ</h2>
        <div class="kpi-grid">
            <div class="kpi"><div class="label">ผู้สูงอายุในระบบ</div><div class="value"><?=number_format((int)$metrics['patients'])?> คน</div></div>
            <div class="kpi"><div class="label">ประเมิน ADL แล้ว</div><div class="value"><?=number_format((int)$am['done'])?> คน</div><div class="sub">จากผู้สูงอายุทั้งหมด</div></div>
            <div class="kpi"><div class="label">เข้าเยี่ยมทั้งหมด</div><div class="value"><?=number_format((int)$vm['total'])?> ครั้ง</div></div>
            <div class="kpi"><div class="label">เข้าเยี่ยมเดือนนี้</div><div class="value"><?=number_format((int)$vm['monthTotal'])?> ครั้ง</div><div class="sub"><?=dirH($monthLabel)?></div></div>
        </div>
    </section>

    <form class="report-search" method="get" action="director_reports.php">
        <input type="hidden" name="month" value="<?=dirH($month)?>">
        <input type="search" name="q" value="<?=dirH($q)?>" placeholder="ค้นหารายงาน เช่น ADL หรือการเข้าเยี่ยม">
        <button type="submit">ค้นหา</button>
        <?php if($q!==''): ?><a href="director_reports.php?month=<?=urlencode($month)?>">ล้าง</a><?php endif; ?>
    </form>

    <section class="section">
        <h2 class="section-title">รายงานที่ใช้งาน</h2>
        <?php if(!$reports): ?>
            <div class="empty">ไม่พบรายงานตามคำค้นหา</div>
        <?php else: ?>
        <div class="report-grid">
            <?php foreach($reports as $r): ?>
            <article class="report-card">
                <div class="report-card-top">
                    <h3><?=dirH($r['title'])?></h3>
                    <div class="count"><?=dirH($r['count'])?></div>
                </div>
                <p><?=dirH($r['detail'])?></p>
                <div class="report-actions">
                    <a class="btn-open" href="<?=dirH($r['href'])?>"><?=dirH($r['button'])?></a>
                    <?php if($r['print']!==''): ?><a class="btn-print" href="<?=dirH($r['print'])?>" target="_blank" rel="noopener">พิมพ์รายงาน</a><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>
</main>
</body>
</html>

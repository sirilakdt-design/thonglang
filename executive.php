<?php
require_once __DIR__ . '/director_tools.php';
$metrics = dirPatientMetrics($conn);
$allVisits = dirFetchVisitRows($conn, '', '');
$recentVisits = array_slice($allVisits, 0, 4);

// Donut data
$totalPatients = max(0, (int)$metrics['patients']);
$adlDone = max(0, (int)$metrics['adl_done']);
$adlPending = max(0, (int)$metrics['adl_pending']);
$donePct = $totalPatients > 0 ? round(($adlDone / $totalPatients) * 100) : 0;
$pendingPct = max(0, 100 - $donePct);

// Last 7 data-days ending at the latest visit date in the database.
$latestTs = 0;
foreach ($allVisits as $visit) {
    if (!empty($visit['visit_date'])) {
        $ts = strtotime((string)$visit['visit_date']);
        if ($ts && $ts > $latestTs) $latestTs = $ts;
    }
}
if (!$latestTs) $latestTs = time();

$visitCounts = [];
foreach ($allVisits as $visit) {
    $date = (string)($visit['visit_date'] ?? '');
    if ($date !== '') $visitCounts[$date] = ($visitCounts[$date] ?? 0) + 1;
}

$chartLabels = [];
$chartValues = [];
$monthShort = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-{$i} day", $latestTs);
    $key = date('Y-m-d', $ts);
    $chartLabels[] = (int)date('j', $ts) . ' ' . $monthShort[(int)date('n', $ts)];
    $chartValues[] = (int)($visitCounts[$key] ?? 0);
}
$maxChart = max(1, ...$chartValues);

// SVG geometry
$svgW = 700; $svgH = 230;
$padL = 42; $padR = 18; $padT = 20; $padB = 42;
$plotW = $svgW - $padL - $padR;
$plotH = $svgH - $padT - $padB;
$points = [];
foreach ($chartValues as $i => $value) {
    $x = $padL + ($plotW * ($i / 6));
    $y = $padT + $plotH - (($value / $maxChart) * $plotH);
    $points[] = [round($x,1), round($y,1)];
}
$polyline = implode(' ', array_map(fn($p) => $p[0].','.$p[1], $points));
$areaPoints = $padL.','.($padT+$plotH).' '.$polyline.' '.($padL+$plotW).','.($padT+$plotH);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ภาพรวมผู้บริหาร | <?= e(appName()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/role_pages.css">
<link rel="stylesheet" href="assets/director_portal.css?v=20260927-1">
<style>
/* 2026-09-27 dashboard cleanup + balanced KPI layout */
.executive-redesign .director-main.executive-main{padding-top:30px!important;min-height:calc(100vh - 88px)}
.exec-kpi:after{content:none!important}
.exec-kpis{gap:14px!important;margin-bottom:18px!important}
.exec-kpi{
  min-height:118px!important;
  padding:18px 20px!important;
  justify-content:center!important;
  text-align:center!important;
}
.exec-kpi>div{width:100%}
.exec-kpi span{margin-bottom:6px!important}
.exec-kpi strong{font-size:38px!important}
.exec-kpi small{margin-left:6px!important}
.exec-chart-grid{grid-template-columns:minmax(360px,.85fr) minmax(520px,1.15fr)!important;gap:16px!important}
.exec-heading{margin-bottom:18px!important}
@media(max-width:1250px){.exec-chart-grid{grid-template-columns:1fr!important}}
@media(max-width:900px){
  .executive-redesign .director-main.executive-main{padding-top:22px!important}
  .exec-kpis{grid-template-columns:repeat(2,minmax(0,1fr))!important}
}
@media(max-width:560px){.exec-kpis{grid-template-columns:1fr!important}}
</style>
<link rel="stylesheet" href="assets/inspired_layout.css?v=20260929-1"><link rel="stylesheet" href="assets/unified_home_report.css?v=20260929-1">
</head>
<body class="role-page executive-redesign">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="director-main executive-main unified-home-page">
    <header class="exec-heading">
        <div>
            <h1>ภาพรวมข้อมูล</h1>
        </div>
        <div class="exec-date"><?= dirH(dirThaiDate(date('Y-m-d'), false)) ?></div>
    </header>

    <section class="exec-kpis">
        <article class="exec-kpi mint">
            <div><span>ผู้สูงอายุ</span><strong><?= $totalPatients ?></strong><small>คน</small></div>
        </article>
        <article class="exec-kpi sky">
            <div><span>มอบหมายแล้ว</span><strong><?= (int)$metrics['assigned'] ?></strong><small>คน</small></div>
        </article>
        <article class="exec-kpi green">
            <div><span>ประเมินแล้ว</span><strong><?= $adlDone ?></strong><small>คน</small></div>
        </article>
        <article class="exec-kpi peach">
            <div><span>รอประเมิน</span><strong><?= $adlPending ?></strong><small>คน</small></div>
        </article>
    </section>

    <section class="exec-chart-grid">
        <article class="exec-card exec-donut-card">
            <div class="exec-card-head">
                <div><span class="exec-card-kicker">สถานะ</span><h2>การประเมิน ADL</h2></div>
                <strong><?= $totalPatients ?> คน</strong>
            </div>
            <div class="exec-donut-layout">
                <div class="exec-donut" style="--done: <?= $donePct ?>%;">
                    <div class="exec-donut-center"><strong><?= $donePct ?>%</strong><span>ประเมินแล้ว</span></div>
                </div>
                <div class="exec-legend">
                    <div><i class="done"></i><span>ประเมินแล้ว</span><b><?= $adlDone ?> คน</b></div>
                    <div><i class="pending"></i><span>รอประเมิน</span><b><?= $adlPending ?> คน</b></div>
                    <div class="exec-legend-note">คงเหลือ <?= $pendingPct ?>%</div>
                </div>
            </div>
        </article>

        <article class="exec-card exec-recent-card">
            <div class="exec-card-head compact">
                <div><span class="exec-card-kicker">ล่าสุด</span><h2>การเข้าเยี่ยม</h2></div>
                <a href="director_visits.php">ดูทั้งหมด</a>
            </div>
            <?php if (!$recentVisits): ?>
                <div class="exec-empty">ยังไม่มีข้อมูลการเข้าเยี่ยม</div>
            <?php else: ?>
                <div class="exec-recent-list">
                    <?php foreach ($recentVisits as $row): ?>
                        <div class="exec-recent-row">
                            <div class="exec-recent-date"><?= dirH(dirThaiDate($row['visit_date'] ?? null, false)) ?></div>
                            <div class="exec-recent-person"><strong><?= dirH($row['Fullname'] ?? '-') ?></strong><span><?= dirH($row['caregiver_name'] ?? '-') ?></span></div>
                            <div class="exec-recent-status">เสร็จสิ้น</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
</main>
</body>
</html>

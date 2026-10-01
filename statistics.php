<?php
require_once __DIR__ . '/connect.php';
requireRole('admin', 'director');
mysqli_report(MYSQLI_REPORT_OFF);
if (isset($conn) && $conn instanceof mysqli) mysqli_set_charset($conn, 'utf8mb4');
require_once __DIR__ . '/admin/statistics_shared.php';

$totalStaff = $totalCaregivers + $totalDoctors;

$filterVillage = isset($_GET['village_id']) ? max(0, (int)$_GET['village_id']) : 0;
$filterAdl = trim((string)($_GET['adl_group'] ?? ''));
$filterAssignment = trim((string)($_GET['assignment'] ?? ''));
$filterKeyword = trim((string)($_GET['q'] ?? ''));
$allowedAdl = ['', 'ติดสังคม', 'ติดบ้าน', 'ติดเตียง', 'ยังไม่ประเมิน'];
$allowedAssignment = ['', 'assigned', 'unassigned'];
if (!in_array($filterAdl, $allowedAdl, true)) $filterAdl = '';
if (!in_array($filterAssignment, $allowedAssignment, true)) $filterAssignment = '';

$patientRowsRaw = [];
if ($hasPatient) {
    $patientCols = ['Patient_id','Fullname','Gender','Age','Disease','Village_id'];
    $selectCols = [];
    foreach ($patientCols as $col) {
        if (statColumnExists($conn, 'patient', $col)) $selectCols[] = 'p.`'.$col.'`';
    }
    if (!in_array('p.`Patient_id`', $selectCols, true)) $selectCols[] = 'p.Patient_id';
    $villageJoin = $hasVillage ? ' LEFT JOIN village v ON p.Village_id=v.village_id ' : '';
    $villageSelect = $hasVillage ? ', v.villagename' : ", '' AS villagename";
    $patientRowsRaw = statRows($conn, 'SELECT '.implode(',', $selectCols).$villageSelect.' FROM patient p '.$villageJoin.' ORDER BY p.Fullname ASC, p.Patient_id ASC');
}

$filteredPatients = [];
foreach ($patientRowsRaw as $r) {
    $pid = (int)($r['Patient_id'] ?? 0);
    $vid = (int)($r['Village_id'] ?? 0);
    $assigned = isset($assignedSet[$pid]);
    $adlGroup = $latestAdlByPatient[$pid] ?? 'ยังไม่ประเมิน';
    if ($filterVillage > 0 && $vid !== $filterVillage) continue;
    if ($filterAdl !== '' && $adlGroup !== $filterAdl) continue;
    if ($filterAssignment === 'assigned' && !$assigned) continue;
    if ($filterAssignment === 'unassigned' && $assigned) continue;
    if ($filterKeyword !== '') {
        $hay = mb_strtolower(trim((string)($r['Fullname'] ?? '')).' '.trim((string)($r['Disease'] ?? '')).' '.trim((string)($r['villagename'] ?? '')), 'UTF-8');
        if (mb_strpos($hay, mb_strtolower($filterKeyword, 'UTF-8')) === false) continue;
    }
    $r['_assigned'] = $assigned;
    $r['_adl_group'] = $adlGroup;
    $filteredPatients[] = $r;
}

$filteredTotal = count($filteredPatients);
$filteredAssigned = 0;
$filteredAdl = ['ติดสังคม'=>0,'ติดบ้าน'=>0,'ติดเตียง'=>0,'ยังไม่ประเมิน'=>0];
$filteredVillageMap = [];
foreach ($filteredPatients as $r) {
    if (!empty($r['_assigned'])) $filteredAssigned++;
    $g = (string)$r['_adl_group'];
    if (isset($filteredAdl[$g])) $filteredAdl[$g]++;
    $vid = (int)($r['Village_id'] ?? 0);
    $vname = trim((string)($r['villagename'] ?? '')) ?: 'ไม่ระบุหมู่บ้าน';
    if (!isset($filteredVillageMap[$vid])) $filteredVillageMap[$vid] = ['name'=>$vname,'total'=>0,'assigned'=>0,'social'=>0,'home'=>0,'bed'=>0,'pending'=>0];
    $filteredVillageMap[$vid]['total']++;
    if (!empty($r['_assigned'])) $filteredVillageMap[$vid]['assigned']++;
    if ($g === 'ติดสังคม') $filteredVillageMap[$vid]['social']++;
    elseif ($g === 'ติดบ้าน') $filteredVillageMap[$vid]['home']++;
    elseif ($g === 'ติดเตียง') $filteredVillageMap[$vid]['bed']++;
    else $filteredVillageMap[$vid]['pending']++;
}
$filteredUnassigned = max(0, $filteredTotal - $filteredAssigned);
$filteredAssessed = $filteredAdl['ติดสังคม'] + $filteredAdl['ติดบ้าน'] + $filteredAdl['ติดเตียง'];
$filteredVillageRows = array_values($filteredVillageMap);
usort($filteredVillageRows, static function($a,$b){ return ($b['total'] <=> $a['total']) ?: strcmp($a['name'],$b['name']); });

$qs = $_GET;
$printQuery = http_build_query($qs);
$printHref = 'statistics_print.php' . ($printQuery !== '' ? ('?'.$printQuery) : '');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ออกรายงาน | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--rp-bg:#f6fbfc;--rp-line:#b8dded;--rp-blue:#7faed8;--rp-blue2:#bfeff2;--rp-text:#244f70;--rp-muted:#71899a;--rp-green:#dff6e7;--rp-yellow:#fff0bd;--rp-red:#ffdede;--rp-card:#fff;}
*{box-sizing:border-box}body{font-family:'Noto Sans Thai',sans-serif}.report-ref-page .main{padding-bottom:38px;background:#fff}.rr-wrap{max-width:1460px;margin:0 auto;padding:18px 28px 36px;color:var(--rp-text)}
.rr-title{padding:8px 2px 12px;margin-bottom:16px}.rr-title h1{margin:0;font-size:31px;line-height:1.15;font-weight:900;color:#244f70}.rr-title p{margin:3px 0 0;color:#7a8da0;font-size:13px;font-weight:600}
.rr-panel{border:1px solid #acd7e8;border-radius:14px;background:linear-gradient(110deg,#fff 0%,#f5fdff 56%,#dbfbf8 100%);box-shadow:0 6px 20px rgba(56,117,145,.06);padding:18px 20px;margin-bottom:18px}.rr-panel h2{margin:0 0 13px;font-size:20px;color:#244f70}
.rr-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.rr-field label{display:block;margin:0 0 6px;font-size:12px;font-weight:900;color:#2f5c77}.rr-control{width:100%;height:44px;border:1px solid #b7d6e5;border-radius:9px;background:#fff;color:#244f70;padding:0 13px;font:inherit;font-size:13px;font-weight:700;outline:none}.rr-control:focus{border-color:#6fb8d9;box-shadow:0 0 0 3px rgba(111,184,217,.13)}
.rr-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.rr-btn{height:39px;min-width:118px;padding:0 18px;border:0;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px;font-weight:900;cursor:pointer}.rr-btn.primary{background:#79a9d6;color:#fff}.rr-btn.danger{background:#eb6774;color:#fff}.rr-btn.export{background:#78a8d5;color:#fff}.rr-btn:hover{filter:brightness(.97);transform:translateY(-1px)}
.rr-summary-title{margin:0 0 3px;font-size:20px;font-weight:900;color:#244f70}.rr-summary-note{margin:0 0 12px;font-size:13px;color:#536f82}.rr-two-cards{display:grid;grid-template-columns:260px 260px;gap:12px;margin-bottom:15px}.rr-mini{border:1px solid #c4dce8;background:#fff;border-radius:9px;padding:13px 14px}.rr-mini span{display:block;font-size:12px;color:#6b8293;font-weight:800}.rr-mini strong{display:block;margin-top:5px;font-size:23px;color:#205479}
.rr-table-wrap{overflow:auto;border:1px solid #c6dce8;border-radius:9px;background:#fff}.rr-table{width:100%;border-collapse:collapse;min-width:850px;font-size:12px}.rr-table th{padding:9px 10px;background:#80a9d3;color:#fff;text-align:center;font-weight:900;white-space:nowrap}.rr-table td{padding:9px 10px;border-bottom:1px solid #dce7ed;text-align:center;color:#36566b;vertical-align:middle}.rr-table tr:last-child td{border-bottom:0}.rr-table td.left{text-align:left;font-weight:700}
.rr-overview{border:1px solid #acd7e8;border-radius:14px;background:linear-gradient(110deg,#fff 0%,#f4fcff 55%,#dffbfa 100%);padding:16px 20px;margin-bottom:18px}.rr-overview h2{margin:0 0 12px;font-size:20px}.rr-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.rr-kpi{position:relative;overflow:hidden;border:1px solid #c7dce7;border-radius:9px;background:#fff;padding:11px 12px;min-height:66px}.rr-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:#79a9d6}.rr-kpi.green::before{background:#57b88c}.rr-kpi.yellow::before{background:#e5bc52}.rr-kpi.red::before{background:#e27582}.rr-kpi span{font-size:11px;color:#718898;font-weight:800}.rr-kpi strong{display:block;margin-top:3px;font-size:20px;color:#205478}.rr-list-panel{border:1px solid #acd7e8;border-radius:14px;background:linear-gradient(110deg,#fff 0%,#f5fdff 62%,#e3fbf8 100%);padding:16px 20px}.rr-list-panel h2{margin:0 0 12px;font-size:20px}.rr-empty{padding:22px;text-align:center;color:#7b909a;background:#fff;border-radius:9px;border:1px dashed #c7dae4}
.status-pill{display:inline-flex;align-items:center;justify-content:center;min-width:80px;padding:5px 11px;border-radius:999px;font-size:11px;font-weight:900}.status-assigned{background:#d8f7e2;color:#218547}.status-unassigned{background:#fff0bc;color:#926b00}.adl-social{background:#d9f7e1;color:#24884d}.adl-home{background:#dff7f4;color:#287f7d}.adl-bed{background:#dff1fb;color:#2c759a}.adl-pending{background:#eef2f4;color:#71838e}
@media(max-width:1180px){.rr-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rr-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.rr-wrap{padding:12px 12px 28px}.rr-title h1{font-size:26px}.rr-filter-grid{grid-template-columns:1fr}.rr-two-cards{grid-template-columns:1fr}.rr-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rr-btn{flex:1}}
@media print{.sidebar,.user-topbar,.rr-filter-panel,.rr-actions{display:none!important}.rr-wrap{padding:0;max-width:none}.rr-panel,.rr-overview,.rr-list-panel{box-shadow:none;break-inside:avoid}.main{margin:0!important}}
</style>
<link rel="stylesheet" href="assets/unified_home_report.css?v=20260929-1">
</head>
<body class="role-page report-ref-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="rr-wrap">
    <header class="rr-title">
        <h1>รายงานข้อมูลสุขภาพผู้สูงอายุ</h1>
    </header>

    <section class="rr-panel rr-filter-panel">
        <h2>ตัวกรองรายงาน</h2>
        <form method="get" action="">
            <div class="rr-filter-grid">
                <div class="rr-field"><label>ค้นหาผู้สูงอายุ</label><input class="rr-control" type="text" name="q" value="<?= statH($filterKeyword) ?>" placeholder="ชื่อผู้สูงอายุ / โรคประจำตัว"></div>
                <div class="rr-field"><label>หมู่บ้าน</label><select class="rr-control" name="village_id"><option value="0">-- ทุกหมู่บ้าน --</option><?php foreach($villageRows as $v): ?><option value="<?= (int)$v['village_id'] ?>" <?= $filterVillage===(int)$v['village_id']?'selected':'' ?>><?= statH($v['villagename'] ?: 'ไม่ระบุชื่อหมู่บ้าน') ?></option><?php endforeach; ?></select></div>
                <div class="rr-field"><label>ผลประเมิน ADL ล่าสุด</label><select class="rr-control" name="adl_group"><option value="">-- ทุกผลประเมิน --</option><?php foreach(['ติดสังคม','ติดบ้าน','ติดเตียง','ยังไม่ประเมิน'] as $g): ?><option value="<?= statH($g) ?>" <?= $filterAdl===$g?'selected':'' ?>><?= statH($g) ?></option><?php endforeach; ?></select></div>
                <div class="rr-field"><label>สถานะมอบหมายผู้ดูแล</label><select class="rr-control" name="assignment"><option value="">-- ทุกสถานะ --</option><option value="assigned" <?= $filterAssignment==='assigned'?'selected':'' ?>>มอบหมายแล้ว</option><option value="unassigned" <?= $filterAssignment==='unassigned'?'selected':'' ?>>รอมอบหมาย</option></select></div>
            </div>
            <div class="rr-actions">
                <button class="rr-btn primary" type="submit">แสดงรายการ</button>
                <a class="rr-btn danger" href="statistics.php">ล้างเงื่อนไข</a>
                <a class="rr-btn export" href="<?= statH($printHref) ?>" target="_blank" rel="noopener">พิมพ์ / Export รายงาน</a>
            </div>
        </form>
    </section>

    <section class="rr-panel">
        <h2 class="rr-summary-title">สรุปข้อมูลตามเงื่อนไขที่เลือก</h2>
        <div class="rr-two-cards">
            <div class="rr-mini"><span>จำนวนผู้สูงอายุที่พบ</span><strong><?= number_format($filteredTotal) ?></strong></div>
            <div class="rr-mini"><span>จำนวนผู้สูงอายุที่ประเมิน ADL แล้ว</span><strong><?= number_format($filteredAssessed) ?></strong></div>
        </div>
        <?php if ($filteredVillageRows): ?>
        <div class="rr-table-wrap">
            <table class="rr-table">
                <thead><tr><th>หมู่บ้าน</th><th>ผู้สูงอายุ</th><th>มอบหมายแล้ว</th><th>ติดสังคม</th><th>ติดบ้าน</th><th>ติดเตียง</th><th>ยังไม่ประเมิน</th></tr></thead>
                <tbody><?php foreach($filteredVillageRows as $v): ?><tr><td class="left"><?= statH($v['name']) ?></td><td><?= number_format($v['total']) ?></td><td><?= number_format($v['assigned']) ?></td><td><?= number_format($v['social']) ?></td><td><?= number_format($v['home']) ?></td><td><?= number_format($v['bed']) ?></td><td><?= number_format($v['pending']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php else: ?><div class="rr-empty">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</div><?php endif; ?>
    </section>

    <section class="rr-overview">
        <h2>ภาพรวมรายการตามตัวกรอง</h2>
        <div class="rr-kpis">
            <div class="rr-kpi"><span>ผู้สูงอายุทั้งหมด</span><strong><?= number_format($filteredTotal) ?></strong></div>
            <div class="rr-kpi green"><span>มอบหมายผู้ดูแลแล้ว</span><strong><?= number_format($filteredAssigned) ?></strong></div>
            <div class="rr-kpi yellow"><span>รอมอบหมายผู้ดูแล</span><strong><?= number_format($filteredUnassigned) ?></strong></div>
            <div class="rr-kpi green"><span>ประเมิน ADL แล้ว</span><strong><?= number_format($filteredAssessed) ?></strong></div>
            <div class="rr-kpi red"><span>ยังไม่ประเมิน ADL</span><strong><?= number_format($filteredAdl['ยังไม่ประเมิน']) ?></strong></div>
            <div class="rr-kpi"><span>หมู่บ้านที่มีข้อมูล</span><strong><?= number_format(count($filteredVillageRows)) ?></strong></div>
        </div>
    </section>

    <section class="rr-list-panel">
        <h2>รายการผู้สูงอายุ</h2>
        <?php if ($filteredPatients): ?>
        <div class="rr-table-wrap"><table class="rr-table" style="min-width:1100px"><thead><tr><th>รหัส</th><th>ชื่อผู้สูงอายุ</th><th>เพศ</th><th>อายุ</th><th>หมู่บ้าน</th><th>โรคประจำตัว</th><th>สถานะผู้ดูแล</th><th>ผล ADL ล่าสุด</th></tr></thead><tbody>
        <?php foreach($filteredPatients as $r): $g=(string)$r['_adl_group']; $gClass=$g==='ติดสังคม'?'adl-social':($g==='ติดบ้าน'?'adl-home':($g==='ติดเตียง'?'adl-bed':'adl-pending')); ?>
            <tr><td><?= (int)($r['Patient_id'] ?? 0) ?></td><td class="left"><?= statH($r['Fullname'] ?? '-') ?></td><td><?= statH($r['Gender'] ?? '-') ?></td><td><?= (int)($r['Age'] ?? 0) ?> ปี</td><td><?= statH($r['villagename'] ?? '-') ?></td><td class="left"><?= statH(trim((string)($r['Disease'] ?? '')) ?: '-') ?></td><td><span class="status-pill <?= !empty($r['_assigned'])?'status-assigned':'status-unassigned' ?>"><?= !empty($r['_assigned'])?'มอบหมายแล้ว':'รอมอบหมาย' ?></span></td><td><span class="status-pill <?= $gClass ?>"><?= statH($g) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><div class="rr-empty">ไม่พบรายชื่อผู้สูงอายุตามตัวกรองที่กำหนด</div><?php endif; ?>
    </section>
</div>
</main>
</body>
</html>

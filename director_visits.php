<?php
require_once __DIR__ . '/director_tools.php';
$month = trim((string)($_GET['month'] ?? date('Y-m')));
$q = trim((string)($_GET['q'] ?? ''));
$rows = dirFetchVisitRows($conn, $month, $q);
$vm = dirVisitMetrics($conn, $month);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ติดตามการเข้าเยี่ยม | <?= e(appName()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/role_pages.css"><link rel="stylesheet" href="assets/director_portal.css">
</head>
<body class="role-page">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="director-main">
    <?php dirPageHeader('ติดตามการเข้าเยี่ยม', 'ติดตามการเข้าเยี่ยมผู้สูงอายุ', 'ดูบันทึกเข้าเยี่ยมล่าสุด นัดติดตามครั้งถัดไป และข้อมูลการส่งต่อของผู้สูงอายุในระบบ'); ?>
    <section class="director-kpis">
        <article class="director-kpi teal"><div class="director-kpi__label">การเข้าเยี่ยมทั้งหมด</div><div class="director-kpi__value"><?= (int)$vm['total'] ?><span class="director-kpi__unit">ครั้ง</span></div><div class="director-kpi__meta">นับจากทุกบันทึกในระบบ</div></article>
        <article class="director-kpi blue"><div class="director-kpi__label">การเข้าเยี่ยมในเดือนที่เลือก</div><div class="director-kpi__value"><?= (int)$vm['monthTotal'] ?><span class="director-kpi__unit">ครั้ง</span></div><div class="director-kpi__meta">กรองตามเดือนที่เลือกด้านล่าง</div></article>
        <article class="director-kpi yellow"><div class="director-kpi__label">มีนัดติดตาม</div><div class="director-kpi__value"><?= (int)$vm['followups'] ?><span class="director-kpi__unit">รายการ</span></div><div class="director-kpi__meta">มีการกำหนดวันติดตามครั้งถัดไป</div></article>
        <article class="director-kpi red"><div class="director-kpi__label">ยังไม่มีบันทึก / ส่งต่อ</div><div class="director-kpi__value"><?= (int)$vm['pending'] ?><span class="director-kpi__unit">คน</span></div><div class="director-kpi__meta">ผู้ยังไม่มีบันทึกเข้าเยี่ยม · ส่งต่อ <?= (int)$vm['referrals'] ?> รายการ</div></article>
    </section>
    <section class="director-panel">
        <div class="director-panel__head"><div><h2>บันทึกการเข้าเยี่ยม</h2><p>ตรวจสอบรายละเอียดการเยี่ยมแต่ละครั้งแบบอ่านอย่างเดียว</p></div></div>
        <div class="director-panel__body">
            <form class="director-controls" method="get">
                <div class="director-controls__group">
                    <input class="director-select" type="month" name="month" value="<?= dirH($month) ?>">
                    <input class="director-search" type="text" name="q" value="<?= dirH($q) ?>" placeholder="ค้นหาจากชื่อผู้สูงอายุ แคร์กิฟเวอร์ หรือประเภทการเยี่ยม">
                    <button class="director-btn primary" type="submit">แสดงข้อมูล</button>
                    <a class="director-btn" href="director_visits.php">รีเซ็ต</a>
                </div>
                <div class="director-note">แสดงสูงสุด 200 รายการ</div>
            </form>
            <div class="director-table-wrap">
                <?php if (!$rows): ?>
                    <div class="director-empty">ยังไม่มีข้อมูลการเข้าเยี่ยม</div>
                <?php else: ?>
                    <table class="director-table">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>ผู้สูงอายุ</th>
                                <th>แคร์กิฟเวอร์ / หมอ</th>
                                <th>ประเภทการเยี่ยม</th>
                                <th>สภาพทั่วไป / วัตถุประสงค์</th>
                                <th>นัดติดตาม</th>
                                <th>การส่งต่อ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= dirH(dirThaiDate($row['visit_date'] ?? null)) ?></strong>
                                        <div class="director-note"><?= !empty($row['visit_time']) ? dirH(substr((string)$row['visit_time'],0,5)).' น.' : '-' ?></div>
                                    </td>
                                    <td><strong><?= dirH($row['Fullname'] ?? '-') ?></strong><?= !empty($row['visit_no']) ? '<div class="director-note">ครั้งที่ '.(int)$row['visit_no'].'</div>' : '' ?></td>
                                    <td><strong>แคร์กิฟเวอร์:</strong> <?= dirH($row['caregiver_name'] ?? '-') ?><br><strong>หมอ:</strong> <?= dirH($row['doctor_name'] ?? '-') ?></td>
                                    <td><?= dirH($row['visit_type'] ?? '-') ?></td>
                                    <td><?= dirH($row['general_condition'] ?? '-') ?><div class="director-note"><?= dirH($row['purpose'] ?? '-') ?></div></td>
                                    <td><?= dirH(dirThaiDate($row['next_visit_date'] ?? null)) ?></td>
                                    <td><?= dirH($row['referral_type'] ?? '-') ?><div class="director-note"><?= dirH($row['referral_reason'] ?? '-') ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
</body>
</html>

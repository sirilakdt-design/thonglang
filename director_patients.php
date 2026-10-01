<?php
require_once __DIR__ . '/director_tools.php';
$q = trim((string)($_GET['q'] ?? ''));
$rows = dirFetchPatientRows($conn, $q);
$metrics = dirPatientMetrics($conn);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ข้อมูลผู้สูงอายุ | <?= e(appName()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/role_pages.css"><link rel="stylesheet" href="assets/director_portal.css">
</head>
<body class="role-page">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="director-main">
    <?php dirPageHeader('ข้อมูลผู้สูงอายุ', 'ข้อมูลผู้สูงอายุ', 'ดูรายชื่อผู้สูงอายุ โรคประจำตัว หมู่บ้าน ผู้รับผิดชอบ และสถานะผลประเมินแบบอ่านอย่างเดียว'); ?>
    <section class="director-stat-strip">
        <div class="director-stat-chip"><span>ผู้สูงอายุทั้งหมด</span><strong><?= (int)$metrics['patients'] ?> คน</strong></div>
        <div class="director-stat-chip"><span>ได้รับมอบหมายแล้ว</span><strong><?= (int)$metrics['assigned'] ?> คน</strong></div>
        <div class="director-stat-chip"><span>ยังไม่ประเมิน ADL</span><strong><?= (int)$metrics['adl_pending'] ?> คน</strong></div>
    </section>
    <section class="director-panel">
        <div class="director-panel__head">
            <div><h2>รายชื่อผู้สูงอายุ</h2><p>ค้นหาและติดตามข้อมูลสำคัญของผู้สูงอายุในระบบ</p></div>
        </div>
        <div class="director-panel__body">
            <form class="director-controls" method="get">
                <div class="director-controls__group">
                    <input class="director-search" type="text" name="q" value="<?= dirH($q) ?>" placeholder="ค้นหาจากชื่อผู้สูงอายุหรือโรคประจำตัว">
                    <button class="director-btn primary" type="submit">ค้นหา</button>
                    <?php if ($q !== ''): ?><a class="director-btn" href="director_patients.php">ล้างการค้นหา</a><?php endif; ?>
                </div>
                <div class="director-note">แสดงสูงสุด 200 รายการ</div>
            </form>
            <div class="director-table-wrap">
                <?php if (!$rows): ?>
                    <div class="director-empty">ไม่พบข้อมูลผู้สูงอายุ</div>
                <?php else: ?>
                    <table class="director-table">
                        <thead>
                            <tr>
                                <th>ชื่อผู้สูงอายุ</th>
                                <th>อายุ / เพศ</th>
                                <th>หมู่บ้าน</th>
                                <th>โรคประจำตัว</th>
                                <th>ผู้รับผิดชอบ</th>
                                <th>ADL ล่าสุด</th>
                                <th>เข้าเยี่ยมล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $score = isset($row['latest_score']) && $row['latest_score'] !== '' ? (int)$row['latest_score'] : null; [$group, $cls] = dirAdlGroup($score); ?>
                                <tr>
                                    <td><strong><?= dirH($row['Fullname'] ?? '-') ?></strong></td>
                                    <td><?= dirH(($row['Age'] ?? '-') . ' ปี') ?><div class="director-note"><?= dirH($row['Gender'] ?? '-') ?></div></td>
                                    <td><?= dirH($row['village_name'] ?? '-') ?></td>
                                    <td><?= dirH($row['Disease'] ?? '-') ?></td>
                                    <td>
                                        <strong>แคร์กิฟเวอร์:</strong> <?= dirH($row['caregiver_name'] ?? '-') ?><br>
                                        <strong>หมอ:</strong> <?= dirH($row['doctor_name'] ?? '-') ?>
                                    </td>
                                    <td><span class="director-status <?= $cls ?>"><?= $score === null ? '-' : $score . '/20' ?> · <?= dirH($group) ?></span></td>
                                    <td><?= dirH(dirThaiDate($row['last_visit_date'] ?? null)) ?><div class="director-note">นัดถัดไป <?= dirH(dirThaiDate($row['next_visit_date'] ?? null)) ?></div></td>
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

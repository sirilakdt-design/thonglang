<?php
require_once __DIR__ . '/director_tools.php';
$q = trim((string)($_GET['q'] ?? ''));
$rows = dirFetchAssessmentRows($conn, $q);
$am = dirAssessmentMetrics($conn);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ผลการประเมิน | <?= e(appName()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/role_pages.css"><link rel="stylesheet" href="assets/director_portal.css">
</head>
<body class="role-page">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="director-main">
    <?php dirPageHeader('ผลการประเมิน', 'ผลการประเมินผู้สูงอายุ', 'สรุปผลการประเมิน ADL ล่าสุดของผู้สูงอายุ พร้อมดูคะแนนและกลุ่ม ADL แบบอ่านอย่างเดียว'); ?>
    <section class="director-kpis">
        <article class="director-kpi teal"><div class="director-kpi__label">ประเมินแล้ว</div><div class="director-kpi__value"><?= (int)$am['done'] ?><span class="director-kpi__unit">คน</span></div><div class="director-kpi__meta">มีคะแนน ADL ล่าสุดในระบบ</div></article>
        <article class="director-kpi red"><div class="director-kpi__label">ยังไม่ประเมิน</div><div class="director-kpi__value"><?= (int)$am['pending'] ?><span class="director-kpi__unit">คน</span></div><div class="director-kpi__meta">ยังไม่มีผลประเมินล่าสุด</div></article>
        <article class="director-kpi blue"><div class="director-kpi__label">คะแนนเฉลี่ย</div><div class="director-kpi__value"><?= (int)$am['avg_score'] ?><span class="director-kpi__unit">/20</span></div><div class="director-kpi__meta">เฉลี่ยจากคะแนนล่าสุดของผู้สูงอายุ</div></article>
        <article class="director-kpi yellow"><div class="director-kpi__label">ติดเตียง / ติดบ้าน / ติดสังคม</div><div class="director-kpi__value"><?= (int)($am['groups']['ติดเตียง'] ?? 0) ?><span class="director-kpi__unit">/<?= (int)($am['groups']['ติดบ้าน'] ?? 0) ?>/<?= (int)($am['groups']['ติดสังคม'] ?? 0) ?></span></div><div class="director-kpi__meta">ลำดับ: ติดเตียง · ติดบ้าน · ติดสังคม</div></article>
    </section>
    <section class="director-panel">
        <div class="director-panel__head"><div><h2>รายการผลการประเมินล่าสุด</h2><p>ค้นหาและดูผลคะแนน ADL ล่าสุดของผู้สูงอายุแต่ละราย</p></div></div>
        <div class="director-panel__body">
            <form class="director-controls" method="get">
                <div class="director-controls__group">
                    <input class="director-search" type="text" name="q" value="<?= dirH($q) ?>" placeholder="ค้นหาจากชื่อผู้สูงอายุ">
                    <button class="director-btn primary" type="submit">ค้นหา</button>
                    <?php if ($q !== ''): ?><a class="director-btn" href="director_assessments.php">ล้างการค้นหา</a><?php endif; ?>
                </div>
                <div class="director-note">แสดงสูงสุด 200 รายการ</div>
            </form>
            <div class="director-table-wrap">
                <?php if (!$rows): ?>
                    <div class="director-empty">ยังไม่มีข้อมูลผลการประเมิน</div>
                <?php else: ?>
                    <table class="director-table">
                        <thead>
                            <tr>
                                <th>ผู้สูงอายุ</th>
                                <th>วันที่ประเมิน</th>
                                <th>ผู้ประเมิน</th>
                                <th>คะแนน</th>
                                <th>กลุ่ม ADL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $score = isset($row['total_score']) && $row['total_score'] !== '' ? (int)$row['total_score'] : null; [$group, $cls] = dirAdlGroup($score); ?>
                                <tr>
                                    <td><strong><?= dirH($row['Fullname'] ?? '-') ?></strong></td>
                                    <td><?= dirH(dirThaiDate($row['assessment_date'] ?? ($row['assessed_at'] ?? null))) ?></td>
                                    <td><strong>แคร์กิฟเวอร์:</strong> <?= dirH($row['caregiver_name'] ?? '-') ?><br><strong>หมอ:</strong> <?= dirH($row['doctor_name'] ?? '-') ?></td>
                                    <td><strong><?= $score === null ? '-' : $score . '/20' ?></strong></td>
                                    <td><span class="director-status <?= $cls ?>"><?= dirH($group) ?></span></td>
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

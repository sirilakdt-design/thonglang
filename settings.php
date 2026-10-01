<?php
require_once __DIR__ . '/connect.php';
requireRole('admin');
mysqli_set_charset($conn, 'utf8mb4');
ensureSystemSettingsTable($conn);

$message = '';
$messageType = '';

$defaults = [
    'org_name' => 'ทองหลาง',
    'director_name' => 'นาย รัศมี แก้วเนตร',
    'subdistrict' => 'ทองหลาง',
    'district' => 'จักราช',
    'province' => 'นครราชสีมา',
    'zipcode' => '30230',
    'items_per_page' => '10',
    'visit_reminder_days' => '3',
    'adl_review_months' => '6',
    'map_default_lat' => '15.0450000',
    'map_default_lng' => '102.3300000',
    'map_default_zoom' => '13',
    'allow_current_location' => '1',
    'report_title' => 'รายงานสรุปข้อมูลผู้สูงอายุ',
    'show_report_date' => '1',
    'show_report_author' => '1',
    'show_signature_space' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedKeys = ['org_name','director_name','subdistrict','district','province','visit_reminder_days','adl_review_months','show_report_date','show_report_author','show_signature_space'];
    $ok = true;
    foreach ($allowedKeys as $key) {
        if (in_array($key, ['show_report_date','show_report_author','show_signature_space'], true)) {
            $value = isset($_POST[$key]) ? '1' : '0';
        } else {
            $value = trim((string)($_POST[$key] ?? $defaults[$key]));
        }
        if ($key === 'org_name') {
            $value = preg_replace('/^[\p{Mn}\x{200B}\s]+/u', '', $value) ?? $value;
            $value = preg_replace('/^ทื(?=ทองหลาง)/u', '', $value) ?? $value;
        }
        if (!saveSystemSetting($conn, $key, $value)) $ok = false;
    }
    if ($ok) {
        $configuredDirector = trim((string)($_POST['director_name'] ?? $defaults['director_name']));
        if ($configuredDirector === '') $configuredDirector = $defaults['director_name'];
        $directorStmt = mysqli_prepare($conn, "UPDATE users SET display_name=? WHERE role='director'");
        if ($directorStmt) {
            mysqli_stmt_bind_param($directorStmt, 's', $configuredDirector);
            mysqli_stmt_execute($directorStmt);
            mysqli_stmt_close($directorStmt);
        }
        $message = 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว';
        $messageType = 'success';
    } else {
        $message = 'บันทึกการตั้งค่าบางรายการไม่สำเร็จ';
        $messageType = 'error';
    }
}

$settings = [];
foreach ($defaults as $key => $default) {
    $settings[$key] = $key === 'org_name' ? appName() : systemSetting($conn, $key, $default);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(appDocumentTitle('ตั้งค่าระบบ')) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.settings-page .settings-shell{max-width:1080px;margin:0 auto;padding:18px 22px 36px}
.settings-page .settings-page-head{display:flex;align-items:flex-end;justify-content:space-between;margin:0 0 16px;padding:0 2px}.settings-page .settings-page-head h1{margin:0;color:#214e52;font-size:28px;line-height:1.2;font-weight:900}.settings-page .settings-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;align-items:start}.settings-page .settings-grid>.settings-card:first-child{grid-row:span 2}
.settings-page .settings-card{background:#fff;border:1px solid #d8ebe8;border-radius:20px;padding:22px 24px;box-shadow:0 10px 26px rgba(36,108,115,.05);min-width:0}
.settings-page .settings-card h2{margin:0 0 16px;color:#214e52;font-size:19px;font-weight:900}
.settings-page .field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.settings-page .field{display:grid;gap:6px}
.settings-page .field.full{grid-column:1/-1}
.settings-page label{font-size:13px;font-weight:800;color:#294c4f}
.settings-page input{width:100%;min-height:42px;border:1px solid #d3e7e4;border-radius:11px;background:#fbfefe;color:#27484a;font:inherit;padding:9px 12px;outline:none;box-sizing:border-box}
.settings-page input:focus{border-color:#61c2c6;box-shadow:0 0 0 3px rgba(97,194,198,.12)}
.settings-page .save-bar{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:18px;padding:0;background:transparent;border:0;box-shadow:none}
.settings-page .btn-save,.settings-page .btn-reset{min-width:150px;min-height:46px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:14px;padding:0 20px;font:inherit;font-size:13px;font-weight:900;transition:.18s ease;box-sizing:border-box}
.settings-page .btn-save{border:1px solid #4fb4b9;background:linear-gradient(135deg,#63c7c3 0%,#4db5ba 100%);color:#fff;cursor:pointer;box-shadow:0 8px 18px rgba(79,180,185,.22)}
.settings-page .btn-save:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(79,180,185,.28);filter:saturate(1.04)}
.settings-page .btn-save:active{transform:translateY(0)}
.settings-page .btn-reset{border:1px solid #cfe3e0;background:#fff;color:#3d6260;text-decoration:none;box-shadow:0 5px 14px rgba(36,108,115,.06)}
.settings-page .btn-reset:hover{border-color:#a9d7d2;background:#f7fcfb;color:#24585a;transform:translateY(-1px)}
.settings-page .alert{margin-bottom:14px;padding:11px 13px;border-radius:12px;font-size:13px}.settings-page .alert.success{background:#eaf8f1;border:1px solid #cfe8da;color:#2d684f}.settings-page .alert.error{background:#fff1f1;border:1px solid #f0d1d1;color:#984b4b}

.settings-page .settings-card + .settings-card{margin-top:0}
.settings-page .settings-card p.section-note{margin:-8px 0 14px;color:#7b9290;font-size:12px}
.settings-page .compact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.settings-page .check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.settings-page .check-item{display:flex;align-items:center;gap:9px;min-height:42px;padding:10px 12px;border:1px solid #d8e9e7;border-radius:12px;background:#fbfefe;color:#294c4f;font-size:13px;font-weight:800}
.settings-page .check-item input{width:16px;height:16px;min-height:0;padding:0;accent-color:#52b9bc}
.settings-page .unit-wrap{position:relative}.settings-page .unit-wrap input{padding-right:58px}.settings-page .unit-label{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:12px;color:#78908d;font-weight:800;pointer-events:none}
@media(max-width:900px){.settings-page .settings-grid{grid-template-columns:1fr}.settings-page .settings-grid>.settings-card:first-child{grid-row:auto}}@media(max-width:680px){.settings-page .settings-shell{padding:12px}.settings-page .settings-page-head h1{font-size:25px}.settings-page .settings-card{padding:18px}.settings-page .field-grid,.settings-page .compact-grid,.settings-page .check-grid{grid-template-columns:1fr}.settings-page .field.full{grid-column:auto}.settings-page .save-bar{justify-content:stretch}.settings-page .btn-save,.settings-page .btn-reset{flex:1;min-width:0}}
</style>
</head>
<body class="role-page settings-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>
<div class="settings-shell">
<div class="settings-page-head"><h1>ตั้งค่าระบบ</h1></div>
<?php if ($message !== ''): ?>
        <div class="alert <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <form method="post" action="settings.php">
        <div class="settings-grid">
            <section class="settings-card">
                <h2>ข้อมูลหน่วยงาน</h2>
                <div class="field-grid">
                    <div class="field full"><label>ชื่อระบบ / หน่วยงาน</label><input name="org_name" value="<?= e($settings['org_name']) ?>"></div>
                    <div class="field full"><label>ชื่อผู้อำนวยการ</label><input name="director_name" value="<?= e($settings['director_name']) ?>" placeholder="ชื่อผู้อำนวยการ"></div>
                    <div class="field"><label>ตำบล</label><input name="subdistrict" value="<?= e($settings['subdistrict']) ?>"></div>
                    <div class="field"><label>อำเภอ</label><input name="district" value="<?= e($settings['district']) ?>"></div>
                    <div class="field full"><label>จังหวัด</label><input name="province" value="<?= e($settings['province']) ?>"></div>
                </div>
            </section>

            <section class="settings-card">
                <h2>รอบการติดตามผู้สูงอายุ</h2>
                <p class="section-note">กำหนดรอบมาตรฐานสำหรับการเข้าเยี่ยมและการประเมิน ADL</p>
                <div class="compact-grid">
                    <div class="field">
                        <label>เข้าเยี่ยมทุก</label>
                        <div class="unit-wrap"><input type="number" min="1" max="24" name="visit_reminder_days" value="<?= e($settings['visit_reminder_days']) ?>"><span class="unit-label">เดือน</span></div>
                    </div>
                    <div class="field">
                        <label>ประเมิน ADL ทุก</label>
                        <div class="unit-wrap"><input type="number" min="1" max="24" name="adl_review_months" value="<?= e($settings['adl_review_months']) ?>"><span class="unit-label">เดือน</span></div>
                    </div>
                </div>
            </section>

            <section class="settings-card">
                <h2>การออกรายงาน</h2>
                <p class="section-note">เลือกข้อมูลประกอบที่ต้องการให้แสดงในรายงานของระบบ</p>
                <div class="check-grid">
                    <label class="check-item"><input type="checkbox" name="show_report_date" value="1" <?= $settings['show_report_date'] === '1' ? 'checked' : '' ?>>แสดงวันที่จัดทำ</label>
                    <label class="check-item"><input type="checkbox" name="show_report_author" value="1" <?= $settings['show_report_author'] === '1' ? 'checked' : '' ?>>แสดงชื่อผู้จัดทำ</label>
                    <label class="check-item"><input type="checkbox" name="show_signature_space" value="1" <?= $settings['show_signature_space'] === '1' ? 'checked' : '' ?>>แสดงพื้นที่ลงชื่อ</label>
                </div>
            </section>

        </div>

        <div class="save-bar">
            <a class="btn-reset" href="settings.php"><span>ยกเลิก</span></a>
            <button class="btn-save" type="submit">บันทึกการตั้งค่า</button>
        </div>
    </form>
</div>
</main>
</body>
</html>

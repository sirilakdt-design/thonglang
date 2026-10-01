<?php
require_once __DIR__ . '/connect.php';
requireRole('admin');
ensureThonglangCoreSchema($conn);
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$messageType = '';
$postAction = '';

function villageRedirectFull(string $success): void
{
    header('Location: village.php?success=' . urlencode($success));
    exit;
}

function villageNumberExists(mysqli $connection, string $villageNumber, int $excludeVillageId = 0): bool
{
    $sql = 'SELECT village_id FROM village WHERE village_number = ?';
    if ($excludeVillageId > 0) $sql .= ' AND village_id <> ?';
    $sql .= ' LIMIT 1';
    $statement = mysqli_prepare($connection, $sql);
    if (!$statement) return false;

    if ($excludeVillageId > 0) mysqli_stmt_bind_param($statement, 'si', $villageNumber, $excludeVillageId);
    else mysqli_stmt_bind_param($statement, 's', $villageNumber);

    mysqli_stmt_execute($statement);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    return (bool)$exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'add' || $postAction === 'edit') {
        $villageId = (int)($_POST['village_id'] ?? 0);
        $villageNumber = trim((string)($_POST['village_number'] ?? ''));
        $villageName = trim((string)($_POST['villagename'] ?? ''));
        $subdistrict = trim((string)($_POST['subdistrict'] ?? ''));
        $district = trim((string)($_POST['district'] ?? ''));
        $province = trim((string)($_POST['province'] ?? ''));
        $zipcode = trim((string)($_POST['zipcode'] ?? ''));

        if ($villageNumber === '' || $villageName === '' || $subdistrict === '' || $district === '' || $province === '' || $zipcode === '') {
            $message = 'กรุณากรอกข้อมูลหมู่บ้านให้ครบทุกช่อง';
            $messageType = 'error';
        } elseif (villageNumberExists($conn, $villageNumber, $postAction === 'edit' ? $villageId : 0)) {
            $message = 'หมายเลขหมู่บ้านนี้มีอยู่แล้ว กรุณาตรวจสอบอีกครั้ง';
            $messageType = 'error';
        } else {
            if ($postAction === 'add') {
                $statement = mysqli_prepare($conn, 'INSERT INTO village (village_number, villagename, subdistrict, district, province, zipcode) VALUES (?, ?, ?, ?, ?, ?)');
                if ($statement) mysqli_stmt_bind_param($statement, 'ssssss', $villageNumber, $villageName, $subdistrict, $district, $province, $zipcode);
            } else {
                $statement = mysqli_prepare($conn, 'UPDATE village SET village_number=?, villagename=?, subdistrict=?, district=?, province=?, zipcode=? WHERE village_id=?');
                if ($statement) mysqli_stmt_bind_param($statement, 'ssssssi', $villageNumber, $villageName, $subdistrict, $district, $province, $zipcode, $villageId);
            }

            if (!$statement) {
                $message = 'ไม่สามารถเตรียมคำสั่งบันทึกข้อมูลได้: ' . mysqli_error($conn);
                $messageType = 'error';
            } elseif (mysqli_stmt_execute($statement)) {
                mysqli_stmt_close($statement);
                villageRedirectFull($postAction);
            } else {
                $message = 'บันทึกข้อมูลหมู่บ้านไม่สำเร็จ: ' . mysqli_stmt_error($statement);
                $messageType = 'error';
                mysqli_stmt_close($statement);
            }
        }
    }

    if ($postAction === 'delete') {
        $villageId = (int)($_POST['village_id'] ?? 0);
        if ($villageId > 0) {
            $patientCheck = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM patient WHERE Village_id=?');
            mysqli_stmt_bind_param($patientCheck, 'i', $villageId);
            mysqli_stmt_execute($patientCheck);
            $patientCount = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($patientCheck))['total'] ?? 0);
            mysqli_stmt_close($patientCheck);

            $caregiverCheck = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM users WHERE role='caregiver' AND responsible_village_id=?");
            mysqli_stmt_bind_param($caregiverCheck, 'i', $villageId);
            mysqli_stmt_execute($caregiverCheck);
            $caregiverCount = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($caregiverCheck))['total'] ?? 0);
            mysqli_stmt_close($caregiverCheck);

            if ($patientCount > 0 || $caregiverCount > 0) {
                $message = 'ไม่สามารถลบหมู่บ้านนี้ได้ เนื่องจากมีข้อมูลผู้สูงอายุหรือแคร์กิฟเวอร์เชื่อมโยงอยู่';
                $messageType = 'error';
            } else {
                $statement = mysqli_prepare($conn, 'DELETE FROM village WHERE village_id=?');
                if ($statement) {
                    mysqli_stmt_bind_param($statement, 'i', $villageId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                villageRedirectFull('delete');
            }
        }
    }
}

$success = (string)($_GET['success'] ?? '');
if ($success === 'add') { $message = 'เพิ่มข้อมูลหมู่บ้านเรียบร้อยแล้ว'; $messageType = 'success'; }
elseif ($success === 'edit') { $message = 'แก้ไขข้อมูลหมู่บ้านเรียบร้อยแล้ว'; $messageType = 'success'; }
elseif ($success === 'delete') { $message = 'ลบข้อมูลหมู่บ้านเรียบร้อยแล้ว'; $messageType = 'success'; }

$editData = null;
if (isset($_GET['edit'])) {
    $villageId = (int)$_GET['edit'];
    $statement = mysqli_prepare($conn, 'SELECT village_id, village_number, villagename, subdistrict, district, province, zipcode FROM village WHERE village_id=? LIMIT 1');
    if ($statement) {
        mysqli_stmt_bind_param($statement, 'i', $villageId);
        mysqli_stmt_execute($statement);
        $editData = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
    }
}

$showForm = isset($_GET['add']) || isset($_GET['edit']) || ($messageType === 'error' && ($postAction === 'add' || $postAction === 'edit'));

$searchKeyword = trim((string)($_GET['keyword'] ?? ''));
$villageSql = "SELECT v.village_id, v.village_number, v.villagename, v.subdistrict, v.district, v.province, v.zipcode,
    (SELECT COUNT(*) FROM patient p WHERE p.Village_id = v.village_id) AS elderly_total,
    (SELECT COUNT(*) FROM users u WHERE u.role='caregiver' AND u.responsible_village_id = v.village_id) AS caregiver_total
    FROM village v";

$villageStatement = null;
if ($searchKeyword !== '') {
    $villageSql .= " WHERE (v.village_number LIKE CONCAT('%', ?, '%')
                      OR v.villagename LIKE CONCAT('%', ?, '%')
                      OR v.subdistrict LIKE CONCAT('%', ?, '%')
                      OR v.district LIKE CONCAT('%', ?, '%')
                      OR v.province LIKE CONCAT('%', ?, '%')
                      OR v.zipcode LIKE CONCAT('%', ?, '%'))";
}
$villageSql .= " ORDER BY CAST(v.village_number AS UNSIGNED), v.village_id";

if ($searchKeyword !== '') {
    $villageStatement = mysqli_prepare($conn, $villageSql);
    if (!$villageStatement) die('ไม่สามารถเตรียมคำสั่งค้นหาข้อมูลหมู่บ้านได้: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($villageStatement, 'ssssss', $searchKeyword, $searchKeyword, $searchKeyword, $searchKeyword, $searchKeyword, $searchKeyword);
    mysqli_stmt_execute($villageStatement);
    $result = mysqli_stmt_get_result($villageStatement);
} else {
    $result = mysqli_query($conn, $villageSql);
}
if (!$result) die('ไม่สามารถดึงข้อมูลหมู่บ้านได้: ' . mysqli_error($conn));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ข้อมูลหมู่บ้าน | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
body.role-page .form-page-card{max-width:1180px;margin:36px auto 0;padding:30px 32px 28px}
body.role-page .admin-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 22px}
body.role-page .admin-form-grid .field{margin:0}
body.role-page .admin-form-grid .field input,body.role-page .admin-form-grid .field select{min-height:44px}
body.role-page .form-page-card .actions{margin-top:24px;padding-top:20px;border-top:1px solid #e2eeee}
body.role-page .form-page-card .section-toolbar{padding-bottom:16px;margin-bottom:22px;border-bottom:1px solid #e2eeee}
body.role-page .data-table{min-width:1180px}
body.role-page .data-table td{vertical-align:middle!important}
body.role-page .table-actions{display:flex;gap:7px;align-items:center;flex-wrap:nowrap;white-space:nowrap}
body.role-page .table-actions form{margin:0}
body.role-page .count{font-weight:800;color:#246C73}
body.role-page .section-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px}
body.role-page .section-toolbar h2{margin:0}
body.role-page .toolbar-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-left:auto;padding-right:6px}
body.role-page .toolbar-actions .btn{margin-right:2px}
body.role-page .list-search-bar{margin:8px 0 14px;width:100%}
body.role-page .list-search-form{display:flex;align-items:center;gap:10px;width:100%;margin:0}
body.role-page .list-search-form input{flex:1;min-width:0;width:100%;padding:12px 14px;border:1px solid #cfe0e2;border-radius:14px;background:#f7fbfb;color:#24484d;outline:none}
body.role-page .list-search-form input:focus{border-color:#7fc9c5;box-shadow:0 0 0 3px rgba(127,201,197,.18)}
@media(max-width:1180px){body.role-page .form-page-card{margin-top:24px}}
@media(max-width:760px){body.role-page .form-page-card{margin-top:18px;padding:22px 18px}body.role-page .admin-form-grid{grid-template-columns:1fr}body.role-page .section-toolbar{flex-direction:column;align-items:stretch}body.role-page .toolbar-actions{justify-content:flex-start;padding-right:0}body.role-page .list-search-form{flex-wrap:wrap}body.role-page .list-search-form input{flex:1 1 100%}}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<div class="main">
    <?php renderUserTopbar(); ?>

    <?php if($message!==''): ?>
        <div class="alert <?= $messageType === 'success' ? 'alert-ok' : 'alert-error' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if($showForm): ?>
    <section class="card form-page-card">
        <div class="section-toolbar">
            <h2><?= $editData ? 'แก้ไขข้อมูลหมู่บ้าน' : 'เพิ่มข้อมูลหมู่บ้าน' ?></h2>
            <div class="toolbar-actions">
                <a class="btn btn-secondary" href="village.php">กลับหน้าหลัก</a>
            </div>
        </div>
        <form method="post" action="village.php<?= $editData ? '?edit='.(int)$editData['village_id'] : '?add=1' ?>">
            <input type="hidden" name="action" value="<?= $editData ? 'edit' : 'add' ?>">
            <?php if($editData): ?><input type="hidden" name="village_id" value="<?= (int)$editData['village_id'] ?>"><?php endif; ?>
            <div class="admin-form-grid">
                <div class="field"><label>หมู่ที่ <span class="required-star" style="color:#d93025!important">*</span></label><input name="village_number" required value="<?= e($editData['village_number'] ?? $_POST['village_number'] ?? '') ?>" placeholder="ตัวอย่าง 9"></div>
                <div class="field"><label>ชื่อหมู่บ้าน <span class="required-star" style="color:#d93025!important">*</span></label><input name="villagename" required value="<?= e($editData['villagename'] ?? $_POST['villagename'] ?? '') ?>" placeholder="กรอกชื่อหมู่บ้าน"></div>
                <div class="field"><label>ตำบล <span class="required-star" style="color:#d93025!important">*</span></label><input name="subdistrict" required value="<?= e($editData['subdistrict'] ?? $_POST['subdistrict'] ?? 'ทองหลาง') ?>" placeholder="กรอกชื่อตำบล"></div>
                <div class="field"><label>อำเภอ <span class="required-star" style="color:#d93025!important">*</span></label><input name="district" required value="<?= e($editData['district'] ?? $_POST['district'] ?? 'จักราช') ?>" placeholder="กรอกชื่ออำเภอ"></div>
                <div class="field"><label>จังหวัด <span class="required-star" style="color:#d93025!important">*</span></label><input name="province" required value="<?= e($editData['province'] ?? $_POST['province'] ?? 'นครราชสีมา') ?>" placeholder="กรอกชื่อจังหวัด"></div>
                <div class="field"><label>รหัสไปรษณีย์ <span class="required-star" style="color:#d93025!important">*</span></label><input name="zipcode" required maxlength="10" value="<?= e($editData['zipcode'] ?? $_POST['zipcode'] ?? '') ?>" placeholder="กรอกรหัสไปรษณีย์"></div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit"><?= $editData ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
                <a class="btn btn-secondary" href="village.php">ยกเลิก</a>
            </div>
        </form>
    </section>
    <?php else: ?>

    <div class="list-search-bar">
        <form class="list-search-form" method="get" action="village.php">
            <input type="text" name="keyword" value="<?= e($searchKeyword) ?>" placeholder="ค้นหา">
            <button class="btn btn-secondary" type="submit">ค้นหา</button>
            <?php if ($searchKeyword !== ''): ?>
                <a class="btn btn-secondary" href="village.php">ล้างการค้นหา</a>
            <?php endif; ?>
        </form>
    </div>

    <section class="card">
        <div class="section-toolbar">
            <h2>รายการหมู่บ้าน</h2>
            <div class="toolbar-actions">
                <a class="btn btn-primary" href="village.php?add=1">เพิ่มข้อมูล</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>หมู่ที่</th>
                        <th>ชื่อหมู่บ้าน</th>
                        <th>ตำบล</th>
                        <th>อำเภอ</th>
                        <th>จังหวัด</th>
                        <th>รหัสไปรษณีย์</th>
                        <th>จำนวนผู้สูงอายุ</th>
                        <th>จำนวนแคร์กิฟเวอร์</th>
                        <th>การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php $number=1; while($row=mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= $number++ ?></td>
                        <td><?= e($row['village_number']) ?></td>
                        <td><?= e($row['villagename']) ?></td>
                        <td><?= e($row['subdistrict']) ?></td>
                        <td><?= e($row['district']) ?></td>
                        <td><?= e($row['province']) ?></td>
                        <td><?= e($row['zipcode']) ?></td>
                        <td><span class="count"><?= (int)$row['elderly_total'] ?> คน</span></td>
                        <td><span class="count"><?= (int)$row['caregiver_total'] ?> คน</span></td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-secondary" href="village.php?edit=<?= (int)$row['village_id'] ?>">แก้ไข</a>
                                <form method="post" onsubmit="return confirm('ยืนยันการลบข้อมูลหมู่บ้านนี้หรือไม่?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="village_id" value="<?= (int)$row['village_id'] ?>">
                                    <button class="btn btn-danger" type="submit">ลบ</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if($number===1): ?><tr><td colspan="10" class="empty">ยังไม่มีข้อมูลหมู่บ้าน</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
</body>
</html>

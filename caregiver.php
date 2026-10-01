<?php
require_once __DIR__ . '/connect.php';
requireRole('admin');
ensureThonglangCoreSchema($conn);
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$messageType = '';
$postAction = '';

function splitCaregiverDisplayName(string $displayName): array
{
    $displayName = trim($displayName);
    if ($displayName === '') return ['firstname' => '', 'lastname' => ''];
    $parts = preg_split('/\s+/u', $displayName, 2);
    return ['firstname' => $parts[0] ?? '', 'lastname' => $parts[1] ?? ''];
}

function caregiverRedirect(string $success): void
{
    header('Location: caregiver.php?success=' . urlencode($success));
    exit;
}

function caregiverUsernameExists(mysqli $connection, string $username, int $excludeUserId = 0): bool
{
    $sql = 'SELECT user_id FROM users WHERE username = ?';
    if ($excludeUserId > 0) $sql .= ' AND user_id <> ?';
    $sql .= ' LIMIT 1';

    $statement = mysqli_prepare($connection, $sql);
    if (!$statement) return false;

    if ($excludeUserId > 0) mysqli_stmt_bind_param($statement, 'si', $username, $excludeUserId);
    else mysqli_stmt_bind_param($statement, 's', $username);

    mysqli_stmt_execute($statement);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    return (bool)$exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'add' || $postAction === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $firstname = trim((string)($_POST['firstname'] ?? ''));
        $lastname = trim((string)($_POST['lastname'] ?? ''));
        $displayName = trim($firstname . ' ' . $lastname);
        $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
        $responsibleVillageId = (int)($_POST['responsible_village_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $createdDate = trim((string)($_POST['created_date'] ?? date('Y-m-d')));

        if ($firstname === '' || $lastname === '' || $phoneNumber === '' || $responsibleVillageId <= 0 || $username === '' || $createdDate === '') {
            $message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบทุกช่อง';
            $messageType = 'error';
        } elseif ($postAction === 'add' && $password === '') {
            $message = 'กรุณากำหนดรหัสผ่านสำหรับเข้าสู่ระบบ';
            $messageType = 'error';
        } elseif ($password !== '' && strlen($password) < 4) {
            $message = 'รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร';
            $messageType = 'error';
        } elseif (isRemovedUsername($username)) {
            $message = 'ชื่อผู้ใช้งานนี้ถูกยกเลิกแล้ว กรุณาใช้ชื่ออื่น';
            $messageType = 'error';
        } elseif (caregiverUsernameExists($conn, $username, $postAction === 'edit' ? $userId : 0)) {
            $message = 'ชื่อผู้ใช้งานสำหรับเข้าสู่ระบบนี้มีอยู่แล้ว กรุณาใช้ชื่ออื่น';
            $messageType = 'error';
        } else {
            $statement = null;
            if ($postAction === 'add') {
                $role = 'caregiver';
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $isActive = 1;
                $statement = mysqli_prepare(
                    $conn,
                    'INSERT INTO users (username, password_hash, role, display_name, phone_number, responsible_village_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($statement) {
                    mysqli_stmt_bind_param($statement, 'sssssiis', $username, $passwordHash, $role, $displayName, $phoneNumber, $responsibleVillageId, $isActive, $createdDate);
                }
            } else {
                if ($userId <= 0) {
                    $message = 'ไม่พบข้อมูลผู้ดูแลผู้สูงอายุที่ต้องการแก้ไข';
                    $messageType = 'error';
                } elseif ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE users SET username=?, password_hash=?, display_name=?, phone_number=?, responsible_village_id=?, created_at=? WHERE user_id=? AND role='caregiver'"
                    );
                    if ($statement) {
                        mysqli_stmt_bind_param($statement, 'ssssisi', $username, $passwordHash, $displayName, $phoneNumber, $responsibleVillageId, $createdDate, $userId);
                    }
                } else {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE users SET username=?, display_name=?, phone_number=?, responsible_village_id=?, created_at=? WHERE user_id=? AND role='caregiver'"
                    );
                    if ($statement) {
                        mysqli_stmt_bind_param($statement, 'sssisi', $username, $displayName, $phoneNumber, $responsibleVillageId, $createdDate, $userId);
                    }
                }
            }

            if ($messageType !== 'error') {
                if (!$statement) {
                    $message = 'ไม่สามารถเตรียมคำสั่งบันทึกข้อมูลได้: ' . mysqli_error($conn);
                    $messageType = 'error';
                } elseif (mysqli_stmt_execute($statement)) {
                    mysqli_stmt_close($statement);
                    caregiverRedirect($postAction);
                } else {
                    $message = 'บันทึกข้อมูลไม่สำเร็จ: ' . mysqli_stmt_error($statement);
                    $messageType = 'error';
                    mysqli_stmt_close($statement);
                }
            }
        }
    }

    if ($postAction === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $statement = mysqli_prepare($conn, "DELETE FROM users WHERE user_id=? AND role='caregiver'");
            if ($statement) {
                mysqli_stmt_bind_param($statement, 'i', $userId);
                if (!mysqli_stmt_execute($statement)) {
                    $message = 'ไม่สามารถลบข้อมูลผู้ดูแลผู้สูงอายุได้ เนื่องจากอาจมีข้อมูลการมอบหมายที่เชื่อมโยงอยู่';
                    $messageType = 'error';
                }
                mysqli_stmt_close($statement);
            }
        }
        if ($messageType !== 'error') caregiverRedirect('delete');
    }
}

$success = (string)($_GET['success'] ?? '');
if ($success === 'add') { $message = 'เพิ่มข้อมูลผู้ดูแลผู้สูงอายุเรียบร้อยแล้ว'; $messageType = 'success'; }
elseif ($success === 'edit') { $message = 'แก้ไขข้อมูลผู้ดูแลผู้สูงอายุเรียบร้อยแล้ว'; $messageType = 'success'; }
elseif ($success === 'delete') { $message = 'ลบข้อมูลผู้ดูแลผู้สูงอายุเรียบร้อยแล้ว'; $messageType = 'success'; }

$villages = [];
$villageResult = mysqli_query($conn, 'SELECT village_id, village_number, villagename FROM village ORDER BY CAST(village_number AS UNSIGNED), village_id');
if ($villageResult) {
    while ($village = mysqli_fetch_assoc($villageResult)) $villages[] = $village;
}

$editData = null;
if (isset($_GET['edit'])) {
    $userId = (int)$_GET['edit'];
    $statement = mysqli_prepare($conn, "SELECT user_id, username, display_name, phone_number, responsible_village_id, created_at FROM users WHERE user_id=? AND role='caregiver' LIMIT 1");
    if ($statement) {
        mysqli_stmt_bind_param($statement, 'i', $userId);
        mysqli_stmt_execute($statement);
        $editData = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
    }

    if ($editData) {
        $nameParts = splitCaregiverDisplayName((string)$editData['display_name']);
        $editData['firstname'] = $nameParts['firstname'];
        $editData['lastname'] = $nameParts['lastname'];
        $editData['created_date'] = !empty($editData['created_at']) ? date('Y-m-d', strtotime((string)$editData['created_at'])) : date('Y-m-d');
    }
}

$showForm = isset($_GET['add']) || isset($_GET['edit']) || ($messageType === 'error' && ($postAction === 'add' || $postAction === 'edit'));

$searchKeyword = trim((string)($_GET['keyword'] ?? ''));
$caregiverSql = "SELECT u.user_id, u.username, u.display_name, u.phone_number, u.created_at, v.village_number, v.villagename
     FROM users u
     LEFT JOIN village v ON u.responsible_village_id = v.village_id
     WHERE u.role='caregiver'";

$caregiverStatement = null;
if ($searchKeyword !== '') {
    $caregiverSql .= " AND (u.display_name LIKE CONCAT('%', ?, '%')
                      OR u.phone_number LIKE CONCAT('%', ?, '%')
                      OR u.username LIKE CONCAT('%', ?, '%')
                      OR v.villagename LIKE CONCAT('%', ?, '%')
                      OR CONCAT('หมู่ที่ ', v.village_number) LIKE CONCAT('%', ?, '%'))";
}
$caregiverSql .= " ORDER BY u.display_name, u.user_id";

if ($searchKeyword !== '') {
    $caregiverStatement = mysqli_prepare($conn, $caregiverSql);
    if (!$caregiverStatement) die('ไม่สามารถเตรียมคำสั่งค้นหาข้อมูลผู้ดูแลได้: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($caregiverStatement, 'sssss', $searchKeyword, $searchKeyword, $searchKeyword, $searchKeyword, $searchKeyword);
    mysqli_stmt_execute($caregiverStatement);
    $caregiverResult = mysqli_stmt_get_result($caregiverStatement);
} else {
    $caregiverResult = mysqli_query($conn, $caregiverSql);
}
if (!$caregiverResult) die('ไม่สามารถดึงข้อมูลผู้ดูแลได้: ' . mysqli_error($conn));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ข้อมูลแคร์กิฟเวอร์ | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
body.role-page .form-page-card{max-width:1180px;margin:36px auto 0;padding:30px 32px 28px}
body.role-page .admin-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 22px}
body.role-page .admin-form-grid .field{margin:0}
body.role-page .admin-form-grid .field input,body.role-page .admin-form-grid .field select{min-height:44px}
body.role-page .admin-form-grid .field:last-child{grid-column:1 / 2}
body.role-page .form-page-card .actions{margin-top:24px;padding-top:20px;border-top:1px solid #e2eeee}
body.role-page .form-page-card .section-toolbar{padding-bottom:16px;margin-bottom:22px;border-bottom:1px solid #e2eeee}
body.role-page .note{font-size:12px;color:#728B8E;margin-top:6px;line-height:1.45}
body.role-page .data-table{min-width:980px}
body.role-page .data-table td{vertical-align:middle!important}
body.role-page .table-actions{display:flex;gap:7px;align-items:center;flex-wrap:nowrap;white-space:nowrap}
body.role-page .table-actions form{margin:0}
body.role-page .section-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px}
body.role-page .section-toolbar h2{margin:0}
body.role-page .toolbar-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-left:auto;padding-right:6px}
body.role-page .toolbar-actions .btn{margin-right:2px}
body.role-page .list-search-bar{margin:8px 0 14px;width:100%}
body.role-page .list-search-form{display:flex;align-items:center;gap:10px;width:100%;margin:0}
body.role-page .list-search-form input{flex:1;min-width:0;width:100%;padding:12px 14px;border:1px solid #cfe0e2;border-radius:14px;background:#f7fbfb;color:#24484d;outline:none}
body.role-page .list-search-form input:focus{border-color:#7fc9c5;box-shadow:0 0 0 3px rgba(127,201,197,.18)}
body.role-page .plain-text-badge{font-weight:700;color:#183B38}
body.role-page .centered-dash{text-align:center;color:#6f8588}
@media(max-width:1180px){body.role-page .form-page-card{margin-top:24px}}
@media(max-width:760px){body.role-page .form-page-card{margin-top:18px;padding:22px 18px}body.role-page .admin-form-grid{grid-template-columns:1fr}body.role-page .admin-form-grid .field:last-child{grid-column:auto}body.role-page .section-toolbar{flex-direction:column;align-items:stretch}body.role-page .toolbar-actions{justify-content:flex-start;padding-right:0}body.role-page .list-search-form{flex-wrap:wrap}body.role-page .list-search-form input{flex:1 1 100%}}
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
            <h2><?= $editData ? 'แก้ไขข้อมูลแคร์กิฟเวอร์' : 'เพิ่มข้อมูลแคร์กิฟเวอร์' ?></h2>
            <div class="toolbar-actions">
                <a class="btn btn-secondary" href="caregiver.php">กลับหน้าหลัก</a>
            </div>
        </div>
        <form method="post" action="caregiver.php<?= $editData ? '?edit='.(int)$editData['user_id'] : '?add=1' ?>">
            <input type="hidden" name="action" value="<?= $editData ? 'edit' : 'add' ?>">
            <?php if ($editData): ?><input type="hidden" name="user_id" value="<?= (int)$editData['user_id'] ?>"><?php endif; ?>

            <div class="admin-form-grid">
                <div class="field">
                    <label>ชื่อ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input name="firstname" required value="<?= e($editData['firstname'] ?? $_POST['firstname'] ?? '') ?>" placeholder="กรอกชื่อ">
                </div>
                <div class="field">
                    <label>นามสกุล <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input name="lastname" required value="<?= e($editData['lastname'] ?? $_POST['lastname'] ?? '') ?>" placeholder="กรอกนามสกุล">
                </div>
                <div class="field">
                    <label>หมายเลขโทรศัพท์ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input name="phone_number" required value="<?= e($editData['phone_number'] ?? $_POST['phone_number'] ?? '') ?>" placeholder="ตัวอย่าง 0812345678">
                </div>
                <div class="field">
                    <label>หมู่บ้านหรือพื้นที่รับผิดชอบ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <select name="responsible_village_id" required>
                        <option value="">-- เลือกหมู่บ้านหรือพื้นที่รับผิดชอบ --</option>
                        <?php $selectedVillage=(int)($editData['responsible_village_id'] ?? $_POST['responsible_village_id'] ?? 0); foreach($villages as $village): ?>
                            <option value="<?= (int)$village['village_id'] ?>" <?= $selectedVillage===(int)$village['village_id']?'selected':'' ?>><?= e(($village['village_number'] ? 'หมู่ที่ '.$village['village_number'].' ' : '').$village['villagename']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>ชื่อผู้ใช้งานสำหรับเข้าสู่ระบบ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input name="username" required autocomplete="off" value="<?= e($editData['username'] ?? $_POST['username'] ?? '') ?>" placeholder="ตัวอย่าง Caregiver01">
                </div>
                <div class="field">
                    <label><?= $editData ? 'รหัสผ่านใหม่สำหรับเข้าสู่ระบบ' : 'รหัสผ่านสำหรับเข้าสู่ระบบ <span class="required-star" style="color:#d93025!important">*</span>' ?></label>
                    <input type="password" name="password" <?= $editData?'':'required' ?> minlength="4" autocomplete="new-password" placeholder="อย่างน้อย 4 ตัวอักษร">
                </div>
                <div class="field">
                    <label>วันที่เพิ่มข้อมูล <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input type="date" name="created_date" required value="<?= e($editData['created_date'] ?? $_POST['created_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit"><?= $editData ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
                <a class="btn btn-secondary" href="caregiver.php">ยกเลิก</a>
            </div>
        </form>
    </section>
    <?php else: ?>

    <div class="list-search-bar">
        <form class="list-search-form" method="get" action="caregiver.php">
            <input type="text" name="keyword" value="<?= e($searchKeyword) ?>" placeholder="ค้นหา">
            <button class="btn btn-secondary" type="submit">ค้นหา</button>
            <?php if ($searchKeyword !== ''): ?>
                <a class="btn btn-secondary" href="caregiver.php">ล้างการค้นหา</a>
            <?php endif; ?>
        </form>
    </div>

    <section class="card">
        <div class="section-toolbar">
            <h2>ข้อมูลแคร์กิฟเวอร์</h2>
            <div class="toolbar-actions">
                <a class="btn btn-primary" href="caregiver.php?add=1">เพิ่มข้อมูลแคร์กิฟเวอร์</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อ</th>
                        <th>นามสกุล</th>
                        <th>หมายเลขโทรศัพท์</th>
                        <th>หมู่บ้านหรือพื้นที่รับผิดชอบ</th>
                        <th>ชื่อผู้ใช้งาน</th>
                        <th>วันที่เพิ่มข้อมูล</th>
                        <th>การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php $number=1; while($row=mysqli_fetch_assoc($caregiverResult)): $parts=splitCaregiverDisplayName((string)$row['display_name']); ?>
                    <tr>
                        <td><?= $number++ ?></td>
                        <td><strong><?= e($parts['firstname']) ?></strong></td>
                        <td><?= e($parts['lastname']) ?></td>
                        <td><?= e($row['phone_number']) ?></td>
                        <td><?= e(($row['village_number'] ? 'หมู่ที่ '.$row['village_number'].' ' : '').($row['villagename'] ?? '')) ?></td>
                        <td><?= e($row['username']) ?></td>
                        <td><?= !empty($row['created_at'])?e(date('d/m/Y',strtotime((string)$row['created_at']))):'-' ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-secondary" href="caregiver.php?edit=<?= (int)$row['user_id'] ?>">แก้ไข</a>
                                <form method="post" onsubmit="return confirm('ยืนยันการลบข้อมูลผู้ดูแลผู้สูงอายุรายนี้หรือไม่?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                                    <button class="btn btn-danger" type="submit">ลบ</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if($number===1): ?><tr><td colspan="8" class="empty">ยังไม่มีข้อมูลผู้ดูแลผู้สูงอายุ</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
</body>
</html>

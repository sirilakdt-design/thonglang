<?php
require_once __DIR__ . '/connect.php';
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = trim((string)($_POST['role'] ?? 'caregiver'));

    if ($username === '' || $displayName === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบ';
    } elseif (in_array(strtolower(trim($username)), ['deputy director','g1','g2','g3','g4','d1','gee'], true)) {
        $error = 'ชื่อผู้ใช้งานนี้ถูกยกเลิกแล้ว กรุณาใช้ชื่ออื่น';
    } elseif (strlen($password) < 4) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร';
    } elseif (!in_array($role, ['admin', 'doctor', 'caregiver'], true)) {
        $error = 'ประเภทผู้ใช้งานไม่ถูกต้อง';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE username = ? LIMIT 1');
        if (!$stmt) {
            $error = 'ไม่สามารถตรวจสอบชื่อผู้ใช้งานได้: ' . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($exists) {
                $error = 'ชื่อผู้ใช้งานนี้มีอยู่แล้ว';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO users (username, password_hash, role, display_name, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())'
                );

                if (!$stmt) {
                    $error = 'ไม่สามารถเตรียมคำสั่งสร้างบัญชีได้: ' . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($stmt, 'ssss', $username, $passwordHash, $role, $displayName);
                    if (mysqli_stmt_execute($stmt)) {
                        $message = 'สร้างบัญชีสำเร็จ';
                    } else {
                        $error = 'สร้างบัญชีไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>สร้างบัญชี | <?= e(appName()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Noto Sans Thai",sans-serif;background:linear-gradient(180deg,#F9FDFD 0%,#EEF9F7 100%);color:#26484C;display:grid;place-items:center;padding:24px}.box{width:min(520px,100%);background:#fff;border:1px solid #D6ECEA;border-radius:22px;padding:28px;box-shadow:0 14px 34px rgba(50,118,121,.10)}h2{margin:0 0 8px;font-size:25px;color:#246C73}.subtitle{margin:0 0 22px;color:#728B8E;font-size:13px}.field{margin-bottom:15px}.field label{display:block;font-weight:700;margin-bottom:7px}.field input,.field select{width:100%;padding:12px 13px;border:1px solid #CFE3E0;border-radius:11px;background:#fff;font:inherit;color:#26484C}.field input:focus,.field select:focus{outline:none;border-color:#58BFC0;box-shadow:0 0 0 3px rgba(88,191,192,.14)}button{width:100%;border:0;border-radius:11px;padding:12px 16px;background:#58BFC0;color:#fff;font:inherit;font-weight:800;cursor:pointer}.notice{padding:12px 14px;border-radius:11px;margin-bottom:16px;font-weight:700}.success{background:#EAF8F1;border:1px solid #CDEAD9;color:#287054}.error{background:#FFF1F1;border:1px solid #F0CCCC;color:#A13E3E}
</style>
</head>
<body>
<div class="box">
    <h2>สร้างผู้ใช้งานระบบ</h2>
    <p class="subtitle">ใช้ชื่อผู้ใช้งานสำหรับเข้าสู่ระบบแทนรหัสประจำตัวแยก</p>

    <?php if ($message !== ''): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post">
        <div class="field"><label>ชื่อผู้ใช้งาน</label><input type="text" name="username" required placeholder="ตัวอย่าง User01"></div>
        <div class="field"><label>ชื่อ-นามสกุล</label><input type="text" name="display_name" required></div>
        <div class="field"><label>รหัสผ่าน</label><input type="password" name="password" minlength="4" required></div>
        <div class="field"><label>ประเภทผู้ใช้งาน</label><select name="role"><option value="admin">ผู้ดูแลระบบ</option><option value="doctor">หมอ</option><option value="caregiver">แคร์กิฟเวอร์</option></select></div>
        <button type="submit">สร้างบัญชี</button>
    </form>
</div>
</body>
</html>

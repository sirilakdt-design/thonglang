<?php

session_start();

require_once __DIR__ . "/connect.php";

if (isset($_SESSION["user_id"])) {
    header("Location: home.php");
    exit;
}

$error = "";

/*
 * อ่านโครงสร้างตาราง users จริง เพื่อให้หน้า Login
 * รองรับชื่อคอลัมน์รหัสผ่านเดิม เช่น password, passwd,
 * user_password, userpass, pass หรือ pwd
 */
function getUsersColumns(mysqli $conn): array
{
    $columns = [];

    $result = mysqli_query($conn, "SHOW COLUMNS FROM `users`");

    if (!$result) {
        return $columns;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $field = (string)($row["Field"] ?? "");
        if ($field !== "") {
            $columns[$field] = strtolower(
                preg_replace('/[^a-z0-9]/i', '', $field)
            );
        }
    }

    mysqli_free_result($result);

    return $columns;
}

function findColumn(array $columns, array $preferredNames, array $contains = []): ?string
{
    /* ตรงชื่อก่อน */
    foreach ($preferredNames as $preferred) {
        $normalizedPreferred = strtolower(
            preg_replace('/[^a-z0-9]/i', '', $preferred)
        );

        foreach ($columns as $actual => $normalized) {
            if ($normalized === $normalizedPreferred) {
                return $actual;
            }
        }
    }

    /* ถ้าไม่ตรง ให้ค้นจากคำที่ชื่อคอลัมน์มีอยู่ */
    foreach ($contains as $needle) {
        $needle = strtolower($needle);

        foreach ($columns as $actual => $normalized) {
            if (str_contains($normalized, $needle)) {
                return $actual;
            }
        }
    }

    return null;
}

$usersColumns = getUsersColumns($conn);

if (!$usersColumns) {
    $error = "ไม่พบตาราง users หรือไม่สามารถอ่านโครงสร้างตาราง users ได้";
}

$usernameColumn = findColumn(
    $usersColumns,
    ["username", "user_name", "user"],
    ["username"]
);

$passwordColumn = findColumn(
    $usersColumns,
    [
        "password",
        "user_password",
        "userpassword",
        "passwd",
        "pass",
        "pwd",
        "password_hash",
        "passwordhash"
    ],
    ["password", "passwd", "pwd", "pass"]
);

$idColumn = findColumn(
    $usersColumns,
    ["user_id", "userid", "id"],
    ["userid"]
);

$fullnameColumn = findColumn(
    $usersColumns,
    ["display_name", "displayname", "fullname", "full_name", "name"],
    ["displayname", "fullname"]
);

$roleColumn = findColumn(
    $usersColumns,
    ["role", "user_role", "userrole"],
    ["role"]
);


if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";

    } elseif ($usernameColumn === null) {

        $error = "ตาราง users ไม่มีคอลัมน์ชื่อผู้ใช้ที่ระบบรู้จัก";

    } elseif ($passwordColumn === null) {

        $error = "ตาราง users ไม่มีคอลัมน์รหัสผ่าน กรุณาตรวจสอบโครงสร้างฐานข้อมูล";

    } else {

        /*
         * SELECT * เพื่อไม่อ้างคอลัมน์ที่ไม่มีจริง
         * แล้วดึงค่าตามชื่อคอลัมน์ที่ตรวจพบด้านบน
         */
        $sql = "SELECT * FROM `users` WHERE `" .
               str_replace("`", "``", $usernameColumn) .
               "` = ? LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            $error = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง Login: " . mysqli_error($conn);
        } else {

            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);

            $login_ok = false;

            if ($user) {

                $storedPassword = (string)($user[$passwordColumn] ?? "");

                /*
                 * รองรับทั้ง password_hash() และรหัสผ่านแบบข้อความเดิม
                 */
                if (
                    $storedPassword !== "" &&
                    password_verify($password, $storedPassword)
                ) {
                    $login_ok = true;

                } elseif (
                    $storedPassword !== "" &&
                    hash_equals($storedPassword, $password)
                ) {
                    $login_ok = true;

                    /*
                     * ถ้าเป็น plain text จะอัปเกรดเป็น hash
                     * เฉพาะเมื่อมีคอลัมน์ ID สำหรับระบุแถวได้แน่นอน
                     */
                    if ($idColumn !== null && isset($user[$idColumn])) {

                        $newHash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $updateSql =
                            "UPDATE `users` SET `" .
                            str_replace("`", "``", $passwordColumn) .
                            "` = ? WHERE `" .
                            str_replace("`", "``", $idColumn) .
                            "` = ?";

                        $updateStmt = mysqli_prepare(
                            $conn,
                            $updateSql
                        );

                        if ($updateStmt) {
                            $idValue = (int)$user[$idColumn];

                            mysqli_stmt_bind_param(
                                $updateStmt,
                                "si",
                                $newHash,
                                $idValue
                            );

                            mysqli_stmt_execute($updateStmt);
                            mysqli_stmt_close($updateStmt);
                        }
                    }
                }
            }

            // Block the removed accounts even if database cleanup has not yet run.
            if ($login_ok && (in_array(strtolower(trim((string)($user[$usernameColumn] ?? ''))), ['deputy director','g1','g2','g3','g4','d1','gee'], true) ||
                ($roleColumn !== null && strtolower(trim((string)($user[$roleColumn] ?? ''))) === 'deputy_director'))) {
                $login_ok = false;
            }

            if ($login_ok) {

                session_regenerate_id(true);

                $_SESSION["user_id"] =
                    $idColumn !== null && isset($user[$idColumn])
                    ? (int)$user[$idColumn]
                    : 1;

                $_SESSION["username"] =
                    (string)($user[$usernameColumn] ?? $username);

                $_SESSION["fullname"] =
                    $fullnameColumn !== null
                    ? (string)($user[$fullnameColumn] ?? "")
                    : "";

                $_SESSION["role"] =
                    $roleColumn !== null
                    ? strtolower(trim((string)($user[$roleColumn] ?? "")))
                    : "";

                header("Location: home.php");
                exit;

            } else {

                $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(appDocumentTitle('เข้าสู่ระบบ')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--deep:#246C73;--deep2:#1D5960;--teal:#58BFC0;--aqua:#83D5D1;--mint:#DFF5F0;--pale:#EFFAF7;--sky:#E8F4FA;--bg:#F7FBFC;--line:#D6E9E9;--text:#26484C;--muted:#748B8E}
body{min-height:100vh;font-family:"Noto Sans Thai",sans-serif;color:var(--text);display:flex;align-items:center;justify-content:center;padding:28px;background:radial-gradient(circle at 18% 12%,rgba(143,219,214,.34),transparent 28%),radial-gradient(circle at 88% 82%,rgba(180,218,238,.28),transparent 31%),linear-gradient(135deg,#E4F7F4 0%,#FBFEFF 48%,#DDEFF6 100%)}
.login-stage{width:100%;min-height:calc(100vh - 56px);display:flex;align-items:center;justify-content:center;position:relative;border:0;border-radius:28px;background:linear-gradient(rgba(236,250,248,.72),rgba(229,244,249,.74));overflow:hidden;box-shadow:inset 0 0 0 1px rgba(255,255,255,.68)}
.login-stage:before,.login-stage:after{content:"";position:absolute;border-radius:50%;filter:blur(2px)}
.login-stage:before{width:360px;height:360px;left:-130px;bottom:-150px;background:rgba(98,205,196,.22)}
.login-stage:after{width:300px;height:300px;right:-100px;top:-110px;background:rgba(8,127,120,.14)}
.login-card{position:relative;z-index:2;width:min(460px,calc(100% - 30px));min-height:auto;display:block;background:rgba(255,255,255,.97);border:1px solid rgba(213,233,230,.95);border-radius:22px;overflow:hidden;box-shadow:0 22px 55px rgba(7,92,86,.18)}
.brand-side{display:none}
.form-side{background:#fff;padding:34px 38px 38px;display:flex;flex-direction:column;justify-content:center}
.mobile-logo{display:flex;width:88px;height:88px;border-radius:50%;background:#fff;border:2px solid #D3EBE9;align-items:center;justify-content:center;margin:0 auto 18px;box-shadow:0 8px 22px rgba(55,128,131,.10)}
.mobile-logo img{width:74px;height:74px;object-fit:contain;border-radius:50%}
.form-top{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:8px}
.form-top h1{font-size:27px;font-weight:700;line-height:1.2;color:var(--deep)}
.form-intro{font-size:12px;color:var(--muted);line-height:1.6;margin:0 0 22px;text-align:center}
.error{display:flex;gap:8px;align-items:flex-start;padding:10px 12px;margin-bottom:16px;background:#FFF1F1;border:1px solid #F0CCCC;border-radius:10px;color:#A13E3E;font-size:12px;line-height:1.5}
.form-group{margin-bottom:16px}
label{display:block;margin-bottom:7px;font-size:12px;font-weight:600;color:#466B6E}
.input-wrap{position:relative}
.input-wrap input{width:100%;height:48px;border:1px solid var(--line);border-radius:10px;background:#FFFFFF;padding:0 54px 0 13px;font:inherit;font-size:13px;color:var(--text);outline:none;transition:.18s ease}
.input-wrap input:focus{border-color:var(--aqua);box-shadow:0 0 0 3px rgba(32,175,166,.13);background:#fff}
.input-wrap input::placeholder{color:#9AA9A7}
.toggle-password{position:absolute;right:12px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--deep);font:inherit;font-size:11px;font-weight:600;cursor:pointer;padding:4px}
.login-btn{width:100%;height:48px;margin-top:5px;border:1px solid var(--deep);border-radius:10px;background:linear-gradient(135deg,#7AD2CC 0%,#55B7BD 100%);color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 9px 20px rgba(70,156,159,.15);transition:.18s ease}
.login-btn:hover{background:linear-gradient(135deg,#67C6C1 0%,#479FA7 100%);transform:translateY(-1px)}
.role-note{display:none}
@media(max-width:520px){body{padding:10px}.login-stage{min-height:calc(100vh - 20px);border-radius:18px}.form-side{padding:30px 22px 32px}.login-card{width:min(440px,calc(100% - 12px));border-radius:18px}.form-top h1{font-size:24px}}
</style>
</head>
<body>
<div class="login-stage">
    <div class="login-card">
        <section class="brand-side">
            <div class="brand-content">
                <div class="brand-logo">
                    <img src="assets/logo.jpg" alt="<?= e(appName()) ?> Logo">
                </div>
                <div class="brand-title"><?= e(appName()) ?></div>
                <div class="brand-subtitle">ระบบบันทึกและจัดการข้อมูลสุขภาพผู้สูงอายุ<br><?= e(appName()) ?></div>
            </div>
        </section>

        <main class="form-side">
            <div class="mobile-logo">
                <img src="assets/logo.jpg" alt="<?= e(appName()) ?> Logo">
            </div>
            <div class="form-top">
                <h1>เข้าสู่ระบบ</h1>
            </div>
            <div class="form-intro">กรอกชื่อผู้ใช้และรหัสผ่านเพื่อเข้าใช้งานระบบ</div>

            <?php if ($error): ?>
                <div class="error"><span>!</span><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="username">ชื่อผู้ใช้</label>
                    <div class="input-wrap">
                        <input id="username" type="text" name="username" value="<?= htmlspecialchars($_POST["username"] ?? "") ?>" placeholder="กรอกชื่อผู้ใช้" autocomplete="off" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">รหัสผ่าน</label>
                    <div class="input-wrap">
                        <input id="password" type="password" name="password" placeholder="กรอกรหัสผ่าน" autocomplete="off" required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="แสดงหรือซ่อนรหัสผ่าน">แสดง</button>
                    </div>
                </div>

                <button type="submit" class="login-btn">เข้าสู่ระบบ</button>
            </form>
        </main>
    </div>
</div>
<script>
const passwordInput=document.getElementById('password');
const togglePassword=document.getElementById('togglePassword');
if(passwordInput&&togglePassword){
    togglePassword.addEventListener('click',function(){
        const hidden=passwordInput.type==='password';
        passwordInput.type=hidden?'text':'password';
        togglePassword.textContent=hidden?'ซ่อน':'แสดง';
    });
}
</script>
</body>
</html>

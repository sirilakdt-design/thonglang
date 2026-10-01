<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbUsername = 'root';
$dbPassword = '';
$database = 'thonglang2';

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database);
if (!$conn) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ : ' . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

function appBaseUrl(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $leaf = strtolower((string)basename($dir));

    if (in_array($leaf, ['admin', 'caregiver', 'doctor', 'adl', 'director'], true)) {
        $dir = rtrim(str_replace('\\', '/', dirname($dir)), '/');
    }

    return $dir === '/' ? '' : $dir;
}

function appUrl(string $path = ''): string
{
    $base = appBaseUrl();
    $path = ltrim($path, '/');
    return ($base !== '' ? $base : '') . '/' . $path;
}

function currentRole(): string
{
    return strtolower(trim((string)($_SESSION['role'] ?? '')));
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0
        && currentRole() !== 'deputy_director'
        && !in_array(strtolower(trim((string)($_SESSION['username'] ?? ''))), ['deputy director','g1','g2','g3','g4','d1','gee'], true);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . appUrl('index.php'));
        exit;
    }
}

function isRemovedUsername(string $username): bool
{
    return in_array(strtolower(trim($username)), ['deputy director','g1','g2','g3','g4','d1','gee'], true);
}

function isAdmin(): bool { return currentRole() === 'admin'; }
function isDoctor(): bool { return currentRole() === 'doctor'; }
function isCaregiver(): bool { return currentRole() === 'caregiver'; }
function isDirector(): bool { return currentRole() === 'director'; }
function isExecutive(): bool { return currentRole() === 'director'; }

function requireRole(string ...$roles): void
{
    requireLogin();
    $allowed = array_map(static fn($role) => strtolower(trim((string)$role)), $roles);
    if (!in_array(currentRole(), $allowed, true)) {
        http_response_code(403);
        echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ไม่มีสิทธิ์เข้าถึง</title><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
html,body,body *,button,input,select,textarea,table,th,td,label,a,span,div,p,h1,h2,h3,h4,h5,h6,small,strong,b{font-family:"Noto Sans Thai",sans-serif!important}
body{font-family:"Noto Sans Thai",sans-serif;background:#F6FBFA;color:#183B38;display:grid;place-items:center;min-height:100vh;margin:0}.box{background:#fff;padding:32px;border:1px solid #D5E9E6;border-radius:15px;box-shadow:0 10px 35px rgba(0,0,0,.08);text-align:center;max-width:520px}.box a{display:inline-block;margin-top:12px;padding:10px 18px;border-radius:10px;background:#61C2C6;color:#fff;text-decoration:none}.user-card-chevron{display:none!important}
</style></head><body><div class="box"><h1>ไม่มีสิทธิ์เข้าถึงหน้านี้</h1><p>บัญชีที่กำลังเข้าสู่ระบบไม่มีสิทธิ์ใช้งานหน้านี้</p><a href="' . e(appUrl('home.php')) . '">กลับหน้าแรก</a></div></body></html>';
        exit;
    }
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ensureSystemSettingsTable(mysqli $conn): void
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function systemSetting(mysqli $conn, string $key, string $default = ''): string
{
    ensureSystemSettingsTable($conn);
    $stmt = mysqli_prepare($conn, 'SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
    if (!$stmt) return $default;
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? (string)($row['setting_value'] ?? $default) : $default;
}

function saveSystemSetting(mysqli $conn, string $key, string $value): bool
{
    ensureSystemSettingsTable($conn);
    $stmt = mysqli_prepare($conn, 'INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function appName(): string
{
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $name = trim(systemSetting($conn, 'org_name', 'ทองหลาง'));
        // Remove accidental leading combining marks / duplicate ทื shown beside ทองหลาง.
        $name = preg_replace('/^[\p{Mn}\x{200B}\s]+/u', '', $name) ?? $name;
        $name = preg_replace('/^ทื(?=ทองหลาง)/u', '', $name) ?? $name;
        if ($name !== '') return $name;
    }
    return 'ทองหลาง';
}

function appDocumentTitle(string $pageTitle = ''): string
{
    $name = appName();
    $pageTitle = trim($pageTitle);
    return $pageTitle === '' ? $name : ($pageTitle . ' | ' . $name);
}

function directorName(): string
{
    global $conn;
    $fallback = 'นาย รัศมี แก้วเนตร';
    if (isset($conn) && $conn instanceof mysqli) {
        $setting = trim(systemSetting($conn, 'director_name', $fallback));
        if ($setting !== '') return $setting;
        $result = @mysqli_query($conn, "SELECT display_name FROM users WHERE role='director' ORDER BY user_id ASC LIMIT 1");
        if ($result && ($row = mysqli_fetch_assoc($result))) {
            $dbName = trim((string)($row['display_name'] ?? ''));
            mysqli_free_result($result);
            if ($dbName !== '') return $dbName;
        }
    }
    return $fallback;
}

function diseaseListFromText(?string $value): array
{
    $value = trim((string)$value);
    if ($value === '') return [];
    $parts = preg_split('/\s*(?:,|\||;|\r?\n)\s*/u', $value) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '' && !in_array($part, $out, true)) $out[] = $part;
    }
    return $out;
}

function diseaseTextFromPost(string $field = 'Disease_items', string $legacyField = 'Disease'): string
{
    $items = $_POST[$field] ?? null;
    if (is_array($items)) {
        $clean = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $clean, true)) $clean[] = $item;
        }
        return implode(', ', $clean);
    }
    return trim((string)($_POST[$legacyField] ?? ''));
}

function renderSidebar(): void
{
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $role = currentRole();

    $menus = [
        'admin' => [
            ['home.php', 'หน้าแรก'],
            ['caregiver.php', 'จัดการข้อมูลแคร์กิฟเวอร์'],
            ['doctor.php', 'จัดการข้อมูลหมอ'],
            ['executive_users.php', 'จัดการข้อมูลผู้อำนวยการ'],
            ['village.php', 'จัดการข้อมูลหมู่บ้าน'],
            ['statistics.php', 'ออกรายงาน'],
            ['settings.php', 'ตั้งค่าระบบ'],
        ],
        'doctor' => [
            ['home.php', 'หน้าแรก'],
            ['patient.php', 'ผู้สูงอายุ'],
            ['assign_patient.php', 'มอบหมายผู้สูงอายุให้แคร์กิฟเวอร์ดูแล'],
            ['adl.php', 'การประเมิน ADL'],
            ['doctor_visit_summary.php', 'สรุปการเข้าเยี่ยม'],
        ],
        'caregiver' => [
            ['home.php', 'หน้าแรก'],
            ['caregiver/caregiver_patients.php', 'ผู้สูงอายุที่อยู่ในความดูแล'],
            ['caregiver/caregiver_visit.php', 'บันทึกการเข้าเยี่ยม'],
            ['caregiver/caregiver_adl.php', 'แบบประเมิน'],
            ['caregiver/caregiver_adl_summary.php', 'สรุปผลการประเมิน'],
        ],
        'director' => [
            ['director/executive.php', 'หน้าแรก', '▦'],
            ['director/director_visits.php', 'ตรวจสอบรายงาน', '▣'],
            ['director/director_assessments.php', 'ลงนามอนุมัติ', '✎'],
            ['director/director_signature_history.php', 'ประวัติการลงนาม', '↶'],
            ['director/director_reports.php', 'รายงานสรุป', '▥'],
            ['director/director_patients.php', 'ข้อมูลผู้สูงอายุ', '♧'],
        ],
    ];

    $menuItems = $menus[$role] ?? [['home.php', 'หน้าแรก']];
    ?>
    <div class="sidebar">
        <div class="brand">
            <div class="logo"><img src="<?= e(appUrl('logo.jpg')) ?>" alt="<?= e(appName()) ?> Logo"></div>
            <h2><?= e(appName()) ?></h2>
        </div>
        <ul class="menu">
            <?php foreach ($menuItems as $item): ?>
                <?php $href = $item[0]; $label = $item[1]; $icon = $item[2] ?? ''; $activeFile = basename(parse_url($href, PHP_URL_PATH) ?: $href); ?>
                <li><a href="<?= e(appUrl($href)) ?>" class="<?= $currentPage === $activeFile ? 'active' : '' ?>"><?php if ($icon !== ''): ?><span class="menu-icon" aria-hidden="true"><?= e($icon) ?></span><?php endif; ?><span class="menu-text"><?= e($label) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function renderUserTopbar(): void
{
    global $conn;

    $username = trim((string)($_SESSION['username'] ?? ''));
    $fullname = trim((string)($_SESSION['fullname'] ?? ''));
    $role = trim((string)($_SESSION['role'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);
$userAvatarPath = trim((string)($_SESSION['user_photo'] ?? $_SESSION['photo'] ?? $_SESSION['profile_photo'] ?? $_SESSION['avatar'] ?? $_SESSION['avatar_path'] ?? ''));
    $userAvatarUrl = '';

    if ($userId > 0 && isset($conn) && $conn instanceof mysqli) {
        $avatarColumn = '';
        $avatarCandidates = ['photo', 'profile_photo', 'avatar', 'avatar_path', 'image', 'image_path', 'user_photo'];
        $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM users");
        if ($columnResult) {
            $availableColumns = [];
            while ($columnRow = mysqli_fetch_assoc($columnResult)) {
                $availableColumns[] = strtolower((string)($columnRow['Field'] ?? ''));
            }
            mysqli_free_result($columnResult);
            foreach ($avatarCandidates as $candidate) {
                if (in_array(strtolower($candidate), $availableColumns, true)) {
                    $avatarColumn = $candidate;
                    break;
                }
            }
        }

        $selectFields = 'display_name, username, role';
        if ($avatarColumn !== '') {
            $selectFields .= ', ' . $avatarColumn . ' AS user_avatar';
        }

        $nameStmt = mysqli_prepare($conn, "SELECT {$selectFields} FROM users WHERE user_id = ? LIMIT 1");
        if ($nameStmt) {
            mysqli_stmt_bind_param($nameStmt, 'i', $userId);
            mysqli_stmt_execute($nameStmt);
            $currentUser = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
            mysqli_stmt_close($nameStmt);
            if ($currentUser) {
                $databaseName = trim((string)($currentUser['display_name'] ?? ''));
                if ($databaseName !== '') { $fullname = $databaseName; $_SESSION['fullname'] = $databaseName; }
                if (!empty($currentUser['username'])) { $username = trim((string)$currentUser['username']); $_SESSION['username'] = $username; }
                if (!empty($currentUser['role'])) { $role = trim((string)$currentUser['role']); $_SESSION['role'] = $role; }
                if ($avatarColumn !== '') {
                    $avatarValue = trim((string)($currentUser['user_avatar'] ?? ''));
                    if ($avatarValue !== '') {
                        $userAvatarPath = $avatarValue;
                        $_SESSION['user_photo'] = $avatarValue;
                    }
                }
            }
        }
    }

    // The director name is controlled centrally so it is consistent on every page.
    if ($role === 'director') {
        $fullname = directorName();
        $_SESSION['fullname'] = $fullname;
    }

    if ($fullname === '') $fullname = $username !== '' ? $username : 'ผู้ใช้งาน';

    $roleText = 'ผู้ใช้งาน';
    if ($role === 'admin') $roleText = 'ผู้ดูแลระบบ';
    elseif ($role === 'caregiver') $roleText = 'ผู้ดูแลผู้สูงอายุ';
    elseif ($role === 'doctor') $roleText = 'หมอ';
    elseif ($role === 'director') $roleText = 'ผู้อำนวยการ';

    $loginIdentity = $username !== '' ? strtoupper($username) : '-';
    $primaryText = $fullname;
    $secondaryText = '';
    if ($userAvatarPath !== '') {
    if (function_exists('thonglangUploadedImageUrl')) {
        $userAvatarUrl = thonglangUploadedImageUrl($userAvatarPath);
    }
    if ($userAvatarUrl === '') {
        $userAvatarUrl = $userAvatarPath;
    }
}

if ($primaryText === '' || trim($primaryText) === trim($roleText)) {
        $primaryText = $roleText;
        $secondaryText = '';
    }

    ?>
    <div class="user-topbar">
        <div class="user-profile-menu">
            <button type="button" class="user-profile-button" onclick="toggleUserMenu(event)">
                <div class="user-card-shell">
                    <div class="user-avatar-circle">
                    <?php if ($userAvatarUrl !== ''): ?>
                        <img src="<?= e($userAvatarUrl) ?>" alt="รูปผู้ใช้งาน" class="user-avatar-photo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="user-avatar-icon"><path d="M12 12c2.76 0 5-2.46 5-5.5S14.76 1 12 1 7 3.46 7 6.5 9.24 12 12 12Zm0 2c-4.42 0-8 2.91-8 6.5 0 .83.67 1.5 1.5 1.5h13c.83 0 1.5-.67 1.5-1.5C20 16.91 16.42 14 12 14Z" fill="currentColor"/></svg>
                    <?php endif; ?>
                </div>
                    <div class="user-info">
                                                <div class="user-primary-text"><?= e($primaryText) ?></div>
                        
                    </div>
                    
                </div>
            </button>
            <div class="user-dropdown" id="userDropdown">
                <div class="dropdown-header-box">
                    <div class="dropdown-profile-head">
                        <div class="user-avatar-circle dropdown-avatar">
                        <?php if ($userAvatarUrl !== ''): ?>
                            <img src="<?= e($userAvatarUrl) ?>" alt="รูปผู้ใช้งาน" class="user-avatar-photo">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="user-avatar-icon"><path d="M12 12c2.76 0 5-2.46 5-5.5S14.76 1 12 1 7 3.46 7 6.5 9.24 12 12 12Zm0 2c-4.42 0-8 2.91-8 6.5 0 .83.67 1.5 1.5 1.5h13c.83 0 1.5-.67 1.5-1.5C20 16.91 16.42 14 12 14Z" fill="currentColor"/></svg>
                        <?php endif; ?>
                    </div>
                        <div class="dropdown-profile-text">
                                                        <div class="dropdown-user-name"><?= e($primaryText) ?></div>
                                                    </div>
                    </div>
                </div>
                <div class="dropdown-identity-list">
                    <div class="identity-row"><span>ชื่อผู้ใช้งาน</span><strong><?= e($loginIdentity) ?></strong></div>
                    <div class="identity-row"><span>ชื่อที่แสดง</span><strong><?= e($fullname) ?></strong></div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="<?= e(appUrl('index.php?logout=1')) ?>" class="logout-link">ออกจากระบบ</a>
            </div>
        </div>
    </div>
    <style>
    .user-topbar{width:100%;min-height:54px;display:flex;align-items:center;justify-content:flex-end;padding:8px 4px 6px;background:transparent}
    .user-profile-menu{position:relative}
    .user-profile-button{min-width:190px;display:block;padding:0;background:transparent!important;border:0!important;box-shadow:none!important;cursor:pointer;font-family:"Noto Sans Thai",sans-serif;color:#183B38!important;text-align:left}
    .user-card-shell{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:15px;border:1px solid #D8ECEA;background:linear-gradient(135deg,rgba(255,255,255,.98) 0%,rgba(239,250,247,.96) 100%);box-shadow:0 8px 22px rgba(50,118,121,.09);transition:.18s ease}
    .user-profile-button:hover .user-card-shell{transform:translateY(-1px);border-color:#B7DFDB;box-shadow:0 11px 26px rgba(50,118,121,.13)}
    .user-avatar-circle{width:34px;height:34px;flex:0 0 34px;border-radius:11px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:linear-gradient(135deg,#7DD3CF 0%,#58BFC0 100%);color:#fff!important;box-shadow:0 6px 14px rgba(88,191,192,.24)}.user-avatar-icon{width:17px;height:17px;display:block;color:#fff}.user-avatar-photo{width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit}
    .user-info{min-width:0;flex:1;text-align:left}
        .user-primary-text{margin-top:0;font-size:15px;font-weight:800;color:#1F5660!important;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .user-secondary-text{margin-top:5px;font-size:12px;color:#4E6C70!important;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .user-card-chevron{width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:#F2FBFA;border:1px solid #D7ECE9;position:relative}
    .user-card-chevron:before{content:'';position:absolute;top:50%;left:50%;width:8px;height:8px;border-right:2px solid #5EAEB0;border-bottom:2px solid #5EAEB0;transform:translate(-60%,-60%) rotate(45deg)}
    .user-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;width:250px;padding:10px;background:#fff!important;border:1px solid #D5E9E6!important;border-radius:15px!important;box-shadow:0 16px 32px rgba(12,86,81,.14)!important;z-index:2000}
    .user-dropdown.show{display:block}
    .dropdown-header-box{border:1px solid #D8ECEA;border-radius:13px;padding:10px;background:linear-gradient(135deg,#EDF9F7 0%,#F5FBFF 100%)}
    .dropdown-profile-head{display:flex;align-items:center;gap:9px}
    .dropdown-avatar{width:34px;height:34px;flex-basis:34px;border-radius:11px}.dropdown-avatar .user-avatar-icon{width:17px;height:17px}.dropdown-avatar .user-avatar-photo{border-radius:inherit}
    .dropdown-profile-text{min-width:0;flex:1}
    .dropdown-title{font-size:11px;font-weight:700;color:#7D9296!important;margin-bottom:4px}
    .dropdown-user-name{font-size:13px;font-weight:800;color:#183B38!important;line-height:1.25;word-break:break-word}
    .dropdown-role-badge{display:inline-flex;align-items:center;padding:0;border:0;border-radius:0;background:transparent;color:#087F78!important;font-size:11px;font-weight:700;margin-top:5px}
    .dropdown-identity-list{display:grid;gap:8px;margin-top:14px}
    .identity-row{display:flex;align-items:center;justify-content:space-between;gap:9px;padding:8px 10px;border:1px solid #D5E9E6;border-radius:10px;background:#fff}
    .identity-row span{font-size:11px;color:#6B7F7D!important;font-weight:700}
    .identity-row strong{font-size:12px;color:#183B38!important;text-align:right;line-height:1.4;word-break:break-word}
    .dropdown-divider{height:1px;background:#E2EEEC;margin:10px 0}
    .user-dropdown a{display:block;padding:12px;border-radius:10px;color:#A13E3E!important;text-decoration:none;font-size:13px;font-weight:700;text-align:center;border:1px solid #F0CCCC;background:#FFF1F1}
    .user-dropdown a:hover{background:#FFE8E8!important}
    .logout-link{color:#A13E3E!important}
    .crud-icon-button,.print-icon-button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:54px!important;height:54px!important;min-width:54px!important;padding:0!important;border-radius:14px!important;line-height:1!important;text-decoration:none!important;vertical-align:middle!important;box-shadow:none!important;overflow:hidden}
    .crud-icon-button svg{width:27px!important;height:27px!important;display:block;pointer-events:none}
    .print-icon-button svg{width:38px!important;height:38px!important;display:block;pointer-events:none}
    .crud-icon-edit{background:#ffffff!important;border:1.5px solid #cfdcda!important;color:#171b1b!important}
    .crud-icon-edit:hover{background:#f7fbfb!important;border-color:#bfd0ce!important}
    .crud-icon-delete{background:#fff1f1!important;border:1.5px solid #ebd0d0!important;color:#cf5a5a!important}
    .crud-icon-delete:hover{background:#ffe7e7!important;border-color:#dfbebe!important}
    .print-icon-button{background:#ffffff!important;border:1.5px solid #d5e0e3!important;color:#1f2b53!important}
    .print-icon-button:hover{background:#f7fbfd!important;border-color:#c6d4da!important}
    @media(max-width:700px){.user-topbar{justify-content:stretch;padding-top:8px}.user-profile-menu{width:100%}.user-profile-button{width:100%;min-width:0}.user-card-shell{padding:7px 9px;border-radius:14px}.user-primary-text{font-size:15px}.user-dropdown{width:100%}}
    td.dash-only-center,th.dash-only-center{text-align:center!important;color:#7d8d89!important;vertical-align:middle!important}.dash-only-center:not(td):not(th){display:block;width:100%;text-align:center!important;color:#7d8d89!important}
    </style>
    <script>
    function toggleUserMenu(event){event.stopPropagation();var menu=document.getElementById('userDropdown');if(menu){menu.classList.toggle('show')}}
    document.addEventListener('click',function(){var menu=document.getElementById('userDropdown');if(menu){menu.classList.remove('show')}});
    (function convertActionButtonsToIcons(){
        function normalize(text){
            return (text||'').replace(/\s+/g,' ').trim();
        }
        function printerSvg(){
            return '<svg viewBox="0 0 64 64" aria-hidden="true">'
                + '<rect x="18" y="7" width="28" height="18" rx="3" fill="#f7fbff" stroke="#1f2b53" stroke-width="4"/>'
                + '<path d="M21 18h22" stroke="#dcecff" stroke-width="4" stroke-linecap="round"/>'
                + '<rect x="9" y="22" width="46" height="25" rx="7" fill="#2f4a78" stroke="#1f2b53" stroke-width="4"/>'
                + '<circle cx="19" cy="33" r="4.8" fill="#ffffff" stroke="#1f2b53" stroke-width="3"/>'
                + '<circle cx="45" cy="33" r="4.8" fill="#ffffff" stroke="#1f2b53" stroke-width="3"/>'
                + '<circle cx="28" cy="30" r="2" fill="#dff5ff"/>'
                + '<circle cx="36" cy="30" r="1.8" fill="#0f1730"/>'
                + '<circle cx="41" cy="30" r="1.8" fill="#0f1730"/>'
                + '<rect x="18" y="39" width="28" height="16" rx="2.5" fill="#54b8e8" stroke="#1f2b53" stroke-width="4"/>'
                + '<path d="M22 45h20M22 49h16" stroke="#ecfbff" stroke-width="3" stroke-linecap="round"/>'
                + '</svg>';
        }
        function apply(){
            document.querySelectorAll('a,button').forEach(function(el){
                if(el.dataset && el.dataset.keepTextButton==='1') return;
                if(el.dataset && el.dataset.iconized==='1') return;
                var rawLabel = (el.dataset && el.dataset.label) ? el.dataset.label : ((el.getAttribute('aria-label') || el.textContent || ''));
                var label = normalize(rawLabel);
                var href = (el.getAttribute('href') || '').toLowerCase();
                var onclick = (el.getAttribute('onclick') || '').toLowerCase();
                var isEdit=(label==='แก้ไข' || label==='แก้ไขข้อมูล');
                var isDelete=(label==='ลบ' || label==='ลบข้อมูล');
                var isPrint=(label==='พิมพ์' || label==='พิมพ์รายงาน' || label==='พิมพ์ออกรายงาน' || href.indexOf('_print.php')!==-1 || href.indexOf('statistics_print.php')!==-1 || href.indexOf('print=1')!==-1 || onclick.indexOf('window.print')!==-1);
                if(!isEdit && !isDelete && !isPrint) return;
                el.dataset.iconized='1';
                if(isEdit || isDelete){
                    el.classList.add('crud-icon-button', isEdit ? 'crud-icon-edit' : 'crud-icon-delete');
                    el.setAttribute('aria-label', label || (isEdit ? 'แก้ไข' : 'ลบ'));
                    el.setAttribute('title', label || (isEdit ? 'แก้ไข' : 'ลบ'));
                    el.innerHTML = isEdit
                      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4.2L19.4 8.8a2 2 0 0 0 0-2.8L18 4.6a2 2 0 0 0-2.8 0L4 15.8V20Z" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/><path d="m13.8 6 4.2 4.2M4 20l4.6-1" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>'
                      : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8v10m5-10v10m5-10v10M5 5h14M9 5V3h6v2m-9 0 1 16h10l1-16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    return;
                }
                el.classList.add('print-icon-button');
                el.setAttribute('aria-label', label || 'พิมพ์รายงาน');
                el.setAttribute('title', label || 'พิมพ์รายงาน');
                el.innerHTML = printerSvg();
            });
        }
        if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',apply); else apply();
    })();
    (function centerStandaloneDashValues(){
        function applyDashAlignment(){
            document.querySelectorAll('td,th,span,strong,div,p').forEach(function(el){
                if(el.children.length) return;
                var txt=(el.textContent||'').replace(/\s+/g,' ').trim();
                if(txt==='-') el.classList.add('dash-only-center');
            });
        }
        if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',applyDashAlignment); else applyDashAlignment();
    })();
    </script>
    <?php
}


/* =========================================================
   Shared Pastel Theme (merged into connect.php)
========================================================= */
function renderPastelTheme(): void
{
    ?>
    <style>
/* ===== Role-page base styles (scoped) ===== */
:root{--green:#70CBC7;--green-dark:#3F9EA5;--green-soft:#E1F5F1;--green-pale:#EFFAF7;--yellow:#EEF7FB;--yellow-soft:#F5FAFC;--bg:#F7FBFC;--card:#FFFFFF;--text:#26484C;--muted:#748B8E;--line:#D6ECEA;--danger:#A95D5D;--shadow:0 8px 24px rgba(61,125,128,.07)}
body.role-page *{box-sizing:border-box}
body.role-page{margin:0;min-height:100vh;font-family:"Noto Sans Thai",sans-serif;background:var(--bg);color:#183B38;font-size:13px}
body.role-page .sidebar{position:fixed;inset:0 auto 0 0;width:260px;height:100vh;padding:24px 14px;background:#20AFA6;color:#183B38;overflow-y:auto;z-index:1000;border-right:1px solid #c6d9bc;box-shadow:2px 0 18px rgba(63,84,50,.05)}
body.role-page .brand{text-align:center;margin-bottom:28px}body.role-page .logo{width:82px;height:82px;margin:0 auto 10px;border-radius:50%;background:#fff;display:grid;place-items:center;overflow:hidden;border:1px solid #dce8d4}body.role-page .logo img{width:74px;height:74px;object-fit:contain}body.role-page .brand h2{margin:0;font-size:23px;color:#183B38}body.role-page .brand small{display:block;margin-top:5px;color:#183B38;font-size:11px}body.role-page .menu{list-style:none;padding:0;margin:0}body.role-page .menu li{margin:8px 0}body.role-page .menu a{display:flex;align-items:center;min-height:50px;padding:10px 13px;border:1px solid #d9e7d2;border-radius:14px;color:#183B38;text-decoration:none;font-size:13px;font-weight:700;line-height:1.35;background:rgba(255,255,255,.48);transition:.18s ease}body.role-page .menu a:hover, body.role-page .menu a.active{background:#F3F7F7;color:#183B38;border-color:#eadb8e;transform:none}body.role-page .menu-text{display:block}body.role-page .menu-icon{display:none!important}
body.role-page .main{margin-left:260px;min-height:100vh;padding:0 28px 40px}body.role-page .page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:12px 0 22px;padding:24px 28px;border:2px solid #20AFA6;border-radius:30px;background:#DDF5F1;box-shadow:0 4px 12px rgba(0,204,0,.08)}body.role-page .page-head h1{margin:0;color:#183B38;font-size:28px}body.role-page .page-head p{margin:6px 0 0;color:#183B38}
body.role-page .card{background:var(--card);border:1px solid #D5E9E6;border-radius:15px;padding:22px;margin-bottom:20px;box-shadow:var(--shadow)}body.role-page .card h2{margin:0 0 17px;color:#183B38;font-size:20px}body.role-page .section-title{margin:22px 0 10px;padding:10px 14px;border-radius:10px;background:#DDF5F1;border-left:4px solid #20AFA6;color:#183B38;font-size:13px;font-weight:700}body.role-page .section-title.yellow{background:#F3F7F7;border-left-color:#d9be59}
body.role-page .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}body.role-page .grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}body.role-page .field label{display:block;margin-bottom:7px;font-weight:700;color:#183B38}body.role-page .field input, body.role-page .field select, body.role-page .field textarea{width:100%;padding:8px 10px;border:1px solid #cfdaca;border-radius:10px;background:#fff;color:#183B38;font:inherit}body.role-page .field textarea{min-height:94px;resize:vertical}body.role-page .field input:focus, body.role-page .field select:focus, body.role-page .field textarea:focus{outline:none;border-color:#20AFA6;box-shadow:0 0 0 3px rgba(151,184,130,.16)}body.role-page .field input[readonly]{background:#f7f8f2;color:#183B38}
body.role-page .actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:18px}body.role-page .btn{display:inline-block;border:1px solid transparent;border-radius:10px;padding:10px 15px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}body.role-page .btn-primary{background:#20AFA6;color:#183B38;border-color:#20AFA6}body.role-page .btn-primary:hover{background:#087F78}body.role-page .btn-secondary{background:#F3F7F7;color:#183B38;border-color:#e2d18b}body.role-page .btn-danger{background:#f6dddd;color:#183B38;border-color:#ecc4c4}body.role-page .btn-warning{background:#F3F7F7;color:#183B38;border-color:#e2c96f}
body.role-page .alert{padding:12px 14px;border-radius:11px;margin-bottom:18px;border:1px solid transparent}body.role-page .alert-ok{background:#EBF9F1;color:#183B38;border-color:#d6e8cb}body.role-page .alert-error{background:#fff0f0;color:#183B38;border-color:#f1d2d2}body.role-page .alert-info{background:#EDF9F7;color:#183B38;border-color:#CFE9E5}
body.role-page .table-wrap{overflow:auto;border:1px solid #e3e9df;border-radius:13px;background:#fff}body.role-page .table-wrap table{width:100%;border-collapse:collapse;min-width:760px;background:#fff}body.role-page .table-wrap th, body.role-page .table-wrap td{padding:12px 13px;border-bottom:1px solid #edf0e9;text-align:left;vertical-align:top;color:#183B38}body.role-page .table-wrap th{background:#EDF9F7;color:#183B38;white-space:nowrap}body.role-page .table-wrap tr:last-child td{border-bottom:0}body.role-page .muted{color:#183B38}body.role-page .badge{display:inline-flex;align-items:center;justify-content:center;padding:0;border-radius:0;background:transparent;color:#183B38;font-size:11px;font-weight:700;border:0;min-width:1em}body.role-page .empty{text-align:center;padding:30px;color:#183B38}
body.role-page .score-box{font-size:28px;font-weight:800;color:#183B38;padding:15px 18px;background:#EDF9F7;border:1px solid #dce9d4;border-radius:14px;margin-top:16px}body.role-page .adl-item{border:1px solid #e1e8dc;border-radius:14px;padding:16px;background:#fff}body.role-page .adl-item label{font-weight:700;display:block;margin-bottom:8px}
body.role-page .measurement-table{width:100%;border-collapse:separate;border-spacing:0;min-width:800px;border:1px solid #dfe7da;border-radius:14px;overflow:hidden}body.role-page .measurement-table th, body.role-page .measurement-table td{padding:10px 12px;border-right:1px solid #e8ece4;border-bottom:1px solid #e8ece4;vertical-align:middle}body.role-page .measurement-table th:last-child, body.role-page .measurement-table td:last-child{border-right:0}body.role-page .measurement-table tr:last-child td{border-bottom:0}body.role-page .measurement-table thead th{background:#DDF5F1;color:#183B38;text-align:center}body.role-page .measurement-table .group-row td{background:#F3F7F7;font-weight:700;color:#183B38}body.role-page .measurement-table .measure-no{width:58px;text-align:center}body.role-page .measurement-table .measure-unit{width:140px;text-align:center;color:#183B38}body.role-page .measurement-table .normal{width:180px;color:#183B38}body.role-page .measurement-table input, body.role-page .measurement-table select{width:100%;min-width:110px;padding:9px 10px;border:1px solid #cfdaca;border-radius:9px;background:#fff;font:inherit;color:#183B38}body.role-page .measurement-table input:focus{outline:none;border-color:#20AFA6;box-shadow:0 0 0 3px rgba(126,163,110,.18)}
body.role-page .patient-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:10px 0 18px}body.role-page .summary-item{padding:8px 10px;border:1px solid #e3e8df;border-radius:11px;background:#F8FBFB}body.role-page .summary-item span{display:block;color:#183B38;font-size:11px;margin-bottom:3px}body.role-page .summary-item strong{font-size:13px;color:#183B38;font-weight:700}body.role-page .summary-item.full{grid-column:1/-1}
body.role-page .radio-list{display:grid;gap:9px}body.role-page .radio-option{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #e0e7dc;border-radius:11px;background:#fff;cursor:pointer}body.role-page .radio-option:hover{background:#F2FAF9}body.role-page .radio-option input{margin-top:3px;accent-color:#20AFA6}body.role-page .radio-option .score{min-width:28px;font-weight:700;color:#183B38}body.role-page .adl-question{padding:18px;border:1px solid #e0e8dc;border-radius:15px;background:#fff;margin-bottom:13px}body.role-page .adl-question h3{font-size:13px;margin:0 0 12px;color:#183B38;line-height:1.5}body.role-page .adl-question .en{font-weight:400;color:#183B38;font-size:12px}
body.role-page .result-band{display:grid;grid-template-columns:1fr 2fr;gap:9px;margin-top:16px}body.role-page .result-score, body.role-page .result-group{padding:16px 18px;border-radius:14px;border:1px solid #dfe8d9}body.role-page .result-score{background:#DDF5F1}body.role-page .result-group{background:#F3F7F7}body.role-page .result-score small, body.role-page .result-group small{display:block;color:#183B38;margin-bottom:4px}body.role-page .result-score strong{font-size:30px;color:#183B38}body.role-page .result-group strong{font-size:13px;color:#183B38}
@media(max-width:1050px){body.role-page .patient-summary{grid-template-columns:repeat(2,minmax(0,1fr))}body.role-page .result-band{grid-template-columns:1fr}}
@media(max-width:900px){body.role-page .sidebar{position:relative;width:100%;height:auto}body.role-page .main{margin-left:0;padding:0 15px 30px}body.role-page .grid, body.role-page .grid-3{grid-template-columns:1fr}body.role-page .page-head{flex-direction:column}body.role-page .menu a{min-height:46px}body.role-page .patient-summary{grid-template-columns:1fr}body.role-page .summary-item.full{grid-column:auto}}


/* ===== Global pastel theme ===== */
/* ============================================================
   Thonglang Healthcare UI Theme — Luminous Aqua Mint
   Soft blue + green pastel palette, clean and premium.
   Loaded last to restyle the UI without changing PHP/DB logic.
   ============================================================ */
:root{
    --theme-deep:#246C73;
    --theme-deep-2:#1D5960;
    --theme-teal:#58BFC0;
    --theme-aqua:#83D5D1;
    --theme-sky:#DFF2FA;
    --theme-mint:#DFF5F0;
    --theme-mint-2:#EFFAF7;
    --theme-blue-2:#EEF7FB;
    --theme-bg:#F7FBFC;
    --theme-card:#FFFFFF;
    --theme-text:#26484C;
    --theme-muted:#728B8E;
    --theme-border:#D6ECEA;
    --theme-danger:#B96767;
    --theme-danger-soft:#FFF3F3;
    --theme-warning:#BD9143;
    --theme-warning-soft:#FFF9EC;
    --theme-shadow:0 14px 34px rgba(50,118,121,.10);
    --theme-shadow-soft:0 7px 20px rgba(50,118,121,.075);
}

html,body{color:var(--theme-text)}
body{
    background:
      radial-gradient(circle at 100% 0%,rgba(131,213,209,.20),transparent 27%),
      radial-gradient(circle at 0% 100%,rgba(181,221,240,.18),transparent 30%),
      linear-gradient(180deg,#FCFEFF 0%,#F3FAFA 100%)!important;
}

/* Sidebar — light aqua / mint instead of dark green */
.sidebar{
    background:
      linear-gradient(180deg,#CDEFEF 0%,#DCF6F1 50%,#E4F3FA 100%)!important;
    color:var(--theme-text)!important;
    border-right:1px solid rgba(82,170,171,.18)!important;
    box-shadow:7px 0 28px rgba(45,105,110,.08)!important;
}
.sidebar .brand h2,.sidebar .brand small,.sidebar .menu a,.sidebar .menu-text{color:var(--theme-deep)!important}
.sidebar .brand small{opacity:.82}
.sidebar .logo{
    background:rgba(255,255,255,.92)!important;
    border:1px solid rgba(105,191,191,.25)!important;
    box-shadow:0 10px 24px rgba(44,118,120,.11)!important;
}
.sidebar .menu a{
    background:rgba(255,255,255,.34)!important;
    border:1px solid rgba(255,255,255,.55)!important;
    color:var(--theme-deep)!important;
}
.sidebar .menu a:hover{
    background:rgba(255,255,255,.70)!important;
    border-color:#B9E4E2!important;
    color:var(--theme-deep-2)!important;
}
.sidebar .menu a.active{
    background:linear-gradient(135deg,#AEE2DF 0%,#C9EEE8 100%)!important;
    border-color:#A8DCD9!important;
    color:#1E5F65!important;
    box-shadow:0 6px 16px rgba(57,135,137,.10)!important;
}

/* Keep the clean no-icon layout already used by this project. */
.user-avatar,.user-arrow,.input-icon,.map-location-icon,
.menu-icon,.icon,.action-icon,.stat-icon,.card-icon{display:none!important}

/* Main shell / topbar */
.main{background:transparent!important}
.user-topbar,.header,.topbar{background:transparent!important}
.page-title,.page-head h1,h1,h2,h3,.title,.stat-value,.card-title{color:var(--theme-text)!important}
.page-subtitle,.muted,.stat-note,.helper,.help-text{color:var(--theme-muted)!important}

.user-profile-button{
    background:rgba(255,255,255,.88)!important;
    border:1px solid #D9ECEB!important;
    box-shadow:var(--theme-shadow-soft)!important;
    color:var(--theme-text)!important;
    backdrop-filter:blur(10px);
}
.user-profile-button:hover{background:#fff!important;border-color:#B8DEDE!important}
.user-login-label,.user-identity,.dropdown-user,.dropdown-identity-list,.user-name{color:var(--theme-muted)!important}
.user-role,.login-role{color:var(--theme-deep)!important}
.user-dropdown{
    background:#fff!important;
    border:1px solid var(--theme-border)!important;
    box-shadow:var(--theme-shadow)!important;
}
.user-dropdown a:hover{background:var(--theme-mint-2)!important;color:var(--theme-deep)!important}

/* Cards — airy white with pastel edge */
.card,.form-card,.table-card,.content-card,.panel,.box,.mini-card,.stat-card,.summary-box{
    background:rgba(255,255,255,.96)!important;
    border:1px solid var(--theme-border)!important;
    box-shadow:var(--theme-shadow-soft)!important;
}
.stat-card{border-left:4px solid #79CDC9!important}
.stat-card:nth-child(even){border-left-color:#9BCFE8!important}

/* Hero / page header */
.hero,.page-head{
    background:linear-gradient(135deg,#D9F4F0 0%,#E9F7F5 52%,#E8F4FA 100%)!important;
    color:var(--theme-text)!important;
    border:1px solid #C6E8E6!important;
    box-shadow:0 12px 28px rgba(74,146,148,.09)!important;
}
.hero *,.page-head *{color:var(--theme-text)!important}
.hero::before,.hero::after{opacity:.12!important}

/* Inputs */
input,select,textarea{
    color:var(--theme-text)!important;
    background:rgba(255,255,255,.96)!important;
    border-color:#D8E9EA!important;
}
input::placeholder,textarea::placeholder{color:#9AAEB0!important;opacity:1}
input:focus,select:focus,textarea:focus{
    border-color:#78C8C7!important;
    box-shadow:0 0 0 4px rgba(105,195,194,.14)!important;
    outline:none!important;
}
input[readonly],textarea[readonly]{background:#F6FBFB!important}

/* Buttons */
button,.btn,a.btn{box-shadow:none!important}
.btn-primary,.save-btn,.submit-btn,.login-btn,.btn-green,.primary,.map-action-search{
    background:linear-gradient(135deg,#75CFCA 0%,#55B8BD 100%)!important;
    color:#fff!important;
    border:1px solid #58B9BC!important;
    box-shadow:0 7px 15px rgba(68,157,159,.12)!important;
}
.btn-primary:hover,.save-btn:hover,.submit-btn:hover,.login-btn:hover,.btn-green:hover,.primary:hover{
    background:linear-gradient(135deg,#64C4C0 0%,#459FA7 100%)!important;
    color:#fff!important;
}
.btn-secondary,.cancel-btn,.btn-yellow,.secondary,.warning,
.map-action-open,.map-table-button,.badge,.role-box,.stat,.result-group{
    background:linear-gradient(135deg,#EFFAF7 0%,#EDF7FB 100%)!important;
    color:var(--theme-deep)!important;
    border-color:#CFE8E6!important;
}
.btn-danger,.delete-btn,.btn-red,.danger{
    background:var(--theme-danger-soft)!important;
    color:#A95757!important;
    border-color:#F0D3D3!important;
}
.logout-btn{background:#FFF4F4!important;color:#A75B5B!important;border:1px solid #F0D6D6!important}

/* Section headings / tables */
.section-title,
.measurement-table thead th,
th,.table thead th,.table th,.status.on,.result-score{
    background:linear-gradient(90deg,#E2F6F1 0%,#EAF6FA 100%)!important;
    color:var(--theme-deep)!important;
    border-color:#D3E9E8!important;
}
.section-title{border-left-color:#73C8C5!important}
.section-title.yellow,.measurement-table .group-row td{
    background:linear-gradient(90deg,#F0FAF7 0%,#F3F9FC 100%)!important;
    color:var(--theme-text)!important;
    border-color:#DDEDEB!important;
}
td{color:var(--theme-text)!important}
.table tbody tr:hover td,table tbody tr:hover td{background:#F4FBFA!important}
.table-wrap{border-color:var(--theme-border)!important}

/* Informational areas */
.login-brand,.map-location-card,.map-location-box,.location-box,.info-box,.alert-info,
.brand-note,.meta-chip,.summary-item,.score-box,.radio-option,.adl-question{
    background:linear-gradient(135deg,#F0FAF7 0%,#F1F8FB 100%)!important;
    color:var(--theme-text)!important;
    border-color:#DCECEB!important;
}
.radio-option:hover,.adl-choice:hover{background:#EAF8F5!important;border-color:#BEE3E0!important}
.alert-ok,.ok{background:#EFFAF4!important;color:#39745E!important;border-color:#D4ECDD!important}
.alert-error,.error,.err{background:var(--theme-danger-soft)!important;color:#A35454!important;border-color:#F0D4D4!important}

/* Dashboard/chart header bars — pastel, not heavy */
.card-header,.panel-header,.chart-header,.table-title-bar{
    background:linear-gradient(90deg,#9BDEDA 0%,#78C9CC 48%,#8BCFDB 100%)!important;
    color:#184F55!important;
}
.card-header *, .panel-header *, .chart-header *, .table-title-bar *{color:#184F55!important}

/* Modals */
.modal{background:rgba(45,79,82,.24)!important;backdrop-filter:blur(3px)}
.modal-box{border:1px solid #D9ECEB!important;box-shadow:0 20px 55px rgba(51,103,106,.15)!important}

::selection{background:#C6EEEA;color:#214F53}

@media(max-width:900px){
    .sidebar{background:linear-gradient(135deg,#CDEFEF 0%,#E4F4F8 100%)!important}
}

/* =========================================================
   Consistent spacing below user topbar on every role page
   ========================================================= */
body.role-page .user-topbar{
    margin-bottom:28px;
}

@media(max-width:900px){
    body.role-page .user-topbar{
        margin-bottom:20px;
    }
}

/* =========================================================
   Layout safety: keep page content from sliding under sidebar
   ========================================================= */
@media(min-width:901px){
    body.role-page .sidebar{
        width:260px!important;
        left:0!important;
        right:auto!important;
    }
    body.role-page .main{
        margin-left:260px!important;
        width:calc(100% - 260px)!important;
        max-width:calc(100% - 260px)!important;
        position:relative!important;
        overflow-x:hidden;
    }
}
@media(max-width:900px){
    body.role-page .sidebar{
        position:relative!important;
        width:100%!important;
        height:auto!important;
    }
    body.role-page .main{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
    }
}

    </style>
    <?php
}

function thonglangTableHasColumn(mysqli $connection, string $tableName, string $columnName): bool
{
    $safeTableName = str_replace('`', '``', $tableName);
    $safeColumnName = mysqli_real_escape_string($connection, $columnName);
    $result = mysqli_query($connection, "SHOW COLUMNS FROM `{$safeTableName}` LIKE '{$safeColumnName}'");
    if (!$result) return false;
    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function thonglangEnsureColumn(mysqli $connection, string $tableName, string $columnName, string $definition): void
{
    if (thonglangTableHasColumn($connection, $tableName, $columnName)) return;
    $safeTableName = str_replace('`', '``', $tableName);
    $safeColumnName = str_replace('`', '``', $columnName);
    $sql = "ALTER TABLE `{$safeTableName}` ADD COLUMN `{$safeColumnName}` {$definition}";
    if (!mysqli_query($connection, $sql)) {
        throw new RuntimeException('ไม่สามารถปรับโครงสร้างฐานข้อมูลได้ (' . $tableName . '.' . $columnName . '): ' . mysqli_error($connection));
    }
}

function ensureThonglangCoreSchema(mysqli $connection): void
{
    try {
        thonglangEnsureColumn($connection, 'users', 'phone_number', "VARCHAR(30) NULL AFTER `display_name`");
        thonglangEnsureColumn($connection, 'users', 'responsible_village_id', "INT NULL AFTER `phone_number`");
        thonglangEnsureColumn($connection, 'users', 'doctor_code', "VARCHAR(50) NULL AFTER `phone_number`");
        thonglangEnsureColumn($connection, 'users', 'professional_license_number', "VARCHAR(100) NULL AFTER `doctor_code`");
        thonglangEnsureColumn($connection, 'users', 'medical_position', "VARCHAR(150) NULL AFTER `professional_license_number`");
        thonglangEnsureColumn($connection, 'users', 'department', "VARCHAR(150) NULL AFTER `medical_position`");

        thonglangEnsureColumn($connection, 'patient', 'Firstname', "VARCHAR(120) NULL AFTER `Patient_id`");
        thonglangEnsureColumn($connection, 'patient', 'Lastname', "VARCHAR(120) NULL AFTER `Firstname`");
        thonglangEnsureColumn($connection, 'patient', 'Weight_kg', "DECIMAL(6,2) NULL AFTER `Age`");
        thonglangEnsureColumn($connection, 'patient', 'Height_cm', "DECIMAL(6,2) NULL AFTER `Weight_kg`");
        thonglangEnsureColumn($connection, 'patient', 'Latitude', "DECIMAL(10,7) NULL AFTER `Village_id`");
        thonglangEnsureColumn($connection, 'patient', 'Longitude', "DECIMAL(10,7) NULL AFTER `Latitude`");
        thonglangEnsureColumn($connection, 'patient', 'Photo', "VARCHAR(255) NULL AFTER `Disease`");
        // Multiple chronic diseases are stored as a normalized comma-separated display string.
        // TEXT prevents truncation when several conditions are entered.
        if (thonglangTableHasColumn($connection, 'patient', 'Disease')) {
            @mysqli_query($connection, "ALTER TABLE `patient` MODIFY COLUMN `Disease` TEXT NULL");
        }

        thonglangEnsureColumn($connection, 'village', 'village_number', "VARCHAR(20) NULL AFTER `village_id`");
        thonglangEnsureColumn($connection, 'village', 'zipcode', "VARCHAR(10) NULL AFTER `province`");

        // Requested reference data and system defaults.
        ensureSystemSettingsTable($connection);
        @mysqli_query($connection, "INSERT IGNORE INTO system_settings(setting_key,setting_value) VALUES ('org_name','ทองหลาง'),('director_name','นาย รัศมี แก้วเนตร')");

        // Keep the director user profile synchronized with the configured official name.
        $configuredDirector = trim(systemSetting($connection, 'director_name', 'นาย รัศมี แก้วเนตร'));
        if ($configuredDirector === '') $configuredDirector = 'นาย รัศมี แก้วเนตร';
        $directorSync = mysqli_prepare($connection, "UPDATE users SET display_name=? WHERE role='director'");
        if ($directorSync) {
            mysqli_stmt_bind_param($directorSync, 's', $configuredDirector);
            @mysqli_stmt_execute($directorSync);
            mysqli_stmt_close($directorSync);
        }

        // Ensure the two requested villages are available without deleting any village that may already be linked to records.
        $requestedVillages = [['8','บ้านตูมหมู่ 8'], ['9','บ้านตูมหมู่ 9']];
        foreach ($requestedVillages as [$villageNumber, $villageName]) {
            $check = mysqli_prepare($connection, "SELECT village_id FROM village WHERE villagename=? OR village_number=? LIMIT 1");
            if ($check) {
                mysqli_stmt_bind_param($check, 'ss', $villageName, $villageNumber);
                mysqli_stmt_execute($check);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
                mysqli_stmt_close($check);
                if ($existing) {
                    $vid = (int)$existing['village_id'];
                    $updateVillage = mysqli_prepare($connection, "UPDATE village SET villagename=?, village_number=? WHERE village_id=?");
                    if ($updateVillage) {
                        mysqli_stmt_bind_param($updateVillage, 'ssi', $villageName, $villageNumber, $vid);
                        mysqli_stmt_execute($updateVillage);
                        mysqli_stmt_close($updateVillage);
                    }
                } else {
                    $insertVillage = mysqli_prepare($connection, "INSERT INTO village(village_number,villagename) VALUES (?,?)");
                    if ($insertVillage) {
                        mysqli_stmt_bind_param($insertVillage, 'ss', $villageNumber, $villageName);
                        @mysqli_stmt_execute($insertVillage);
                        mysqli_stmt_close($insertVillage);
                    }
                }
            }
        }
    } catch (Throwable $error) {
        http_response_code(500);
        die('<meta charset="utf-8"><div style="font-family:Noto Sans Thai,sans-serif;padding:24px;color:#8a1f1f">' .
            htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') .
            '<br><br>กรุณาตรวจสอบสิทธิ์ของบัญชีฐานข้อมูล MySQL แล้วลองใหม่</div>');
    }
}


function thonglangUploadDirectory(string $subDirectory = 'patients'): string
{
    $subDirectory = trim(str_replace('..', '', $subDirectory), "/\\");
    $baseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($baseDirectory) && !@mkdir($baseDirectory, 0777, true) && !is_dir($baseDirectory)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads ได้');
    }

    $targetDirectory = $baseDirectory . DIRECTORY_SEPARATOR . ($subDirectory !== '' ? $subDirectory : 'patients');
    if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์สำหรับจัดเก็บรูปภาพได้');
    }

    return $targetDirectory;
}

function thonglangHandleImageUpload(string $fieldName, string $subDirectory = 'patients'): array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return ['uploaded' => false, 'path' => null, 'error' => null];
    }

    $file = $_FILES[$fieldName];
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['uploaded' => false, 'path' => null, 'error' => null];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['uploaded' => false, 'path' => null, 'error' => 'อัปโหลดรูปภาพไม่สำเร็จ'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['uploaded' => false, 'path' => null, 'error' => 'ไม่พบไฟล์รูปภาพที่อัปโหลด'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return ['uploaded' => false, 'path' => null, 'error' => 'ไฟล์ที่อัปโหลดต้องเป็นรูปภาพเท่านั้น'];
    }

    $allowedMimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $mimeType = strtolower((string)($imageInfo['mime'] ?? ''));
    if (!isset($allowedMimeToExtension[$mimeType])) {
        return ['uploaded' => false, 'path' => null, 'error' => 'รองรับเฉพาะไฟล์ JPG, PNG, GIF หรือ WEBP'];
    }

    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['uploaded' => false, 'path' => null, 'error' => 'ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5 MB'];
    }

    $targetDirectory = thonglangUploadDirectory($subDirectory);
    $extension = $allowedMimeToExtension[$mimeType];
    $filename = strtolower($subDirectory) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
    $destinationPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        return ['uploaded' => false, 'path' => null, 'error' => 'ไม่สามารถบันทึกรูปภาพที่อัปโหลดได้'];
    }

    $relativePath = 'uploads/' . trim(str_replace('\\', '/', $subDirectory), '/') . '/' . $filename;
    return ['uploaded' => true, 'path' => $relativePath, 'error' => null];
}

function thonglangDeleteUploadedFile(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') return;

    $normalized = str_replace('\\', '/', $relativePath);
    if (strpos($normalized, 'uploads/') !== 0) return;

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function thonglangUploadedImageUrl(?string $relativePath): string
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') return '';

    $normalized = str_replace('\\', '/', $relativePath);
    if (strpos($normalized, 'uploads/') !== 0) return '';

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (!is_file($absolutePath)) return '';

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $appFolder = '/' . basename(__DIR__);
    $basePath = '';
    $pos = $scriptName !== '' ? strpos($scriptName, $appFolder) : false;
    if ($pos !== false) {
        $basePath = substr($scriptName, 0, $pos + strlen($appFolder));
    }

    if ($basePath === '' && !empty($_SERVER['PHP_SELF'])) {
        $phpSelf = str_replace('\\', '/', (string)$_SERVER['PHP_SELF']);
        $pos = strpos($phpSelf, $appFolder);
        if ($pos !== false) {
            $basePath = substr($phpSelf, 0, $pos + strlen($appFolder));
        }
    }

    return rtrim($basePath, '/') . '/' . ltrim($normalized, '/');
}


/**
 * ADL round one stores the assessment calendar day (DATE) separately from the
 * moment its entry was saved (created_at). Attach a recorded time only when
 * both dates coincide. A backdated assessment has no proven assessment time,
 * so show the date alone instead of inventing 00:00 or the viewer's clock.
 */
function adlAssessmentRecordedDateTime(?string $assessmentDate, ?string $createdAt): string
{
    $day = trim((string)($assessmentDate ?? ''));
    $saved = trim((string)($createdAt ?? ''));
    if ($day === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
        && preg_match('/^\d{4}-\d{2}-\d{2} [0-2]\d:[0-5]\d:[0-5]\d$/', $saved)
        && substr($saved, 0, 10) === $day) {
        return $saved;
    }
    return $day;
}

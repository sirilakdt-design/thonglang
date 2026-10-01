<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$role = function_exists('currentRole')
    ? currentRole()
    : strtolower(trim((string)($_SESSION['role'] ?? '')));

$menus = [
    'admin' => [
        ['home.php', 'หน้าแรก'],
        ['caregiver.php', 'ข้อมูลแคร์กิฟเวอร์'],
        ['doctor.php', 'ข้อมูลหมอ'],
        ['executive_users.php', 'จัดการข้อมูลผู้อำนวยการ'],
        ['village.php', 'ข้อมูลหมู่บ้าน'],
        ['statistics.php', 'ออกรายงาน'],
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
            ['director/executive.php', 'หน้าแรก'],
            ['director/director_visits.php', 'ตรวจสอบรายงาน'],
            ['director/director_assessments.php', 'ลงนามอนุมัติ'],
            ['director/director_signature_history.php', 'ประวัติการลงนาม'],
            ['director/director_reports.php', 'รายงานสรุป'],
            ['director/director_patients.php', 'ข้อมูลผู้สูงอายุ'],
        ],
];

$menuItems = $menus[$role] ?? [
    ['home.php', 'หน้าแรก']
];
?>
<?php
$sidebarLogoFile = __DIR__ . '/assets/logo.jpg';
$sidebarLogoSrc = (is_file($sidebarLogoFile) && is_readable($sidebarLogoFile))
    ? 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($sidebarLogoFile))
    : (function_exists('appUrl') ? appUrl('assets/logo.jpg') : 'assets/logo.jpg');
?>


<div class="sidebar">
    <div class="brand">
        <div class="logo">
            <img src="<?= e($sidebarLogoSrc) ?>" alt="<?= e(function_exists('appName') ? appName() : 'ทองหลาง') ?> Logo">
        </div>

        <h2><?= e(function_exists('appName') ? appName() : 'ทองหลาง') ?></h2>
    </div>

    <ul class="menu">
        <?php foreach ($menuItems as [$href, $label]): ?>
            <li>
                <?php $activeFile = basename(parse_url($href, PHP_URL_PATH) ?: $href); ?>
                <a href="<?= e(function_exists('appUrl') ? appUrl($href) : $href) ?>"
                   class="<?= $currentPage === $activeFile ? 'active' : '' ?>">

                    <span class="menu-text">
                        <?= e($label) ?>
                    </span>

                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
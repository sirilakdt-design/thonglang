<?php
require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$error = '';
$doctorId = (int) ($_SESSION['user_id'] ?? 0);

$create = "CREATE TABLE IF NOT EXISTS adl_assessment (
    adl_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    caregiver_user_id INT NULL,
    doctor_user_id INT NULL,
    assessment_date DATE NOT NULL,
    feeding TINYINT NOT NULL,
    grooming TINYINT NOT NULL,
    transfer TINYINT NOT NULL,
    toilet_use TINYINT NOT NULL,
    mobility TINYINT NOT NULL,
    dressing TINYINT NOT NULL,
    stairs TINYINT NOT NULL,
    bathing TINYINT NOT NULL,
    bowels TINYINT NOT NULL,
    bladder TINYINT NOT NULL,
    total_score SMALLINT NOT NULL,
    regular_caregiver VARCHAR(120) NULL,
    welfare_status VARCHAR(60) NULL,
    club_membership VARCHAR(120) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient(patient_id),
    KEY idx_caregiver(caregiver_user_id),
    KEY idx_doctor(doctor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!mysqli_query($conn, $create)) {
    $error = 'ไม่สามารถเตรียมตาราง ADL ได้: ' . mysqli_error($conn);
}

function ensureAdlColumn(mysqli $conn, string $column, string $definition): void
{
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    try {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM adl_assessment WHERE Field = '" . mysqli_real_escape_string($conn, $safe) . "'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, "ALTER TABLE adl_assessment ADD COLUMN `$safe` $definition");
        }
    } catch (mysqli_sql_exception $e) {
        // เก็บข้อความไว้ให้หน้าจอแสดงแทนการหยุดทำงานแบบ Fatal error
        global $error;
        if ($error === '') {
            $error = 'ไม่สามารถปรับโครงสร้างตาราง ADL ได้: ' . $e->getMessage();
        }
    }
}

/*
 * รองรับฐานข้อมูลเดิมที่เคยสร้างตาราง adl_assessment ไว้แล้ว
 * CREATE TABLE IF NOT EXISTS จะไม่เพิ่มคอลัมน์ใหม่ให้ตารางเก่า จึงตรวจและเพิ่มทีละคอลัมน์
 * คอลัมน์ของข้อมูลเดิมใช้ NULL/DEFAULT เพื่อไม่ทำลายประวัติเดิม
 */
ensureAdlColumn($conn, 'patient_id', 'INT NULL');
ensureAdlColumn($conn, 'caregiver_user_id', 'INT NULL');
ensureAdlColumn($conn, 'doctor_user_id', 'INT NULL');
ensureAdlColumn($conn, 'assessment_date', 'DATE NULL');
ensureAdlColumn($conn, 'feeding', 'TINYINT NULL');
ensureAdlColumn($conn, 'grooming', 'TINYINT NULL');
ensureAdlColumn($conn, 'transfer', 'TINYINT NULL');
ensureAdlColumn($conn, 'toilet_use', 'TINYINT NULL');
ensureAdlColumn($conn, 'mobility', 'TINYINT NULL');
ensureAdlColumn($conn, 'dressing', 'TINYINT NULL');
ensureAdlColumn($conn, 'stairs', 'TINYINT NULL');
ensureAdlColumn($conn, 'bathing', 'TINYINT NULL');
ensureAdlColumn($conn, 'bowels', 'TINYINT NULL');
ensureAdlColumn($conn, 'bladder', 'TINYINT NULL');
ensureAdlColumn($conn, 'total_score', 'SMALLINT NOT NULL DEFAULT 0');
ensureAdlColumn($conn, 'regular_caregiver', 'VARCHAR(120) NULL');
ensureAdlColumn($conn, 'welfare_status', 'VARCHAR(60) NULL');
ensureAdlColumn($conn, 'club_membership', 'VARCHAR(120) NULL');
ensureAdlColumn($conn, 'note', 'TEXT NULL');
ensureAdlColumn($conn, 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
ensureAdlColumn($conn, 'handoff_status', "VARCHAR(40) NOT NULL DEFAULT 'รอส่งต่อ'");
ensureAdlColumn($conn, 'caregiver_total_score', 'TINYINT NULL');
ensureAdlColumn($conn, 'result_returned_to_doctor', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureAdlColumn($conn, 'result_returned_at', 'DATETIME NULL');
ensureAdlColumn($conn, 'caregiver_completed_at', 'DATETIME NULL');

/* ฐานข้อมูลรุ่นเก่าเคยกำหนด caregiver_user_id เป็น NOT NULL
 * ปัจจุบัน ADL ย้ายมาให้หมอเป็นผู้ประเมิน จึงอนุญาตให้ฟิลด์เดิมเป็น NULL
 * เพื่อเก็บประวัติเดิมไว้โดยไม่ต้องลบข้อมูลเก่า
 */
try {
    mysqli_query($conn, "ALTER TABLE adl_assessment MODIFY caregiver_user_id INT NULL");
} catch (mysqli_sql_exception $e) {
    if ($error === '') {
        $error = 'ไม่สามารถปรับตาราง ADL สำหรับผู้ใช้งานหมอได้: ' . $e->getMessage();
    }
}

$patients = [];
$res = mysqli_query($conn, "SELECT Patient_id,Firstname,Lastname,Fullname,Age,Weight_kg,Height_cm,Address,Phone,Disease FROM patient ORDER BY Fullname ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $patients[] = $row;
    }
} elseif ($error === '') {
    $error = 'ไม่สามารถอ่านรายชื่อผู้สูงอายุได้: ' . mysqli_error($conn);
}

/* =========================================================
   ข้อมูลหมู่บ้าน + เพิ่มผู้สูงอายุจากหน้า ADL
========================================================= */
$villages = [];
$villageRes = mysqli_query(
    $conn,
    "SELECT village_id, villagename, district, province
     FROM village
     ORDER BY village_id ASC"
);
if ($villageRes) {
    while ($villageRow = mysqli_fetch_assoc($villageRes)) {
        $villages[] = $villageRow;
    }
}

$addPatientModalOpen = false;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'add_patient'
    && $error === ''
) {
    $addPatientModalOpen = true;

    $newFirstname = trim((string) ($_POST['new_firstname'] ?? ''));
    $newLastname = trim((string) ($_POST['new_lastname'] ?? ''));
    $newFullname = trim($newFirstname . ' ' . $newLastname);
    $newGender = trim((string) ($_POST['new_gender'] ?? ''));
    $newAge = (int) ($_POST['new_age'] ?? 0);
    $newWeight = trim((string) ($_POST['new_weight_kg'] ?? ''));
    $newHeight = trim((string) ($_POST['new_height_cm'] ?? ''));
    $newWeightValue = $newWeight === '' ? null : (float) $newWeight;
    $newHeightValue = $newHeight === '' ? null : (float) $newHeight;
    $newVillageId = (int) ($_POST['new_village_id'] ?? 0);
    $newAddress = trim((string) ($_POST['new_address'] ?? ''));
    $newPhone = trim((string) ($_POST['new_phone'] ?? ''));
    $newDisease = trim((string) ($_POST['new_disease'] ?? ''));

    if (
        $newFirstname === ''
        || $newLastname === ''
        || !in_array($newGender, ['ชาย', 'หญิง'], true)
        || $newAge <= 0
        || $newAge > 150
        || $newVillageId <= 0
    ) {
        $error = 'กรุณากรอกชื่อ นามสกุล เพศ อายุ และหมู่บ้านให้ครบถ้วน';
    } elseif ($newWeight !== '' && ($newWeightValue <= 0 || $newWeightValue > 500)) {
        $error = 'กรุณากรอกน้ำหนักให้ถูกต้อง';
    } elseif ($newHeight !== '' && ($newHeightValue <= 0 || $newHeightValue > 300)) {
        $error = 'กรุณากรอกส่วนสูงให้ถูกต้อง';
    } elseif ($newPhone !== '' && !preg_match('/^[0-9]{9,10}$/', $newPhone)) {
        $error = 'กรุณากรอกเบอร์โทรเป็นตัวเลข 9-10 หลัก';
    } else {
        $villageCheck = mysqli_prepare(
            $conn,
            "SELECT village_id FROM village WHERE village_id=? LIMIT 1"
        );
        $validVillage = false;

        if ($villageCheck) {
            mysqli_stmt_bind_param($villageCheck, 'i', $newVillageId);
            mysqli_stmt_execute($villageCheck);
            $validVillage = (bool) mysqli_fetch_row(mysqli_stmt_get_result($villageCheck));
            mysqli_stmt_close($villageCheck);
        }

        if (!$validVillage) {
            $error = 'ไม่พบข้อมูลหมู่บ้านที่เลือก';
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO patient
                    (Firstname,Lastname,Fullname,Gender,Age,Weight_kg,Height_cm,Address,Phone,Disease,Village_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );

            if (!$stmt) {
                $error = 'ไม่สามารถเตรียมคำสั่งเพิ่มข้อมูลผู้สูงอายุได้: ' . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssssiddsssi',
                    $newFirstname,
                    $newLastname,
                    $newFullname,
                    $newGender,
                    $newAge,
                    $newWeightValue,
                    $newHeightValue,
                    $newAddress,
                    $newPhone,
                    $newDisease,
                    $newVillageId
                );

                if (mysqli_stmt_execute($stmt)) {
                    $newPatientId = (int) mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    header('Location: adl.php?new_patient=' . $newPatientId . '#adlForm');
                    exit;
                }

                $error = 'เพิ่มข้อมูลผู้สูงอายุไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$adlItems = [
    'feeding' => [
        'title' => '1. การรับประทานอาหาร',
        'subtitle' => 'รับประทานอาหารเมื่อเตรียมสำรับไว้ให้เรียบร้อยต่อหน้า',
        'options' => [
            0 => 'ไม่สามารถตักอาหารเข้าปากได้ ต้องมีคนป้อนให้',
            1 => 'ตักอาหารเองได้แต่ต้องมีคนช่วย เช่น ช่วยใช้ช้อนตักเตรียมไว้ให้ หรือตัดเป็นชิ้นเล็ก ๆ ไว้ล่วงหน้า',
            2 => 'ตักอาหารและช่วยตัวเองได้เป็นปกติ',
        ],
    ],
    'grooming' => [
        'title' => '2. การดูแลตนเอง',
        'subtitle' => 'ล้างหน้า หวีผม แปรงฟัน โกนหนวด ในระยะเวลา 24 - 28 ชั่วโมงที่ผ่านมา',
        'options' => [
            0 => 'ต้องการความช่วยเหลือ',
            1 => 'ทำเองได้ (รวมทั้งที่ทำได้เองถ้าเตรียมอุปกรณ์ไว้ให้)',
        ],
    ],
    'transfer' => [
        'title' => '3. การลุกนั่งและเคลื่อนย้าย',
        'subtitle' => 'ลุกนั่งจากที่นอน หรือจากเตียงไปยังเก้าอี้',
        'options' => [
            0 => 'ไม่สามารถนั่งได้ (นั่งแล้วจะล้มเสมอ) หรือต้องใช้คนสองคนช่วยกันยกขึ้น',
            1 => 'ต้องการความช่วยเหลืออย่างมากจึงจะนั่งได้ เช่น ต้องใช้คนที่แข็งแรงหรือมีทักษะ 1 คน หรือใช้คนทั่วไป 2 คนพยุงหรือดันขึ้นมาจึงจะนั่งอยู่ได้',
            2 => 'ต้องการความช่วยเหลือบ้าง เช่น บอกให้ทำตาม หรือช่วยพยุงเล็กน้อย หรือต้องมีคนดูแลเพื่อความปลอดภัย',
            3 => 'ทำได้เอง',
        ],
    ],
    'toilet_use' => [
        'title' => '4. การใช้ห้องน้ำ',
        'subtitle' => 'ใช้ห้องน้ำ',
        'options' => [
            0 => 'ช่วยตัวเองไม่ได้',
            1 => 'ทำเองได้บ้าง (อย่างน้อยทำความสะอาดตัวเองได้หลังจากเสร็จธุระ) แต่ต้องการความช่วยเหลือในบางสิ่ง',
            2 => 'ช่วยตัวเองได้ดี (ขึ้นนั่งและลงจากโถส้วมเองได้ ทำความสะอาดได้เรียบร้อยหลังจากเสร็จธุระ ถอดใส่เสื้อผ้าได้เรียบร้อย)',
        ],
    ],
    'mobility' => [
        'title' => '5. การเคลื่อนที่',
        'subtitle' => 'การเคลื่อนที่ภายในห้องหรือบ้าน',
        'options' => [
            0 => 'เคลื่อนที่ไปไหนไม่ได้',
            1 => 'ต้องใช้รถเข็นช่วยตัวเองให้เคลื่อนที่ได้เอง (ไม่ต้องมีคนเข็นให้) และจะต้องเข้าออกมุมห้องหรือประตูได้',
            2 => 'เดินหรือเคลื่อนที่โดยมีคนช่วย เช่น พยุง หรือบอกให้ทำตาม หรือต้องให้ความสนใจดูแลเพื่อความปลอดภัย',
            3 => 'เดินหรือเคลื่อนที่ได้เอง',
        ],
    ],
    'dressing' => [
        'title' => '6. การสวมใส่เสื้อผ้า',
        'subtitle' => 'การสวมใส่เสื้อผ้า',
        'options' => [
            0 => 'ต้องมีคนสวมใส่ให้ ช่วยตัวเองแทบไม่ได้หรือน้อย',
            1 => 'ช่วยตัวเองได้ประมาณร้อยละ 50 ที่เหลือต้องมีคนช่วย',
            2 => 'ช่วยตัวเองได้ดี (รวมทั้งการติดกระดุม รูดซิป หรือใช้เสื้อผ้าที่ดัดแปลงให้เหมาะสมก็ได้)',
        ],
    ],
    'stairs' => [
        'title' => '7. การขึ้นลงบันได',
        'subtitle' => 'การขึ้นลงบันได 1 ชั้น',
        'options' => [
            0 => 'ไม่สามารถทำได้',
            1 => 'ต้องการคนช่วย',
            2 => 'ขึ้นลงได้เอง (ถ้าต้องใช้เครื่องช่วยเดิน เช่น walker จะต้องเอาขึ้นลงได้ด้วย)',
        ],
    ],
    'bathing' => [
        'title' => '8. การอาบน้ำ',
        'subtitle' => 'การอาบน้ำ',
        'options' => [
            0 => 'ต้องมีคนช่วยหรือทำให้',
            1 => 'อาบน้ำเองได้',
        ],
    ],
    'bowels' => [
        'title' => '9. การกลั้นอุจจาระ',
        'subtitle' => 'การกลั้นการถ่ายอุจจาระในระยะ 1 สัปดาห์ที่ผ่านมา',
        'options' => [
            0 => 'กลั้นไม่ได้ หรือต้องการการสวนอุจจาระอยู่เสมอ',
            1 => 'กลั้นไม่ได้บางครั้ง (เป็นน้อยกว่า 1 ครั้งต่อสัปดาห์)',
            2 => 'กลั้นได้เป็นปกติ',
        ],
    ],
    'bladder' => [
        'title' => '10. การกลั้นปัสสาวะ',
        'subtitle' => 'การกลั้นปัสสาวะในระยะ 1 สัปดาห์ที่ผ่านมา',
        'options' => [
            0 => 'กลั้นไม่ได้ หรือใส่สายสวนปัสสาวะแต่ไม่สามารถดูแลเองได้',
            1 => 'กลั้นไม่ได้บางครั้ง (เป็นน้อยกว่าวันละ 1 ครั้ง)',
            2 => 'กลั้นได้เป็นปกติ',
        ],
    ],
];

/* =========================================================
   ข้อความรายละเอียดคำถาม ADL ที่แก้ไขได้
   - เก็บชื่อข้อ
   - คำอธิบาย
   - ข้อความตัวเลือก
   คะแนนตัวเลขยังคงเดิมเพื่อไม่ให้สูตร ADL เปลี่ยน
========================================================= */
$defaultAdlItems = $adlItems;

$questionConfigSql = "CREATE TABLE IF NOT EXISTS adl_question_config (
    field_key VARCHAR(50) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subtitle TEXT NULL,
    options_json LONGTEXT NOT NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $questionConfigSql) && $error === '') {
    $error = 'ไม่สามารถเตรียมตารางรายละเอียดคำถาม ADL ได้: ' . mysqli_error($conn);
}

if ($error === '') {
    $configRes = mysqli_query(
        $conn,
        "SELECT field_key,title,subtitle,options_json
         FROM adl_question_config"
    );

    if ($configRes) {
        while ($configRow = mysqli_fetch_assoc($configRes)) {
            $fieldKey = (string) ($configRow['field_key'] ?? '');

            if (!isset($adlItems[$fieldKey], $defaultAdlItems[$fieldKey])) {
                continue;
            }

            $title = trim((string) ($configRow['title'] ?? ''));
            $subtitle = (string) ($configRow['subtitle'] ?? '');
            $savedOptions = json_decode((string) ($configRow['options_json'] ?? ''), true);

            if ($title !== '') {
                $adlItems[$fieldKey]['title'] = $title;
            }

            $adlItems[$fieldKey]['subtitle'] = $subtitle;

            if (is_array($savedOptions)) {
                foreach ($defaultAdlItems[$fieldKey]['options'] as $score => $defaultText) {
                    $lookupKey = (string) $score;

                    if (array_key_exists($lookupKey, $savedOptions)) {
                        $adlItems[$fieldKey]['options'][$score] = (string) $savedOptions[$lookupKey];
                    } elseif (array_key_exists($score, $savedOptions)) {
                        $adlItems[$fieldKey]['options'][$score] = (string) $savedOptions[$score];
                    }
                }
            }
        }
    }
}

$rules = [];
foreach ($adlItems as $key => $item) {
    $rules[$key] = array_keys($item['options']);
}

$caregiverOptions = [
    'ไม่มี ลูกญาติทอดทิ้ง',
    'ไม่มี อยู่ตามลำพังไม่มีญาติ',
    'มี ครอบครัวดูแล',
    'มี เพื่อนบ้านดูแล',
];
$welfareOptions = ['ไม่ได้รับ', 'ได้รับ', 'ไม่ทราบ'];
$clubOptions = ['ไม่ได้เป็น', 'ชมรมผู้สูงอายุ', 'คลังปัญญา', 'ชมรมผู้สูงอายุและคลังปัญญา', 'อื่นๆ'];

/* =========================================================
   บันทึกรายละเอียดข้อความของคำถามแต่ละข้อ
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'save_question_details'
) {
    header('Content-Type: application/json; charset=utf-8');

    $field = trim((string) ($_POST['field'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
    $postedOptions = json_decode((string) ($_POST['options'] ?? ''), true);

    if (!isset($defaultAdlItems[$field])) {
        echo json_encode([
            'ok' => false,
            'message' => 'ไม่พบข้อคำถามที่ต้องการแก้ไข'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($title === '') {
        echo json_encode([
            'ok' => false,
            'message' => 'กรุณากรอกชื่อข้อ'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_array($postedOptions)) {
        echo json_encode([
            'ok' => false,
            'message' => 'ข้อมูลตัวเลือกไม่ถูกต้อง'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanOptions = [];

    foreach ($defaultAdlItems[$field]['options'] as $score => $defaultText) {
        $scoreKey = (string) $score;
        $optionText = trim((string) ($postedOptions[$scoreKey] ?? ''));

        if ($optionText === '') {
            echo json_encode([
                'ok' => false,
                'message' => 'กรุณากรอกรายละเอียดตัวเลือกคะแนน ' . $score . ' ให้ครบ'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cleanOptions[$scoreKey] = $optionText;
    }

    $optionsJson = json_encode(
        $cleanOptions,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO adl_question_config
            (field_key,title,subtitle,options_json,updated_by,updated_at)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            title=VALUES(title),
            subtitle=VALUES(subtitle),
            options_json=VALUES(options_json),
            updated_by=VALUES(updated_by),
            updated_at=NOW()"
    );

    if (!$stmt) {
        echo json_encode([
            'ok' => false,
            'message' => 'ไม่สามารถเตรียมคำสั่งบันทึกรายละเอียดได้'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssssi',
        $field,
        $title,
        $subtitle,
        $optionsJson,
        $doctorId
    );

    $saved = mysqli_stmt_execute($stmt);
    $saveError = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    if (!$saved) {
        echo json_encode([
            'ok' => false,
            'message' => 'บันทึกรายละเอียดไม่สำเร็จ: ' . $saveError
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'บันทึกรายละเอียดข้อนี้เรียบร้อยแล้ว',
        'field' => $field,
        'title' => $title,
        'subtitle' => $subtitle,
        'options' => $cleanOptions
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   บันทึกเฉพาะข้อที่กำลังแก้ไข
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'save_single_adl_field'
) {
    header('Content-Type: application/json; charset=utf-8');

    $adlId = (int) ($_POST['adl_id'] ?? 0);
    $field = trim((string) ($_POST['field'] ?? ''));
    $rawValue = (string) ($_POST['value'] ?? '');

    if ($adlId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ ADL ที่ต้องการแก้ไข'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerStmt = mysqli_prepare(
        $conn,
        "SELECT adl_id FROM adl_assessment
         WHERE adl_id=? AND doctor_user_id=? LIMIT 1"
    );

    if (!$ownerStmt) {
        echo json_encode(['ok' => false, 'message' => 'ไม่สามารถตรวจสอบสิทธิ์การแก้ไขได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mysqli_stmt_bind_param($ownerStmt, 'ii', $adlId, $doctorId);
    mysqli_stmt_execute($ownerStmt);
    $owned = (bool) mysqli_fetch_row(mysqli_stmt_get_result($ownerStmt));
    mysqli_stmt_close($ownerStmt);

    if (!$owned) {
        echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์แก้ไขรายการนี้'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* 10 ข้อที่มีคะแนน */
    if (array_key_exists($field, $rules)) {
        $value = (int) $rawValue;

        if (!in_array($value, $rules[$field], true)) {
            echo json_encode(['ok' => false, 'message' => 'ค่าคะแนนไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = "UPDATE adl_assessment SET `$field`=? WHERE adl_id=? AND doctor_user_id=?";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            echo json_encode(['ok' => false, 'message' => 'ไม่สามารถเตรียมคำสั่งบันทึกได้'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        mysqli_stmt_bind_param($stmt, 'iii', $value, $adlId, $doctorId);
        $saved = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$saved) {
            echo json_encode(['ok' => false, 'message' => 'บันทึกข้อนี้ไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $scoreStmt = mysqli_prepare(
            $conn,
            "SELECT feeding,grooming,transfer,toilet_use,mobility,dressing,stairs,bathing,bowels,bladder
             FROM adl_assessment
             WHERE adl_id=? AND doctor_user_id=? LIMIT 1"
        );
        mysqli_stmt_bind_param($scoreStmt, 'ii', $adlId, $doctorId);
        mysqli_stmt_execute($scoreStmt);
        $scoreRow = mysqli_fetch_assoc(mysqli_stmt_get_result($scoreStmt));
        mysqli_stmt_close($scoreStmt);

        $total = 0;
        foreach (array_keys($rules) as $scoreField) {
            $total += (int) ($scoreRow[$scoreField] ?? 0);
        }

        $totalStmt = mysqli_prepare(
            $conn,
            "UPDATE adl_assessment SET total_score=? WHERE adl_id=? AND doctor_user_id=?"
        );
        mysqli_stmt_bind_param($totalStmt, 'iii', $total, $adlId, $doctorId);
        mysqli_stmt_execute($totalStmt);
        mysqli_stmt_close($totalStmt);

        echo json_encode([
            'ok' => true,
            'message' => 'บันทึกข้อนี้เรียบร้อยแล้ว',
            'total' => $total
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* 3 ข้อเพิ่มเติม */
    $extraRules = [
        'regular_caregiver' => $caregiverOptions,
        'welfare_status' => $welfareOptions,
        'club_membership' => $clubOptions,
    ];

    if (array_key_exists($field, $extraRules)) {
        $value = trim($rawValue);

        if (!in_array($value, $extraRules[$field], true)) {
            echo json_encode(['ok' => false, 'message' => 'คำตอบไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = "UPDATE adl_assessment SET `$field`=? WHERE adl_id=? AND doctor_user_id=?";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            echo json_encode(['ok' => false, 'message' => 'ไม่สามารถเตรียมคำสั่งบันทึกได้'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        mysqli_stmt_bind_param($stmt, 'sii', $value, $adlId, $doctorId);
        $saved = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode([
            'ok' => $saved,
            'message' => $saved ? 'บันทึกข้อนี้เรียบร้อยแล้ว' : 'บันทึกข้อนี้ไม่สำเร็จ'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'ไม่พบหัวข้อที่ต้องการแก้ไข'], JSON_UNESCAPED_UNICODE);
    exit;
}


/* =========================================================
   ลบประวัติการประเมิน ADL
   - ลบได้เฉพาะรายการที่หมอคนที่ล็อกอินเป็นผู้ประเมิน
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'delete_adl'
) {
    $deleteAdlId = (int) ($_POST['adl_id'] ?? 0);

    if ($deleteAdlId <= 0) {
        $error = 'ไม่พบรายการ ADL ที่ต้องการลบ';
    } else {
        $deleteStmt = mysqli_prepare(
            $conn,
            "DELETE FROM adl_assessment
             WHERE adl_id=? AND doctor_user_id=?
             LIMIT 1"
        );

        if (!$deleteStmt) {
            $error = 'ไม่สามารถเตรียมคำสั่งลบได้: ' . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($deleteStmt, 'ii', $deleteAdlId, $doctorId);

            if (mysqli_stmt_execute($deleteStmt)) {
                $deletedRows = mysqli_stmt_affected_rows($deleteStmt);
                mysqli_stmt_close($deleteStmt);

                if ($deletedRows > 0) {
                    $redirectPatientId = (int) ($_POST['patient_id'] ?? 0);
                    $redirectMonth = trim((string) ($_POST['month'] ?? ''));
                    $query = ['deleted' => '1'];
                    if ($redirectPatientId > 0) {
                        $query['patient_id'] = (string) $redirectPatientId;
                    }
                    if ($redirectMonth !== '') {
                        $query['month'] = $redirectMonth;
                    }
                    header('Location: adl_history.php?' . http_build_query($query));
                    exit;
                }

                $error = 'ไม่พบรายการที่ต้องการลบ หรือคุณไม่มีสิทธิ์ลบรายการนี้';
            } else {
                $error = 'ลบข้อมูลไม่สำเร็จ: ' . mysqli_stmt_error($deleteStmt);
                mysqli_stmt_close($deleteStmt);
            }
        }
    }
}

/* =========================================================
   โหลดข้อมูลเดิมสำหรับโหมดแก้ไข
========================================================= */
$editId = (int) ($_GET['edit'] ?? $_POST['adl_id'] ?? 0);
$editRecord = null;

if ($editId > 0 && $error === '') {
    $editStmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM adl_assessment
         WHERE adl_id=? AND doctor_user_id=?
         LIMIT 1"
    );

    if ($editStmt) {
        mysqli_stmt_bind_param($editStmt, 'ii', $editId, $doctorId);
        mysqli_stmt_execute($editStmt);
        $editRecord = mysqli_fetch_assoc(mysqli_stmt_get_result($editStmt));
        mysqli_stmt_close($editStmt);

        if (!$editRecord) {
            $error = 'ไม่พบรายการ ADL ที่ต้องการแก้ไข หรือคุณไม่มีสิทธิ์แก้ไขรายการนี้';
            $editId = 0;
        }
    }
}

/* เติมค่าเดิมเข้าแบบฟอร์มเมื่อเปิดโหมดแก้ไข */
if ($editRecord && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST = [
        'adl_id' => $editRecord['adl_id'],
        'patient_id' => $editRecord['patient_id'],
        'assessment_date' => $editRecord['assessment_date'],
        'feeding' => $editRecord['feeding'],
        'grooming' => $editRecord['grooming'],
        'transfer' => $editRecord['transfer'],
        'toilet_use' => $editRecord['toilet_use'],
        'mobility' => $editRecord['mobility'],
        'dressing' => $editRecord['dressing'],
        'stairs' => $editRecord['stairs'],
        'bathing' => $editRecord['bathing'],
        'bowels' => $editRecord['bowels'],
        'bladder' => $editRecord['bladder'],
        'regular_caregiver' => $editRecord['regular_caregiver'],
        'welfare_status' => $editRecord['welfare_status'],
        'club_membership' => $editRecord['club_membership'],
        'note' => $editRecord['note'],
    ];
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') !== 'add_patient'
    && $error === ''
) {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $date = trim((string) ($_POST['assessment_date'] ?? date('Y-m-d')));
    $note = trim((string) ($_POST['note'] ?? ''));
    $regularCaregiver = trim((string) ($_POST['regular_caregiver'] ?? ''));
    $welfare = trim((string) ($_POST['welfare_status'] ?? ''));
    $club = trim((string) ($_POST['club_membership'] ?? ''));

    $check = mysqli_prepare($conn, "SELECT Patient_id FROM patient WHERE Patient_id=? LIMIT 1");
    $validPatient = false;
    if ($check) {
        mysqli_stmt_bind_param($check, 'i', $patientId);
        mysqli_stmt_execute($check);
        $validPatient = (bool) mysqli_fetch_row(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);
    }

    $vals = [];
    $valid = true;
    foreach ($rules as $key => $allowed) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            $valid = false;
            break;
        }
        $value = (int) $_POST[$key];
        if (!in_array($value, $allowed, true)) {
            $valid = false;
            break;
        }
        $vals[$key] = $value;
    }

    if ($patientId <= 0 || !$validPatient) {
        $error = 'ไม่พบข้อมูลผู้สูงอายุที่เลือก';
    } elseif (!$valid) {
        $error = 'กรุณาประเมิน ADL ให้ครบทั้ง 10 หัวข้อ';
    } else {
        $total = array_sum($vals);

        // ส่งผลคะแนนต่อให้แคร์กิฟเวอร์ที่ถูกมอบหมายอยู่ในขณะนั้น
        $assignedCaregiverId = null;
        $assignmentTable = mysqli_query($conn, "SHOW TABLES LIKE 'patient_caregiver_assignment'");
        if ($assignmentTable && mysqli_num_rows($assignmentTable) > 0) {
            $handoffStmt = mysqli_prepare(
                $conn,
                "SELECT caregiver_user_id FROM patient_caregiver_assignment WHERE patient_id=? AND care_status='กำลังดูแล' LIMIT 1"
            );
            if ($handoffStmt) {
                mysqli_stmt_bind_param($handoffStmt, 'i', $patientId);
                mysqli_stmt_execute($handoffStmt);
                $handoffRow = mysqli_fetch_assoc(mysqli_stmt_get_result($handoffStmt));
                mysqli_stmt_close($handoffStmt);
                if ($handoffRow) {
                    $assignedCaregiverId = (int) $handoffRow['caregiver_user_id'];
                }
            }
        }
        $handoffStatus = $assignedCaregiverId ? 'รอแคร์กิฟเวอร์ประเมินต่อ' : 'รอมอบหมายแคร์กิฟเวอร์';
        $regularCaregiver = '';
        $welfare = '';
        $club = '';

        $postedAdlId = (int) ($_POST['adl_id'] ?? 0);

        if ($postedAdlId > 0) {
            /* แก้ไขรายการเดิมของหมอคนที่ล็อกอินเท่านั้น */
            $sql = "UPDATE adl_assessment SET
                        patient_id=?,
                        assessment_date=?,
                        feeding=?,
                        grooming=?,
                        transfer=?,
                        toilet_use=?,
                        mobility=?,
                        dressing=?,
                        stairs=?,
                        bathing=?,
                        bowels=?,
                        bladder=?,
                        total_score=?,
                        caregiver_user_id=?,
                        handoff_status=?,
                        regular_caregiver=NULL,
                        welfare_status=NULL,
                        club_membership=NULL,
                        caregiver_completed_at=NULL,
                        note=?
                    WHERE adl_id=? AND doctor_user_id=?";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'isiiiiiiiiiiiissii',
                    $patientId,
                    $date,
                    $vals['feeding'],
                    $vals['grooming'],
                    $vals['transfer'],
                    $vals['toilet_use'],
                    $vals['mobility'],
                    $vals['dressing'],
                    $vals['stairs'],
                    $vals['bathing'],
                    $vals['bowels'],
                    $vals['bladder'],
                    $total,
                    $assignedCaregiverId,
                    $handoffStatus,
                    $note,
                    $postedAdlId,
                    $doctorId
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = 'บันทึกผลการประเมินครั้งที่ 1 โดยหมอเรียบร้อยแล้ว คะแนนรวม ' . $total . '/20';
                    $_POST = [];
                    $editId = 0;
                    $editRecord = null;
                } else {
                    $error = 'แก้ไขข้อมูลไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);
            } else {
                $error = 'ไม่สามารถเตรียมคำสั่งแก้ไขได้: ' . mysqli_error($conn);
            }
        } else {
            /* บันทึกเวลาจริงขณะประเมินเป็นเวลาไทย ไม่พึ่ง timezone ของ MySQL */
            $savedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
            $sql = "INSERT INTO adl_assessment (
                patient_id,doctor_user_id,caregiver_user_id,assessment_date,feeding,grooming,transfer,toilet_use,mobility,dressing,stairs,bathing,bowels,bladder,total_score,
                handoff_status,note,created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'iiisiiiiiiiiiiisss',
                    $patientId,
                    $doctorId,
                    $assignedCaregiverId,
                    $date,
                    $vals['feeding'],
                    $vals['grooming'],
                    $vals['transfer'],
                    $vals['toilet_use'],
                    $vals['mobility'],
                    $vals['dressing'],
                    $vals['stairs'],
                    $vals['bathing'],
                    $vals['bowels'],
                    $vals['bladder'],
                    $total,
                    $handoffStatus,
                    $note,
                    $savedAt
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = 'บันทึกผลการประเมินครั้งที่ 1 โดยหมอเรียบร้อยแล้ว คะแนนรวม ' . $total . '/20';
                    $_POST = [];
                } else {
                    $error = 'บันทึกไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);
            } else {
                $error = 'ไม่สามารถเตรียมคำสั่งบันทึกได้: ' . mysqli_error($conn);
            }
        }
    }
}

if (
    isset($_GET['deleted'])
    && (string) $_GET['deleted'] === '1'
    && $message === ''
    && $error === ''
) {
    $message = 'ลบประวัติการประเมิน ADL เรียบร้อยแล้ว';
}

function adlGroup(int $score): string
{
    if ($score > 20)
        return 'ข้อมูล ADL รูปแบบเดิม';
    if ($score >= 12)
        return '1B1280-Special PP (กลุ่มติดสังคม)';
    if ($score >= 5)
        return '1B1281-Special PP (กลุ่มติดบ้าน)';
    return '1B1282-Special PP (กลุ่มติดเตียง)';
}

function checkedPost(string $name, string $value): string
{
    return ((string) ($_POST[$name] ?? '') === $value) ? 'checked' : '';
}

function adlThaiDate(?string $value): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string) $value;
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . (date('Y', $ts) + 543);
}

function adlThaiDateTime(?string $value): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string) $value;
    return adlThaiDate(date('Y-m-d', $ts)) . ' • ' . date('H:i', $ts) . ' น.';
}

$patientMap = [];
foreach ($patients as $patient) {
    $patientMap[(string) $patient['Patient_id']] = [
        'fullname' => (string) ($patient['Fullname'] ?? ''),
        'age' => (string) ($patient['Age'] ?? ''),
        'address' => (string) ($patient['Address'] ?? ''),
        'phone' => (string) ($patient['Phone'] ?? ''),
        'disease' => (string) ($patient['Disease'] ?? ''),
    ];
}
$selectedPatientId = (string) ($_POST['patient_id'] ?? ($_GET['patient_id'] ?? ($_GET['new_patient'] ?? '')));
$selectedPatient = $patientMap[$selectedPatientId] ?? null;

function adlOptionText(array $adlItems, string $field, $score): string
{
    if (!isset($adlItems[$field]['options'])) return '-';
    $scoreKey = (string) $score;
    foreach ($adlItems[$field]['options'] as $optionScore => $optionText) {
        if ((string) $optionScore === $scoreKey) return (string) $optionText;
    }
    return '-';
}

$adlHistoryMap = [];
$historyRes = mysqli_query($conn, "SELECT a.*, COALESCE(u.display_name, u.username, '-') AS doctor_name
    FROM adl_assessment a
    LEFT JOIN users u ON u.user_id = a.doctor_user_id
    ORDER BY a.patient_id ASC, a.assessment_date ASC, a.created_at ASC, a.adl_id ASC");
if ($historyRes) {
    while ($historyRow = mysqli_fetch_assoc($historyRes)) {
        $patientKey = (string) ($historyRow['patient_id'] ?? '');
        if ($patientKey === '') continue;
        $adlHistoryMap[$patientKey][] = $historyRow;
    }
}
if ($selectedPatientId === '' || !$selectedPatient) {
    header('Location: adl.php');
    exit;
}
$selectedHistoryRows = $adlHistoryMap[$selectedPatientId] ?? [];
$currentHistoryMonth = date('Y-m');
$historyMonth = trim((string)($_GET['month'] ?? $currentHistoryMonth));
if (!preg_match('/^\d{4}-\d{2}$/', $historyMonth)) $historyMonth = $currentHistoryMonth;
if ($historyMonth > $currentHistoryMonth) $historyMonth = $currentHistoryMonth;
$historyMonthTs = strtotime($historyMonth.'-01');
$prevHistoryMonth = date('Y-m', strtotime('-1 month', $historyMonthTs));
$nextHistoryMonth = date('Y-m', strtotime('+1 month', $historyMonthTs));
$canGoNextHistoryMonth = $historyMonth < $currentHistoryMonth;
$thaiMonthsFull=[1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$historyMonthLabel=$thaiMonthsFull[(int)date('n',$historyMonthTs)].' '.((int)date('Y',$historyMonthTs)+543);
$historyPageRows = array_values(array_filter($selectedHistoryRows,function($r) use ($historyMonth){ return substr((string)($r['assessment_date']??''),0,7)===$historyMonth; }));
$historyTotal = count($historyPageRows);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>รายละเอียดการประเมิน ADL | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.history-only-page{padding-bottom:40px}
.history-only-shell{display:grid;gap:16px}
.history-back{display:flex;justify-content:flex-start}
.history-back a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:14px;background:#fff;border:1px solid #cfe4e1;color:#286465;text-decoration:none;font-size:13px;font-weight:900;box-shadow:0 8px 18px rgba(36,108,115,.06)}
.history-card{border:1px solid #d8ebe8;border-radius:26px;background:#fff;overflow:hidden;box-shadow:0 14px 34px rgba(36,108,115,.06)}
.history-card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;background:linear-gradient(135deg,#59bfc0,#49a8aa);color:#fff}
.history-card-head h1{margin:0;font-size:21px;color:#fff}
.history-count{display:inline-flex;align-items:center;justify-content:center;min-width:82px;padding:8px 13px;border-radius:999px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);font-size:12px;font-weight:900}
.history-body{padding:18px}
.history-empty{padding:36px 16px;border:1px dashed #d9e8e5;border-radius:18px;background:#fbfefe;text-align:center;color:#718986}
.history-list{display:grid;gap:14px}
.assessment{border:1px solid #dcebe8;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfefe);overflow:hidden}
.assessment summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:17px 18px;cursor:pointer}
.assessment summary::-webkit-details-marker{display:none}
.assessment-title{font-size:21px;font-weight:900;color:#204f53}
.assessment-meta{margin-top:7px;color:#788f8b;font-size:14px;line-height:1.6}
.score{display:inline-flex;align-items:center;justify-content:center;min-width:96px;min-height:50px;padding:9px 15px;border-radius:14px;background:#eaf8f5;color:#215e58;font-size:24px;font-weight:900}
.assessment-detail{padding:0 18px 18px}
.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding-top:16px;border-top:1px dashed #d9e8e5}
.detail-field{padding:16px 16px;border:1px solid #e3efed;border-radius:13px;background:#fff}
.detail-field small{display:block;margin-bottom:6px;color:#6f8581;font-size:14px;font-weight:700}
.detail-field strong{display:block;color:#294f53;font-size:16px;line-height:1.6}
.detail-field p{margin:8px 0 0;color:#536d68;font-size:15px;line-height:1.65}.detail-score-line{display:flex;align-items:center;justify-content:space-between;gap:14px}.detail-score-line small{margin-bottom:0!important}.detail-score-line strong{white-space:nowrap;color:#1f5b60!important}.score-line-field p{margin-top:8px!important}
.detail-field.full{grid-column:1/-1}
.history-pagination{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px}.history-page-link,.history-page-current,.history-page-ellipsis{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 10px;border-radius:11px;font-size:13px;font-weight:900;text-decoration:none}.history-page-link{background:#fff;border:1px solid #d6e8e5;color:#2d6466}.history-page-link:hover{background:#f2faf9}.history-page-current{background:linear-gradient(135deg,#59bfc0,#49aaac);border:1px solid #49aaac;color:#fff;box-shadow:0 8px 18px rgba(88,191,192,.18)}.history-page-ellipsis{color:#78908b}.history-page-link.disabled{opacity:.4;pointer-events:none}
@media(max-width:760px){.history-card-head,.assessment summary{align-items:flex-start;flex-direction:column}.detail-grid{grid-template-columns:1fr}.detail-field.full{grid-column:auto}}
.adl-month-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 18px;padding:0;border:none;border-radius:0;background:transparent}.adl-month-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.adl-month-btn{display:inline-flex;align-items:center;justify-content:center;min-width:42px;min-height:42px;padding:0 14px;border:1px solid #cfe4e1;border-radius:13px;background:#fff;color:#2e6467;text-decoration:none;font-weight:900;box-shadow:0 6px 14px rgba(46,112,116,.04)}.adl-month-current{font-size:16px;font-weight:900;color:#214f54}.adl-month-picker{margin:0}.adl-month-picker select{appearance:none;-webkit-appearance:none;-moz-appearance:none;min-width:240px;height:54px;padding:0 52px 0 18px;border:1px solid #cfe4e1;border-radius:16px;background-color:#fff;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%232b3b3b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cline x1='16' y1='3' x2='16' y2='7'/%3E%3Cline x1='8' y1='3' x2='8' y2='7'/%3E%3Cline x1='3' y1='11' x2='21' y2='11'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;background-size:18px 18px;color:#244f52;font:inherit;font-size:16px;font-weight:500;outline:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,112,116,.03)}.adl-month-picker select:focus{border-color:#83cbc8;box-shadow:0 0 0 3px rgba(89,191,192,.10)}.adl-month-form{display:flex;gap:8px;align-items:center}.adl-month-form input{height:40px;border:1px solid #cfe4e1;border-radius:12px;padding:0 12px;font:inherit}.adl-month-form button{height:40px;border:0;border-radius:12px;padding:0 14px;background:#59bfc0;color:#fff;font:inherit;font-weight:900}.adl-month-table{width:100%;min-width:820px;border-collapse:collapse}.adl-month-table th,.adl-month-table td{padding:14px 12px;border-bottom:1px solid #e2efed;text-align:left}.adl-month-table th{background:#eef8f7;color:#285d60;font-size:13px}.adl-month-table td{font-size:13px;color:#34575a}.adl-score-pill{display:inline-flex;padding:7px 11px;border-radius:999px;background:#eaf8f5;color:#215e58;font-weight:900}.adl-detail-row details{margin:0}.adl-detail-row summary{cursor:pointer;font-weight:900;color:#2e6467}

/* Editorial patient header: one coherent block, typography rather than boxed fields. */
.adl-patient-overview{
    display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;
    column-gap:24px;row-gap:16px;margin:0 0 21px;padding:3px 3px 22px;
    border-bottom:1px solid #e1eeeb;background:transparent;
}
.adl-patient-main{min-width:0}
.adl-patient-kicker{display:block;margin:0 0 4px;color:#698782;font-size:12px;font-weight:700;line-height:1.6}
.adl-patient-main strong{display:block;margin:0;color:#204f53;font-size:29px;font-weight:800;line-height:1.35;letter-spacing:-.02em;overflow-wrap:anywhere}
.adl-patient-overview .adl-month-toolbar{grid-column:2;grid-row:1;align-self:center;justify-content:flex-end;margin:0}
.adl-patient-overview .thai-month-picker-toggle{
    min-width:204px;height:43px;border:1px solid #d9e9e6;border-radius:10px;
    box-shadow:none;padding:0 12px 0 14px;font-size:14px;font-weight:700;
}
.adl-patient-overview .thai-month-picker-toggle:hover{border-color:#9dccca;background:#f9fdfc}
.adl-patient-overview .thai-month-picker-icon,.adl-patient-overview .thai-month-picker-icon svg{width:17px;height:17px}
.adl-patient-tags{
    grid-column:1 / -1;display:grid;grid-template-columns:minmax(100px,.55fr) minmax(170px,.9fr) minmax(230px,1.8fr);
    column-gap:22px;row-gap:14px;max-width:1030px;min-width:0;margin:0;
}
.adl-patient-pill{display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:0;
    padding:0;border:0;border-radius:0;background:none;box-shadow:none;line-height:1.55;
}
.adl-patient-pill b{color:#718a85;font-size:12px;font-weight:600}
.adl-patient-pill .adl-patient-value{color:#244d51;font-size:15px;font-weight:750;line-height:1.55;overflow-wrap:anywhere}
.history-card-head .history-count{min-width:0;padding:0;border:none;border-radius:0;background:none;box-shadow:none;font-size:13px;font-weight:650;color:#fff}
@media(max-width:760px){
    .adl-patient-overview{grid-template-columns:1fr;gap:14px;padding-bottom:19px}
    .adl-patient-main strong{font-size:24px}
    .adl-patient-overview .adl-month-toolbar{grid-column:1;grid-row:2;justify-content:flex-start}
    .adl-patient-tags{grid-column:1;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
    .adl-patient-pill:last-child{grid-column:1 / -1}
}
@media(max-width:420px){.adl-patient-tags{grid-template-columns:1fr}.adl-patient-pill:last-child{grid-column:1}}

/* Thai month picker */
.thai-month-picker{position:relative;margin:0;z-index:60}
.thai-month-picker-toggle{display:flex;align-items:center;justify-content:space-between;gap:14px;min-width:250px;height:52px;padding:0 15px 0 17px;border:1px solid #cfe4e1;border-radius:18px;background:#fff;color:#244f52;font:inherit;font-size:16px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px rgba(46,112,116,.04)}
.thai-month-picker-toggle:focus,.thai-month-picker.open .thai-month-picker-toggle{border-color:#75c7c5;box-shadow:0 0 0 3px rgba(89,191,192,.12);outline:none}
.thai-month-picker-icon{width:19px;height:19px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto}
.thai-month-picker-icon svg{width:18px;height:18px;display:block}
.thai-month-picker-popover{position:absolute;right:0;top:calc(100% + 8px);width:320px;padding:14px;background:#fff;border:1px solid #d7e5e3;border-radius:16px;box-shadow:0 18px 44px rgba(29,68,71,.18);display:none;z-index:999}
.thai-month-picker.open .thai-month-picker-popover{display:block}
.thai-month-picker-yearbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:2px 2px 12px;border-bottom:1px solid #e6efed;margin-bottom:12px}
.thai-month-picker-year{font-size:17px;font-weight:900;color:#244f52}
.thai-month-year-controls{display:flex;gap:6px}
.thai-month-year-btn{width:32px;height:32px;border:1px solid #d8e8e5;border-radius:9px;background:#f9fcfb;color:#5d7777;font:inherit;font-weight:900;cursor:pointer}
.thai-month-year-btn:disabled{opacity:.32;cursor:not-allowed}.thai-month-year-text{width:auto;min-width:78px;padding:0 10px;font-size:11px}
.thai-month-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.thai-month-option{height:42px;border:1px solid transparent;border-radius:10px;background:transparent;color:#294f53;font:inherit;font-size:14px;font-weight:700;cursor:pointer}
.thai-month-option:hover{background:#eef8f7;border-color:#d8ebe8}
.thai-month-option.selected{background:#3f4a4d;color:#fff;border-color:#3f4a4d}
.thai-month-option:disabled{color:#b7c0bf;background:transparent;cursor:not-allowed}
.thai-month-picker-footer{display:flex;justify-content:flex-end;margin-top:12px;padding-top:10px;border-top:1px solid #e8f0ef}
.thai-month-today{border:0;background:transparent;color:#2f7e82;font:inherit;font-size:13px;font-weight:900;cursor:pointer;padding:5px 4px}
@media(max-width:620px){.thai-month-picker{width:100%}.thai-month-picker-toggle{width:100%;min-width:0}.thai-month-picker-popover{right:auto;left:0;width:min(320px,calc(100vw - 44px))}}


/* Consistent "รายละเอียดเพิ่มเติม" action */
.adl-detail-row details>summary{list-style:none!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:46px!important;padding:0 18px!important;border:1px solid #bcdedb!important;border-radius:14px!important;background:#fff!important;color:#215a60!important;font-size:13px!important;font-weight:900!important;cursor:pointer!important;white-space:nowrap!important}
.adl-detail-row details>summary::-webkit-details-marker{display:none!important}
.adl-detail-row details>summary::marker{display:none!important;content:''!important}
.adl-detail-row details>summary:hover{background:#f6fbfb!important;border-color:#9fd1cd!important}

.assessment-detail-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border:1px solid #cfe4e1;border-radius:14px;background:#fff;color:#285f63;text-decoration:none;font-size:13px;font-weight:900;box-shadow:0 5px 12px rgba(46,112,116,.04)}.assessment-detail-link:hover{background:#f3faf9}
.adl-manage-cell{text-align:center;vertical-align:middle}.adl-row-actions{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}.adl-row-actions form{margin:0}.adl-row-actions .crud-icon-button{width:42px!important;height:42px!important;min-width:42px!important;border-radius:12px!important}.adl-row-actions .crud-icon-button svg{width:22px!important;height:22px!important}.adl-action-head{min-width:120px;text-align:center}

/* Version 18: month filter and monthly entry count share one horizontal row. */
.adl-patient-overview .adl-month-toolbar{
    display:flex;align-items:center;justify-content:flex-end;
    gap:12px;flex-wrap:nowrap;min-width:0;margin:0;
}
.adl-patient-overview .adl-month-toolbar .thai-month-picker{flex:0 1 auto;min-width:0}
.adl-patient-overview .adl-month-toolbar .history-count{
    display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;
    min-width:0;padding:7px 12px;border:1px solid #d7e9e5;
    border-radius:999px;background:#f0faf8;color:#245a59;
    font-size:12px;font-weight:800;white-space:nowrap;box-shadow:none;
}
@media(max-width:760px){
    .adl-patient-overview .adl-month-toolbar{grid-column:1;grid-row:2;justify-content:flex-start;gap:8px;width:100%}
    .adl-patient-overview .adl-month-toolbar .thai-month-picker{flex:1 1 0;width:auto}
    .adl-patient-overview .thai-month-picker-toggle{min-width:0;width:100%;padding:0 10px;font-size:13px;gap:6px}
    .adl-patient-overview .adl-month-toolbar .history-count{font-size:11px;padding:7px 9px}
}

</style>
</head>
<body class="role-page history-only-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>

<div class="history-only-shell">
    <div class="history-back">
        <a href="adl.php">ย้อนกลับ</a>
    </div>

    <section class="history-card">
        <div class="history-card-head">
            <h1>รายละเอียดการประเมิน</h1>

        </div>
        <div class="history-body">
            <div class="adl-patient-overview">
                <div class="adl-patient-main">
                    <strong><?=e($selectedPatient['fullname'] ?? '-')?></strong>
                </div>
                <div class="adl-month-toolbar">
                    <form class="thai-month-picker" method="get" action="adl_history.php">
                <input type="hidden" name="patient_id" value="<?=e((string)$selectedPatientId)?>">
                <input type="hidden" name="month" value="<?=e($historyMonth)?>">
                <button type="button" class="thai-month-picker-toggle" aria-haspopup="dialog" aria-expanded="false">
                    <span class="thai-month-picker-label"><?=e($historyMonthLabel)?></span>
                    <span class="thai-month-picker-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="11" x2="21" y2="11"/></svg></span>
                </button>
                <div class="thai-month-picker-popover" role="dialog" aria-label="เลือกเดือน">
                    <div class="thai-month-picker-yearbar"><div class="thai-month-picker-year"></div><div class="thai-month-year-controls"><button type="button" class="thai-month-year-btn thai-month-year-text" data-year-prev>ปีก่อนหน้า</button><button type="button" class="thai-month-year-btn thai-month-year-text" data-year-next>ปีถัดไป</button></div></div>
                    <div class="thai-month-grid"></div>
                    <div class="thai-month-picker-footer"><button type="button" class="thai-month-today">เดือนปัจจุบัน</button></div>
                </div>
            </form>
                </div>
                <div class="adl-patient-tags">
                    <div class="adl-patient-pill"><b>อายุ</b><span class="adl-patient-value"><?=e($selectedPatient['age'] !== '' ? $selectedPatient['age'].' ปี' : '-')?></span></div>
                    <div class="adl-patient-pill"><b>เบอร์โทร</b><span class="adl-patient-value"><?=e($selectedPatient['phone'] ?: '-')?></span></div>
                    <div class="adl-patient-pill"><b>โรคประจำตัว</b><span class="adl-patient-value"><?=e($selectedPatient['disease'] ?: '-')?></span></div>
                </div>
            </div>
            <?php if (!$historyPageRows): ?>
                <div class="history-empty">ไม่มีผลการประเมินในเดือนนี้</div>
            <?php else: ?>
                <div style="overflow:auto"><table class="adl-month-table"><thead><tr><th>วันที่ประเมิน</th><th>ผู้ประเมิน</th><th>คะแนนรวม</th><th>รายละเอียด</th><th class="adl-action-head">จัดการ</th></tr></thead><tbody>
                <?php foreach($historyPageRows as $historyRow): ?>
                    <tr class="adl-detail-row"><td><?=e(adlThaiDate($historyRow['assessment_date']??''))?></td><td><?=e($historyRow['doctor_name']??'-')?></td><td><span class="adl-score-pill"><?=(int)($historyRow['total_score']??0)?>/20</span></td><td><a class="assessment-detail-link" href="doctor_adl_round_detail.php?adl_id=<?=(int)($historyRow['adl_id']??0)?>">รายละเอียดเพิ่มเติม</a></td><td class="adl-manage-cell"><div class="adl-row-actions"><a href="adl_assessment.php?patient_id=<?=(int)$selectedPatientId?>&edit=<?=(int)($historyRow['adl_id']??0)?>#adlForm">แก้ไข</a><form method="post" onsubmit="return confirm('ยืนยันการลบผลการประเมินรายการนี้ใช่หรือไม่');"><input type="hidden" name="action" value="delete_adl"><input type="hidden" name="adl_id" value="<?=(int)($historyRow['adl_id']??0)?>"><input type="hidden" name="patient_id" value="<?=(int)$selectedPatientId?>"><input type="hidden" name="month" value="<?=e($historyMonth)?>"><button type="submit">ลบ</button></form></div></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div>
    </section>
</div>
</main>

<script>
(function(){
  const THAI_MONTHS=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  const THAI_SHORT=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  function initPicker(root){
    const toggle=root.querySelector('.thai-month-picker-toggle');
    const label=root.querySelector('.thai-month-picker-label');
    const pop=root.querySelector('.thai-month-picker-popover');
    const yearEl=root.querySelector('.thai-month-picker-year');
    const grid=root.querySelector('.thai-month-grid');
    const hidden=root.querySelector('input[name="month"]');
    const prevYear=root.querySelector('[data-year-prev]');
    const nextYear=root.querySelector('[data-year-next]');
    const todayBtn=root.querySelector('.thai-month-today');
    if(!toggle||!pop||!yearEl||!grid||!hidden)return;
    const now=new Date();
    const maxY=now.getFullYear(), maxM=now.getMonth()+1;
    let [selY,selM]=(hidden.value||'').split('-').map(Number);
    if(!selY||!selM){selY=maxY;selM=maxM;}
    let viewY=selY;
    function close(){root.classList.remove('open');toggle.setAttribute('aria-expanded','false');}
    function render(){
      yearEl.textContent=String(viewY+543);
      grid.innerHTML='';
      THAI_SHORT.forEach((m,i)=>{
        const month=i+1;
        const b=document.createElement('button');b.type='button';b.className='thai-month-option';b.textContent=m;
        const future=viewY>maxY||(viewY===maxY&&month>maxM);
        b.disabled=future;
        if(viewY===selY&&month===selM)b.classList.add('selected');
        b.addEventListener('click',()=>{selY=viewY;selM=month;hidden.value=selY+'-'+String(selM).padStart(2,'0');label.textContent=THAI_MONTHS[selM-1]+' '+(selY+543);close();hidden.form.submit();});
        grid.appendChild(b);
      });
      if(nextYear) nextYear.disabled=viewY>=maxY;
    }
    toggle.addEventListener('click',e=>{e.stopPropagation();const isOpen=root.classList.toggle('open');toggle.setAttribute('aria-expanded',isOpen?'true':'false');if(isOpen){viewY=selY;render();}});
    prevYear&&prevYear.addEventListener('click',()=>{viewY--;render();});
    nextYear&&nextYear.addEventListener('click',()=>{if(viewY<maxY){viewY++;render();}});
    todayBtn&&todayBtn.addEventListener('click',()=>{selY=maxY;selM=maxM;hidden.value=selY+'-'+String(selM).padStart(2,'0');label.textContent=THAI_MONTHS[selM-1]+' '+(selY+543);close();hidden.form.submit();});
    document.addEventListener('click',e=>{if(!root.contains(e.target))close();});
    render();
  }
  document.querySelectorAll('.thai-month-picker').forEach(initPicker);
})();
</script>

</body>
</html>

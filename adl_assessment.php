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

                    header('Location: adl_assessment.php?new_patient=' . $newPatientId . '#adlForm');
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
                    header('Location: adl.php?deleted=1#adlHistory');
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
                    $message = 'บันทึกคะแนนสำเร็จ';
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
                    $message = 'บันทึกคะแนนสำเร็จ';
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
    ORDER BY a.patient_id ASC, a.assessment_date DESC, a.created_at DESC, a.adl_id DESC");
if ($historyRes) {
    while ($historyRow = mysqli_fetch_assoc($historyRes)) {
        $patientKey = (string) ($historyRow['patient_id'] ?? '');
        if ($patientKey === '') continue;
        $adlHistoryMap[$patientKey][] = $historyRow;
    }
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ประเมิน ADL | <?= e(appName()) ?></title>
    <?php renderPastelTheme(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
        .adl-paper {
            background: transparent;
            padding: 0
        }

        .adl-form-title {
            margin: 0;
            text-align: center;
            font-size: 26px;
            color: #173b34;
            font-weight: 800
        }

        .adl-form-subtitle {
            text-align: center;
            margin: 6px 0 0;
            color: #54736a;
            font-size: 14px
        }

        .adl-intro {
            margin: 12px 0 0;
            text-align: center;
            color: #48635b;
            line-height: 1.7
        }

        .adl-edit-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding: 12px 14px;
            border: 1px solid #f0d890;
            border-radius: 12px;
            background: #fff9e8;
            color: #6e5a20
        }

        .adl-edit-banner strong {
            color: #5b4717
        }

        .adl-cancel-edit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 9px 14px;
            border: 1px solid #d8e6df;
            border-radius: 10px;
            background: #fff;
            color: #41584f;
            text-decoration: none;
            font-weight: 700
        }

        .adl-shell {
            display: grid;
            gap: 18px;
            width: 100%
        }

        .adl-info-panel,
        .adl-panel {
            background: #fff;
            border: 1px solid #dcefe8;
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(34, 87, 71, .07);
            overflow: hidden
        }

        .adl-panel-head,
        .adl-info-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            background: linear-gradient(90deg, #4bcbb7 0%, #108574 100%);
            color: #fff
        }

        .adl-panel-head h3,
        .adl-info-head h3 {
            margin: 0;
            font-size: 18px;
            color: #fff
        }

        .adl-panel-step {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .2);
            font-weight: 800
        }

        .adl-info-body {
            padding: 18px
        }

        .adl-info-actions {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap
        }

        .adl-add-patient-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 8px 14px;
            border: 1px solid rgba(255, 255, 255, .55);
            border-radius: 10px;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            transition: .18s ease
        }

        .adl-add-patient-btn:hover {
            background: rgba(255, 255, 255, .24);
            transform: translateY(-1px)
        }

        .adl-patient-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 6000;
            background: rgba(22, 52, 44, .42);
            padding: 24px;
            align-items: center;
            justify-content: center
        }

        .adl-patient-modal.open {
            display: flex
        }

        .adl-patient-modal-card {
            position: relative;
            width: min(840px, 100%);
            max-height: calc(100vh - 48px);
            overflow: auto;
            background: #fff;
            border: 1px solid #d9ebe4;
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(22, 52, 44, .20)
        }

        .adl-patient-modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid #e5efeb;
            background: #f6fcf9
        }

        .adl-patient-modal-head h3 {
            margin: 0;
            color: #173b34;
            font-size: 20px
        }

        .adl-patient-modal-head p {
            margin: 4px 0 0;
            color: #71847d;
            font-size: 12px
        }

        .adl-patient-modal-close {
            width: 38px;
            height: 38px;
            border: 1px solid #d5e6df;
            border-radius: 50%;
            background: #fff;
            color: #45655b;
            font-size: 24px;
            line-height: 1;
            cursor: pointer
        }

        .adl-patient-modal-body {
            padding: 20px 22px 22px
        }

        .adl-patient-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px
        }

        .adl-patient-grid .full {
            grid-column: 1/-1
        }

        .adl-patient-modal .field label {
            font-size: 13px;
            font-weight: 700;
            color: #365c50;
            margin-bottom: 6px
        }

        .adl-patient-modal .field input,
        .adl-patient-modal .field select,
        .adl-patient-modal .field textarea {
            width: 100%;
            border: 1px solid #cfe2db;
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
            font: inherit;
            color: #173b34;
            outline: none
        }

        .adl-patient-modal .field input,
        .adl-patient-modal .field select {
            height: 44px
        }

        .adl-patient-modal .field textarea {
            min-height: 78px;
            resize: vertical
        }

        .adl-patient-modal .field input:focus,
        .adl-patient-modal .field select:focus,
        .adl-patient-modal .field textarea:focus {
            border-color: #31a98f;
            box-shadow: 0 0 0 3px rgba(49, 169, 143, .10)
        }

        .adl-patient-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #edf2ef
        }

        .adl-patient-cancel {
            min-height: 40px;
            padding: 8px 15px;
            border: 1px solid #d6e4df;
            border-radius: 10px;
            background: #fff;
            color: #4e685f;
            font: inherit;
            font-weight: 700;
            cursor: pointer
        }

        @media(max-width:700px) {
            .adl-patient-grid {
                grid-template-columns: 1fr
            }

            .adl-patient-grid .full {
                grid-column: auto
            }

            .adl-patient-modal {
                padding: 12px
            }

            .adl-info-head {
                align-items: flex-start;
                flex-wrap: wrap
            }
        }

        .adl-topline {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
            margin-bottom: 14px
        }

        .adl-header-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px
        }

        .adl-inline-field,
        .adl-readonly {
            border: 1px solid #dcefe8;
            background: #fbfefd;
            border-radius: 12px;
            padding: 12px 14px
        }

        .adl-inline-field label,
        .adl-readonly small {
            display: block;
            font-size: 13px;
            color: #54736a;
            margin-bottom: 6px;
            font-weight: 700
        }

        .adl-combobox {
            position: relative
        }

        .adl-combobox-input-wrap {
            position: relative
        }

        .adl-combobox-input {
            width: 100%;
            height: 48px;
            padding: 11px 44px 11px 14px;
            border: 1px solid #cfe2db;
            border-radius: 10px;
            background: #fff;
            font: inherit;
            color: #173b34
        }

        .adl-combobox-input:focus {
            outline: none;
            border-color: #31a98f;
            box-shadow: 0 0 0 3px rgba(49, 169, 143, .10)
        }

        .adl-combobox-arrow {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #315f50;
            font-size: 14px
        }

        .adl-combobox-list {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            z-index: 300;
            background: #fff;
            border: 1px solid #cfe2db;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(34, 87, 71, .14);
            max-height: 280px;
            overflow: auto;
            padding: 6px
        }

        .adl-combobox.open .adl-combobox-list {
            display: block
        }

        .adl-combobox-option {
            display: block;
            width: 100%;
            border: 0;
            background: #fff;
            text-align: left;
            padding: 10px 12px;
            border-radius: 8px;
            font: inherit;
            color: #173b34;
            cursor: pointer
        }

        .adl-combobox-option:hover,
        .adl-combobox-option.active {
            background: #edf9f5;
            color: #147967
        }

        .adl-combobox-empty {
            padding: 12px;
            text-align: center;
            color: #7a8d86;
            font-size: 13px
        }

        .adl-readonly strong {
            display: block;
            color: #173b34;
            font-size: 15px;
            min-height: 24px
        }

        .adl-main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 18px;
            align-items: start;
            width: 100%
        }

        .adl-panel-body {
            padding: 18px
        }

        .adl-nav-panel {
            position: sticky;
            top: 90px
        }

        .adl-nav-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px
        }

        .adl-nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 38px;
            border: 1px solid #dce7e2;
            border-radius: 10px;
            background: #fff;
            color: #45665d;
            text-decoration: none;
            font-weight: 700
        }

        .adl-nav-btn.active,
        .adl-nav-btn:hover {
            background: #e7faf5;
            border-color: #49bfa8;
            color: #147967
        }

        .adl-nav-note {
            margin-top: 12px;
            color: #738680;
            font-size: 12px;
            line-height: 1.6;
            text-align: center
        }

        .adl-question-card {
            border: 1px solid #e3efeb;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 14px;
            background: #fcfefd;
            scroll-margin-top: 100px
        }

        .adl-question-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 10px
        }

        .adl-question-card h3 {
            margin: 0;
            font-size: 18px;
            color: #173b34
        }

        .adl-question-card .thai-sub {
            margin: 6px 0 0;
            color: #516962;
            line-height: 1.6
        }

        .adl-question-tools {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            flex-wrap: wrap
        }

        .adl-edit-one,
        .adl-save-one {
            min-height: 36px;
            padding: 7px 13px;
            border-radius: 10px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap
        }

        .adl-edit-one {
            border: 1px solid #cfe4dc;
            background: #fff;
            color: #315f50
        }

        .adl-edit-one:hover {
            background: #f2faf7
        }

        .adl-save-one {
            border: 1px solid #31a98f;
            background: #31a98f;
            color: #fff
        }

        .adl-save-one:disabled {
            opacity: .45;
            cursor: not-allowed
        }

        .adl-question-card.editing,
        .adl-extra-question.editing {
            border-color: #31a98f;
            box-shadow: 0 0 0 3px rgba(49, 169, 143, .10)
        }

        .adl-one-status {
            margin-top: 8px;
            min-height: 18px;
            font-size: 12px;
            color: #6c8179
        }

        .adl-one-status.ok {
            color: #14806a
        }

        .adl-one-status.error {
            color: #b54c55
        }

        .adl-detail-editor {
            margin: 12px 0 14px;
            padding: 16px;
            border: 1px solid #cfe6de;
            border-radius: 14px;
            background: #f8fcfa
        }

        .adl-detail-editor[hidden] {
            display: none
        }

        .adl-detail-grid {
            display: grid;
            gap: 12px
        }

        .adl-detail-field label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #365e52
        }

        .adl-detail-field input,
        .adl-detail-field textarea {
            width: 100%;
            border: 1px solid #cfe2db;
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
            font: inherit;
            color: #173b34
        }

        .adl-detail-field textarea {
            min-height: 70px;
            resize: vertical
        }

        .adl-detail-field input:focus,
        .adl-detail-field textarea:focus {
            outline: none;
            border-color: #31a98f;
            box-shadow: 0 0 0 3px rgba(49, 169, 143, .10)
        }

        .adl-option-edit-row {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr);
            gap: 10px;
            align-items: start
        }

        .adl-option-score-label {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border-radius: 10px;
            background: #e9f7f2;
            color: #1c715d;
            font-weight: 800
        }

        .adl-detail-status {
            min-height: 18px;
            margin-top: 8px;
            font-size: 12px;
            color: #607c72
        }

        .adl-detail-status.ok {
            color: #14806a
        }

        .adl-detail-status.error {
            color: #b54c55
        }

        .adl-choice-list {
            display: grid;
            gap: 10px
        }

        .adl-choice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid #dcefe8;
            border-radius: 12px;
            padding: 11px 12px;
            background: #fff;
            cursor: pointer;
            transition: .18s
        }

        .adl-choice input {
            margin-top: 3px;
            accent-color: #17a589
        }

        .adl-choice:hover {
            border-color: #49bfa8;
            background: #f1fcf8
        }

        .adl-choice:has(input:checked) {
            border-color: #22a68f;
            background: #edf9f4;
            box-shadow: inset 0 0 0 1px #22a68f
        }

        .adl-score-num {
            min-width: 26px;
            font-weight: 800;
            color: #173b34
        }

        .adl-summary-wrap {
            margin-top: 18px;
            border-top: 2px solid #dcefe8;
            padding-top: 16px
        }

        .adl-total-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px
        }

        .adl-total-label {
            font-size: 20px;
            font-weight: 800;
            color: #173b34
        }

        .adl-total-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
            padding: 10px 16px;
            border: 2px solid #173b34;
            background: #fff;
            border-radius: 8px;
            font-size: 28px;
            font-weight: 800;
            color: #173b34
        }

        .adl-total-unit {
            font-size: 18px;
            font-weight: 700;
            color: #173b34
        }

        .adl-group-list {
            display: grid;
            gap: 10px
        }

        .adl-group-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #dcefe8;
            border-radius: 12px;
            background: #fff
        }

        .adl-group-check {
            width: 22px;
            height: 22px;
            border: 2px solid #173b34;
            border-radius: 4px;
            display: inline-grid;
            place-items: center;
            font-size: 15px;
            font-weight: 700;
            line-height: 1;
            color: transparent;
            background: #fff;
            flex: 0 0 22px;
            margin-top: 2px
        }

        .adl-group-item.active {
            background: #edf9f4;
            border-color: #22a68f
        }

        .adl-group-item.active .adl-group-check {
            color: #173b34;
            background: #dcf7ed
        }

        .adl-extra-title {
            text-align: left;
            font-size: 20px;
            font-weight: 800;
            color: #173b34;
            margin: 18px 0 14px
        }

        .adl-extra-box {
            display: grid;
            gap: 14px;
            border: 0;
            border-radius: 0;
            padding: 0;
            background: transparent
        }

        .adl-extra-question {
            margin: 0;
            border: 1px solid #dcefe8;
            border-radius: 16px;
            padding: 16px;
            background: #fff
        }

        .adl-extra-question h4 {
            margin: 0 0 12px;
            font-size: 17px;
            color: #173b34
        }

        .adl-extra-options {
            display: flex;
            flex-wrap: wrap;
            gap: 18px 24px
        }

        .adl-extra-option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            color: #173b34
        }

        .adl-extra-option input {
            accent-color: #17a589;
            margin-top: 2px
        }

        .adl-note-wrap {
            margin-top: 18px
        }

        .adl-note-wrap textarea {
            min-height: 90px
        }

        .adl-submit-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px
        }

        .adl-global-edit-btn {
            border: 1px solid #b9ddd3;
            background: #fff;
            color: #286553;
            font-weight: 700
        }

        .adl-global-edit-btn:hover {
            background: #f1faf7
        }

        .adl-global-edit-btn.is-editing {
            background: #31a98f;
            border-color: #31a98f;
            color: #fff
        }

        .adl-record-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap
        }

        .adl-record-actions form {
            margin: 0
        }

        .adl-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 7px 12px;
            border: 1px solid #f0d28a;
            border-radius: 10px;
            background: #fff8dc;
            color: #725814;
            text-decoration: none;
            font-weight: 700;
            white-space: nowrap
        }

        .adl-edit-btn:hover {
            background: #fff1b8
        }

        .adl-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 7px 12px;
            border: 1px solid #efb9bd;
            border-radius: 10px;
            background: #fff1f1;
            color: #a8464c;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: .18s ease
        }

        .adl-delete-btn:hover {
            background: #ffe5e6;
            border-color: #e59da3
        }

        .adl-record-title {
            margin-top: 0
        }

        @media(max-width:1100px) {
            .adl-main-grid {
                grid-template-columns: 1fr
            }

            .adl-nav-panel {
                position: static
            }

            .adl-nav-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr))
            }
        }

        @media(max-width:900px) {

            .adl-topline,
            .adl-header-grid {
                grid-template-columns: 1fr
            }

            .adl-form-title {
                font-size: 22px
            }

            .adl-total-box {
                min-width: 96px;
                font-size: 24px
            }

            .adl-extra-options {
                display: grid;
                gap: 10px
            }
        }

        @media(max-width:640px) {
            .adl-nav-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr))
            }

            .adl-panel-head,
            .adl-info-head {
                padding: 12px 14px
            }

            .adl-info-body,
            .adl-panel-body {
                padding: 14px
            }
        }
    
/* ===== Premium Doctor ADL Assessment Refresh ===== */
body.role-page .main{max-width:none!important;padding:0 34px 48px!important}
body.role-page .alert{border-radius:14px!important;padding:13px 16px!important;box-shadow:0 6px 18px rgba(36,108,115,.04)!important}
body.role-page .alert-ok{background:linear-gradient(90deg,#eefaf5 0%,#f5fcfa 100%)!important;border:1px solid #cfe9dc!important;color:#2f6757!important}
.adl-paper{max-width:1500px;margin:0 auto!important}
.adl-shell{gap:22px!important}
.adl-shell>div:first-child{
    position:relative;overflow:hidden;background:linear-gradient(135deg,#effaf8 0%,#f5fbfd 100%);
    border:1px solid #d8ece9;border-radius:26px;padding:30px 28px 26px;
    box-shadow:0 18px 44px rgba(36,108,115,.07)
}
.adl-shell>div:first-child:before{content:'';position:absolute;left:0;top:0;width:100%;height:5px;background:linear-gradient(90deg,#58bfc0 0%,#8bd8d2 55%,#9edce7 100%)}
.adl-form-title{font-size:34px!important;letter-spacing:-.4px!important;color:#1b4f55!important}
.adl-form-subtitle{margin-top:8px!important;font-size:15px!important;color:#668287!important}
.adl-intro{max-width:850px;margin:14px auto 0!important;font-size:14px!important;color:#607a7d!important;line-height:1.75!important}
.adl-info-panel,.adl-panel{border:1px solid #d9ebe8!important;border-radius:22px!important;box-shadow:0 14px 34px rgba(36,108,115,.06)!important;background:#fff!important}
.adl-info-head,.adl-panel-head{
    padding:17px 22px!important;background:linear-gradient(90deg,#58bfc0 0%,#4fb8b7 52%,#3f9fa5 100%)!important;
    border-bottom:1px solid rgba(255,255,255,.28)
}
.adl-info-head h3,.adl-panel-head h3{font-size:20px!important;letter-spacing:-.1px!important}
.adl-info-body,.adl-panel-body{padding:22px!important}
.adl-topline{gap:16px!important;margin-bottom:16px!important}
.adl-inline-field{background:#fbfefe;border:1px solid #e0efed;border-radius:18px;padding:15px 16px}
.adl-inline-field label{font-size:13px!important;color:#315d60!important;margin-bottom:8px!important}
.adl-combobox-input,.adl-inline-field input[type=date]{height:52px!important;border-radius:14px!important;border:1px solid #d4e8e5!important;background:#fff!important;box-shadow:0 6px 18px rgba(36,108,115,.035)!important}
.adl-combobox-input:focus,.adl-inline-field input[type=date]:focus{border-color:#72c9c7!important;box-shadow:0 0 0 4px rgba(88,191,192,.12)!important}
.adl-header-grid{gap:14px!important}
.adl-readonly{background:linear-gradient(180deg,#fbfefe 0%,#f7fbfb 100%)!important;border:1px solid #dfeeea!important;border-radius:16px!important;padding:15px 16px!important;min-height:78px;box-shadow:0 5px 14px rgba(36,108,115,.025)}
.adl-readonly small{display:block!important;font-size:12px!important;font-weight:800!important;color:#728b87!important;margin-bottom:7px!important}
.adl-readonly strong{font-size:16px!important;color:#214e50!important}
.adl-panel-step{min-width:76px!important;padding:7px 13px!important;background:rgba(255,255,255,.22)!important;border:1px solid rgba(255,255,255,.30)!important}
.adl-view-toolbar{display:flex!important;justify-content:flex-start!important;align-items:center!important;gap:12px!important;margin:0 0 16px!important;flex-wrap:wrap!important}
.adl-mode-switch{display:inline-flex!important;align-items:center!important;gap:10px!important;padding:8px!important;border-radius:18px!important;background:#eef8f7!important;border:1px solid #d7eae7!important;box-shadow:0 8px 18px rgba(36,108,115,.06)!important}
.adl-mode-btn{border:1px solid transparent!important;background:transparent!important;color:#3b6767!important;min-height:44px!important;padding:0 20px!important;border-radius:12px!important;font:inherit!important;font-weight:900!important;cursor:pointer!important;transition:.18s ease!important;user-select:none!important}.adl-mode-btn:hover{background:#fff!important;border-color:#d6e9e6!important;transform:translateY(-1px)!important}
.adl-mode-btn.is-active{background:linear-gradient(135deg,#58bfc0,#4baeb0)!important;color:#fff!important;box-shadow:0 10px 20px rgba(88,191,192,.20)!important}
.adl-panel-label{font-size:17px!important;font-weight:800!important;color:#214f55!important}
.adl-history-list{display:grid!important;gap:14px!important}.adl-history-card{border:1px solid #dcecea!important;border-radius:18px!important;background:linear-gradient(180deg,#fff 0%,#fcfefe 100%)!important;box-shadow:0 7px 20px rgba(36,108,115,.035)!important;overflow:hidden!important}.adl-history-card[open]{border-color:#c8e7e2!important;box-shadow:0 12px 24px rgba(36,108,115,.06)!important}
.adl-history-summary{list-style:none!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:14px!important;padding:16px 18px!important;cursor:pointer!important}.adl-history-summary::-webkit-details-marker{display:none!important}.adl-history-title{margin:0!important;font-size:17px!important;color:#1f5251!important;font-weight:800!important}.adl-history-meta{margin-top:6px!important;color:#7b908c!important;font-size:12px!important;line-height:1.6!important}.adl-history-score{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:92px!important;min-height:46px!important;padding:8px 14px!important;border-radius:14px!important;background:linear-gradient(135deg,#e8f8f5,#f3fbfb)!important;box-shadow:inset 0 0 0 1px #cce8e3!important;color:#1e5d5c!important;font-size:22px!important;font-weight:900!important}
.adl-history-body{padding:0 18px 18px!important}.adl-history-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important}.adl-history-field{padding:12px 13px!important;border:1px solid #e3efed!important;border-radius:13px!important;background:#fff!important}.adl-history-field small{display:block!important;color:#7a908d!important;font-size:11px!important;margin-bottom:5px!important}.adl-history-field strong{display:block!important;color:#294f53!important;font-size:13px!important;line-height:1.55!important;font-weight:800!important}.adl-history-field p{margin:6px 0 0!important;color:#5e7570!important;font-size:12px!important;line-height:1.55!important}.adl-history-field.full{grid-column:1 / -1!important}
.adl-question-card{border:1px solid #dcecea!important;border-radius:18px!important;padding:18px!important;background:linear-gradient(180deg,#fff 0%,#fcfefe 100%)!important;box-shadow:0 7px 20px rgba(36,108,115,.035)!important;margin-bottom:14px!important;transition:.16s ease}
.adl-question-card:hover{border-color:#c7e5e1!important;box-shadow:0 12px 24px rgba(36,108,115,.07)!important;transform:translateY(-1px)}
.adl-question-card h3{font-size:17px!important;color:#1f5251!important}
.adl-question-card .thai-sub{color:#7b908c!important}
.adl-choice{border-radius:13px!important;border:1px solid #deece9!important;padding:12px 13px!important;background:#fff!important}
.adl-choice:hover{background:#f4fbfa!important;border-color:#bfe2dc!important}
.adl-choice:has(input:checked){background:#edf9f7!important;border-color:#61c2bd!important;box-shadow:inset 0 0 0 1px #61c2bd!important}
.adl-score-num{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:9px;background:#eef8f6;color:#2f716b!important}
.adl-total-box{border:0!important;border-radius:14px!important;background:linear-gradient(135deg,#e8f8f5,#f3fbfb)!important;color:#1e5d5c!important;box-shadow:inset 0 0 0 1px #cce8e3!important}
.adl-group-item{border-radius:14px!important;background:#fbfefe!important}
.adl-group-item.active{background:#edf9f5!important;border-color:#6bc7b6!important}
.adl-submit-row .btn-primary,.adl-submit-row button[type=submit]{border-radius:14px!important;padding:12px 20px!important;background:linear-gradient(135deg,#58bfc0,#479fa4)!important;color:#fff!important;box-shadow:0 10px 22px rgba(88,191,192,.18)!important}
@media(max-width:900px){body.role-page .main{padding:0 16px 32px!important}.adl-shell>div:first-child{padding:22px 18px!important;border-radius:22px}.adl-form-title{font-size:27px!important}.adl-info-body,.adl-panel-body{padding:16px!important}.adl-history-grid{grid-template-columns:1fr!important}.adl-history-summary{align-items:flex-start!important;flex-direction:column!important}.adl-history-score{min-width:84px!important}}


.adl-page-back-wrap{display:flex;justify-content:flex-start;margin:0 0 16px}
.adl-page-back{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:14px;background:#fff;border:1px solid #cfe4e1;color:#286465;text-decoration:none;font-weight:900;box-shadow:0 8px 18px rgba(36,108,115,.06)}
.adl-page-back:hover{background:#f5fbfa}

/* หน้าประเมินแบบกระชับ: แสดงเฉพาะ 10 ข้อและปุ่มรวมคะแนน */
.adl-page-back-wrap,
.adl-shell > div:first-child,
.adl-info-panel,
.adl-view-toolbar,
.adl-panel-head,
.adl-summary-wrap,
.adl-note-wrap,
.adl-global-edit-btn,
#adlHistoryPane{display:none!important}
.adl-shell{display:block!important}
.adl-main-grid{display:block!important;margin-top:0!important}
.adl-panel{border:0!important;box-shadow:none!important;background:transparent!important}
.adl-panel-body{padding:0!important}
.adl-question-card{margin-bottom:14px!important}
.adl-submit-row{display:flex!important;justify-content:center!important;margin-top:18px!important}
.adl-submit-row .btn-primary{min-width:180px!important;font-size:15px!important}

.adl-success-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(21,55,58,.28);backdrop-filter:blur(3px)}
.adl-success-modal[hidden]{display:none!important}.adl-success-card{width:min(420px,100%);padding:30px 26px;border-radius:26px;background:#fff;border:1px solid #d7ebe8;box-shadow:0 24px 70px rgba(25,79,83,.22);text-align:center}.adl-success-icon{width:68px;height:68px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#5ac3bd,#4aadae);color:#fff;font-size:34px;font-weight:900}.adl-success-card h3{margin:0;color:#1d5054;font-size:25px}.adl-success-card p{margin:10px 0 22px;color:#718985;font-size:14px;line-height:1.65}.adl-success-ok{min-width:140px;min-height:44px;border:0;border-radius:14px;background:linear-gradient(135deg,#58bfc0,#49aaac);color:#fff;font:inherit;font-weight:900;cursor:pointer;box-shadow:0 10px 22px rgba(88,191,192,.22)}
</style>
</head>

<body class="role-page">
    <?php renderSidebar(); ?>
    <main class="main">
        <?php renderUserTopbar(); ?>

        <?php if ($message): ?>
            <div class="alert alert-ok"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <div class="adl-page-back-wrap">
            <a class="adl-page-back" href="adl.php">กลับหน้าหลักการประเมิน</a>
        </div>

        <section>
            <?php if (!$patients): ?>
                <div class="alert alert-info">ยังไม่มีข้อมูลผู้สูงอายุในระบบ จึงยังไม่สามารถทำแบบประเมินได้</div>
            <?php else: ?>
                <form method="post" action="adl_assessment.php" id="adlForm" class="adl-paper">
                    <input type="hidden" name="adl_id" value="<?= (int) ($_POST['adl_id'] ?? 0) ?>">
                    <input type="hidden" name="patient_id" value="<?= e($selectedPatientId) ?>">
                    <input type="hidden" name="assessment_date" value="<?= e((string) ($_POST['assessment_date'] ?? date('Y-m-d'))) ?>">

                    <?php if ((int) ($_POST['adl_id'] ?? 0) > 0): ?>
                        <div class="adl-edit-banner">
                            <div>
                                <strong>กำลังแก้ไขผลประเมินเดิม</strong>
                                <div>แก้ไขคะแนนหรือข้อมูลในข้อที่ต้องการ แล้วกดบันทึกการแก้ไข</div>
                            </div>
                            <a class="adl-cancel-edit" href="adl_assessment.php">ยกเลิกการแก้ไข</a>
                        </div>
                    <?php endif; ?>

                    <div class="adl-shell">
                        <div style="text-align:center;">
                            <h2 class="adl-form-title">แบบประเมินคัดกรอง Barthel ADL</h2>

                            <div class="adl-form-subtitle">
                                ความสามารถในการดำเนินชีวิตประจำวัน ดัชนีบาร์เธลเอดีแอล (Barthel ADL index)
                            </div>

                            <p class="adl-intro">
                                กรุณากรอกข้อมูลและเลือกคะแนนให้ตรงตามแบบฟอร์มที่ส่งมา
                                จากนั้นระบบจะรวมคะแนนอัตโนมัติและจัดกลุ่มผลประเมินให้ทันที
                            </p>
                        </div>

                        <section class="adl-info-panel">
                            <div class="adl-info-head">
                                <h3>ข้อมูลผู้สูงอายุ</h3>
                            </div>
                            <div class="adl-info-body">
                                <div class="adl-topline">
                                    <div class="field adl-inline-field">
                                        <label>ชื่อ-สกุลผู้สูงอายุ <span class="required-star" style="color:#d93025!important">*</span></label>

                                        <div class="adl-combobox" id="patientCombobox">
                                            <div class="adl-combobox-input-wrap">
                                                <input type="text" id="patientSearchSelect" class="adl-combobox-input"
                                                    placeholder="-- ค้นหาและเลือกผู้สูงอายุ --" autocomplete="off" required
                                                    value="<?= e($selectedPatient ? (($selectedPatient['fullname'] ?? '') . (($selectedPatient['age'] ?? '') !== '' ? ' (' . $selectedPatient['age'] . ' ปี)' : '')) : '') ?>">
                                                
                                            </div>

                                            <input type="hidden" id="patient_id"
                                                value="<?= e($selectedPatientId) ?>">

                                            <div class="adl-combobox-list" id="patientComboList">
                                                <?php foreach ($patients as $p): ?>
                                                    <?php
                                                    $patientLabel = (string) $p['Fullname'];
                                                    if (!empty($p['Age'])) {
                                                        $patientLabel .= ' (' . (int) $p['Age'] . ' ปี)';
                                                    }
                                                    ?>
                                                    <button type="button" class="adl-combobox-option"
                                                        data-patient-id="<?= (int) $p['Patient_id'] ?>"
                                                        data-patient-label="<?= e($patientLabel) ?>"><?= e($patientLabel) ?></button>
                                                <?php endforeach; ?>

                                                <div class="adl-combobox-empty" id="patientComboEmpty" hidden>
                                                    ไม่พบรายชื่อผู้สูงอายุ
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field adl-inline-field">
                                        <label>วันที่ประเมิน <span class="required-star" style="color:#d93025!important">*</span></label>
                                        <input type="date" data-field="assessment_date"
                                            value="<?= e((string) ($_POST['assessment_date'] ?? date('Y-m-d'))) ?>" required>
                                    </div>
                                </div>

                                <div class="adl-header-grid" id="adlPatientDetails" <?= $selectedPatient ? '' : 'hidden' ?>>
                                    <div class="adl-readonly">
                                        <small>ชื่อ-สกุล</small>
                                        <strong id="adlPatientName"><?= e($selectedPatient['fullname'] ?? '-') ?></strong>
                                    </div>
                                    <div class="adl-readonly">
                                        <small>อายุ</small>
                                        <strong
                                            id="adlPatientAge"><?= e(($selectedPatient['age'] ?? '') !== '' ? $selectedPatient['age'] . ' ปี' : '-') ?></strong>
                                    </div>
                                    <div class="adl-readonly">
                                        <small>ที่อยู่ / บ้านเลขที่</small>
                                        <strong id="adlPatientAddress"><?= e($selectedPatient['address'] ?? '-') ?></strong>
                                    </div>
                                    <div class="adl-readonly">
                                        <small>เบอร์โทร</small>
                                        <strong id="adlPatientPhone"><?= e($selectedPatient['phone'] ?? '-') ?></strong>
                                    </div>
                                </div>
                                <div class="alert alert-info" id="adlSelectHint" <?= $selectedPatient ? 'hidden' : '' ?> style="margin-top:14px">กรุณาค้นหาและเลือกผู้สูงอายุก่อน ระบบจะแสดงข้อมูลผู้สูงอายุและแบบประเมิน ADL ด้านล่าง</div>
                            </div>
                        </section>

                        <div class="adl-main-grid" id="adlAssessmentContent" <?= $selectedPatient ? '' : 'hidden' ?>>
                            <div>
                                <div class="adl-view-toolbar">
                                    <div class="adl-mode-switch">
                                        <a id="adlFormLink" class="adl-mode-btn is-active" href="adl_assessment.php">การประเมิน</a>
                                        <a id="adlHistoryLink" class="adl-mode-btn" href="adl_history.php">ดูการประเมิน</a>
                                    </div>
                                </div>

                                <div id="adlFormPane">
                                <section class="adl-panel">
                                    <div class="adl-panel-head">
                                        <div class="adl-panel-label">การประเมิน ADL</div>
                                        <div class="adl-panel-step"><span id="adlAnswered">0</span> /
                                            <?= count($adlItems) ?></div>
                                    </div>
                                    <div class="adl-panel-body">
                                        <?php $questionIndex = 0;
                                        foreach ($adlItems as $key => $item):
                                            $questionIndex++; ?>
                                            <div class="adl-question-card" id="question-<?= $questionIndex ?>">
                                                <div class="adl-question-top">
                                                    <div>
                                                        <h3 data-question-title="<?= e($key) ?>"><?= e($item['title']) ?></h3>
                                                        <p class="thai-sub" data-question-subtitle="<?= e($key) ?>">
                                                            <?= $item['subtitle'] !== '' ? '(' . e($item['subtitle']) . ')' : '' ?>
                                                        </p>
                                                    </div>

                                                    <?php if ((int) ($_POST['adl_id'] ?? 0) > 0): ?>
                                                        <div class="adl-question-tools">
                                                            <button type="button" class="adl-edit-one"
                                                                data-edit-field="<?= e($key) ?>">แก้ไขคำตอบ</button>
                                                            <button type="button" class="adl-save-one"
                                                                data-save-field="<?= e($key) ?>" disabled>บันทึกคำตอบ</button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="adl-detail-editor" data-detail-editor="<?= e($key) ?>" hidden>
                                                    <div class="adl-detail-grid">
                                                        <div class="adl-detail-field">
                                                            <label>ชื่อข้อ</label>
                                                            <input type="text" data-detail-title="<?= e($key) ?>"
                                                                value="<?= e($item['title']) ?>">
                                                        </div>

                                                        <div class="adl-detail-field">
                                                            <label>คำอธิบาย / รายละเอียดของข้อ</label>
                                                            <textarea
                                                                data-detail-subtitle="<?= e($key) ?>"><?= e($item['subtitle']) ?></textarea>
                                                        </div>

                                                        <div class="adl-detail-field">
                                                            <label>รายละเอียดตัวเลือก</label>

                                                            <?php foreach ($item['options'] as $detailScore => $detailText): ?>
                                                                <div class="adl-option-edit-row">
                                                                    <div class="adl-option-score-label"><?= (int) $detailScore ?>
                                                                        คะแนน</div>
                                                                    <textarea data-detail-option="<?= e($key) ?>"
                                                                        data-detail-score="<?= (int) $detailScore ?>"><?= e($detailText) ?></textarea>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>

                                                    <div class="adl-detail-status" data-detail-status="<?= e($key) ?>"></div>
                                                </div>

                                                <div class="adl-choice-list">
                                                    <?php foreach ($item['options'] as $score => $text): ?>
                                                        <label class="adl-choice">
                                                            <input type="radio" name="<?= e($key) ?>" value="<?= (int) $score ?>"
                                                                <?= ((string) ($_POST[$key] ?? '') === (string) $score) ? 'checked' : '' ?>             <?= ((int) ($_POST['adl_id'] ?? 0) > 0) ? 'disabled' : 'required' ?>>
                                                            <span class="adl-score-num"><?= (int) $score ?>.</span>
                                                            <span data-option-text="<?= e($key) ?>"
                                                                data-option-score="<?= (int) $score ?>"><?= e($text) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if ((int) ($_POST['adl_id'] ?? 0) > 0): ?>
                                                    <div class="adl-one-status" data-status-field="<?= e($key) ?>"></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="adl-summary-wrap">
                                            <div class="adl-total-row">
                                                <div class="adl-total-label">คะแนนการประเมินครั้งที่ 1 โดยหมอ</div>
                                                <div class="adl-total-box"><span id="adlTotal">0</span></div>
                                                <div class="adl-total-unit">คะแนน</div>
                                            </div>
                                        </div>

                                        <div class="field adl-note-wrap">
                                            <label>หมายเหตุ</label>
                                            <textarea data-field="note"
                                                placeholder="บันทึกข้อมูลเพิ่มเติม (ถ้ามี)"><?= e((string) ($_POST['note'] ?? '')) ?></textarea>
                                        </div>
                                        <div class="adl-submit-row">
                                            <?php if ((int) ($_POST['adl_id'] ?? 0) > 0): ?>
                                                <a class="adl-cancel-edit" href="adl_assessment.php">เสร็จสิ้นการแก้ไข</a>
                                            <?php else: ?>
                                                <button class="btn btn-primary" type="submit">รวมคะแนน</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>
                                </div>

                                <div id="adlHistoryPane" hidden>
                                    <section class="adl-panel">
                                        <div class="adl-panel-head">
                                            <div class="adl-panel-label">รายละเอียดการประเมินทั้งหมด</div>
                                            <div class="adl-panel-step"><span id="adlHistoryCount">0</span> รายการ</div>
                                        </div>
                                        <div class="adl-panel-body">
                                            <div id="adlHistoryEmpty" class="alert alert-info">ยังไม่มีประวัติการประเมินของผู้สูงอายุคนนี้</div>
                                            <div id="adlHistoryList" class="adl-history-list" hidden></div>
                                            <div id="adlHistoryStore" hidden>
                                                <?php foreach ($adlHistoryMap as $historyPatientId => $historyRows): ?>
                                                    <div data-history-patient="<?= (int) $historyPatientId ?>" data-history-count="<?= count($historyRows) ?>">
                                                        <?php foreach ($historyRows as $historyIndex => $historyRow): ?>
                                                            <?php $historyNumber = count($historyRows) - $historyIndex; ?>
                                                            <details class="adl-history-card" <?= $historyIndex === 0 ? 'open' : '' ?>>
                                                                <summary class="adl-history-summary">
                                                                    <div>
                                                                        <div class="adl-history-title">การประเมินครั้งที่ <?= (int) $historyNumber ?></div>
                                                                        <div class="adl-history-meta">วันที่ประเมิน <?= e(adlThaiDate($historyRow['assessment_date'] ?? '')) ?> • ผู้ประเมิน <?= e($historyRow['doctor_name'] ?? '-') ?></div>
                                                                    </div>
                                                                    <div class="adl-history-score"><?= (int) ($historyRow['total_score'] ?? 0) ?>/20</div>
                                                                </summary>
                                                                <div class="adl-history-body">
                                                                    <div class="adl-history-grid">
                                                                        <?php foreach ($adlItems as $historyField => $historyItem): ?>
                                                                            <div class="adl-history-field">
                                                                                <small><?= e($historyItem['title']) ?></small>
                                                                                <strong><?= (int) ($historyRow[$historyField] ?? 0) ?> คะแนน</strong>
                                                                                <p><?= e(adlOptionText($adlItems, $historyField, (string) ($historyRow[$historyField] ?? ''))) ?></p>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                        <div class="adl-history-field"><small>ผู้ดูแลประจำ</small><strong><?= e($historyRow['regular_caregiver'] ?? '-') ?></strong></div>
                                                                        <div class="adl-history-field"><small>สิทธิ/สวัสดิการ</small><strong><?= e($historyRow['welfare_status'] ?? '-') ?></strong></div>
                                                                        <div class="adl-history-field"><small>สมาชิกชมรม</small><strong><?= e($historyRow['club_membership'] ?? '-') ?></strong></div>
                                                                        <div class="adl-history-field"><small>บันทึกเมื่อ</small><strong><?= e(adlThaiDateTime($historyRow['created_at'] ?? '')) ?></strong></div>
                                                                        <div class="adl-history-field full"><small>หมายเหตุ</small><strong><?= nl2br(e($historyRow['note'] ?? '-')) ?></strong></div>
                                                                    </div>
                                                                </div>
                                                            </details>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <div class="adl-patient-modal<?= $addPatientModalOpen ? ' open' : '' ?>" id="addPatientModal"
            aria-hidden="<?= $addPatientModalOpen ? 'false' : 'true' ?>">
            <div class="adl-patient-modal-card" role="dialog" aria-modal="true" aria-labelledby="addPatientTitle">
                <div class="adl-patient-modal-head">
                    <div>
                        <h3 id="addPatientTitle">เพิ่มข้อมูลผู้สูงอายุ</h3>
                        <p>กรอกข้อมูลพื้นฐานเพื่อเพิ่มรายชื่อและประเมิน ADL ต่อได้ทันที</p>
                    </div>
                    <button type="button" class="adl-patient-modal-close" id="closeAddPatientModal"
                        aria-label="ปิด">×</button>
                </div>

                <form method="post" class="adl-patient-modal-body" id="addPatientForm">
                    <input type="hidden" name="action" value="add_patient">

                    <div class="adl-patient-grid">
                        <div class="field">
                            <label>ชื่อ <span class="required-star" style="color:#d93025!important">*</span></label>
                            <input type="text" name="new_firstname" required
                                value="<?= e((string) ($_POST['new_firstname'] ?? '')) ?>" placeholder="กรอกชื่อ">
                        </div>

                        <div class="field">
                            <label>นามสกุล <span class="required-star" style="color:#d93025!important">*</span></label>
                            <input type="text" name="new_lastname" required
                                value="<?= e((string) ($_POST['new_lastname'] ?? '')) ?>" placeholder="กรอกนามสกุล">
                        </div>

                        <div class="field">
                            <label>เพศ <span class="required-star" style="color:#d93025!important">*</span></label>
                            <select name="new_gender" required>
                                <option value="">-- เลือกเพศ --</option>
                                <option value="ชาย" <?= (($_POST['new_gender'] ?? '') === 'ชาย') ? 'selected' : '' ?>>ชาย
                                </option>
                                <option value="หญิง" <?= (($_POST['new_gender'] ?? '') === 'หญิง') ? 'selected' : '' ?>>
                                    หญิง</option>
                            </select>
                        </div>

                        <div class="field">
                            <label>อายุ <span class="required-star" style="color:#d93025!important">*</span></label>
                            <input type="number" name="new_age" min="1" max="150" required
                                value="<?= e((string) ($_POST['new_age'] ?? '')) ?>" placeholder="กรอกอายุ">
                        </div>

                        <div class="field">
                            <label>น้ำหนัก (กก.)</label>
                            <input type="number" name="new_weight_kg" min="1" max="500" step="0.01"
                                value="<?= e((string) ($_POST['new_weight_kg'] ?? '')) ?>" placeholder="กรอกน้ำหนัก">
                        </div>

                        <div class="field">
                            <label>ส่วนสูง (ซม.)</label>
                            <input type="number" name="new_height_cm" min="1" max="300" step="0.01"
                                value="<?= e((string) ($_POST['new_height_cm'] ?? '')) ?>" placeholder="กรอกส่วนสูง">
                        </div>

                        <div class="field">
                            <label>หมู่บ้าน <span class="required-star" style="color:#d93025!important">*</span></label>
                            <select name="new_village_id" required>
                                <option value="">-- เลือกหมู่บ้าน --</option>
                                <?php foreach ($villages as $v): ?>
                                    <?php
                                    $villageLabel = trim((string) ($v['villagename'] ?? ''));
                                    if (!empty($v['district']))
                                        $villageLabel .= ' • ' . $v['district'];
                                    ?>
                                    <option value="<?= (int) $v['village_id'] ?>" <?= ((string) ($_POST['new_village_id'] ?? '') === (string) $v['village_id']) ? 'selected' : '' ?>><?= e($villageLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>เบอร์โทร</label>
                            <input type="text" name="new_phone" maxlength="10" inputmode="numeric"
                                value="<?= e((string) ($_POST['new_phone'] ?? '')) ?>" placeholder="เช่น 0812345678">
                        </div>

                        <div class="field full">
                            <label>ที่อยู่ / บ้านเลขที่</label>
                            <textarea name="new_address"
                                placeholder="กรอกที่อยู่เพิ่มเติม (ถ้ามี)"><?= e((string) ($_POST['new_address'] ?? '')) ?></textarea>
                        </div>

                        <div class="field full">
                            <label>โรคประจำตัว</label>
                            <input type="text" name="new_disease"
                                value="<?= e((string) ($_POST['new_disease'] ?? '')) ?>"
                                placeholder="เช่น ความดันโลหิตสูง, เบาหวาน">
                        </div>
                    </div>

                    <div class="adl-patient-modal-actions">
                        <button type="button" class="adl-patient-cancel" id="cancelAddPatientModal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกผู้สูงอายุ</button>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <script>
        (function () {
            const totalEl = document.getElementById('adlTotal');
            const scoreNames = <?= json_encode(array_keys($adlItems), JSON_UNESCAPED_UNICODE) ?>;
            const patientMap = <?= json_encode($patientMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const patientSelect = document.getElementById('patient_id');
            const patientCombobox = document.getElementById('patientCombobox');
            const patientSearchSelect = document.getElementById('patientSearchSelect');
            const patientComboList = document.getElementById('patientComboList');
            const patientComboEmpty = document.getElementById('patientComboEmpty');
            const patientOptions = Array.from(document.querySelectorAll('.adl-combobox-option'));
            const patientDetails = document.getElementById('adlPatientDetails');
            const assessmentContent = document.getElementById('adlAssessmentContent');
            const selectHint = document.getElementById('adlSelectHint');
            const adlFormPane = document.getElementById('adlFormPane');
            const adlHistoryPane = document.getElementById('adlHistoryPane');
            const adlHistoryStore = document.getElementById('adlHistoryStore');
            const adlHistoryList = document.getElementById('adlHistoryList');
            const adlHistoryEmpty = document.getElementById('adlHistoryEmpty');
            const adlHistoryCount = document.getElementById('adlHistoryCount');
            const adlViewButtons = Array.from(document.querySelectorAll('[data-adl-view]'));
            const adlFormLink = document.getElementById('adlFormLink');
            const adlHistoryLink = document.getElementById('adlHistoryLink');
            let currentAdlView = 'form';
            const nameEl = document.getElementById('adlPatientName');
            const addPatientModal = document.getElementById('addPatientModal');
            const openAddPatientModal = document.getElementById('openAddPatientModal');
            const closeAddPatientModal = document.getElementById('closeAddPatientModal');
            const cancelAddPatientModal = document.getElementById('cancelAddPatientModal');

            function showAddPatientModal() {
                if (!addPatientModal) return;
                addPatientModal.classList.add('open');
                addPatientModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function hideAddPatientModal() {
                if (!addPatientModal) return;
                addPatientModal.classList.remove('open');
                addPatientModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            if (openAddPatientModal) openAddPatientModal.addEventListener('click', showAddPatientModal);
            if (closeAddPatientModal) closeAddPatientModal.addEventListener('click', hideAddPatientModal);
            if (cancelAddPatientModal) cancelAddPatientModal.addEventListener('click', hideAddPatientModal);
            if (addPatientModal) {
                addPatientModal.addEventListener('click', function (event) {
                    if (event.target === addPatientModal) hideAddPatientModal();
                });
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && addPatientModal && addPatientModal.classList.contains('open')) {
                    hideAddPatientModal();
                }
            });

            const ageEl = document.getElementById('adlPatientAge');
            const addressEl = document.getElementById('adlPatientAddress');
            const phoneEl = document.getElementById('adlPatientPhone');
            const groupSocial = document.getElementById('groupSocial');
            const groupHome = document.getElementById('groupHome');
            const groupBed = document.getElementById('groupBed');
            const answeredEl = document.getElementById('adlAnswered');
            const navButtons = document.querySelectorAll('[data-question-nav]');

            function setActiveGroup(target) {
                [groupSocial, groupHome, groupBed].forEach(function (el) {
                    if (el) el.classList.remove('active');
                });
                if (target) target.classList.add('active');
            }

            function updateScore() {
                let total = 0;
                let answered = 0;
                scoreNames.forEach(function (name, index) {
                    const selected = document.querySelector('input[name="' + name + '"]:checked');
                    const navBtn = document.querySelector('[data-question-nav="' + (index + 1) + '"]');
                    if (selected) {
                        total += parseInt(selected.value, 10) || 0;
                        answered++;
                        if (navBtn) navBtn.classList.add('active');
                    } else {
                        if (navBtn) navBtn.classList.remove('active');
                    }
                });
                if (totalEl) totalEl.textContent = total;
                if (answeredEl) answeredEl.textContent = answered;
                if (answered < scoreNames.length) {
                    setActiveGroup(null);
                    return;
                }
                if (total >= 12) setActiveGroup(groupSocial);
                else if (total >= 5) setActiveGroup(groupHome);
                else setActiveGroup(groupBed);
            }

            function renderAssessmentHistory() {
                if (!adlHistoryList || !adlHistoryStore || !adlHistoryEmpty || !adlHistoryCount) return;
                const patientId = (patientSelect && patientSelect.value) ? patientSelect.value : '';
                const source = patientId ? adlHistoryStore.querySelector('[data-history-patient="' + patientId + '"]') : null;
                const count = source ? parseInt(source.getAttribute('data-history-count') || '0', 10) : 0;
                adlHistoryCount.textContent = String(count || 0);
                if (source && count > 0) {
                    adlHistoryList.innerHTML = source.innerHTML;
                    adlHistoryList.hidden = false;
                    adlHistoryEmpty.hidden = true;
                } else {
                    adlHistoryList.innerHTML = '';
                    adlHistoryList.hidden = true;
                    adlHistoryEmpty.hidden = false;
                }
            }

            function setAdlView(view) {
                const requestedView = view === 'history' ? 'history' : 'form';
                if (requestedView === 'history' && (!patientSelect || !patientSelect.value)) {
                    if (patientSearchSelect) {
                        patientSearchSelect.setCustomValidity('กรุณาเลือกผู้สูงอายุก่อนดูการประเมิน');
                        patientSearchSelect.reportValidity();
                        patientSearchSelect.setCustomValidity('');
                        patientSearchSelect.focus();
                    }
                    return;
                }

                currentAdlView = requestedView;
                adlViewButtons.forEach(function(btn){
                    const active = btn.getAttribute('data-adl-view') === currentAdlView;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                if (adlFormPane) adlFormPane.hidden = currentAdlView !== 'form';
                if (adlHistoryPane) adlHistoryPane.hidden = currentAdlView !== 'history';
                if (currentAdlView === 'history') renderAssessmentHistory();
            }

            function updateAdlNavLinks() {
                const patientId = (patientSelect && patientSelect.value) ? patientSelect.value : '';
                if (adlFormLink) adlFormLink.href = 'adl_assessment.php' + (patientId ? ('?patient_id=' + encodeURIComponent(patientId)) : '');
                if (adlHistoryLink) adlHistoryLink.href = 'adl_history.php' + (patientId ? ('?patient_id=' + encodeURIComponent(patientId)) : '');
            }

            function updatePatientCard() {
                const data = patientMap[(patientSelect && patientSelect.value) ? patientSelect.value : ''] || null;
                const hasPatient = !!data;

                if (patientDetails) patientDetails.hidden = !hasPatient;
                if (assessmentContent) assessmentContent.hidden = !hasPatient;
                if (selectHint) selectHint.hidden = hasPatient;

                if (!data) {
                    if (nameEl) nameEl.textContent = '-';
                    if (ageEl) ageEl.textContent = '-';
                    if (addressEl) addressEl.textContent = '-';
                    if (phoneEl) phoneEl.textContent = '-';
                    renderAssessmentHistory();
                    updateAdlNavLinks();
                    return;
                }
                if (nameEl) nameEl.textContent = data.fullname || '-';
                if (ageEl) ageEl.textContent = data.age ? data.age + ' ปี' : '-';
                if (addressEl) addressEl.textContent = data.address || '-';
                if (phoneEl) phoneEl.textContent = data.phone || '-';
                renderAssessmentHistory();
                updateAdlNavLinks();
            }

            const currentAdlId = parseInt(document.querySelector('input[name="adl_id"]')?.value || '0', 10);

            function setDetailStatus(field, message, type) {
                const status = document.querySelector('[data-detail-status="' + field + '"]');
                if (!status) return;

                status.textContent = message || '';
                status.classList.remove('ok', 'error');

                if (type) {
                    status.classList.add(type);
                }
            }

            const globalDetailsEditButton = document.getElementById('toggleQuestionDetailsEdit');
            let questionDetailsEditing = false;

            function setDetailStatus(field, message, type) {
                const status = document.querySelector('[data-detail-status="' + field + '"]');
                if (!status) return;

                status.textContent = message || '';
                status.classList.remove('ok', 'error');

                if (type) {
                    status.classList.add(type);
                }
            }

            function openAllQuestionDetailEditors() {
                document.querySelectorAll('[data-detail-editor]').forEach(function (editor) {
                    editor.hidden = false;
                });

                questionDetailsEditing = true;

                if (globalDetailsEditButton) {
                    globalDetailsEditButton.textContent = 'บันทึกการแก้ไข';
                    globalDetailsEditButton.classList.add('is-editing');
                }

                const firstInput = document.querySelector('[data-detail-title]');
                if (firstInput) {
                    firstInput.focus();
                }
            }

            function closeAllQuestionDetailEditors() {
                document.querySelectorAll('[data-detail-editor]').forEach(function (editor) {
                    editor.hidden = true;
                });

                questionDetailsEditing = false;

                if (globalDetailsEditButton) {
                    globalDetailsEditButton.textContent = 'แก้ไข';
                    globalDetailsEditButton.classList.remove('is-editing');
                    globalDetailsEditButton.disabled = false;
                }
            }

            function collectQuestionDetail(field) {
                const editor = document.querySelector('[data-detail-editor="' + field + '"]');

                if (!editor) {
                    throw new Error('ไม่พบข้อมูลข้อที่ต้องการแก้ไข');
                }

                const titleInput = editor.querySelector('[data-detail-title="' + field + '"]');
                const subtitleInput = editor.querySelector('[data-detail-subtitle="' + field + '"]');
                const optionInputs = Array.from(
                    editor.querySelectorAll('[data-detail-option="' + field + '"]')
                );

                const title = (titleInput?.value || '').trim();
                const subtitle = (subtitleInput?.value || '').trim();
                const options = {};

                if (title === '') {
                    titleInput?.focus();
                    throw new Error('กรุณากรอกชื่อข้อให้ครบ');
                }

                for (const input of optionInputs) {
                    const score = input.dataset.detailScore;
                    const value = (input.value || '').trim();

                    if (value === '') {
                        input.focus();
                        throw new Error('กรุณากรอกรายละเอียดตัวเลือกให้ครบทุกคะแนน');
                    }

                    options[score] = value;
                }

                return {
                    field: field,
                    title: title,
                    subtitle: subtitle,
                    options: options
                };
            }

            async function saveOneQuestionDetail(payload) {
                const formData = new FormData();
                formData.append('action', 'save_question_details');
                formData.append('field', payload.field);
                formData.append('title', payload.title);
                formData.append('subtitle', payload.subtitle);
                formData.append('options', JSON.stringify(payload.options));

                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const result = await response.json();

                if (!result.ok) {
                    throw new Error(result.message || 'บันทึกรายละเอียดไม่สำเร็จ');
                }

                return result;
            }

            function applySavedQuestionDetail(field, result, payload) {
                const titleEl = document.querySelector('[data-question-title="' + field + '"]');
                const subtitleEl = document.querySelector('[data-question-subtitle="' + field + '"]');

                if (titleEl) {
                    titleEl.textContent = result.title || payload.title;
                }

                if (subtitleEl) {
                    const subtitleValue = result.subtitle ?? payload.subtitle;
                    subtitleEl.textContent = subtitleValue ? '(' + subtitleValue + ')' : '';
                }

                Object.entries(result.options || payload.options).forEach(function (entry) {
                    const score = entry[0];
                    const value = entry[1];

                    const optionText = document.querySelector(
                        '[data-option-text="' + field + '"][data-option-score="' + score + '"]'
                    );

                    if (optionText) {
                        optionText.textContent = value;
                    }
                });
            }

            if (globalDetailsEditButton) {
                globalDetailsEditButton.addEventListener('click', async function () {
                    if (!questionDetailsEditing) {
                        openAllQuestionDetailEditors();
                        return;
                    }

                    const editors = Array.from(document.querySelectorAll('[data-detail-editor]'));
                    const payloads = [];

                    try {
                        editors.forEach(function (editor) {
                            const field = editor.dataset.detailEditor;
                            if (field) {
                                payloads.push(collectQuestionDetail(field));
                            }
                        });
                    } catch (error) {
                        alert(error.message || 'กรุณาตรวจสอบข้อมูลที่แก้ไข');
                        return;
                    }

                    globalDetailsEditButton.disabled = true;
                    globalDetailsEditButton.textContent = 'กำลังบันทึก...';

                    try {
                        for (const payload of payloads) {
                            const result = await saveOneQuestionDetail(payload);
                            applySavedQuestionDetail(payload.field, result, payload);
                            setDetailStatus(payload.field, '', '');
                        }

                        closeAllQuestionDetailEditors();
                    } catch (error) {
                        globalDetailsEditButton.disabled = false;
                        globalDetailsEditButton.textContent = 'บันทึกการแก้ไข';
                        alert(error.message || 'เกิดข้อผิดพลาดในการบันทึกการแก้ไข');
                    }
                });
            }

            function cardForField(field) {
                const input = document.querySelector('input[name="' + field + '"]');
                return input ? (input.closest('.adl-question-card') || input.closest('.adl-extra-question')) : null;
            }

            function setFieldStatus(field, message, type) {
                const status = document.querySelector('[data-status-field="' + field + '"]');
                if (!status) return;
                status.textContent = message || '';
                status.classList.remove('ok', 'error');
                if (type) status.classList.add(type);
            }

            document.querySelectorAll('[data-edit-field]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const field = button.dataset.editField;
                    const card = cardForField(field);
                    if (!card) return;

                    card.classList.add('editing');

                    card.querySelectorAll('input[name="' + field + '"]').forEach(function (input) {
                        input.disabled = false;
                    });

                    const saveButton = card.querySelector('[data-save-field="' + field + '"]');
                    if (saveButton) saveButton.disabled = false;

                    setFieldStatus(field, 'กำลังแก้ไขข้อนี้', '');
                });
            });

            document.querySelectorAll('[data-save-field]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const field = button.dataset.saveField;
                    const card = cardForField(field);
                    if (!card || !currentAdlId) return;

                    const selected = card.querySelector('input[name="' + field + '"]:checked');

                    if (!selected) {
                        setFieldStatus(field, 'กรุณาเลือกคำตอบก่อนบันทึก', 'error');
                        return;
                    }

                    const oldText = button.textContent;
                    button.disabled = true;
                    button.textContent = 'กำลังบันทึก...';

                    const formData = new FormData();
                    formData.append('action', 'save_single_adl_field');
                    formData.append('adl_id', String(currentAdlId));
                    formData.append('field', field);
                    formData.append('value', selected.value);

                    try {
                        const response = await fetch(window.location.pathname, {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });

                        const result = await response.json();

                        if (!result.ok) {
                            throw new Error(result.message || 'บันทึกไม่สำเร็จ');
                        }

                        card.classList.remove('editing');
                        card.querySelectorAll('input[name="' + field + '"]').forEach(function (input) {
                            input.disabled = true;
                        });

                        if (typeof result.total !== 'undefined') {
                            if (totalEl) totalEl.textContent = result.total;

                            if (result.total >= 12) setActiveGroup(groupSocial);
                            else if (result.total >= 5) setActiveGroup(groupHome);
                            else setActiveGroup(groupBed);
                        }

                        setFieldStatus(field, 'บันทึกเรียบร้อยแล้ว', 'ok');
                    } catch (error) {
                        button.disabled = false;
                        setFieldStatus(field, error.message || 'เกิดข้อผิดพลาดในการบันทึก', 'error');
                    } finally {
                        button.textContent = oldText;
                    }
                });
            });

            document.querySelectorAll('#adlForm input[type="radio"]').forEach(function (el) {
                el.addEventListener('change', updateScore);
            });
            if (patientCombobox && patientSearchSelect && patientSelect) {
                let activeIndex = -1;

                function openPatientList() {
                    patientCombobox.classList.add('open');
                }

                function closePatientList() {
                    patientCombobox.classList.remove('open');
                    activeIndex = -1;
                    patientOptions.forEach(function (option) {
                        option.classList.remove('active');
                    });
                }

                function visiblePatientOptions() {
                    return patientOptions.filter(function (option) {
                        return !option.hidden;
                    });
                }

                function filterPatientOptions() {
                    const keyword = patientSearchSelect.value.trim().toLocaleLowerCase('th-TH');
                    let visibleCount = 0;

                    patientOptions.forEach(function (option) {
                        const label = (option.dataset.patientLabel || '').toLocaleLowerCase('th-TH');
                        const show = keyword === '' || label.includes(keyword);
                        option.hidden = !show;
                        option.classList.remove('active');
                        if (show) visibleCount++;
                    });

                    if (patientComboEmpty) {
                        patientComboEmpty.hidden = visibleCount !== 0;
                    }

                    activeIndex = -1;
                }

                function selectPatient(option) {
                    if (!option) return;

                    patientSelect.value = option.dataset.patientId || '';
                    patientSearchSelect.value = option.dataset.patientLabel || '';
                    patientSearchSelect.setCustomValidity('');
                    closePatientList();
                    updatePatientCard();
                }

                patientSearchSelect.addEventListener('focus', function () {
                    filterPatientOptions();
                    openPatientList();
                });

                patientSearchSelect.addEventListener('click', function () {
                    filterPatientOptions();
                    openPatientList();
                });

                patientSearchSelect.addEventListener('input', function () {
                    /*
                     * เมื่อผู้ใช้เริ่มพิมพ์ ถือว่ายังไม่ได้เลือกจากรายการ
                     * จนกว่าจะคลิกรายชื่อหรือกด Enter
                     */
                    patientSelect.value = '';
                    patientSearchSelect.setCustomValidity('กรุณาเลือกรายชื่อผู้สูงอายุจากรายการ');
                    updatePatientCard();
                    updatePatientCard();
                    filterPatientOptions();
                    openPatientList();
                });

                patientOptions.forEach(function (option) {
                    option.addEventListener('click', function () {
                        selectPatient(option);
                    });
                });

                patientSearchSelect.addEventListener('keydown', function (event) {
                    const visible = visiblePatientOptions();

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        openPatientList();
                        if (!visible.length) return;
                        activeIndex = (activeIndex + 1) % visible.length;
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        openPatientList();
                        if (!visible.length) return;
                        activeIndex = activeIndex <= 0 ? visible.length - 1 : activeIndex - 1;
                    } else if (event.key === 'Enter') {
                        if (patientCombobox.classList.contains('open') && visible.length) {
                            event.preventDefault();
                            selectPatient(visible[activeIndex >= 0 ? activeIndex : 0]);
                        }
                        return;
                    } else if (event.key === 'Escape') {
                        closePatientList();
                        return;
                    } else {
                        return;
                    }

                    visible.forEach(function (option) {
                        option.classList.remove('active');
                    });

                    if (visible[activeIndex]) {
                        visible[activeIndex].classList.add('active');
                        visible[activeIndex].scrollIntoView({ block: 'nearest' });
                    }
                });

                document.addEventListener('click', function (event) {
                    if (!patientCombobox.contains(event.target)) {
                        closePatientList();
                    }
                });

                const adlForm = document.getElementById('adlForm');
                if (adlForm) {
                    adlForm.addEventListener('submit', function (event) {
                        if (!patientSelect.value) {
                            patientSearchSelect.setCustomValidity('กรุณาค้นหาและเลือกรายชื่อผู้สูงอายุ');
                            patientSearchSelect.reportValidity();
                            event.preventDefault();
                            return;
                        }

                        patientSearchSelect.setCustomValidity('');
                    });
                }

                if (patientSelect.value) {
                    patientSearchSelect.setCustomValidity('');
                }
                updatePatientCard();
            }
            adlViewButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setAdlView(btn.getAttribute('data-adl-view') || 'form');
                });
            });
            setAdlView('form');
            updateAdlNavLinks();
            updatePatientCard();
            updateScore();
        })();
    </script>

<?php if ($message === 'บันทึกคะแนนสำเร็จ'): ?>
<div class="adl-success-modal" id="adlSuccessModal" role="dialog" aria-modal="true" aria-labelledby="adlSuccessTitle">
    <div class="adl-success-card">
        <div class="adl-success-icon">✓</div>
        <h3 id="adlSuccessTitle">บันทึกสำเร็จแล้ว</h3>
        <p>ระบบบันทึกคะแนนการประเมิน ADL เรียบร้อยแล้ว</p>
        <button type="button" class="adl-success-ok" id="adlSuccessOk">ตกลง</button>
    </div>
</div>
<script>
(function(){
    const modal=document.getElementById('adlSuccessModal');
    const ok=document.getElementById('adlSuccessOk');
    if(!modal||!ok) return;
    ok.addEventListener('click',function(){
        modal.hidden=true;
        window.location.href='adl.php';
    });
})();
</script>
<?php endif; ?>
</body>

</html>
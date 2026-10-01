<?php
/**
 * ตรวจสอบและเพิ่มคอลัมน์ที่จำเป็นสำหรับระบบ Thonglang
 * ใช้ SHOW COLUMNS แทน information_schema เพื่อรองรับ XAMPP/MariaDB
 * ที่จำกัดสิทธิ์การอ่าน information_schema
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

function thonglangTableHasColumn(mysqli $connection, string $tableName, string $columnName): bool
{
    $safeTableName = str_replace('`', '``', $tableName);
    $safeColumnName = mysqli_real_escape_string($connection, $columnName);

    $result = mysqli_query(
        $connection,
        "SHOW COLUMNS FROM `{$safeTableName}` LIKE '{$safeColumnName}'"
    );

    if (!$result) {
        return false;
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function thonglangEnsureColumn(mysqli $connection, string $tableName, string $columnName, string $definition): void
{
    if (thonglangTableHasColumn($connection, $tableName, $columnName)) {
        return;
    }

    $safeTableName = str_replace('`', '``', $tableName);
    $safeColumnName = str_replace('`', '``', $columnName);
    $sql = "ALTER TABLE `{$safeTableName}` ADD COLUMN `{$safeColumnName}` {$definition}";

    if (!mysqli_query($connection, $sql)) {
        throw new RuntimeException(
            'ไม่สามารถปรับโครงสร้างฐานข้อมูลได้ (' . $tableName . '.' . $columnName . '): ' . mysqli_error($connection)
        );
    }
}

try {
    // ข้อมูลบัญชีแคร์กิฟเวอร์และหมอ
    // ไม่มี caregiver_code / doctor_code แล้ว ใช้ username สำหรับเข้าสู่ระบบแทน
    thonglangEnsureColumn($conn, 'users', 'phone_number', "VARCHAR(30) NULL AFTER `display_name`");
    thonglangEnsureColumn($conn, 'users', 'responsible_village_id', "INT NULL AFTER `phone_number`");
    thonglangEnsureColumn($conn, 'users', 'doctor_code', "VARCHAR(50) NULL AFTER `phone_number`");
    thonglangEnsureColumn($conn, 'users', 'professional_license_number', "VARCHAR(100) NULL AFTER `doctor_code`");
    thonglangEnsureColumn($conn, 'users', 'medical_position', "VARCHAR(150) NULL AFTER `professional_license_number`");
    thonglangEnsureColumn($conn, 'users', 'department', "VARCHAR(150) NULL AFTER `medical_position`");

    // ข้อมูลผู้สูงอายุ
    thonglangEnsureColumn($conn, 'patient', 'Firstname', "VARCHAR(120) NULL AFTER `Patient_id`");
    thonglangEnsureColumn($conn, 'patient', 'Lastname', "VARCHAR(120) NULL AFTER `Firstname`");
    thonglangEnsureColumn($conn, 'patient', 'Weight_kg', "DECIMAL(6,2) NULL AFTER `Age`");
    thonglangEnsureColumn($conn, 'patient', 'Height_cm', "DECIMAL(6,2) NULL AFTER `Weight_kg`");
    thonglangEnsureColumn($conn, 'patient', 'Latitude', "DECIMAL(10,7) NULL AFTER `Village_id`");
    thonglangEnsureColumn($conn, 'patient', 'Longitude', "DECIMAL(10,7) NULL AFTER `Latitude`");

    // ข้อมูลหมู่บ้าน
    thonglangEnsureColumn($conn, 'village', 'village_number', "VARCHAR(20) NULL AFTER `village_id`");
    thonglangEnsureColumn($conn, 'village', 'zipcode', "VARCHAR(10) NULL AFTER `province`");
} catch (Throwable $error) {
    http_response_code(500);
    die('<meta charset="utf-8"><div style="font-family:Noto Sans Thai,sans-serif;padding:24px;color:#8a1f1f">' .
        htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') .
        '<br><br>กรุณาตรวจสอบสิทธิ์ของบัญชีฐานข้อมูล MySQL แล้วลองใหม่</div>');
}

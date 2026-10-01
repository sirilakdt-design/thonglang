-- Thonglang: ปรับฟิลด์ข้อมูลผู้ใช้งานฉบับไม่มีรหัสประจำตัวแยก
-- ใช้ username เป็นชื่อผู้ใช้งานสำหรับเข้าสู่ระบบ เช่น Caregiver01 / Doctor01
-- รองรับ MariaDB 10.4+

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `phone_number` VARCHAR(30) NULL AFTER `display_name`,
    ADD COLUMN IF NOT EXISTS `responsible_village_id` INT NULL AFTER `phone_number`,
    ADD COLUMN IF NOT EXISTS `professional_license_number` VARCHAR(100) NULL AFTER `responsible_village_id`,
    ADD COLUMN IF NOT EXISTS `medical_position` VARCHAR(150) NULL AFTER `professional_license_number`;

ALTER TABLE `village`
    ADD COLUMN IF NOT EXISTS `village_number` VARCHAR(20) NULL AFTER `village_id`,
    ADD COLUMN IF NOT EXISTS `zipcode` VARCHAR(10) NULL AFTER `province`;

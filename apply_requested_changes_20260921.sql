-- การปรับฐานข้อมูลประกอบชุดแก้ไข 21/09/2569
-- หมายเหตุ: ตัวระบบ core.php สามารถปรับโครงสร้างที่จำเป็นอัตโนมัติอยู่แล้ว
-- ไฟล์นี้จัดไว้สำหรับผู้ที่ต้องการรันฐานข้อมูลด้วยตนเองก่อนเปิดระบบ

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`) VALUES
('org_name','ทองหลาง'),
('director_name','นาย รัศมี แก้วเนตร');

-- รองรับโรคประจำตัวหลายโรคในช่องเดิมโดยไม่ตัดข้อความ
ALTER TABLE `patient` MODIFY COLUMN `Disease` TEXT NULL;

-- รองรับหมายเลขหมู่บ้าน
ALTER TABLE `village` ADD COLUMN IF NOT EXISTS `village_number` VARCHAR(20) NULL AFTER `village_id`;

-- ปรับชื่อหมู่บ้าน 8 และ 9 ถ้ามีอยู่แล้ว
UPDATE `village` SET `villagename`='บ้านตูมหมู่ 8', `village_number`='8' WHERE `village_number`='8';
UPDATE `village` SET `villagename`='บ้านตูมหมู่ 9', `village_number`='9' WHERE `village_number`='9';

-- เพิ่มหมู่บ้านถ้ายังไม่มี
INSERT INTO `village` (`village_number`,`villagename`)
SELECT '8','บ้านตูมหมู่ 8' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `village` WHERE `village_number`='8' OR `villagename`='บ้านตูมหมู่ 8');

INSERT INTO `village` (`village_number`,`villagename`)
SELECT '9','บ้านตูมหมู่ 9' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `village` WHERE `village_number`='9' OR `villagename`='บ้านตูมหมู่ 9');

-- ชื่อ ผอ. ตามที่กำหนด
UPDATE `users`
SET `display_name`=(SELECT `setting_value` FROM `system_settings` WHERE `setting_key`='director_name' LIMIT 1)
WHERE `role`='director';

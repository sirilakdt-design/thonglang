-- ใช้ไฟล์นี้ครั้งเดียว หากต้องการลบคอลัมน์รหัสประจำตัวเดิมออกจากตาราง users จริง ๆ
-- ระบบเวอร์ชันนี้ไม่ใช้ caregiver_code และ doctor_code แล้ว

USE `thonglang`;

ALTER TABLE `users`
    DROP COLUMN IF EXISTS `caregiver_code`,
    DROP COLUMN IF EXISTS `doctor_code`;

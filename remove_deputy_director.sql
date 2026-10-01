-- SQL ปรับฐานข้อมูลระบบที่ติดตั้งอยู่แล้ว: ลบบัญชีรอง ผอ. และเลิกใช้บทบาทนี้
-- สำรองฐานข้อมูลก่อนรัน จากนั้นใช้ phpMyAdmin > ฐานข้อมูล thonglang > SQL / Import
-- หาก DELETE ไม่สำเร็จเนื่องจาก foreign key โปรดตรวจสอบข้อมูลอ้างอิงก่อน; อย่าปิด FK checks
DELETE FROM `users` WHERE `role` = 'deputy_director';
ALTER TABLE `users`
  MODIFY `role` ENUM('admin','doctor','caregiver','director') NOT NULL;

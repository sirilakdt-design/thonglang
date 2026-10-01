-- รองรับบัญชีผู้อำนวยการ (ผอ.) เท่านั้น
-- สำหรับฐานข้อมูลเดิม โปรดสำรองข้อมูลก่อนรัน
-- ลบบัญชีรอง ผอ. ก่อนเปลี่ยน ENUM เพื่อหลีกเลี่ยงการแปลง role เป็นค่าว่าง
DELETE FROM `users` WHERE `role` = 'deputy_director';
ALTER TABLE `users`
  MODIFY `role` ENUM('admin','doctor','caregiver','director') NOT NULL;

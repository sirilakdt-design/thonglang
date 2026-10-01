Thonglang - ชุดโค้ดปรับปรุงล่าสุด

สิ่งที่ปรับแล้ว
1) จัดการข้อมูลแคร์กิฟเวอร์
   - เอาช่อง "รหัสประจำตัวแคร์กิฟเวอร์" ออกจากฟอร์มและตาราง
   - ใช้ username เช่น Caregiver01, Caregiver02, Caregiver03 เป็นชื่อผู้ใช้งานสำหรับเข้าสู่ระบบ
   - เชื่อมข้อมูลกับตาราง users โดยตรง
   - ปรับหน้าจอให้กลับมาใช้โทนพาสเทลฟ้า-เขียวแบบเดียวกับหน้าหลัก

2) จัดการข้อมูลหมอ
   - เอาช่อง "รหัสประจำตัวหมอ" ออกจากฟอร์มและตาราง
   - ใช้ username เช่น D1 เป็นชื่อผู้ใช้งานสำหรับเข้าสู่ระบบ
   - เชื่อมข้อมูลกับตาราง users โดยตรง
   - ปรับหน้าจอให้กลับมาใช้โทนพาสเทลฟ้า-เขียวแบบเดียวกับหน้าหลัก

3) จัดการข้อมูลหมู่บ้าน
   - คงข้อมูลเดิม
   - ปรับให้ใช้ Shared Pastel Theme เช่นเดียวกับหน้าอื่น

4) ฐานข้อมูล
   - database_schema_upgrade.php ไม่ใช้ information_schema แล้ว
   - ใช้ SHOW COLUMNS เพื่อลดปัญหา #1044 Access denied
   - ไม่สร้าง caregiver_code / doctor_code ใหม่
   - remove_identity_codes.sql ใช้เมื่อต้องการลบคอลัมน์ caregiver_code และ doctor_code เดิมออกจากตาราง users

5) การเข้าสู่ระบบ
   - ใช้ users.username + users.password_hash
   - ไม่ใช้ session caregiver_code แล้ว
   - make_password.php ปรับให้ใช้โครงสร้างตาราง users ปัจจุบัน

ไฟล์สำคัญที่ปรับ
- caregiver.php
- doctor.php
- village.php
- database_schema_upgrade.php
- database_update_full_fields.sql
- remove_identity_codes.sql
- index.php
- login.php
- make_password.php
- assign_patient.php
- caregiver_patients.php

Update 2026-09-04
- เพิ่มเมนู "แผนที่ผู้สูงอายุ" สำหรับผู้ดูแลระบบ
- เพิ่มหน้า elderly_map.php แสดงผู้สูงอายุที่มี Latitude/Longitude เป็นหมุดบน OpenStreetMap
- ค้นหาชื่อ กรองตามหมู่บ้าน และเปิดตำแหน่งใน Google Maps ได้
- ไม่ต้องเพิ่มตารางฐานข้อมูลใหม่ ใช้พิกัดเดิมในตาราง patient

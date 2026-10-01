-- Thonglang: remove Deputy Director, G1, G2, G3, G4, D1, Gee
-- BACK UP the database first. Use only with the thonglang database selected in phpMyAdmin.
-- Deletion targets the username field, not a person's display name.
-- If a foreign-key error occurs, STOP and inspect dependent records. Do not disable FK checks.
DELETE FROM `users`
WHERE `role` = 'deputy_director' OR LOWER(TRIM(`username`)) IN ('deputy director','g1','g2','g3','g4','d1','gee');

-- Keep only supported roles after the deputy-director accounts have been removed.
ALTER TABLE `users`
  MODIFY `role` ENUM('admin','doctor','caregiver','director') NOT NULL;

-- Expect zero rows after successful execution. This query does not alter records.
SELECT `user_id`, `username`, `role` FROM `users`
WHERE `role` = 'deputy_director' OR LOWER(TRIM(`username`)) IN ('deputy director','g1','g2','g3','g4','d1','gee');

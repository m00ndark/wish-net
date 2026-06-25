-- 001: widen users.password for password_hash() output
--
-- The legacy salted-SHA1 (16-char salt + 40-char SHA1 hex) fits varchar(56) exactly.
-- password_hash() output is longer: bcrypt = 60 chars, argon2id ~= 97+ chars.
-- Widen to varchar(255) before adopting password_hash()/password_verify() (PLAN.md section 5).
--
-- Safe/additive: only grows the column; existing hashes are preserved.

ALTER TABLE `users` MODIFY `password` varchar(255) NOT NULL DEFAULT '';

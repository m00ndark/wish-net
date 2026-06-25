-- 002: opaque bearer-token sessions
--
-- Backs token auth (PLAN.md section 5). On login the API generates a random token, returns
-- it to the client, and stores only its SHA-256 hash here. Every protected request resolves
-- the bearer token -> user_id + is_super server-side. Logout/expiry deletes the row.
--
-- New table -> utf8mb4 (the legacy tables are latin1; see PLAN.md section 4).

CREATE TABLE `sessions` (
  `token_hash` char(64) NOT NULL,            -- SHA-256 hex of the bearer token
  `user_id` int(11) NOT NULL,
  `is_super` tinyint(1) NOT NULL DEFAULT 0,  -- per-session elevation (master pwd / "§" suffix)
  `created` datetime NOT NULL,
  `expires` datetime NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_expires` (`expires`),
  CONSTRAINT `fk_sessions_users` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

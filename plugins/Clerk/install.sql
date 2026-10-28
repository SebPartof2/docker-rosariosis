/**
 * Install PostgreSQL
 * Required if the plugin has menu entries or tables
 * - Add profile exceptions for the plugin to appear in the menu
 * - Add program config options if any (to every school)
 * - Add plugin specific tables (and their eventual sequences & indexes)
 *   if any: see rosariosis.sql file for examples
 * - Add plugin specific templates if any
 *
 * @package Clerk plugin
 */

/**
 * config Table
 *
 * Global settings (SCHOOL_ID=0). Secrets are environment variables, not here.
 */
INSERT INTO config (school_id, title, config_value)
SELECT 0, t.title, t.config_value
FROM (VALUES
	('CLERK_SYNC_PROFILES', ',admin,teacher,parent,student,'),
	('CLERK_PASSWORD_MONTH', '01'),
	('CLERK_PASSWORD_CASE', 'lower'),
	('CLERK_INACTIVE', 'ban'),
	('CLERK_AUTO_SYNC', 'Y'),
	('CLERK_PUSH_PASSWORDS', 'Y'),
	('CLERK_SSO', 'Y'),
	('CLERK_SSO_LABEL', ''),
	('CLERK_LAST_SYNC', '')
) AS t(title, config_value)
WHERE NOT EXISTS (SELECT 1
	FROM config
	WHERE config.title=t.title
	AND config.school_id=0);

/**
 * Birthdate fields used for the default password:
 * students: the "Birthdate" field; users: a date field whose title starts with "Birth", if any.
 */
INSERT INTO config (school_id, title, config_value)
SELECT 0, 'CLERK_STUDENT_BIRTHDATE_FIELD', COALESCE(CAST(MIN(id) AS text), '')
FROM custom_fields
WHERE type='date'
AND title LIKE 'Birth%'
HAVING NOT EXISTS (SELECT 1
	FROM config
	WHERE title='CLERK_STUDENT_BIRTHDATE_FIELD'
	AND school_id=0);

INSERT INTO config (school_id, title, config_value)
SELECT 0, 'CLERK_STAFF_BIRTHDATE_FIELD', COALESCE(CAST(MIN(id) AS text), '')
FROM staff_fields
WHERE type='date'
AND title LIKE 'Birth%'
HAVING NOT EXISTS (SELECT 1
	FROM config
	WHERE title='CLERK_STAFF_BIRTHDATE_FIELD'
	AND school_id=0);

/**
 * Sync log: changes & errors only, 90 days.
 */
CREATE TABLE IF NOT EXISTS clerk_sync_log (
	id serial PRIMARY KEY,
	external_id varchar(100),
	username varchar(100),
	action varchar(20),
	message text,
	created_at timestamp DEFAULT current_timestamp
);

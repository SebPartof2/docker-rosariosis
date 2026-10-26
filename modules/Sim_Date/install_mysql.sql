-- Sim Date module installation (MySQL)

-- The sim date: global (SCHOOL_ID=0) config, YYYY-MM-DD.
-- Empty means use the real date.

INSERT INTO config (SCHOOL_ID, TITLE, CONFIG_VALUE)
	SELECT 0, 'SIM_DATE', ''
	FROM DUAL
	WHERE NOT EXISTS (SELECT 1
		FROM config
		WHERE TITLE='SIM_DATE'
		AND SCHOOL_ID=0);

-- Every change of the sim date. CREATED_AT is the real time.

CREATE TABLE IF NOT EXISTS sim_date_log (
	id integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
	staff_id integer,
	old_value varchar(10),
	new_value varchar(10),
	action varchar(20),
	created_at timestamp DEFAULT current_timestamp
);

-- Give the hidden "Sim Date" program to every Administrator profile.
-- It is in no menu, so it cannot be granted from Users > User Profiles:
-- other profiles never get it.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Sim_Date/SimDate.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin';

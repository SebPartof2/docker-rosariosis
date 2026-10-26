-- Course Express module installation (MySQL)

-- Override sets: the section settings that replace the defaults
-- for the courses of the set.
-- OVERRIDDEN holds the overridden settings, comma wrapped, for example
-- ',GRADE_SCALE_ID,DOES_HONOR_ROLL,'. A setting which is not listed there
-- keeps its default, whatever the value stored in its column.

CREATE TABLE IF NOT EXISTS course_express_override_sets (
	id integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
	syear numeric(4,0) NOT NULL,
	school_id integer NOT NULL,
	title varchar(100) NOT NULL,
	sort_order numeric,
	overridden varchar(255),
	total_seats numeric,
	days varchar(7),
	does_attendance text,
	calendar_id integer,
	grade_scale_id integer,
	does_class_rank varchar(1),
	does_honor_roll varchar(1),
	created_at timestamp DEFAULT current_timestamp,
	updated_at timestamp NULL ON UPDATE current_timestamp
);

CREATE TABLE IF NOT EXISTS course_express_override_courses (
	id integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
	set_id integer NOT NULL REFERENCES course_express_override_sets(id),
	course_id integer NOT NULL,
	created_at timestamp DEFAULT current_timestamp,
	UNIQUE (set_id, course_id)
);

-- Give the Course Express programs to every Administrator profile.
-- The module creates records, so CAN_EDIT is required.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, p.MODNAME, 'Y', 'Y'
		FROM user_profiles up,
			(SELECT 'Course_Express/Import.php' AS MODNAME
				UNION SELECT 'Course_Express/Sections.php'
				UNION SELECT 'Course_Express/MoveSections.php'
				UNION SELECT 'Course_Express/Defaults.php'
				UNION SELECT 'Course_Express/Overrides.php') p
		WHERE up.PROFILE='admin';

-- Administrators with custom permissions (staff_exceptions):
-- give the programs to the users who can already edit the Courses setup.
-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, p.MODNAME, 'Y', 'Y'
		FROM (SELECT USER_ID
				FROM staff_exceptions
				WHERE MODNAME='Scheduling/Courses.php'
				AND CAN_EDIT='Y') se,
			(SELECT 'Course_Express/Import.php' AS MODNAME
				UNION SELECT 'Course_Express/Sections.php'
				UNION SELECT 'Course_Express/MoveSections.php'
				UNION SELECT 'Course_Express/Defaults.php'
				UNION SELECT 'Course_Express/Overrides.php') p;

-- The three reports only read: CAN_USE without CAN_EDIT.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, r.MODNAME, 'Y', NULL
		FROM user_profiles up,
			(SELECT 'Course_Express/MasterSchedule.php' AS MODNAME
				UNION SELECT 'Course_Express/Troubleshoot.php'
				UNION SELECT 'Course_Express/EmptySections.php') r
		WHERE up.PROFILE='admin';

-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, r.MODNAME, 'Y', NULL
		FROM (SELECT USER_ID
			FROM staff_exceptions
			WHERE MODNAME='Scheduling/Courses.php') se,
			(SELECT 'Course_Express/MasterSchedule.php' AS MODNAME
				UNION SELECT 'Course_Express/Troubleshoot.php'
				UNION SELECT 'Course_Express/EmptySections.php') r;

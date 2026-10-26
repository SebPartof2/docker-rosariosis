-- Course Express module installation (PostgreSQL)

-- Override sets: the section settings that replace the defaults
-- for the courses of the set.
-- OVERRIDDEN holds the overridden settings, comma wrapped, for example
-- ',GRADE_SCALE_ID,DOES_HONOR_ROLL,'. A setting which is not listed there
-- keeps its default, whatever the value stored in its column.

CREATE TABLE IF NOT EXISTS course_express_override_sets (
	id serial PRIMARY KEY,
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
	updated_at timestamp
);

CREATE TABLE IF NOT EXISTS course_express_override_courses (
	id serial PRIMARY KEY,
	set_id integer NOT NULL REFERENCES course_express_override_sets(id),
	course_id integer NOT NULL,
	created_at timestamp DEFAULT current_timestamp,
	UNIQUE (set_id, course_id)
);

-- Give the Course Express programs to every Administrator profile.
-- The module creates records, so CAN_EDIT is required.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, p.MODNAME, 'Y', 'Y'
		FROM user_profiles up,
			(SELECT 'Course_Express/Import.php' AS MODNAME
				UNION SELECT 'Course_Express/Sections.php'
				UNION SELECT 'Course_Express/MoveSections.php'
				UNION SELECT 'Course_Express/Defaults.php'
				UNION SELECT 'Course_Express/Overrides.php') p
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME=p.MODNAME);

-- Administrators with custom permissions (staff_exceptions):
-- give the programs to the users who can already edit the Courses setup.

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, p.MODNAME, 'Y', 'Y'
		FROM staff_exceptions se,
			(SELECT 'Course_Express/Import.php' AS MODNAME
				UNION SELECT 'Course_Express/Sections.php'
				UNION SELECT 'Course_Express/MoveSections.php'
				UNION SELECT 'Course_Express/Defaults.php'
				UNION SELECT 'Course_Express/Overrides.php') p
		WHERE se.MODNAME='Scheduling/Courses.php'
		AND se.CAN_EDIT='Y'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME=p.MODNAME);

-- The three reports only read: CAN_USE without CAN_EDIT.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, r.MODNAME, 'Y', NULL
		FROM user_profiles up,
			(SELECT 'Course_Express/MasterSchedule.php' AS MODNAME
				UNION SELECT 'Course_Express/Troubleshoot.php'
				UNION SELECT 'Course_Express/EmptySections.php') r
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME=r.MODNAME);

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, r.MODNAME, 'Y', NULL
		FROM staff_exceptions se,
			(SELECT 'Course_Express/MasterSchedule.php' AS MODNAME
				UNION SELECT 'Course_Express/Troubleshoot.php'
				UNION SELECT 'Course_Express/EmptySections.php') r
		WHERE se.MODNAME='Scheduling/Courses.php'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME=r.MODNAME);

-- Info Express module installation (MySQL)
--
-- Give the "Update Student Info" program to every Administrator profile.
-- The module updates the students, so CAN_EDIT is required.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/Import.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin';

-- Administrators with custom permissions (staff_exceptions):
-- give the program to the users who can already edit the Student Info.
-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/Import.php', 'Y', 'Y'
		FROM (SELECT USER_ID
			FROM staff_exceptions
			WHERE MODNAME='Students/AssignOtherInfo.php'
			AND CAN_EDIT='Y') se;

-- "Reactivate Students" as well. It edits the enrollment records, so it also
-- requires CAN_EDIT.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/Reactivate.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin';

-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/Reactivate.php', 'Y', 'Y'
		FROM (SELECT USER_ID
			FROM staff_exceptions
			WHERE MODNAME='Students/Student.php'
			AND CAN_EDIT='Y') se;

-- "Enrollment Dates" as well. It edits the enrollment and schedule records, so
-- it also requires CAN_EDIT.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/EnrollmentDates.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin';

-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/EnrollmentDates.php', 'Y', 'Y'
		FROM (SELECT USER_ID
			FROM staff_exceptions
			WHERE MODNAME='Students/Student.php'
			AND CAN_EDIT='Y') se;

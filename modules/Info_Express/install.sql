-- Info Express module installation (PostgreSQL)
--
-- Give the "Update Student Info" program to every Administrator profile.
-- The module updates the students, so CAN_EDIT is required.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/Import.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME='Info_Express/Import.php');

-- Administrators with custom permissions (staff_exceptions):
-- give the program to the users who can already edit the Student Info.

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/Import.php', 'Y', 'Y'
		FROM staff_exceptions se
		WHERE se.MODNAME='Students/AssignOtherInfo.php'
		AND se.CAN_EDIT='Y'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME='Info_Express/Import.php');

-- "Reactivate Students" as well. It edits the enrollment records, so it also
-- requires CAN_EDIT.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/Reactivate.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME='Info_Express/Reactivate.php');

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/Reactivate.php', 'Y', 'Y'
		FROM staff_exceptions se
		WHERE se.MODNAME='Students/Student.php'
		AND se.CAN_EDIT='Y'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME='Info_Express/Reactivate.php');

-- "Enrollment Dates" as well. It edits the enrollment and schedule records, so
-- it also requires CAN_EDIT.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Info_Express/EnrollmentDates.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME='Info_Express/EnrollmentDates.php');

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Info_Express/EnrollmentDates.php', 'Y', 'Y'
		FROM staff_exceptions se
		WHERE se.MODNAME='Students/Student.php'
		AND se.CAN_EDIT='Y'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME='Info_Express/EnrollmentDates.php');

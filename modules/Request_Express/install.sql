-- Request Express module installation (PostgreSQL)
--
-- Give the "Import Course Requests" program to every Administrator profile.
-- The module replaces the students' requests, so CAN_EDIT is required.

INSERT INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Request_Express/Import.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin'
		AND NOT EXISTS (SELECT 1
			FROM profile_exceptions pe
			WHERE pe.PROFILE_ID=up.ID
			AND pe.MODNAME='Request_Express/Import.php');

-- Administrators with custom permissions (staff_exceptions):
-- give the program to the users who can already edit the Student Requests.

INSERT INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Request_Express/Import.php', 'Y', 'Y'
		FROM staff_exceptions se
		WHERE se.MODNAME='Scheduling/Requests.php'
		AND se.CAN_EDIT='Y'
		AND NOT EXISTS (SELECT 1
			FROM staff_exceptions se2
			WHERE se2.USER_ID=se.USER_ID
			AND se2.MODNAME='Request_Express/Import.php');

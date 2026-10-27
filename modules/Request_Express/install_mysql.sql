-- Request Express module installation (MySQL)
--
-- Give the "Import Course Requests" program to every Administrator profile.
-- The module replaces the students' requests, so CAN_EDIT is required.

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT up.ID, 'Request_Express/Import.php', 'Y', 'Y'
		FROM user_profiles up
		WHERE up.PROFILE='admin';

-- Administrators with custom permissions (staff_exceptions):
-- give the program to the users who can already edit the Student Requests.
-- The derived table is required: MySQL cannot select from the table being inserted into.

INSERT IGNORE INTO staff_exceptions (USER_ID, MODNAME, CAN_USE, CAN_EDIT)
	SELECT se.USER_ID, 'Request_Express/Import.php', 'Y', 'Y'
		FROM (SELECT USER_ID
			FROM staff_exceptions
			WHERE MODNAME='Scheduling/Requests.php'
			AND CAN_EDIT='Y') se;

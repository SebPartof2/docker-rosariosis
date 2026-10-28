<?php
/**
 * Clerk sync functions
 *
 * RosarioSIS is the source of truth. For each RosarioSIS user (staff of the
 * default school year & students) that has a username:
 * - Active: create the Clerk user, or bring username, names, email &
 *   public metadata back in line with RosarioSIS.
 * - Inactive (profile "No Access", student not enrolled today) or deleted:
 *   ban the Clerk user (plugin setting), unban it when active again.
 * - Profile not synced (plugin setting): left alone.
 *
 * Clerk users are linked by their external ID: rosario-staff-<STAFF_ID> or
 * rosario-student-<STUDENT_ID>. A Clerk user with the same username and no
 * external ID (or the ID of a RosarioSIS user that no longer exists, for
 * example the previous school year STAFF_ID after Rollover) is adopted.
 *
 * Passwords are only set when the Clerk user is created (default password),
 * when a password is changed in RosarioSIS, or by the "Reset passwords" action.
 * Clerk users without the plugin external ID prefix are never touched.
 *
 * @package Clerk plugin
 */

require_once 'plugins/Clerk/includes/Api.fnc.php';

/**
 * Plugin setting (global config, SCHOOL_ID=0)
 *
 * @param string $item Setting name, without the CLERK_ prefix.
 *
 * @return string
 */
function ClerkConfig( $item )
{
	return (string) Config( 'CLERK_' . $item );
}

/**
 * Profiles synced to Clerk
 *
 * @return array admin, teacher, parent, student.
 */
function ClerkSyncProfiles()
{
	return array_values( array_filter( explode( ',', ClerkConfig( 'SYNC_PROFILES' ) ) ) );
}

/**
 * Clerk external ID of a RosarioSIS user
 *
 * @param string $type staff or student.
 * @param int    $id   STAFF_ID or STUDENT_ID.
 *
 * @return string
 */
function ClerkExternalID( $type, $id )
{
	return CLERK_EXTERNAL_ID_PREFIX . $type . '-' . (int) $id;
}

/**
 * Parse a Clerk external ID
 *
 * @param string $external_id External ID.
 *
 * @return array|false [ type, id ] or false if not managed by this plugin.
 */
function ClerkParseExternalID( $external_id )
{
	if ( ! preg_match( '/^' . preg_quote( CLERK_EXTERNAL_ID_PREFIX, '/' ) . '(staff|student)-(\d+)$/', (string) $external_id, $matches ) )
	{
		return false;
	}

	return [ $matches[1], (int) $matches[2] ];
}

/**
 * Date field column (CUSTOM_X) if the field exists and is a date field
 *
 * @param string $table   custom_fields (students) or staff_fields.
 * @param string $setting Setting name holding the field ID.
 *
 * @return string Column name, '' if none.
 */
function ClerkDateFieldColumn( $table, $setting )
{
	$field_id = (int) ClerkConfig( $setting );

	if ( ! $field_id
		|| ! DBGetOne( "SELECT 1 FROM " . $table . " WHERE ID='" . $field_id . "' AND TYPE='date'" ) )
	{
		return '';
	}

	return 'CUSTOM_' . $field_id;
}

/**
 * RosarioSIS users, as the Clerk users they should be
 *
 * @param string $staff_where   SQL condition on staff (s), '' for all. FALSE: no staff.
 * @param string $student_where SQL condition on students (s), '' for all. FALSE: no students.
 *
 * @return array Records, keyed by external ID.
 */
function ClerkRosarioUsers( $staff_where = '', $student_where = '' )
{
	$profiles = ClerkSyncProfiles();

	$syear = Config( 'SYEAR' );

	$records = [];

	if ( $staff_where !== false )
	{
		$birthdate_column = ClerkDateFieldColumn( 'staff_fields', 'STAFF_BIRTHDATE_FIELD' );

		$staff_RET = DBGet( "SELECT s.STAFF_ID,s.USERNAME,s.FIRST_NAME,s.LAST_NAME,s.EMAIL,s.PROFILE," .
			( $birthdate_column ? 's.' . $birthdate_column : 'NULL' ) . " AS BIRTHDATE
			FROM staff s
			WHERE s.SYEAR='" . $syear . "'
			AND s.USERNAME IS NOT NULL
			AND s.USERNAME<>''" .
			( $staff_where ? ' AND ' . $staff_where : '' ) );

		foreach ( $staff_RET as $staff )
		{
			$status = 'active';

			if ( ! in_array( $staff['PROFILE'], [ 'admin', 'teacher', 'parent' ] ) )
			{
				// No Access.
				$status = 'inactive';
			}
			elseif ( ! in_array( $staff['PROFILE'], $profiles ) )
			{
				$status = 'excluded';
			}

			$records[ ClerkExternalID( 'staff', $staff['STAFF_ID'] ) ] = [
				'external_id' => ClerkExternalID( 'staff', $staff['STAFF_ID'] ),
				'type' => 'staff',
				'id' => (int) $staff['STAFF_ID'],
				'username' => $staff['USERNAME'],
				'first_name' => (string) $staff['FIRST_NAME'],
				'last_name' => (string) $staff['LAST_NAME'],
				'email' => filter_var( trim( (string) $staff['EMAIL'] ), FILTER_VALIDATE_EMAIL ) ?: '',
				'profile' => $staff['PROFILE'],
				'birthdate' => (string) $staff['BIRTHDATE'],
				'status' => $status,
			];
		}
	}

	if ( $student_where !== false )
	{
		$birthdate_column = ClerkDateFieldColumn( 'custom_fields', 'STUDENT_BIRTHDATE_FIELD' );

		$email_column = '';

		if ( Config( 'STUDENTS_EMAIL_FIELD' ) === 'USERNAME' )
		{
			$email_column = 'USERNAME';
		}
		elseif ( (int) Config( 'STUDENTS_EMAIL_FIELD' ) )
		{
			$email_column = 'CUSTOM_' . (int) Config( 'STUDENTS_EMAIL_FIELD' );
		}

		// Same enrollment check as the RosarioSIS login (index.php).
		$students_RET = DBGet( "SELECT s.STUDENT_ID,s.USERNAME,s.FIRST_NAME,s.LAST_NAME," .
			( $email_column ? 's.' . $email_column : 'NULL' ) . " AS EMAIL," .
			( $birthdate_column ? 's.' . $birthdate_column : 'NULL' ) . " AS BIRTHDATE,
			(SELECT 'Y'
				FROM student_enrollment se
				WHERE se.STUDENT_ID=s.STUDENT_ID
				AND se.SYEAR='" . $syear . "'
				AND se.START_DATE<='" . DBDate() . "'
				AND (se.END_DATE IS NULL OR se.END_DATE>='" . DBDate() . "')
				LIMIT 1) AS ENROLLED
			FROM students s
			WHERE s.USERNAME IS NOT NULL
			AND s.USERNAME<>''" .
			( $student_where ? ' AND ' . $student_where : '' ) );

		foreach ( $students_RET as $student )
		{
			$status = $student['ENROLLED'] ? 'active' : 'inactive';

			if ( ! in_array( 'student', $profiles ) )
			{
				$status = 'excluded';
			}

			$records[ ClerkExternalID( 'student', $student['STUDENT_ID'] ) ] = [
				'external_id' => ClerkExternalID( 'student', $student['STUDENT_ID'] ),
				'type' => 'student',
				'id' => (int) $student['STUDENT_ID'],
				'username' => $student['USERNAME'],
				'first_name' => (string) $student['FIRST_NAME'],
				'last_name' => (string) $student['LAST_NAME'],
				'email' => filter_var( trim( (string) $student['EMAIL'] ), FILTER_VALIDATE_EMAIL ) ?: '',
				'profile' => 'student',
				'birthdate' => (string) $student['BIRTHDATE'],
				'status' => $status,
			];
		}
	}

	return $records;
}

/**
 * Default password: first name + last name + month of birth
 *
 * Lowercase (setting), letters & digits only: spaces, hyphens, apostrophes... are removed.
 * Month format (setting): 03 (default), 3, march, mar.
 *
 * @example John O'Neil, born 2010-03-14: johnoneil03
 *
 * @param array $record RosarioSIS user record.
 *
 * @return string Password, '' if no valid birthdate.
 */
function ClerkDefaultPassword( $record )
{
	$month = (int) substr( $record['birthdate'], 5, 2 );

	if ( $month < 1
		|| $month > 12 )
	{
		return '';
	}

	// Not a switch: '01' == '1' in a loose comparison.
	$format = ClerkConfig( 'PASSWORD_MONTH' );

	if ( $format === '1' )
	{
		$month = (string) $month;
	}
	elseif ( $format === 'january' )
	{
		$month = strtolower( date( 'F', mktime( 0, 0, 0, $month, 1 ) ) );
	}
	elseif ( $format === 'jan' )
	{
		$month = strtolower( date( 'M', mktime( 0, 0, 0, $month, 1 ) ) );
	}
	else
	{
		$month = sprintf( '%02d', $month );
	}

	$name = preg_replace( '/[^\p{L}\p{N}]+/u', '', $record['first_name'] . $record['last_name'] );

	if ( ClerkConfig( 'PASSWORD_CASE' ) !== 'asis' )
	{
		$name = mb_strtolower( $name );
	}

	return $name . $month;
}

/**
 * Public metadata RosarioSIS writes to the Clerk user
 *
 * @param array $record RosarioSIS user record.
 *
 * @return array
 */
function ClerkPublicMetadata( $record )
{
	return [
		'rosario' => [
			'type' => $record['type'],
			'id' => $record['id'],
			'profile' => $record['profile'],
		],
	];
}

/**
 * Does a RosarioSIS user still exist for a managed external ID?
 * Staff: in the default school year only.
 *
 * @param string $external_id External ID.
 *
 * @return bool
 */
function ClerkRosarioUserExists( $external_id )
{
	$parsed = ClerkParseExternalID( $external_id );

	if ( ! $parsed )
	{
		return false;
	}

	if ( $parsed[0] === 'staff' )
	{
		return (bool) DBGetOne( "SELECT 1 FROM staff
			WHERE STAFF_ID='" . $parsed[1] . "'
			AND SYEAR='" . Config( 'SYEAR' ) . "'" );
	}

	return (bool) DBGetOne( "SELECT 1 FROM students
		WHERE STUDENT_ID='" . $parsed[1] . "'" );
}

/**
 * Can this record adopt the Clerk user found by username?
 *
 * @param array $record     RosarioSIS user record.
 * @param array $clerk_user Clerk user with the same username.
 *
 * @return string Error message, '' if it can.
 */
function ClerkAdoptError( $record, $clerk_user )
{
	$other = (string) issetVal( $clerk_user['external_id'], '' );

	if ( $other === ''
		|| $other === $record['external_id'] )
	{
		return '';
	}

	if ( ClerkParseExternalID( $other )
		&& ! ClerkRosarioUserExists( $other ) )
	{
		// Previous school year staff, or deleted user.
		return '';
	}

	return sprintf(
		dgettext( 'Clerk', 'The Clerk username "%s" already belongs to another account (external ID: %s).' ),
		$record['username'],
		$other ? $other : '-'
	);
}

/**
 * Clerk user email addresses, lowercase
 *
 * @param array $clerk_user Clerk user.
 *
 * @return array
 */
function ClerkUserEmails( $clerk_user )
{
	$emails = [];

	foreach ( (array) issetVal( $clerk_user['email_addresses'], [] ) as $email )
	{
		$emails[] = mb_strtolower( $email['email_address'] );
	}

	return $emails;
}

/**
 * Bring one Clerk user in line with its RosarioSIS user
 *
 * @param array      $record     RosarioSIS user record, null if the user was deleted.
 * @param array|null $clerk_user Linked Clerk user, null if none.
 * @param bool       $dry_run    Only report what would be done.
 *
 * @return array [ 'action' => create|update|ban|unban|unchanged|skip|error, 'message' => string ]
 */
function ClerkSyncRecord( $record, $clerk_user, $dry_run = false )
{
	$result = function( $action, $message = '' )
	{
		return [ 'action' => $action, 'message' => $message ];
	};

	$is_active = $record && $record['status'] === 'active';

	if ( $record
		&& $record['status'] === 'excluded' )
	{
		return $result( 'skip' );
	}

	if ( ! $is_active )
	{
		if ( ! $clerk_user
			|| ClerkConfig( 'INACTIVE' ) !== 'ban'
			|| ! empty( $clerk_user['banned'] ) )
		{
			return $result( 'unchanged' );
		}

		if ( $dry_run )
		{
			return $result( 'ban' );
		}

		$response = ClerkAPI( 'POST', '/users/' . rawurlencode( $clerk_user['id'] ) . '/ban' );

		if ( $response['error'] )
		{
			return $result( 'error', $response['error'] );
		}

		// Remember RosarioSIS banned the user: only unban those.
		ClerkAPI( 'PATCH', '/users/' . rawurlencode( $clerk_user['id'] ) . '/metadata', [
			'private_metadata' => [ 'rosario_banned' => true ],
		] );

		return $result( 'ban', $record ? '' : dgettext( 'Clerk', 'Deleted in RosarioSIS.' ) );
	}

	if ( ! $clerk_user )
	{
		$password = ClerkDefaultPassword( $record );

		$data = [
			'external_id' => $record['external_id'],
			'username' => $record['username'],
			'first_name' => $record['first_name'],
			'last_name' => $record['last_name'],
			'public_metadata' => ClerkPublicMetadata( $record ),
		];

		if ( $record['email'] )
		{
			$data['email_address'] = [ $record['email'] ];
		}

		if ( $password )
		{
			$data['password'] = $password;

			// The default password is too weak for the Clerk password policy.
			$data['skip_password_checks'] = true;
		}
		else
		{
			$data['skip_password_requirement'] = true;
		}

		$message = $password ? '' : dgettext( 'Clerk', 'No birthdate: created without password.' );

		if ( $dry_run )
		{
			return $result( 'create', $message );
		}

		$response = ClerkAPI( 'POST', '/users', $data );

		if ( $response['error'] )
		{
			return $result( 'error', $response['error'] );
		}

		return $result( 'create', $message );
	}

	// Existing Clerk user: RosarioSIS wins.
	$user_path = '/users/' . rawurlencode( $clerk_user['id'] );

	$changes = [];

	$update = [];

	if ( (string) issetVal( $clerk_user['external_id'], '' ) !== $record['external_id'] )
	{
		$update['external_id'] = $record['external_id'];
	}

	if ( mb_strtolower( (string) issetVal( $clerk_user['username'], '' ) ) !== mb_strtolower( $record['username'] ) )
	{
		$update['username'] = $record['username'];
	}

	foreach ( [ 'first_name', 'last_name' ] as $field )
	{
		if ( (string) issetVal( $clerk_user[ $field ], '' ) !== $record[ $field ] )
		{
			$update[ $field ] = $record[ $field ];
		}
	}

	$changes = array_keys( $update );

	$public_metadata = ClerkPublicMetadata( $record );

	$update_metadata = issetVal( $clerk_user['public_metadata']['rosario'] ) != $public_metadata['rosario'];

	if ( $update_metadata )
	{
		$changes[] = 'public_metadata';
	}

	$add_email = $record['email']
		&& ! in_array( mb_strtolower( $record['email'] ), ClerkUserEmails( $clerk_user ) );

	if ( $add_email )
	{
		$changes[] = 'email';
	}

	$unban = ! empty( $clerk_user['banned'] )
		&& ! empty( $clerk_user['private_metadata']['rosario_banned'] );

	if ( ! $changes
		&& ! $unban )
	{
		return $result( 'unchanged' );
	}

	$action = $changes ? 'update' : 'unban';

	$message = $changes ? implode( ', ', $changes ) . ( $unban ? '; unban' : '' ) : '';

	if ( $dry_run )
	{
		return $result( $action, $message );
	}

	if ( $update )
	{
		$response = ClerkAPI( 'PATCH', $user_path, $update );

		if ( $response['error'] )
		{
			return $result( 'error', $response['error'] );
		}
	}

	if ( $update_metadata
		|| $unban )
	{
		$metadata = [ 'public_metadata' => $public_metadata ];

		if ( $unban )
		{
			$metadata['private_metadata'] = [ 'rosario_banned' => null ];
		}

		$response = ClerkAPI( 'PATCH', $user_path . '/metadata', $metadata );

		if ( $response['error'] )
		{
			return $result( 'error', $response['error'] );
		}
	}

	if ( $add_email )
	{
		$response = ClerkAPI( 'POST', '/email_addresses', [
			'user_id' => $clerk_user['id'],
			'email_address' => $record['email'],
			'verified' => true,
			'primary' => true,
		] );

		if ( $response['error'] )
		{
			return $result( 'error', 'Email: ' . $response['error'] );
		}
	}

	if ( $unban )
	{
		$response = ClerkAPI( 'POST', $user_path . '/unban' );

		if ( $response['error'] )
		{
			return $result( 'error', $response['error'] );
		}
	}

	return $result( $action, $message );
}

/**
 * Sync every RosarioSIS user to Clerk
 *
 * @param bool $dry_run Only report what would be done.
 *
 * @return array [ 'error' => string, 'counts' => [ action => count ], 'rows' => log rows (changes & errors) ]
 */
function ClerkSyncAll( $dry_run = false )
{
	$error = '';

	$clerk_users = ClerkListAllUsers( $error );

	if ( $clerk_users === false )
	{
		return [ 'error' => $error, 'counts' => [], 'rows' => [] ];
	}

	$by_external_id = $by_username = [];

	foreach ( $clerk_users as $clerk_user )
	{
		if ( ! empty( $clerk_user['external_id'] ) )
		{
			$by_external_id[ $clerk_user['external_id'] ] = $clerk_user;
		}

		if ( ! empty( $clerk_user['username'] ) )
		{
			$by_username[ mb_strtolower( $clerk_user['username'] ) ] = $clerk_user;
		}
	}

	$records = ClerkRosarioUsers();

	// Clerk user ID => external ID of the record linked to it.
	$claimed = [];

	$links = [];

	// First, link by external ID, so a renamed user keeps its Clerk user.
	foreach ( $records as $external_id => $record )
	{
		if ( isset( $by_external_id[ $external_id ] ) )
		{
			$links[ $external_id ] = $by_external_id[ $external_id ];

			$claimed[ $by_external_id[ $external_id ]['id'] ] = $external_id;
		}
	}

	$counts = [];

	$rows = [];

	$log = function( $record, $clerk_user, $sync ) use ( &$counts, &$rows )
	{
		$counts[ $sync['action'] ] = issetVal( $counts[ $sync['action'] ], 0 ) + 1;

		if ( in_array( $sync['action'], [ 'unchanged', 'skip' ] ) )
		{
			return;
		}

		$rows[] = [
			'EXTERNAL_ID' => $record ? $record['external_id'] : $clerk_user['external_id'],
			'USERNAME' => $record ? $record['username'] : (string) issetVal( $clerk_user['username'], '' ),
			'ACTION' => $sync['action'],
			'MESSAGE' => $sync['message'],
		];
	};

	foreach ( $records as $external_id => $record )
	{
		$clerk_user = issetVal( $links[ $external_id ] );

		if ( ! $clerk_user
			&& $record['status'] === 'active'
			&& isset( $by_username[ mb_strtolower( $record['username'] ) ] ) )
		{
			// Then, adopt by username. Active users only: never adopt a Clerk user just to ban it.
			$candidate = $by_username[ mb_strtolower( $record['username'] ) ];

			$adopt_error = isset( $claimed[ $candidate['id'] ] ) ?
				sprintf(
					dgettext( 'Clerk', 'The Clerk username "%s" already belongs to another account (external ID: %s).' ),
					$record['username'],
					$claimed[ $candidate['id'] ]
				) :
				ClerkAdoptError( $record, $candidate );

			if ( $adopt_error )
			{
				$log( $record, null, [ 'action' => 'error', 'message' => $adopt_error ] );

				continue;
			}

			$clerk_user = $candidate;

			$claimed[ $candidate['id'] ] = $external_id;
		}

		$log( $record, $clerk_user, ClerkSyncRecord( $record, $clerk_user, $dry_run ) );
	}

	// Managed Clerk users with no RosarioSIS user any more (deleted, not rolled over).
	foreach ( $by_external_id as $external_id => $clerk_user )
	{
		if ( isset( $claimed[ $clerk_user['id'] ] )
			|| ! ClerkParseExternalID( $external_id ) )
		{
			continue;
		}

		$log( null, $clerk_user, ClerkSyncRecord( null, $clerk_user, $dry_run ) );
	}

	if ( ! $dry_run )
	{
		ClerkSaveLog( $rows, $counts );
	}

	return [ 'error' => '', 'counts' => $counts, 'rows' => $rows ];
}

/**
 * Sync one RosarioSIS user to Clerk
 * Called after a user is created, updated or deleted in RosarioSIS.
 *
 * @param string $type     staff or student.
 * @param int    $id       STAFF_ID or STUDENT_ID (any school year for staff).
 * @param string $password New RosarioSIS password, to push to Clerk. Optional.
 *
 * @return array See ClerkSyncRecord().
 */
function ClerkSyncOne( $type, $id, $password = '' )
{
	$id = (int) $id;

	if ( $type === 'staff' )
	{
		// The default school year user with the same username (the edited one may be from another year).
		$records = ClerkRosarioUsers( "(s.STAFF_ID='" . $id . "'
			OR s.USERNAME=(SELECT st2.USERNAME FROM staff st2 WHERE st2.STAFF_ID='" . $id . "'))", false );
	}
	else
	{
		$records = ClerkRosarioUsers( false, "s.STUDENT_ID='" . $id . "'" );
	}

	$record = $records ? reset( $records ) : null;

	$external_id = $record ? $record['external_id'] : ClerkExternalID( $type, $id );

	$error = '';

	$clerk_user = ClerkFindUser( 'external_id', $external_id, $error );

	if ( ! $clerk_user
		&& $clerk_user !== false
		&& $record
		&& $record['status'] === 'active' )
	{
		$candidate = ClerkFindUser( 'username', $record['username'], $error );

		if ( $candidate )
		{
			$adopt_error = ClerkAdoptError( $record, $candidate );

			if ( $adopt_error )
			{
				$error = $adopt_error;

				$clerk_user = false;
			}
			else
			{
				$clerk_user = $candidate;
			}
		}
		elseif ( $candidate === false )
		{
			$clerk_user = false;
		}
	}

	if ( $clerk_user === false )
	{
		$sync = [ 'action' => 'error', 'message' => $error ];
	}
	else
	{
		$sync = ClerkSyncRecord( $record, $clerk_user );

		if ( $password !== ''
			&& $sync['action'] !== 'error'
			&& $record
			&& $record['status'] === 'active' )
		{
			$password_error = ClerkSetPassword( $external_id, $password );

			if ( $password_error )
			{
				$sync = [ 'action' => 'error', 'message' => 'Password: ' . $password_error ];
			}
			elseif ( $sync['action'] === 'unchanged' )
			{
				$sync = [ 'action' => 'update', 'message' => 'password' ];
			}
			else
			{
				$sync['message'] = trim( $sync['message'] . ', password', ', ' );
			}
		}
	}

	if ( ! in_array( $sync['action'], [ 'unchanged', 'skip' ] ) )
	{
		ClerkSaveLog( [ [
			'EXTERNAL_ID' => $external_id,
			'USERNAME' => $record ? $record['username'] : '',
			'ACTION' => $sync['action'],
			'MESSAGE' => $sync['message'],
		] ] );
	}

	if ( $sync['action'] === 'error' )
	{
		error_log( 'Clerk: ' . $external_id . ': ' . $sync['message'] );
	}

	return $sync;
}

/**
 * Set the password of a Clerk user
 *
 * @param string $external_id External ID.
 * @param string $password    Password.
 *
 * @return string Error message, '' on success.
 */
function ClerkSetPassword( $external_id, $password )
{
	$error = '';

	$clerk_user = ClerkFindUser( 'external_id', $external_id, $error );

	if ( ! $clerk_user )
	{
		return $error ? $error : dgettext( 'Clerk', 'Not in Clerk yet: run the sync first.' );
	}

	$response = ClerkAPI( 'PATCH', '/users/' . rawurlencode( $clerk_user['id'] ), [
		'password' => $password,
		'skip_password_checks' => true,
	] );

	return $response['error'];
}

/**
 * Reset Clerk passwords to the default password
 *
 * @param string $username Username, '' for every active user.
 *
 * @return array [ 'ok' => count, 'rows' => log rows (errors & users without birthdate) ]
 */
function ClerkResetPasswords( $username = '' )
{
	$where = $username !== '' ? "UPPER(s.USERNAME)=UPPER('" . DBEscapeString( $username ) . "')" : '';

	$ok = 0;

	$rows = [];

	foreach ( ClerkRosarioUsers( $where, $where ) as $record )
	{
		if ( $record['status'] !== 'active' )
		{
			continue;
		}

		$password = ClerkDefaultPassword( $record );

		$error = $password ?
			ClerkSetPassword( $record['external_id'], $password ) :
			dgettext( 'Clerk', 'No birthdate: no default password.' );

		if ( $error )
		{
			$rows[] = [
				'EXTERNAL_ID' => $record['external_id'],
				'USERNAME' => $record['username'],
				'ACTION' => 'error',
				'MESSAGE' => 'Password: ' . $error,
			];
		}
		else
		{
			$ok++;
		}
	}

	ClerkSaveLog( $rows );

	return [ 'ok' => $ok, 'rows' => $rows ];
}

/**
 * Save sync results
 * Keeps 90 days of log.
 *
 * @param array      $rows   Log rows.
 * @param array|null $counts Action counts of a full sync, saved as the last sync summary.
 */
function ClerkSaveLog( $rows, $counts = null )
{
	foreach ( $rows as $row )
	{
		DBInsert( 'clerk_sync_log', [
			'EXTERNAL_ID' => DBEscapeString( mb_substr( $row['EXTERNAL_ID'], 0, 100 ) ),
			'USERNAME' => DBEscapeString( mb_substr( $row['USERNAME'], 0, 100 ) ),
			'ACTION' => DBEscapeString( $row['ACTION'] ),
			'MESSAGE' => DBEscapeString( mb_substr( $row['MESSAGE'], 0, 1000 ) ),
		] );
	}

	if ( $counts !== null )
	{
		Config( 'CLERK_LAST_SYNC', DBEscapeString( json_encode( [
			'date' => date( 'Y-m-d H:i:s' ),
			'counts' => $counts,
		] ) ) );
	}

	DBQuery( "DELETE FROM clerk_sync_log
		WHERE CREATED_AT<'" . date( 'Y-m-d', strtotime( '-90 days' ) ) . "'" );
}

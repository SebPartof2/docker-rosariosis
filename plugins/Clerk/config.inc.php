<?php
/**
 * Plugin configuration interface
 *
 * Connection status, settings, sync (preview or run), password reset & sync log.
 *
 * @package Clerk plugin
 */

// Check the script is called by the right program & plugin is activated.
if ( $_REQUEST['modname'] !== 'School_Setup/Configuration.php'
	|| ! $RosarioPlugins['Clerk']
	|| $_REQUEST['modfunc'] !== 'config' )
{
	$error[] = _( 'You\'re not allowed to use this program!' );

	echo ErrorMessage( $error, 'fatal' );
}

require_once 'plugins/Clerk/includes/SSO.fnc.php';

// Note: no need to call ProgramTitle() here!

$config_url = 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&tab=plugins&modfunc=config&plugin=Clerk';

$action_labels = [
	'create' => dgettext( 'Clerk', 'Created' ),
	'update' => dgettext( 'Clerk', 'Updated' ),
	'ban' => dgettext( 'Clerk', 'Banned' ),
	'unban' => dgettext( 'Clerk', 'Unbanned' ),
	'unchanged' => dgettext( 'Clerk', 'Unchanged' ),
	'skip' => dgettext( 'Clerk', 'Not synced (profile)' ),
	'error' => _( 'Error' ),
];

$result_rows = null;

$result_title = '';

$clerk_action = issetVal( $_REQUEST['clerk_action'], '' );

if ( $clerk_action
	&& AllowEdit()
	&& User( 'PROFILE' ) === 'admin' )
{
	if ( $clerk_action === 'save' )
	{
		$profiles = array_intersect(
			(array) issetVal( $_REQUEST['values']['SYNC_PROFILES'], [] ),
			[ 'admin', 'teacher', 'parent', 'student' ]
		);

		Config( 'CLERK_SYNC_PROFILES', $profiles ? ',' . implode( ',', $profiles ) . ',' : '' );

		Config( 'CLERK_STUDENT_BIRTHDATE_FIELD', (string) (int) issetVal( $_REQUEST['values']['STUDENT_BIRTHDATE_FIELD'] ) ?: '' );

		Config( 'CLERK_STAFF_BIRTHDATE_FIELD', (string) (int) issetVal( $_REQUEST['values']['STAFF_BIRTHDATE_FIELD'] ) ?: '' );

		$selects = [
			'PASSWORD_MONTH' => [ '01', '1', 'january', 'jan' ],
			'PASSWORD_CASE' => [ 'lower', 'asis' ],
			'INACTIVE' => [ 'ban', 'keep' ],
		];

		foreach ( $selects as $item => $allowed )
		{
			if ( in_array( issetVal( $_REQUEST['values'][ $item ] ), $allowed, true ) )
			{
				Config( 'CLERK_' . $item, $_REQUEST['values'][ $item ] );
			}
		}

		foreach ( [ 'AUTO_SYNC', 'PUSH_PASSWORDS', 'SSO' ] as $item )
		{
			Config( 'CLERK_' . $item, empty( $_REQUEST['values'][ $item ] ) ? '' : 'Y' );
		}

		Config( 'CLERK_SSO_LABEL', mb_substr( trim( issetVal( $_REQUEST['values']['SSO_LABEL'], '' ) ), 0, 100 ) );

		$note[] = button( 'check' ) . '&nbsp;' . _( 'The plugin configuration has been modified.' );
	}
	elseif ( $clerk_action === 'test' )
	{
		$response = ClerkAPI( 'GET', '/users/count' );

		if ( $response['error'] )
		{
			$error[] = dgettext( 'Clerk', 'Clerk connection failed:' ) . ' ' . htmlspecialchars( $response['error'], ENT_QUOTES );
		}
		else
		{
			$note[] = button( 'check' ) . '&nbsp;' . sprintf(
				dgettext( 'Clerk', 'Connected to Clerk: %d users.' ),
				issetVal( $response['body']['total_count'], 0 )
			);
		}
	}
	elseif ( $clerk_action === 'sync'
		|| $clerk_action === 'preview' )
	{
		@set_time_limit( 0 );

		$sync = ClerkSyncAll( $clerk_action === 'preview' );

		if ( $sync['error'] )
		{
			$error[] = dgettext( 'Clerk', 'Clerk connection failed:' ) . ' ' . htmlspecialchars( $sync['error'], ENT_QUOTES );
		}
		else
		{
			$counts = [];

			foreach ( $sync['counts'] as $action => $count )
			{
				$counts[] = $action_labels[ $action ] . ': ' . $count;
			}

			$note[] = button( 'check' ) . '&nbsp;' . ( $clerk_action === 'preview' ?
				dgettext( 'Clerk', 'Preview, nothing was changed in Clerk.' ) :
				dgettext( 'Clerk', 'Sync done.' ) ) . ' ' . ( $counts ? implode( ', ', $counts ) : '' );

			$result_rows = $sync['rows'];

			$result_title = $clerk_action === 'preview' ?
				dgettext( 'Clerk', 'Preview' ) :
				dgettext( 'Clerk', 'Sync Results' );
		}
	}
	elseif ( $clerk_action === 'reset_passwords' )
	{
		$username = trim( (string) issetVal( $_POST['reset_username'], '' ) );

		if ( $username === ''
			&& empty( $_REQUEST['reset_all'] ) )
		{
			$error[] = dgettext( 'Clerk', 'Enter a username, or check "Every user".' );
		}
		else
		{
			@set_time_limit( 0 );

			$reset = ClerkResetPasswords( $username );

			$note[] = button( 'check' ) . '&nbsp;' . sprintf(
				dgettext( 'Clerk', 'Passwords reset to the default password: %d.' ),
				$reset['ok']
			);

			$result_rows = $reset['rows'];

			$result_title = dgettext( 'Clerk', 'Reset Passwords' );
		}
	}
}

// Unset action & redirect URL, so reloading the page does not sync again.
RedirectURL( [ 'clerk_action', 'values', 'reset_username', 'reset_all' ] );

echo ErrorMessage( $note, 'note' );

echo ErrorMessage( $error, 'error' );

/**
 * Select input, plain HTML
 *
 * @param string $name    values[] key.
 * @param string $value   Current value.
 * @param array  $options Options.
 * @param string $title   Label.
 *
 * @return string
 */
function _clerkSelect( $name, $value, $options, $title )
{
	$html = '<label><select name="values[' . $name . ']"' . ( AllowEdit() ? '' : ' disabled' ) . '>';

	foreach ( $options as $key => $label )
	{
		$html .= '<option value="' . AttrEscape( $key ) . '"' .
			( (string) $key === (string) $value ? ' selected' : '' ) . '>' .
			htmlspecialchars( $label, ENT_QUOTES ) . '</option>';
	}

	return $html . '</select> ' . $title . '</label>';
}

/**
 * Checkbox input, plain HTML
 *
 * @param string $name    values[] key (add [] for multiple).
 * @param string $value   Checkbox value.
 * @param bool   $checked Checked.
 * @param string $title   Label.
 *
 * @return string
 */
function _clerkCheckbox( $name, $value, $checked, $title )
{
	return '<label><input type="checkbox" name="values[' . $name . ']" value="' . AttrEscape( $value ) . '"' .
		( $checked ? ' checked' : '' ) . ( AllowEdit() ? '' : ' disabled' ) . ' /> ' . $title . '</label>';
}

// Connection.
echo '<br />';

PopTable( 'header', dgettext( 'Clerk', 'Clerk' ) );

echo '<p>' . dgettext(
	'Clerk',
	'Sync RosarioSIS users to Clerk, and sign in to RosarioSIS with Clerk. RosarioSIS is the source of truth: changes made in Clerk to synced users (username, name, email) are overwritten by the next sync.'
) . '</p>';

$yes_no = function( $set )
{
	return $set ? button( 'check' ) . ' ' . _( 'Yes' ) : button( 'x' ) . ' ' . _( 'No' );
};

echo '<table class="width-100p">';

$env_sources = [
	'env' => dgettext( 'Clerk', 'environment variable' ),
	'constant' => dgettext( 'Clerk', 'config.inc.php constant' ),
	'empty' => dgettext( 'Clerk', 'set, but empty' ),
];

$env_missing = false;

foreach ( [ 'CLERK_SECRET_KEY', 'CLERK_FRONTEND_API', 'CLERK_OAUTH_CLIENT_ID', 'CLERK_OAUTH_CLIENT_SECRET' ] as $env )
{
	$source = ClerkEnvSource( $env );

	$env_missing = $env_missing || ! in_array( $source, [ 'env', 'constant' ] );

	echo '<tr><td>' . NoInput(
		$yes_no( in_array( $source, [ 'env', 'constant' ] ) ) . ( $source ? ' (' . $env_sources[ $source ] . ')' : '' ),
		'<code>' . $env . '</code>'
	) . '</td></tr>';
}

if ( $env_missing )
{
	// Diagnostic: variable names only, never values.
	$env_names = array_keys( (array) getenv() );

	$clerk_like = preg_grep( '/clerk/i', $env_names );

	echo '<tr><td>' . NoInput(
		( $clerk_like ?
			'<code>' . htmlspecialchars( implode( ', ', array_map( 'json_encode', $clerk_like ) ), ENT_QUOTES ) . '</code>' :
			dgettext( 'Clerk', 'None' ) ),
		dgettext( 'Clerk', 'Variables containing "clerk" seen by PHP (check spelling, case, spaces)' )
	) . '</td></tr>';

	echo '<tr><td>' . NoInput(
		$yes_no( getenv( 'ROSARIO_REST_API_SECRET' ) !== false ) . ' &nbsp; ' .
		dgettext( 'Clerk', 'Compose variables reach PHP:' ) . ' ' .
		count( $env_names ) . ' ' . dgettext( 'Clerk', 'variables seen' ),
		'<code>ROSARIO_REST_API_SECRET</code>'
	) . '</td></tr>';
}

echo '<tr><td>' . NoInput(
	'<code style="user-select: all;">' . htmlspecialchars( ClerkSSORedirectURI(), ENT_QUOTES ) . '</code>',
	dgettext( 'Clerk', 'OAuth Redirect URI (add it to the Clerk OAuth application)' )
) . '</td></tr>';

$last_sync = json_decode( ClerkConfig( 'LAST_SYNC' ), true );

if ( $last_sync )
{
	$counts = [];

	foreach ( (array) $last_sync['counts'] as $action => $count )
	{
		$counts[] = issetVal( $action_labels[ $action ], $action ) . ': ' . (int) $count;
	}

	echo '<tr><td>' . NoInput(
		htmlspecialchars( $last_sync['date'], ENT_QUOTES ) . ( $counts ? ' &mdash; ' . implode( ', ', $counts ) : '' ),
		dgettext( 'Clerk', 'Last Full Sync' )
	) . '</td></tr>';
}

echo '</table>';

echo '<p class="size-1">' . dgettext(
	'Clerk',
	'Secrets are environment variables of the web container, never stored in the database. See the plugin README.'
) . '</p>';

if ( AllowEdit()
	&& ClerkSecretKey() )
{
	echo '<form action="' . URLEscape( $config_url . '&clerk_action=test' ) . '" method="POST" class="center">' .
		SubmitButton( dgettext( 'Clerk', 'Test Connection' ), '', '' ) . '</form>';
}

PopTable( 'footer' );

// Settings.
$student_date_fields = [ '' => _( 'N/A' ) ];

foreach ( DBGet( "SELECT ID,TITLE FROM custom_fields WHERE TYPE='date' ORDER BY SORT_ORDER IS NULL,SORT_ORDER,ID", [ 'TITLE' => 'ParseMLField' ] ) as $field )
{
	$student_date_fields[ $field['ID'] ] = $field['TITLE'];
}

$staff_date_fields = [ '' => _( 'N/A' ) ];

foreach ( DBGet( "SELECT ID,TITLE FROM staff_fields WHERE TYPE='date' ORDER BY SORT_ORDER IS NULL,SORT_ORDER,ID", [ 'TITLE' => 'ParseMLField' ] ) as $field )
{
	$staff_date_fields[ $field['ID'] ] = $field['TITLE'];
}

$profiles = ClerkSyncProfiles();

echo '<br /><form action="' . URLEscape( $config_url . '&clerk_action=save' ) . '" method="POST">';

PopTable( 'header', _( 'Configuration' ) );

echo '<table class="width-100p">';

echo '<tr><td><b>' . dgettext( 'Clerk', 'Sync these profiles' ) . '</b><br />';

foreach ( [ 'admin' => _( 'Administrator' ), 'teacher' => _( 'Teacher' ), 'parent' => _( 'Parent' ), 'student' => _( 'Student' ) ] as $profile => $label )
{
	echo _clerkCheckbox( 'SYNC_PROFILES][', $profile, in_array( $profile, $profiles ), $label ) . ' &nbsp; ';
}

echo '<br /><span class="legend-gray">' . dgettext(
	'Clerk',
	'Users of the default school year and students, with a username. Users of other profiles are left alone in Clerk.'
) . '</span></td></tr>';

echo '<tr><td><b>' . dgettext( 'Clerk', 'Default password' ) . '</b>: ' . dgettext(
	'Clerk',
	'first name + last name + month of birth, letters and digits only. Example: John O\'Neil born in March: johnoneil03. Set when the user is created in Clerk.'
) . '</td></tr>';

echo '<tr><td>' . _clerkSelect( 'STUDENT_BIRTHDATE_FIELD', ClerkConfig( 'STUDENT_BIRTHDATE_FIELD' ), $student_date_fields, dgettext( 'Clerk', 'Student birthdate field' ) ) . '</td></tr>';

echo '<tr><td>' . _clerkSelect( 'STAFF_BIRTHDATE_FIELD', ClerkConfig( 'STAFF_BIRTHDATE_FIELD' ), $staff_date_fields, dgettext( 'Clerk', 'User birthdate field' ) ) . '</td></tr>';

echo '<tr><td>' . _clerkSelect( 'PASSWORD_MONTH', ClerkConfig( 'PASSWORD_MONTH' ), [
	'01' => '03 (' . dgettext( 'Clerk', 'two digits' ) . ')',
	'1' => '3',
	'january' => 'march',
	'jan' => 'mar',
], dgettext( 'Clerk', 'Month format' ) ) . '</td></tr>';

echo '<tr><td>' . _clerkSelect( 'PASSWORD_CASE', ClerkConfig( 'PASSWORD_CASE' ), [
	'lower' => 'johnoneil03',
	'asis' => 'JohnONeil03',
], dgettext( 'Clerk', 'Name case' ) ) . '<br /><span class="legend-gray">' . dgettext(
	'Clerk',
	'Users without a birthdate are created without a password: they can still use Sign in with Clerk methods that need none (email code, Google...).'
) . '</span></td></tr>';

echo '<tr><td>' . _clerkSelect( 'INACTIVE', ClerkConfig( 'INACTIVE' ), [
	'ban' => dgettext( 'Clerk', 'Ban in Clerk' ),
	'keep' => dgettext( 'Clerk', 'Do nothing' ),
], dgettext( 'Clerk', 'Inactive or deleted users' ) ) . '<br /><span class="legend-gray">' . dgettext(
	'Clerk',
	'Inactive: No Access profile, student not enrolled today. Unbanned when active again.'
) . '</span></td></tr>';

echo '<tr><td>' . _clerkCheckbox( 'AUTO_SYNC', 'Y', ClerkConfig( 'AUTO_SYNC' ) === 'Y', dgettext( 'Clerk', 'Sync users when they are saved in RosarioSIS' ) ) . '</td></tr>';

echo '<tr><td>' . _clerkCheckbox( 'PUSH_PASSWORDS', 'Y', ClerkConfig( 'PUSH_PASSWORDS' ) === 'Y', dgettext( 'Clerk', 'Send passwords changed in RosarioSIS to Clerk' ) ) . '</td></tr>';

echo '<tr><td>' . _clerkCheckbox( 'SSO', 'Y', ClerkConfig( 'SSO' ) === 'Y', dgettext( 'Clerk', 'Sign in with Clerk on the login page' ) ) .
	( ClerkSSOConfigured() ? '' : ' <span class="legend-red">' . dgettext( 'Clerk', 'OAuth environment variables missing' ) . '</span>' ) . '</td></tr>';

echo '<tr><td><label><input type="text" name="values[SSO_LABEL]" value="' . AttrEscape( ClerkConfig( 'SSO_LABEL' ) ) . '" maxlength="100" size="30" placeholder="' .
	AttrEscape( dgettext( 'Clerk', 'Sign in with Clerk' ) ) . '"' . ( AllowEdit() ? '' : ' disabled' ) . ' /> ' .
	dgettext( 'Clerk', 'Login link text' ) . '</label></td></tr>';

echo '</table>';

PopTable( 'footer' );

if ( AllowEdit() )
{
	echo '<br /><div class="center">' . SubmitButton() . '</div>';
}

echo '</form>';

// Sync.
if ( AllowEdit()
	&& ClerkSecretKey() )
{
	echo '<br />';

	PopTable( 'header', dgettext( 'Clerk', 'Sync' ) );

	echo '<p>' . dgettext(
		'Clerk',
		'Preview lists what the sync would change, without changing anything. To sync on a schedule, run plugins/Clerk/sync.php from cron (see README).'
	) . '</p>';

	echo '<table class="width-100p"><tr><td class="center">';

	echo '<form action="' . URLEscape( $config_url . '&clerk_action=preview' ) . '" method="POST" style="display: inline-block;">' .
		SubmitButton( dgettext( 'Clerk', 'Preview' ), '', '' ) . '</form> &nbsp; ';

	echo '<form action="' . URLEscape( $config_url . '&clerk_action=sync' ) . '" method="POST" style="display: inline-block;">' .
		SubmitButton( dgettext( 'Clerk', 'Sync Now' ) ) . '</form>';

	echo '</td></tr></table>';

	PopTable( 'footer' );

	echo '<br /><form action="' . URLEscape( $config_url . '&clerk_action=reset_passwords' ) . '" method="POST">';

	PopTable( 'header', dgettext( 'Clerk', 'Reset Passwords' ) );

	echo '<p>' . dgettext(
		'Clerk',
		'Set the Clerk password back to the default password (first name + last name + month of birth). Signs nobody out.'
	) . '</p>';

	echo '<table class="width-100p"><tr><td>';

	echo '<label><input type="text" name="reset_username" maxlength="100" size="20" /> ' . _( 'Username' ) . '</label>';

	echo ' &nbsp; <label><input type="checkbox" name="reset_all" value="Y" /> ' . dgettext( 'Clerk', 'Every user' ) . '</label>';

	echo '</td></tr></table>';

	echo '<div class="center">' . SubmitButton( dgettext( 'Clerk', 'Reset Passwords' ) ) . '</div>';

	PopTable( 'footer' );

	echo '</form>';
}

// Results of this action, or latest log.
if ( $result_rows === null )
{
	$result_rows = DBGet( "SELECT CREATED_AT,USERNAME,EXTERNAL_ID,ACTION,MESSAGE
		FROM clerk_sync_log
		ORDER BY ID DESC
		LIMIT 200" );

	$result_title = dgettext( 'Clerk', 'Sync Log (latest 200)' );
}
else
{
	$result_rows = $result_rows ? array_combine( range( 1, count( $result_rows ) ), $result_rows ) : [];
}

foreach ( $result_rows as $i => $row )
{
	$result_rows[ $i ]['ACTION'] = issetVal( $action_labels[ $row['ACTION'] ], $row['ACTION'] );

	$result_rows[ $i ]['MESSAGE'] = htmlspecialchars( (string) $row['MESSAGE'], ENT_QUOTES );

	$result_rows[ $i ]['USERNAME'] = htmlspecialchars( (string) $row['USERNAME'], ENT_QUOTES );
}

$columns = [
	'USERNAME' => _( 'Username' ),
	'EXTERNAL_ID' => dgettext( 'Clerk', 'External ID' ),
	'ACTION' => _( 'Action' ),
	'MESSAGE' => dgettext( 'Clerk', 'Details' ),
];

if ( isset( reset( $result_rows )['CREATED_AT'] ) )
{
	$columns = [ 'CREATED_AT' => _( 'Date' ) ] + $columns;
}

echo '<br /><h3>' . $result_title . '</h3>';

ListOutput(
	$result_rows,
	$columns,
	dgettext( 'Clerk', 'Change' ),
	dgettext( 'Clerk', 'Changes' ),
	[],
	[],
	[ 'search' => false, 'save' => false ]
);

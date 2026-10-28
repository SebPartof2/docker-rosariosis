<?php
/**
 * Functions
 *
 * - Sign in with Clerk: login page link & SSO requests (index.php?clerk_sso=...).
 * - Automatic sync: users created, updated or deleted in RosarioSIS are synced
 *   to Clerk at the end of the request, once the changes are saved.
 *   Passwords set in RosarioSIS are pushed to Clerk.
 *
 * @package Clerk plugin
 */

require_once 'plugins/Clerk/includes/SSO.fnc.php';

if ( basename( $_SERVER['SCRIPT_NAME'] ) === 'index.php' )
{
	if ( isset( $_GET['clerk_sso'] ) )
	{
		ClerkSSOHandle( (string) $_GET['clerk_sso'] );
	}

	if ( isset( $_GET['clerk_sso_error'] ) )
	{
		ClerkLoginError( (string) $_GET['clerk_sso_error'] );
	}

	add_action( 'index.php|login_form_link', 'ClerkLoginLink' );
}

if ( ClerkConfig( 'AUTO_SYNC' ) === 'Y'
	&& ClerkSecretKey() )
{
	add_action( 'Users/User.php|create_user', 'ClerkQueueStaff' );
	add_action( 'Users/User.php|update_user_checks', 'ClerkQueueStaff' );
	add_action( 'Users/User.php|delete_user', 'ClerkQueueStaff' );
	add_action( 'Users/Preferences.php|update_password', 'ClerkQueuePreferences' );

	add_action( 'Students/Student.php|create_student', 'ClerkQueueStudent' );
	add_action( 'Students/Student.php|update_student_checks', 'ClerkQueueStudent' );
	add_action( 'Students/Student.php|delete_student', 'ClerkQueueStudent' );
}

/**
 * "Sign in with Clerk" link on the login page
 *
 * @uses index.php|login_form_link action hook
 */
function ClerkLoginLink()
{
	if ( ! ClerkSSOEnabled() )
	{
		return;
	}

	$label = ClerkConfig( 'SSO_LABEL' ) ? ClerkConfig( 'SSO_LABEL' ) : dgettext( 'Clerk', 'Sign in with Clerk' );
	// A link, not a button: this is inside the login form.
	?>
	<p class="center">
		<a href="index.php?clerk_sso=start" rel="nofollow"><b><?php echo htmlspecialchars( $label, ENT_QUOTES ); ?></b></a>
	</p>
	<?php
}

/**
 * SSO error on the login page
 * Message from a fixed list: the reason code is never output.
 *
 * @global $error
 *
 * @param string $reason Error code.
 */
function ClerkLoginError( $reason )
{
	global $error;

	$error[] = ClerkSSOErrorMessage( $reason );
}

/**
 * Queue a user to sync at the end of the request
 * Shutdown function: the RosarioSIS changes are saved by then, and an update
 * that failed its checks can be told apart.
 *
 * @param string $type     staff or student.
 * @param int    $id       STAFF_ID or STUDENT_ID.
 * @param string $password Password set in RosarioSIS (plain text), '' if none.
 */
function ClerkQueue( $type, $id, $password = '' )
{
	global $ClerkQueue;

	if ( ! $id
		|| $id === 'new' )
	{
		return;
	}

	if ( ! $ClerkQueue )
	{
		register_shutdown_function( 'ClerkProcessQueue' );
	}

	$ClerkQueue[ $type . '-' . (int) $id ] = [ $type, (int) $id, (string) $password ];
}

/**
 * @uses Users/User.php hooks
 *
 * @param string $hook_tag Hook tag.
 */
function ClerkQueueStaff( $hook_tag )
{
	$id = $hook_tag === 'Users/User.php|delete_user' ? issetVal( $_REQUEST['staff_id'] ) : UserStaffID();

	ClerkQueue( 'staff', $id, ClerkPushPasswords() ? issetVal( $_POST['staff']['PASSWORD'], '' ) : '' );
}

/**
 * @uses Students/Student.php hooks
 *
 * @param string $hook_tag Hook tag.
 */
function ClerkQueueStudent( $hook_tag )
{
	$id = $hook_tag === 'Students/Student.php|delete_student' ? issetVal( $_REQUEST['student_id'] ) : UserStudentID();

	ClerkQueue( 'student', $id, ClerkPushPasswords() ? issetVal( $_POST['students']['PASSWORD'], '' ) : '' );
}

/**
 * User changed their own password
 *
 * @uses Users/Preferences.php|update_password action hook
 */
function ClerkQueuePreferences()
{
	if ( ! ClerkPushPasswords() )
	{
		return;
	}

	if ( User( 'PROFILE' ) === 'student' )
	{
		ClerkQueue( 'student', UserStudentID(), issetVal( $_POST['values']['new'], '' ) );
	}
	else
	{
		ClerkQueue( 'staff', User( 'STAFF_ID' ), issetVal( $_POST['values']['new'], '' ) );
	}
}

/**
 * Push passwords set in RosarioSIS to Clerk? (setting)
 *
 * @return bool
 */
function ClerkPushPasswords()
{
	return ClerkConfig( 'PUSH_PASSWORDS' ) === 'Y';
}

/**
 * Sync the queued users
 * A password is only pushed if it is the one saved in RosarioSIS.
 */
function ClerkProcessQueue()
{
	global $ClerkQueue;

	foreach ( (array) $ClerkQueue as $item )
	{
		list( $type, $id, $password ) = $item;

		if ( $password !== '' )
		{
			$hash = $type === 'staff' ?
				DBGetOne( "SELECT PASSWORD FROM staff WHERE STAFF_ID='" . $id . "'" ) :
				DBGetOne( "SELECT PASSWORD FROM students WHERE STUDENT_ID='" . $id . "'" );

			if ( ! $hash
				|| ! match_password( $hash, $password ) )
			{
				// Not saved (checks failed).
				$password = '';
			}
		}

		ClerkSyncOne( $type, $id, $password );
	}

	$ClerkQueue = [];
}

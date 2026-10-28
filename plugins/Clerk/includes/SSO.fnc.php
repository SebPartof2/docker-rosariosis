<?php
/**
 * Clerk SSO functions
 *
 * "Sign in with Clerk" on the RosarioSIS login page: OAuth 2.0 authorization
 * code flow with PKCE, Clerk being the identity provider (Clerk Dashboard >
 * OAuth applications).
 *
 * index.php?clerk_sso=start    Redirect to Clerk.
 * index.php?clerk_sso=callback Clerk redirects back here (the redirect URI).
 *
 * The Clerk user is mapped to its RosarioSIS user by its external ID, which
 * only the sync (Backend API) sets. Never by username or email: a user could
 * change those in Clerk.
 *
 * Handled from functions.php before index.php runs, so the session cookie
 * keeps the RosarioSIS path.
 *
 * @package Clerk plugin
 */

require_once 'plugins/Clerk/includes/Sync.fnc.php';

/**
 * OAuth state & PKCE verifier cookie name
 */
if ( ! defined( 'CLERK_SSO_COOKIE' ) )
{
	define( 'CLERK_SSO_COOKIE', 'RosarioClerkSSO' );
}

/**
 * Is "Sign in with Clerk" enabled and configured?
 *
 * @return bool
 */
function ClerkSSOEnabled()
{
	return ClerkConfig( 'SSO' ) === 'Y' && ClerkSSOConfigured();
}

/**
 * OAuth redirect URI, to add to the Clerk OAuth application
 * CLERK_OAUTH_REDIRECT_URI environment variable overrides it (reverse proxy).
 *
 * @return string
 */
function ClerkSSORedirectURI()
{
	if ( ClerkEnv( 'CLERK_OAUTH_REDIRECT_URI' ) )
	{
		return ClerkEnv( 'CLERK_OAUTH_REDIRECT_URI' );
	}

	// RosarioURL() is escaped for HTML.
	return html_entity_decode( RosarioURL( 'dir' ) ) . 'index.php?clerk_sso=callback';
}

/**
 * Base64 URL encoding, no padding (PKCE)
 *
 * @param string $data Data.
 *
 * @return string
 */
function ClerkBase64URL( $data )
{
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Set or clear the SSO cookie
 *
 * SameSite=Lax: it must be sent when Clerk redirects back (cross-site).
 * The RosarioSIS session cookie is SameSite=Strict and is not.
 *
 * @param string $value Value, '' to clear.
 */
function ClerkSSOCookie( $value )
{
	$path = dirname( $_SERVER['SCRIPT_NAME'] ) === DIRECTORY_SEPARATOR ?
		'/' : dirname( $_SERVER['SCRIPT_NAME'] ) . '/';

	setcookie( CLERK_SSO_COOKIE, $value, [
		'expires' => $value === '' ? time() - 3600 : time() + 600,
		'path' => $path,
		'secure' => strpos( ClerkSSORedirectURI(), 'https://' ) === 0,
		'httponly' => true,
		'samesite' => 'Lax',
	] );
}

/**
 * Handle SSO requests, then exit
 *
 * @param string $step start or callback.
 */
function ClerkSSOHandle( $step )
{
	header( 'Cache-Control: no-store' );

	if ( ! ClerkSSOEnabled() )
	{
		ClerkSSOFail( 'disabled' );
	}

	if ( $step === 'start' )
	{
		ClerkSSOStart();
	}

	if ( $step === 'callback' )
	{
		ClerkSSOCallback();
	}

	ClerkSSOFail( 'failed' );
}

/**
 * Redirect to the Clerk authorization page
 */
function ClerkSSOStart()
{
	$state = bin2hex( random_bytes( 16 ) );

	$verifier = ClerkBase64URL( random_bytes( 32 ) );

	ClerkSSOCookie( $state . '.' . $verifier );

	$url = ClerkFrontendAPI() . '/oauth/authorize?' . http_build_query( [
		'response_type' => 'code',
		'client_id' => ClerkEnv( 'CLERK_OAUTH_CLIENT_ID' ),
		'redirect_uri' => ClerkSSORedirectURI(),
		'scope' => 'openid profile email',
		'state' => $state,
		'code_challenge' => ClerkBase64URL( hash( 'sha256', $verifier, true ) ),
		'code_challenge_method' => 'S256',
	], '', '&', PHP_QUERY_RFC3986 );

	header( 'Location: ' . $url );

	exit;
}

/**
 * Clerk redirected back: exchange the code, find the RosarioSIS user, log in
 */
function ClerkSSOCallback()
{
	$cookie = isset( $_COOKIE[ CLERK_SSO_COOKIE ] ) ? explode( '.', (string) $_COOKIE[ CLERK_SSO_COOKIE ], 2 ) : [];

	ClerkSSOCookie( '' );

	if ( ! empty( $_GET['error'] ) )
	{
		// For example access_denied: the user cancelled.
		ClerkSSOFail( 'cancelled' );
	}

	if ( count( $cookie ) !== 2
		|| empty( $_GET['state'] )
		|| empty( $_GET['code'] )
		|| ! hash_equals( $cookie[0], (string) $_GET['state'] ) )
	{
		// Expired (10 minutes), or not started from this browser.
		ClerkSSOFail( 'state' );
	}

	$response = ClerkHTTP(
		'POST',
		ClerkFrontendAPI() . '/oauth/token',
		[
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: Basic ' . base64_encode(
				rawurlencode( ClerkEnv( 'CLERK_OAUTH_CLIENT_ID' ) ) . ':' .
				rawurlencode( ClerkEnv( 'CLERK_OAUTH_CLIENT_SECRET' ) )
			),
		],
		http_build_query( [
			'grant_type' => 'authorization_code',
			'code' => (string) $_GET['code'],
			'redirect_uri' => ClerkSSORedirectURI(),
			'code_verifier' => $cookie[1],
		], '', '&' )
	);

	if ( $response['error']
		|| empty( $response['body']['access_token'] ) )
	{
		error_log( 'Clerk SSO: token: ' . ( $response['error'] ? $response['error'] : 'no access_token' ) );

		ClerkSSOFail( 'failed' );
	}

	$userinfo = ClerkHTTP(
		'GET',
		ClerkFrontendAPI() . '/oauth/userinfo',
		[ 'Authorization: Bearer ' . $response['body']['access_token'] ]
	);

	$clerk_user_id = (string) issetVal( $userinfo['body']['sub'], '' );

	if ( $userinfo['error']
		|| $clerk_user_id === '' )
	{
		error_log( 'Clerk SSO: userinfo: ' . ( $userinfo['error'] ? $userinfo['error'] : 'no sub' ) );

		ClerkSSOFail( 'failed' );
	}

	// External ID from the Backend API: users cannot change it.
	$clerk_user = ClerkAPI( 'GET', '/users/' . rawurlencode( $clerk_user_id ) );

	if ( $clerk_user['error'] )
	{
		error_log( 'Clerk SSO: user ' . $clerk_user_id . ': ' . $clerk_user['error'] );

		ClerkSSOFail( 'failed' );
	}

	$parsed = ClerkParseExternalID( issetVal( $clerk_user['body']['external_id'], '' ) );

	if ( ! $parsed )
	{
		ClerkSSOFail( 'not_linked' );
	}

	ClerkSSOLogin( $parsed[0], $parsed[1] );
}

/**
 * Log the RosarioSIS user in, as index.php does after a correct password
 *
 * @param string $type staff or student.
 * @param int    $id   STAFF_ID (any school year) or STUDENT_ID.
 */
function ClerkSSOLogin( $type, $id )
{
	$syear = Config( 'SYEAR' );

	if ( $type === 'staff' )
	{
		// Default school year user (the external ID may be from before Rollover).
		$user_RET = DBGet( "SELECT STAFF_ID,USERNAME,PROFILE,LAST_LOGIN
			FROM staff
			WHERE SYEAR='" . $syear . "'
			AND USERNAME=(SELECT st2.USERNAME FROM staff st2 WHERE st2.STAFF_ID='" . (int) $id . "')" );

		if ( ! $user_RET
			|| ! in_array( $user_RET[1]['PROFILE'], [ 'admin', 'teacher', 'parent' ] ) )
		{
			ClerkSSOFail( 'inactive' );
		}
	}
	else
	{
		$user_RET = DBGet( "SELECT s.STUDENT_ID,s.USERNAME,s.LAST_LOGIN
			FROM students s,student_enrollment se
			WHERE se.STUDENT_ID=s.STUDENT_ID
			AND s.STUDENT_ID='" . (int) $id . "'
			AND se.SYEAR='" . $syear . "'
			AND se.START_DATE<='" . DBDate() . "'
			AND (se.END_DATE IS NULL OR se.END_DATE>='" . DBDate() . "')" );

		if ( ! $user_RET )
		{
			ClerkSSOFail( 'inactive' );
		}
	}

	$user = $user_RET[1];

	session_regenerate_id( true );

	$_SESSION['token'] = bin2hex( random_bytes( 16 ) );

	unset( $_SESSION['STAFF_ID'], $_SESSION['STUDENT_ID'], $_SESSION['UserSchool'] );

	if ( $type === 'staff' )
	{
		$_SESSION['STAFF_ID'] = $user['STAFF_ID'];
	}
	else
	{
		$_SESSION['STUDENT_ID'] = $user['STUDENT_ID'];
	}

	$_SESSION['LAST_LOGIN'] = $user['LAST_LOGIN'];

	$_SESSION['UserSyear'] = $syear;

	$ip = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
		&& filter_var( $_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP ) ?
		$_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];

	if ( ! function_exists( 'AccessLogRecord' ) )
	{
		DBInsert( 'access_log', [
			'SYEAR' => $syear,
			'USERNAME' => DBEscapeString( mb_substr( $user['USERNAME'], 0, 100 ) ),
			'PROFILE' => User( 'PROFILE' ),
			'IP_ADDRESS' => $ip,
			'USER_AGENT' => DBEscapeString( issetVal( $_SERVER['HTTP_USER_AGENT'], '' ) ),
			'STATUS' => 'Y',
		] );
	}

	// Plugins hooked to the password login run too (those loaded before this one).
	do_action( 'index.php|login_check', DBEscapeString( $user['USERNAME'] ) );

	if ( $type === 'staff' )
	{
		DBQuery( "UPDATE staff
			SET LAST_LOGIN=CURRENT_TIMESTAMP,FAILED_LOGIN=NULL
			WHERE STAFF_ID='" . (int) $user['STAFF_ID'] . "'" );
	}
	else
	{
		DBQuery( "UPDATE students
			SET LAST_LOGIN=CURRENT_TIMESTAMP,FAILED_LOGIN=NULL
			WHERE STUDENT_ID='" . (int) $user['STUDENT_ID'] . "'" );
	}

	// Not a 302: the browser would treat Modules.php as part of the cross-site
	// navigation from Clerk and not send the SameSite=Strict session cookie.
	ClerkSSORedirect( 'Modules.php?modname=misc/Portal.php' );
}

/**
 * Same-site redirect, from the page itself
 *
 * @param string $url Relative URL.
 */
function ClerkSSORedirect( $url )
{
	?>
	<!DOCTYPE html>
	<html>
	<head>
	<meta http-equiv="refresh" content="0;URL=<?php echo AttrEscape( $url ); ?>" />
	</head>
	<body></body>
	</html>
	<?php
	exit;
}

/**
 * Back to the login page, with an error message
 *
 * @param string $reason See ClerkSSOErrorMessage().
 */
function ClerkSSOFail( $reason )
{
	ClerkSSORedirect( 'index.php?clerk_sso_error=' . rawurlencode( $reason ) );
}

/**
 * Login page error message
 *
 * @param string $reason Error code.
 *
 * @return string
 */
function ClerkSSOErrorMessage( $reason )
{
	$messages = [
		'disabled' => dgettext( 'Clerk', 'Sign in with Clerk is not available.' ),
		'cancelled' => dgettext( 'Clerk', 'Sign in with Clerk was cancelled.' ),
		'state' => dgettext( 'Clerk', 'Your sign in expired. Please try again.' ),
		'not_linked' => dgettext( 'Clerk', 'Your Clerk account is not linked to a RosarioSIS account.' ),
		'inactive' => _( 'Your account has not yet been activated.' ),
	];

	return issetVal( $messages[ $reason ], dgettext( 'Clerk', 'Sign in with Clerk failed. Please try again.' ) );
}

<?php
/**
 * Clerk API functions
 *
 * HTTP client for the Clerk Backend API and the Clerk OAuth endpoints.
 * Secrets come from environment variables, never from the database.
 *
 * @link https://clerk.com/docs/reference/backend-api
 *
 * @package Clerk plugin
 */

/**
 * Clerk Backend API base URL
 * CLERK_API_URL environment variable overrides it (tests against a mock server).
 */
if ( ! defined( 'CLERK_API_URL' ) )
{
	define( 'CLERK_API_URL', getenv( 'CLERK_API_URL' ) ? rtrim( getenv( 'CLERK_API_URL' ), '/' ) : 'https://api.clerk.com/v1' );
}

/**
 * External ID prefix of the Clerk users this plugin manages.
 * Full ID: rosario-staff-<STAFF_ID> or rosario-student-<STUDENT_ID>.
 */
if ( ! defined( 'CLERK_EXTERNAL_ID_PREFIX' ) )
{
	define( 'CLERK_EXTERNAL_ID_PREFIX', 'rosario-' );
}

/**
 * Environment variable
 *
 * @param string $name Variable name.
 *
 * @return string Value, '' if not set.
 */
function ClerkEnv( $name )
{
	$value = getenv( $name );

	return $value === false ? '' : trim( $value );
}

/**
 * Clerk Backend API secret key (sk_live_... or sk_test_...)
 *
 * @return string
 */
function ClerkSecretKey()
{
	return ClerkEnv( 'CLERK_SECRET_KEY' );
}

/**
 * Clerk Frontend API URL, used for SSO (OAuth)
 * Clerk Dashboard > Domains, for example https://clerk.example.com
 *
 * @return string URL without trailing slash, '' if not set.
 */
function ClerkFrontendAPI()
{
	$url = rtrim( ClerkEnv( 'CLERK_FRONTEND_API' ), '/' );

	if ( $url && strpos( $url, '://' ) === false )
	{
		$url = 'https://' . $url;
	}

	return $url;
}

/**
 * Is the SSO configured? OAuth application credentials + Frontend API.
 *
 * @return bool
 */
function ClerkSSOConfigured()
{
	return ClerkSecretKey()
		&& ClerkFrontendAPI()
		&& ClerkEnv( 'CLERK_OAUTH_CLIENT_ID' )
		&& ClerkEnv( 'CLERK_OAUTH_CLIENT_SECRET' );
}

/**
 * HTTP request
 *
 * Retries on 429 Too Many Requests, waiting for the Retry-After delay (10 seconds max).
 *
 * @param string      $method  GET, POST, PATCH, DELETE.
 * @param string      $url     Full URL.
 * @param array       $headers Request headers.
 * @param string|null $body    Request body.
 *
 * @return array [ 'status' => HTTP code (0 on network error), 'body' => decoded JSON or null, 'error' => message ]
 */
function ClerkHTTP( $method, $url, $headers = [], $body = null )
{
	for ( $attempt = 0; $attempt < 4; $attempt++ )
	{
		$retry_after = 0;

		$curl = curl_init( $url );

		curl_setopt_array( $curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array_merge( [ 'Accept: application/json' ], $headers ),
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HEADERFUNCTION => function( $curl, $header ) use ( &$retry_after )
			{
				if ( stripos( $header, 'Retry-After:' ) === 0 )
				{
					$retry_after = (int) trim( substr( $header, 12 ) );
				}

				return strlen( $header );
			},
		] );

		if ( $body !== null )
		{
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
		}

		$response = curl_exec( $curl );

		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );

		$curl_error = curl_error( $curl );

		curl_close( $curl );

		if ( $response === false )
		{
			return [ 'status' => 0, 'body' => null, 'error' => 'Network error: ' . $curl_error ];
		}

		if ( $status === 429
			&& $attempt < 3 )
		{
			sleep( min( max( $retry_after, 1 ), 10 ) );

			continue;
		}

		$decoded = json_decode( $response, true );

		return [
			'status' => $status,
			'body' => $decoded,
			'error' => $status >= 400 ? ClerkErrorMessage( $decoded, $status ) : '',
		];
	}
}

/**
 * Clerk error message from an error response
 *
 * @param array|null $body   Decoded response body.
 * @param int        $status HTTP status code.
 *
 * @return string
 */
function ClerkErrorMessage( $body, $status )
{
	if ( ! empty( $body['errors'][0] ) )
	{
		$error = $body['errors'][0];

		$message = ! empty( $error['long_message'] ) ? $error['long_message'] : issetVal( $error['message'], '' );

		return $message . ( ! empty( $error['code'] ) ? ' (' . $error['code'] . ')' : '' );
	}

	if ( ! empty( $body['error_description'] ) )
	{
		// OAuth error.
		return $body['error_description'];
	}

	return 'HTTP ' . $status;
}

/**
 * Clerk Backend API request
 *
 * @param string     $method GET, POST, PATCH, DELETE.
 * @param string     $path   Path, for example /users?limit=10.
 * @param array|null $data   JSON body.
 *
 * @return array See ClerkHTTP().
 */
function ClerkAPI( $method, $path, $data = null )
{
	if ( ! ClerkSecretKey() )
	{
		return [ 'status' => 0, 'body' => null, 'error' => 'CLERK_SECRET_KEY environment variable is not set.' ];
	}

	$headers = [ 'Authorization: Bearer ' . ClerkSecretKey() ];

	$body = null;

	if ( $data !== null )
	{
		$headers[] = 'Content-Type: application/json';

		$body = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	return ClerkHTTP( $method, CLERK_API_URL . $path, $headers, $body );
}

/**
 * Every Clerk user, using cursor pagination
 *
 * @param string $error Error message, by reference.
 *
 * @return array|false Clerk users, false on error.
 */
function ClerkListAllUsers( &$error = '' )
{
	$users = [];

	$after = '';

	do
	{
		$response = ClerkAPI( 'GET', '/users?limit=500' . ( $after ? '&starting_after=' . urlencode( $after ) : '' ) );

		if ( $response['error'] )
		{
			$error = $response['error'];

			return false;
		}

		$page = (array) $response['body'];

		$users = array_merge( $users, $page );

		$after = $page ? end( $page )['id'] : '';
	}
	while ( count( $page ) === 500 );

	return $users;
}

/**
 * Find Clerk users by external ID or username
 *
 * @param string $field external_id or username.
 * @param string $value Value.
 * @param string $error Error message, by reference.
 *
 * @return array|null|false Clerk user, null if not found, false on error.
 */
function ClerkFindUser( $field, $value, &$error = '' )
{
	if ( (string) $value === '' )
	{
		return null;
	}

	$response = ClerkAPI( 'GET', '/users?limit=1&' . $field . '=' . urlencode( $value ) );

	if ( $response['error'] )
	{
		$error = $response['error'];

		return false;
	}

	return empty( $response['body'][0] ) ? null : $response['body'][0];
}

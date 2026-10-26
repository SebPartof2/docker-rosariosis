<?php
/**
 * MCP endpoint (Streamable HTTP transport)
 *
 * POST JSON-RPC messages with the `Authorization: Bearer <token>` header.
 * Generate a token in School > Configuration > Plugins > MCP Server > Configuration.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#streamable-http
 *
 * @package MCP Server plugin
 */

define( 'MCP_SERVER_VERSION', '1.0' );

// Never let a PHP notice corrupt the JSON response.
ini_set( 'display_errors', '0' );

require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/includes/Common.fnc.php';
require_once __DIR__ . '/includes/Protocol.fnc.php';
require_once __DIR__ . '/includes/Tools.fnc.php';

/**
 * Send a JSON response and exit
 *
 * @param int        $status HTTP status code.
 * @param array|null $body   Response body, or null for no body.
 * @param array      $headers Additional headers.
 */
function MCPServerSend( $status, $body = null, $headers = [] )
{
	http_response_code( $status );

	foreach ( $headers as $header )
	{
		header( $header );
	}

	if ( $body !== null )
	{
		header( 'Content-Type: application/json; charset=utf-8' );

		echo json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	exit;
}

/**
 * Get the Bearer token from the Authorization header
 *
 * @return string Token, '' if none.
 */
function MCPServerBearerToken()
{
	$authorization = '';

	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) )
	{
		$authorization = $_SERVER['HTTP_AUTHORIZATION'];
	}
	elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) )
	{
		$authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	}
	elseif ( function_exists( 'getallheaders' ) )
	{
		// Apache mod_php may not expose the header in $_SERVER.
		foreach ( getallheaders() as $name => $value )
		{
			if ( strtolower( $name ) === 'authorization' )
			{
				$authorization = $value;
			}
		}
	}

	return preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ? $matches[1] : '';
}

header( 'Cache-Control: no-store' );
header( 'X-Content-Type-Options: nosniff' );

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' )
{
	// Stateless server: no server-initiated SSE stream (GET), no session to delete (DELETE).
	MCPServerSend( 405, null, [ 'Allow: POST' ] );
}

// Protect against DNS rebinding: browsers send Origin, MCP clients usually do not.
if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) )
{
	$origin_host = parse_url( $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST );

	$host = preg_replace( '/:\d+$/', '', isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );

	if ( ! $origin_host
		|| strcasecmp( $origin_host, $host ) !== 0 )
	{
		MCPServerSend( 403, MCPServerError( null, -32000, 'Forbidden origin.' ) );
	}
}

if ( ! empty( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] )
	&& ! in_array( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'], MCPServerProtocolVersions(), true ) )
{
	MCPServerSend( 400, MCPServerError( null, -32600, 'Unsupported MCP-Protocol-Version.' ) );
}

$token = MCPServerBearerToken();

$unauthorized_header = 'WWW-Authenticate: Bearer realm="RosarioSIS MCP"';

if ( ! $token )
{
	MCPServerSend( 401, MCPServerError( null, -32001, 'Missing Bearer token.' ), [ $unauthorized_header ] );
}

try
{
	if ( ! MCPServerPluginActive() )
	{
		MCPServerSend( 403, MCPServerError( null, -32000, 'The MCP Server plugin is not activated.' ) );
	}

	$user = MCPServerAuthenticate( $token );
}
catch ( PDOException $e )
{
	error_log( 'MCP_Server: database connection error: ' . $e->getMessage() );

	MCPServerSend( 503, MCPServerError( null, -32603, 'Database unavailable.' ) );
}

if ( ! $user )
{
	MCPServerSend( 401, MCPServerError( null, -32001, 'Invalid token.' ), [ $unauthorized_header ] );
}

$body = file_get_contents( 'php://input', false, null, 0, 1048576 );

$message = json_decode( (string) $body, true );

if ( json_last_error() !== JSON_ERROR_NONE )
{
	MCPServerSend( 400, MCPServerError( null, -32700, 'Parse error.' ) );
}

try
{
	$response = MCPServerHandleMessage( $message, $user );
}
catch ( Throwable $e )
{
	error_log( 'MCP_Server: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

	$response = MCPServerError(
		is_array( $message ) && isset( $message['id'] ) ? $message['id'] : null,
		-32603,
		'Internal error.'
	);
}

if ( $response === null )
{
	// Notification or response accepted.
	MCPServerSend( 202 );
}

MCPServerSend( 200, $response );

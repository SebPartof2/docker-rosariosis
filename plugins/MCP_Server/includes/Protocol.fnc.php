<?php
/**
 * MCP protocol (JSON-RPC 2.0) functions
 *
 * Stateless server: no MCP session, every request is authenticated by its token.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#streamable-http
 *
 * @package MCP Server plugin
 */

/**
 * Supported MCP protocol versions, latest first
 *
 * @return array
 */
function MCPServerProtocolVersions()
{
	return [ '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' ];
}

/**
 * JSON-RPC error response
 *
 * @param mixed  $id      Request ID.
 * @param int    $code    Error code.
 * @param string $message Error message.
 *
 * @return array
 */
function MCPServerError( $id, $code, $message )
{
	return [
		'jsonrpc' => '2.0',
		'id' => $id,
		'error' => [ 'code' => $code, 'message' => $message ],
	];
}

/**
 * JSON-RPC result response
 *
 * @param mixed $id     Request ID.
 * @param array $result Result.
 *
 * @return array
 */
function MCPServerResult( $id, $result )
{
	return [
		'jsonrpc' => '2.0',
		'id' => $id,
		// Empty result must be a JSON object, not an array.
		'result' => $result ? $result : new stdClass,
	];
}

/**
 * Handle a JSON-RPC message
 *
 * @param mixed $message Decoded JSON message.
 * @param array $user    Authenticated user.
 *
 * @return array|null Response, or null for notifications and responses (HTTP 202, no body).
 */
function MCPServerHandleMessage( $message, $user )
{
	if ( ! is_array( $message )
		|| ( isset( $message[0] ) || $message === [] ) )
	{
		// Batches were removed from MCP in 2025-06-18.
		return MCPServerError( null, -32600, 'Invalid Request: expected a single JSON-RPC object.' );
	}

	$id = array_key_exists( 'id', $message ) ? $message['id'] : null;

	if ( ! isset( $message['method'] ) )
	{
		// Response from the client (we never send requests): ignore.
		return array_key_exists( 'result', $message ) || array_key_exists( 'error', $message ) ?
			null :
			MCPServerError( $id, -32600, 'Invalid Request: missing method.' );
	}

	if ( ! isset( $message['jsonrpc'] )
		|| $message['jsonrpc'] !== '2.0'
		|| ! is_string( $message['method'] ) )
	{
		return MCPServerError( $id, -32600, 'Invalid Request.' );
	}

	if ( ! array_key_exists( 'id', $message ) )
	{
		// Notification: notifications/initialized, notifications/cancelled...
		return null;
	}

	$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : [];

	switch ( $message['method'] )
	{
		case 'initialize':

			return MCPServerResult( $id, MCPServerInitialize( $params, $user ) );

		case 'ping':

			return MCPServerResult( $id, [] );

		case 'tools/list':

			return MCPServerResult( $id, [ 'tools' => MCPServerToolsList( $user ) ] );

		case 'tools/call':

			return MCPServerToolsCall( $id, $params, $user );
	}

	return MCPServerError( $id, -32601, 'Method not found: ' . $message['method'] );
}

/**
 * Initialize: negotiate protocol version and declare capabilities
 *
 * @param array $params Request params.
 * @param array $user   User.
 *
 * @return array Result.
 */
function MCPServerInitialize( $params, $user )
{
	$versions = MCPServerProtocolVersions();

	$version = isset( $params['protocolVersion'] )
		&& in_array( $params['protocolVersion'], $versions, true ) ?
		$params['protocolVersion'] :
		$versions[0];

	return [
		'protocolVersion' => $version,
		'capabilities' => [
			'tools' => [ 'listChanged' => false ],
		],
		'serverInfo' => [
			'name' => 'rosariosis',
			'title' => 'RosarioSIS',
			'version' => MCP_SERVER_VERSION,
		],
		'instructions' => 'RosarioSIS Student Information System (read-only). You act as the RosarioSIS user "' .
			$user['username'] . '" (' . $user['profile'] . '), with the same permissions as in the RosarioSIS interface. ' .
			'Data is for the ' . $user['syear'] . '-' . ( $user['syear'] + 1 ) . ' school year. ' .
			'Find students with search_students, then use their student_id with the get_student* tools. ' .
			'Student data is confidential (FERPA): only share it with people entitled to it.',
	];
}

/**
 * Tools list, for the user
 *
 * @param array $user User.
 *
 * @return array Tools, MCP format.
 */
function MCPServerToolsList( $user )
{
	$list = [];

	foreach ( MCPServerUserTools( $user ) as $name => $tool )
	{
		$list[] = [
			'name' => $name,
			'title' => $tool['title'],
			'description' => $tool['description'],
			'inputSchema' => [
				'type' => 'object',
				// Empty properties must be a JSON object, not an array.
				'properties' => $tool['properties'] ? $tool['properties'] : new stdClass,
				'required' => $tool['required'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'title' => $tool['title'],
				'readOnlyHint' => true,
				'destructiveHint' => false,
				'idempotentHint' => true,
				'openWorldHint' => false,
			],
		];
	}

	return $list;
}

/**
 * Call a tool
 *
 * Errors in the tool (bad argument, no access) are returned as a result
 * with isError so the AI can see them and adapt. Unknown tools are protocol errors.
 *
 * @param mixed $id     Request ID.
 * @param array $params Request params: name, arguments.
 * @param array $user   User.
 *
 * @return array Response.
 */
function MCPServerToolsCall( $id, $params, $user )
{
	$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

	$tools = MCPServerUserTools( $user );

	if ( ! isset( $tools[ $name ] ) )
	{
		return MCPServerError( $id, -32602, 'Unknown tool: ' . $name );
	}

	$args = isset( $params['arguments'] ) ? $params['arguments'] : [];

	if ( ! is_array( $args ) )
	{
		return MCPServerError( $id, -32602, 'Tool arguments must be an object.' );
	}

	// Audit log: who accessed what. Goes to the PHP error log (docker logs).
	error_log( 'MCP_Server: ' . $user['username'] . ' (staff_id ' . $user['staff_id'] . ') ' .
		$name . ' ' . mb_substr( json_encode( $args ), 0, 300 ) );

	try
	{
		$data = call_user_func( $tools[ $name ]['callback'], $args, $user );

		$is_error = false;

		$text = json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}
	catch ( MCPServerToolError $e )
	{
		$is_error = true;

		$text = $e->getMessage();
	}
	catch ( PDOException $e )
	{
		error_log( 'MCP_Server: ' . $name . ' database error: ' . $e->getMessage() );

		$is_error = true;

		$text = 'Database error. Details were written to the server log.';
	}

	return MCPServerResult( $id, [
		'content' => [ [ 'type' => 'text', 'text' => $text ] ],
		'isError' => $is_error,
	] );
}

<?php
/**
 * Plugin configuration interface
 *
 * Administrators generate access tokens for themselves or for other
 * Administrators and Teachers. The AI client then acts as that user,
 * with the same permissions. Tokens can be revoked at any time.
 *
 * @package MCP Server plugin
 */

// Check the script is called by the right program & plugin is activated.
if ( $_REQUEST['modname'] !== 'School_Setup/Configuration.php'
	|| ! $RosarioPlugins['MCP_Server']
	|| $_REQUEST['modfunc'] !== 'config' )
{
	$error[] = _( 'You\'re not allowed to use this program!' );

	echo ErrorMessage( $error, 'fatal' );
}

require_once 'plugins/MCP_Server/includes/Common.fnc.php';

// Note: no need to call ProgramTitle() here!

$config_url = 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&tab=plugins&modfunc=config&plugin=MCP_Server';

// Users who can have a token: Administrators & Teachers of the current school year.
$users_RET = DBGet( "SELECT STAFF_ID," . DisplayNameSQL() . " AS FULL_NAME,PROFILE
	FROM staff
	WHERE SYEAR='" . UserSyear() . "'
	AND PROFILE IN('admin','teacher')
	ORDER BY FULL_NAME", [], [ 'STAFF_ID' ] );

$new_token = '';

$new_token_staff_id = 0;

if ( issetVal( $_REQUEST['mcp_action'] ) === 'hidden_fields'
	&& AllowEdit() )
{
	$hidden_fields = array_filter( array_map( 'intval', (array) issetVal( $_REQUEST['hidden_fields'], [] ) ) );

	Config( 'MCP_SERVER_HIDDEN_FIELDS', $hidden_fields ? ',' . implode( ',', $hidden_fields ) . ',' : '' );

	$note[] = button( 'check' ) . '&nbsp;' . _( 'The plugin configuration has been modified.' );
}
elseif ( ! empty( $_REQUEST['mcp_action'] )
	&& ! empty( $_REQUEST['staff_id'] )
	&& isset( $users_RET[ $_REQUEST['staff_id'] ] )
	&& AllowEdit() )
{
	$staff_id = (int) $_REQUEST['staff_id'];

	if ( $_REQUEST['mcp_action'] === 'generate' )
	{
		$new_token = MCPServerGenerateToken();

		$new_token_staff_id = $staff_id;

		// Only the hash is stored: the token is displayed once, below.
		ProgramUserConfig( 'MCP_Server', $staff_id, [
			'TOKEN_HASH' => MCPServerTokenHash( $new_token ),
			'TOKEN_CREATED' => DBDate() . ' ' . date( 'H:i:s' ),
		] );

		$note[] = button( 'check' ) . '&nbsp;' . dgettext( 'MCP_Server', 'The access token was generated. Copy it now: it will not be shown again.' );
	}
	elseif ( $_REQUEST['mcp_action'] === 'revoke' )
	{
		ProgramUserConfig( 'MCP_Server', $staff_id, [
			'TOKEN_HASH' => '',
			'TOKEN_CREATED' => '',
		] );

		$note[] = button( 'check' ) . '&nbsp;' . dgettext( 'MCP_Server', 'The access token was revoked.' );
	}
}

// Unset action & redirect URL, so reloading the page does not generate another token.
RedirectURL( [ 'mcp_action', 'staff_id', 'hidden_fields' ] );

$mcp_url = str_replace( 'Modules.php', 'plugins/MCP_Server/mcp.php', RosarioURL( 'script' ) );

echo ErrorMessage( $note, 'note' );

echo ErrorMessage( $error, 'error' );

echo '<br />';

PopTable( 'header', dgettext( 'MCP_Server', 'MCP Server' ) );

echo '<p>' . dgettext(
	'MCP_Server',
	'Connect an AI assistant supporting the Model Context Protocol (Claude, ...) to RosarioSIS. The assistant gets read-only access to the data the token user can see in RosarioSIS: same schools, students and permissions.'
) . '</p>';

echo '<table class="width-100p">';

echo '<tr><td>' . NoInput(
	'<code>' . $mcp_url . '</code>',
	dgettext( 'MCP_Server', 'MCP Server URL' )
) . '</td></tr>';

if ( $new_token )
{
	echo '<tr><td>' . NoInput(
		'<code style="user-select: all;">' . $new_token . '</code>',
		sprintf(
			dgettext( 'MCP_Server', 'Access Token for %s' ),
			$users_RET[ $new_token_staff_id ][1]['FULL_NAME']
		)
	) . '</td></tr>';

	// Claude Code example.
	$example = 'claude mcp add --transport http rosariosis ' . $mcp_url .
		' --header "Authorization: Bearer ' . $new_token . '"';

	echo '<tr><td>' . NoInput(
		'<code style="user-select: all;">' . htmlspecialchars( $example, ENT_QUOTES ) . '</code>',
		dgettext( 'MCP_Server', 'Claude Code' )
	) . '</td></tr>';
}

echo '</table>';

echo '<p class="size-1">' . dgettext(
	'MCP_Server',
	'Send the token in the HTTP header <code>Authorization: Bearer &lt;token&gt;</code>. Keep it secret: anyone with the token can read student data as its user. Tokens are valid for the current school year only: generate new ones after Rollover.'
) . '</p>';

if ( AllowEdit() )
{
	$user_options = [];

	foreach ( $users_RET as $staff_id => $user )
	{
		$user_options[ $staff_id ] = $user[1]['FULL_NAME'] . ' (' .
			( $user[1]['PROFILE'] === 'admin' ? _( 'Administrator' ) : _( 'Teacher' ) ) . ')';
	}

	echo '<form action="' . URLEscape( $config_url . '&mcp_action=generate' ) . '" method="POST">';

	echo '<table class="width-100p"><tr><td>' . SelectInput(
		User( 'STAFF_ID' ),
		'staff_id',
		_( 'User' ),
		$user_options,
		false
	) . '</td></tr></table>';

	echo '<div class="center">' . SubmitButton( dgettext( 'MCP_Server', 'Generate Token' ) ) . '</div>';

	echo '</form>';
}

PopTable( 'footer' );

// Active tokens.
$tokens_RET = DBGet( "SELECT puc.USER_ID AS STAFF_ID," . DisplayNameSQL( 's' ) . " AS FULL_NAME,s.PROFILE,
	(SELECT puc2.VALUE
		FROM program_user_config puc2
		WHERE puc2.USER_ID=puc.USER_ID
		AND puc2.PROGRAM='MCP_Server'
		AND puc2.TITLE='TOKEN_CREATED') AS TOKEN_CREATED
	FROM program_user_config puc
	JOIN staff s ON s.STAFF_ID=puc.USER_ID
	WHERE puc.PROGRAM='MCP_Server'
	AND puc.TITLE='TOKEN_HASH'
	AND puc.VALUE<>''
	AND s.SYEAR='" . UserSyear() . "'
	ORDER BY FULL_NAME" );

$link = [];

if ( AllowEdit() )
{
	$link['remove']['link'] = $config_url . '&mcp_action=revoke';
	$link['remove']['variables'] = [ 'staff_id' => 'STAFF_ID' ];
}

echo '<br />';

ListOutput(
	$tokens_RET,
	[
		'FULL_NAME' => _( 'User' ),
		'PROFILE' => _( 'Profile' ),
		'TOKEN_CREATED' => dgettext( 'MCP_Server', 'Generated' ),
	],
	dgettext( 'MCP_Server', 'Active Token' ),
	dgettext( 'MCP_Server', 'Active Tokens' ),
	$link,
	[],
	[ 'search' => false, 'save' => false ]
);

// Student fields hidden from AI assistants.
$fields_RET = DBGet( "SELECT cf.ID,cf.TITLE,sfc.TITLE AS CATEGORY
	FROM custom_fields cf
	JOIN student_field_categories sfc ON sfc.ID=cf.CATEGORY_ID
	ORDER BY sfc.SORT_ORDER,sfc.ID,cf.SORT_ORDER,cf.ID", [ 'TITLE' => 'ParseMLField', 'CATEGORY' => 'ParseMLField' ] );

$hidden_fields = explode( ',', (string) Config( 'MCP_SERVER_HIDDEN_FIELDS' ) );

echo '<br /><form action="' . URLEscape( $config_url . '&mcp_action=hidden_fields' ) . '" method="POST">';

PopTable( 'header', dgettext( 'MCP_Server', 'Student fields hidden from AI assistants' ) );

echo '<p>' . dgettext(
	'MCP_Server',
	'These fields are never sent, whatever the user permissions. Student data sent to an AI assistant is processed by its provider.'
) . '</p>';

echo '<table class="width-100p">';

$category = '';

foreach ( $fields_RET as $field )
{
	if ( $field['CATEGORY'] !== $category )
	{
		$category = $field['CATEGORY'];

		echo '<tr><td><b>' . $category . '</b></td></tr>';
	}

	echo '<tr><td><label><input type="checkbox" name="hidden_fields[]" value="' . (int) $field['ID'] . '"' .
		( in_array( $field['ID'], $hidden_fields ) ? ' checked' : '' ) .
		( AllowEdit() ? '' : ' disabled' ) . ' /> ' . $field['TITLE'] . '</label></td></tr>';
}

echo '</table>';

PopTable( 'footer' );

if ( AllowEdit() )
{
	echo '<br /><div class="center">' . SubmitButton() . '</div>';
}

echo '</form>';

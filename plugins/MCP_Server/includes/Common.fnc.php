<?php
/**
 * MCP Server common functions
 *
 * Database access, token authentication and the RosarioSIS permission model,
 * for use by mcp.php which runs outside of a RosarioSIS browser session.
 *
 * Note: database.inc.php is not used because db_query() prints an HTML
 * error page and dies on failure, which would corrupt the JSON-RPC response.
 * PDO with prepared statements is used instead.
 *
 * @package MCP Server plugin
 */

/**
 * Program name used for the program_user_config table rows
 */
if ( ! defined( 'MCP_SERVER_PROGRAM' ) )
{
	define( 'MCP_SERVER_PROGRAM', 'MCP_Server' );
}

/**
 * Access token prefix
 */
if ( ! defined( 'MCP_SERVER_TOKEN_PREFIX' ) )
{
	define( 'MCP_SERVER_TOKEN_PREFIX', 'rmcp_' );
}

/**
 * Error a tool reports back to the AI client (bad argument, no access...)
 *
 * The message is shown to the client, so it must never contain SQL or internals.
 */
class MCPServerToolError extends Exception {}

/**
 * Generate a new access token
 *
 * @return string Token: prefix + 64 hex characters.
 */
function MCPServerGenerateToken()
{
	return MCP_SERVER_TOKEN_PREFIX . bin2hex( random_bytes( 32 ) );
}

/**
 * Hash an access token
 * Only the hash is stored in the database.
 *
 * @param string $token Access token.
 *
 * @return string SHA-256 hex hash.
 */
function MCPServerTokenHash( $token )
{
	return hash( 'sha256', (string) $token );
}

/**
 * PDO database connection
 *
 * @global $DatabaseType, $DatabaseServer, $DatabaseUsername, $DatabasePassword, $DatabaseName, $DatabasePort
 * @see config.inc.php
 *
 * @throws PDOException On connection failure.
 *
 * @return PDO
 */
function MCPServerDB()
{
	global $DatabaseType,
		$DatabaseServer,
		$DatabaseUsername,
		$DatabasePassword,
		$DatabaseName,
		$DatabasePort;

	static $pdo = null;

	if ( $pdo )
	{
		return $pdo;
	}

	if ( $DatabaseType === 'mysql' )
	{
		$dsn = 'mysql:host=' . $DatabaseServer . ';dbname=' . $DatabaseName . ';charset=utf8mb4';
	}
	else
	{
		$dsn = 'pgsql:host=' . $DatabaseServer . ';dbname=' . $DatabaseName;
	}

	if ( ! empty( $DatabasePort ) )
	{
		$dsn .= ';port=' . (int) $DatabasePort;
	}

	$pdo = new PDO( $dsn, $DatabaseUsername, $DatabasePassword, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		// PostgreSQL returns lowercase column names, MySQL returns them as written.
		PDO::ATTR_CASE => PDO::CASE_LOWER,
	] );

	return $pdo;
}

/**
 * Run a SELECT query and return all rows
 *
 * Only user input goes through $params. Integers we control (school year, IDs
 * already cast to int) are written directly in the SQL: the PostgreSQL driver
 * does not allow the same named parameter twice.
 *
 * @param string $sql    SQL query with named placeholders.
 * @param array  $params Placeholder values.
 *
 * @return array Rows, lowercase column names.
 */
function MCPServerQuery( $sql, $params = [] )
{
	$statement = MCPServerDB()->prepare( $sql );

	$statement->execute( $params );

	return $statement->fetchAll();
}

/**
 * Run a SELECT query and return the first row
 *
 * @param string $sql    SQL query with named placeholders.
 * @param array  $params Placeholder values.
 *
 * @return array|null First row or null.
 */
function MCPServerQueryRow( $sql, $params = [] )
{
	$rows = MCPServerQuery( $sql, $params );

	return $rows ? $rows[0] : null;
}

/**
 * Run a SELECT query and return the first column of the first row
 *
 * @param string $sql    SQL query with named placeholders.
 * @param array  $params Placeholder values.
 *
 * @return mixed|null Value or null.
 */
function MCPServerQueryValue( $sql, $params = [] )
{
	$row = MCPServerQueryRow( $sql, $params );

	return $row ? reset( $row ) : null;
}

/**
 * Comma separated list of integers, for use in an SQL IN() clause
 *
 * @param array $ids IDs.
 *
 * @return string For example "1,2,3", or "NULL" when empty so that IN() matches nothing.
 */
function MCPServerSQLIntList( $ids )
{
	$ids = array_unique( array_map( 'intval', (array) $ids ) );

	return $ids ? implode( ',', $ids ) : 'NULL';
}

/**
 * Is the plugin activated in School > Configuration > Plugins?
 *
 * @return bool
 */
function MCPServerPluginActive()
{
	$plugins = MCPServerQueryValue( "SELECT CONFIG_VALUE
		FROM config
		WHERE TITLE='PLUGINS'
		AND SCHOOL_ID=0" );

	$plugins = $plugins ? @unserialize( $plugins, [ 'allowed_classes' => false ] ) : [];

	return ! empty( $plugins[ MCP_SERVER_PROGRAM ] );
}

/**
 * Student fields never sent to AI assistants
 * Set in the plugin configuration, Social Security by default (see install.sql).
 *
 * @return array custom_fields IDs.
 */
function MCPServerHiddenFields()
{
	$hidden_fields = MCPServerQueryValue( "SELECT CONFIG_VALUE
		FROM config
		WHERE TITLE='MCP_SERVER_HIDDEN_FIELDS'
		AND SCHOOL_ID=0" );

	return array_filter( array_map( 'intval', explode( ',', (string) $hidden_fields ) ) );
}

/**
 * Get the user an access token belongs to
 *
 * The user must exist in the default school year and have the Administrator
 * or Teacher profile. Like for the REST API plugin, tokens are tied to the
 * staff_id of the current school year: a new token is needed after Rollover.
 *
 * @global $DefaultSyear
 *
 * @param string $token Access token.
 *
 * @return array|null User, or null if the token is not valid.
 */
function MCPServerAuthenticate( $token )
{
	global $DefaultSyear;

	if ( ! preg_match( '/^' . MCP_SERVER_TOKEN_PREFIX . '[0-9a-f]{64}$/', (string) $token ) )
	{
		return null;
	}

	$user = MCPServerQueryRow( "SELECT s.STAFF_ID,s.SYEAR,s.FIRST_NAME,s.LAST_NAME,s.USERNAME,
		s.PROFILE,s.PROFILE_ID,s.SCHOOLS
		FROM program_user_config puc
		JOIN staff s ON s.STAFF_ID=puc.USER_ID
		WHERE puc.PROGRAM='" . MCP_SERVER_PROGRAM . "'
		AND puc.TITLE='TOKEN_HASH'
		AND puc.VALUE=:hash
		AND s.SYEAR=" . (int) $DefaultSyear . "
		AND s.PROFILE IN ('admin','teacher')", [ 'hash' => MCPServerTokenHash( $token ) ] );

	if ( ! $user )
	{
		return null;
	}

	$user['staff_id'] = (int) $user['staff_id'];
	$user['syear'] = (int) $user['syear'];
	$user['schools'] = MCPServerUserSchools( $user );
	$user['can_use'] = MCPServerUserPrograms( $user );

	return $user;
}

/**
 * Schools the user has access to
 * staff.SCHOOLS is either empty (all schools) or a list like ",1,3,".
 *
 * @param array $user User.
 *
 * @return array School titles, indexed by school ID.
 */
function MCPServerUserSchools( $user )
{
	$schools_RET = MCPServerQuery( "SELECT ID,TITLE
		FROM schools
		WHERE SYEAR=" . (int) $user['syear'] . "
		ORDER BY ID" );

	$allowed = trim( (string) $user['schools'], ',' );

	$allowed_ids = $allowed === '' ? [] : array_map( 'intval', explode( ',', $allowed ) );

	$schools = [];

	foreach ( $schools_RET as $school )
	{
		if ( ! $allowed_ids
			|| in_array( (int) $school['id'], $allowed_ids, true ) )
		{
			$schools[ (int) $school['id'] ] = $school['title'];
		}
	}

	return $schools;
}

/**
 * Programs the user can use
 * Same logic as AllowUse(): profile permissions, or user permissions if no profile.
 *
 * @param array $user User.
 *
 * @return array Program modnames, for example 'Students/Student.php'.
 */
function MCPServerUserPrograms( $user )
{
	if ( ! empty( $user['profile_id'] ) )
	{
		$programs_RET = MCPServerQuery( "SELECT MODNAME
			FROM profile_exceptions
			WHERE PROFILE_ID=" . (int) $user['profile_id'] . "
			AND CAN_USE='Y'" );
	}
	else
	{
		$programs_RET = MCPServerQuery( "SELECT MODNAME
			FROM staff_exceptions
			WHERE USER_ID=" . (int) $user['staff_id'] . "
			AND CAN_USE='Y'" );
	}

	return array_column( $programs_RET, 'modname' );
}

/**
 * Can the user use a program?
 *
 * @param array  $user    User.
 * @param string $modname Program modname.
 *
 * @return bool
 */
function MCPServerCanUse( $user, $modname )
{
	return in_array( $modname, $user['can_use'], true );
}

/**
 * Is the user an Administrator?
 *
 * @param array $user User.
 *
 * @return bool
 */
function MCPServerIsAdmin( $user )
{
	return $user['profile'] === 'admin';
}

/**
 * SQL condition restricting students to the ones the user has access to
 *
 * Administrators: students enrolled this school year in one of their schools.
 * Teachers: students scheduled this school year in one of their course periods.
 *
 * @param array  $user   User.
 * @param string $column Student ID column, for example 's.STUDENT_ID'.
 *
 * @return string SQL condition.
 */
function MCPServerStudentScopeSQL( $user, $column )
{
	$syear = (int) $user['syear'];

	if ( MCPServerIsAdmin( $user ) )
	{
		return $column . " IN(SELECT STUDENT_ID
			FROM student_enrollment
			WHERE SYEAR=" . $syear . "
			AND SCHOOL_ID IN(" . MCPServerSQLIntList( array_keys( $user['schools'] ) ) . "))";
	}

	return $column . " IN(SELECT sch.STUDENT_ID
		FROM schedule sch
		JOIN course_periods cp ON cp.COURSE_PERIOD_ID=sch.COURSE_PERIOD_ID
		WHERE sch.SYEAR=" . $syear . "
		AND " . MCPServerTeacherSQL( $user, 'cp' ) . ")";
}

/**
 * SQL condition restricting course periods to the ones taught by the user
 *
 * @param array  $user  User.
 * @param string $alias course_periods table alias.
 *
 * @return string SQL condition.
 */
function MCPServerTeacherSQL( $user, $alias )
{
	$staff_id = (int) $user['staff_id'];

	return "(" . $alias . ".TEACHER_ID=" . $staff_id .
		" OR " . $alias . ".SECONDARY_TEACHER_ID=" . $staff_id . ")";
}

/**
 * Check the user has access to a student, or throw
 *
 * @param array $user       User.
 * @param int   $student_id Student ID.
 *
 * @throws MCPServerToolError
 */
function MCPServerCheckStudentAccess( $user, $student_id )
{
	$accessible = MCPServerQueryValue( "SELECT 1
		FROM students s
		WHERE s.STUDENT_ID=" . (int) $student_id . "
		AND " . MCPServerStudentScopeSQL( $user, 's.STUDENT_ID' ) );

	if ( ! $accessible )
	{
		// Same message whether the student does not exist or is out of scope.
		throw new MCPServerToolError( 'Student ' . (int) $student_id . ' not found or not accessible to you.' );
	}
}

/**
 * Check the user has access to a school, or throw
 *
 * @param array $user      User.
 * @param int   $school_id School ID.
 *
 * @throws MCPServerToolError
 */
function MCPServerCheckSchoolAccess( $user, $school_id )
{
	if ( ! isset( $user['schools'][ (int) $school_id ] ) )
	{
		throw new MCPServerToolError( 'School ' . (int) $school_id . ' not found or not accessible to you.' );
	}
}

/**
 * Parse a Multi Language field value
 * Same as ParseMLField(), using the first configured locale.
 *
 * @example "Mathematics|fr_FR.utf8:Mathématiques|es_ES.utf8:Matemáticas"
 *
 * @global $RosarioLocales
 *
 * @param string|null $value Field value.
 *
 * @return string|null Translated value.
 */
function MCPServerML( $value )
{
	global $RosarioLocales;

	if ( $value === null
		|| strpos( $value, '|' ) === false )
	{
		return $value;
	}

	$locale = ! empty( $RosarioLocales[0] ) ? $RosarioLocales[0] : '';

	$parts = explode( '|', $value );

	foreach ( array_slice( $parts, 1 ) as $part )
	{
		if ( $locale
			&& strpos( $part, $locale . ':' ) === 0 )
		{
			return mb_substr( $part, mb_strlen( $locale ) + 1 );
		}
	}

	return $parts[0];
}

/**
 * Format a person name
 *
 * @param array $row Row with first_name, last_name and optional middle_name.
 *
 * @return string
 */
function MCPServerName( $row )
{
	return trim( preg_replace( '/\s+/', ' ', $row['first_name'] . ' ' .
		( isset( $row['middle_name'] ) ? $row['middle_name'] : '' ) . ' ' .
		$row['last_name'] ) );
}

/**
 * Validate an ISO date argument
 *
 * @param mixed  $value    Date argument.
 * @param string $name     Argument name, for the error message.
 * @param string $default  Default value when empty.
 *
 * @throws MCPServerToolError
 *
 * @return string Date YYYY-MM-DD.
 */
function MCPServerDateArg( $value, $name, $default )
{
	if ( $value === null || $value === '' )
	{
		return $default;
	}

	$date = DateTime::createFromFormat( '!Y-m-d', (string) $value );

	if ( ! $date
		|| $date->format( 'Y-m-d' ) !== $value )
	{
		throw new MCPServerToolError( $name . ' must be a date in the YYYY-MM-DD format.' );
	}

	return $value;
}

/**
 * Validate an integer argument
 *
 * @param mixed $value   Argument.
 * @param string $name   Argument name, for the error message.
 * @param int|null $min  Minimum value.
 * @param int|null $max  Maximum value.
 *
 * @throws MCPServerToolError
 *
 * @return int
 */
function MCPServerIntArg( $value, $name, $min = null, $max = null )
{
	if ( ! is_int( $value )
		&& ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) )
		&& ! ( is_float( $value ) && floor( $value ) == $value ) )
	{
		throw new MCPServerToolError( $name . ' must be an integer.' );
	}

	$value = (int) $value;

	if ( $min !== null && $value < $min )
	{
		throw new MCPServerToolError( $name . ' must be at least ' . $min . '.' );
	}

	if ( $max !== null && $value > $max )
	{
		throw new MCPServerToolError( $name . ' must be at most ' . $max . '.' );
	}

	return $value;
}

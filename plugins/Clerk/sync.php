<?php
/**
 * Sync every RosarioSIS user to Clerk, from the command line
 *
 * @example docker compose exec web php plugins/Clerk/sync.php
 * @example docker compose exec web php plugins/Clerk/sync.php --dry-run
 *
 * Exit code: 0 OK, 1 errors (see the plugin configuration Sync Log), 2 could not sync.
 *
 * @package Clerk plugin
 */

if ( PHP_SAPI !== 'cli' )
{
	http_response_code( 403 );

	exit;
}

$dry_run = in_array( '--dry-run', $argv, true );

// Bootstrap RosarioSIS from its root folder (relative includes).
chdir( __DIR__ . '/../..' );

$_SERVER['SCRIPT_NAME'] = '/sync.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once 'Warehouse.php';

if ( empty( $RosarioPlugins['Clerk'] ) )
{
	fwrite( STDERR, "The Clerk plugin is not activated (School > Configuration > Plugins).\n" );

	exit( 2 );
}

require_once 'plugins/Clerk/includes/Sync.fnc.php';

$sync = ClerkSyncAll( $dry_run );

if ( $sync['error'] )
{
	fwrite( STDERR, 'Clerk: ' . $sync['error'] . "\n" );

	exit( 2 );
}

foreach ( $sync['rows'] as $row )
{
	echo ( $dry_run ? '[dry run] ' : '' ) . $row['ACTION'] . "\t" . $row['USERNAME'] . "\t" .
		$row['EXTERNAL_ID'] . ( $row['MESSAGE'] ? "\t" . $row['MESSAGE'] : '' ) . "\n";
}

$counts = [];

foreach ( $sync['counts'] as $action => $count )
{
	$counts[] = $action . '=' . $count;
}

echo ( $dry_run ? 'Dry run: ' : 'Done: ' ) . ( $counts ? implode( ' ', $counts ) : 'no users' ) . "\n";

exit( empty( $sync['counts']['error'] ) ? 0 : 1 );

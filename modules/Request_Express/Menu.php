<?php
/**
 * Request Express module Menu entries
 *
 * @uses $menu global var
 *
 * @see  Menu.php in root folder
 *
 * @package RosarioSIS
 * @subpackage modules
 */

$menu['Request_Express']['admin'] = [
	'title' => _( 'Request Express' ),
	'default' => 'Request_Express/Import.php',
	'Request_Express/Import.php' => _( 'Import Course Requests' ),
] + issetVal( $menu['Request_Express']['admin'], [] );

// Replacing the students' requests requires the "Can Edit" permission.
$exceptions['Request_Express'] = [
	'Request_Express/Import.php' => true,
];

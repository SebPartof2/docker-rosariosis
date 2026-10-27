<?php
/**
 * Info Express module Menu entries
 *
 * @uses $menu global var
 *
 * @see  Menu.php in root folder
 *
 * @package RosarioSIS
 * @subpackage modules
 */

$menu['Info_Express']['admin'] = [
	'title' => _( 'Info Express' ),
	'default' => 'Info_Express/Import.php',
	'Info_Express/Import.php' => _( 'Update Student Info' ),
	'Info_Express/Reactivate.php' => _( 'Reactivate Students' ),
	'Info_Express/EnrollmentDates.php' => _( 'Enrollment Dates' ),
] + issetVal( $menu['Info_Express']['admin'], [] );

// Updating students requires the "Can Edit" permission.
$exceptions['Info_Express'] = [
	'Info_Express/Import.php' => true,
	'Info_Express/Reactivate.php' => true,
	'Info_Express/EnrollmentDates.php' => true,
];

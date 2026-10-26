<?php
/**
 * Course Express module Menu entries
 *
 * @uses $menu global var
 *
 * @see  Menu.php in root folder
 *
 * @package RosarioSIS
 * @subpackage modules
 */

$menu['Course_Express']['admin'] = [
	'title' => _( 'Course Express' ),
	'default' => 'Course_Express/Import.php',
	'Course_Express/Import.php' => _( 'Import Courses' ),
	'Course_Express/Sections.php' => _( 'Import Sections' ),
	'Course_Express/MoveSections.php' => _( 'Move Sections' ),
	1 => _( 'Reports' ),
	'Course_Express/MasterSchedule.php' => _( 'Master Schedule' ),
	'Course_Express/Troubleshoot.php' => _( 'Schedule Troubleshoot' ),
	'Course_Express/EmptySections.php' => _( 'Empty Sections' ),
	2 => _( 'Setup' ),
	'Course_Express/Defaults.php' => _( 'Section Defaults' ),
	'Course_Express/Overrides.php' => _( 'Section Overrides' ),
] + issetVal( $menu['Course_Express']['admin'], [] );

// Creating courses & sections requires the "Can Edit" permission.
// The three reports are deliberately absent: they only read, so an
// administrator who may look but not edit can still open them.
$exceptions['Course_Express'] = [
	'Course_Express/Import.php' => true,
	'Course_Express/Sections.php' => true,
	'Course_Express/MoveSections.php' => true,
	'Course_Express/Defaults.php' => true,
	'Course_Express/Overrides.php' => true,
];

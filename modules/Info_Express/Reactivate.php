<?php
/**
 * Info Express: Reactivate Students
 *
 * Every student of this school year & school who is not active today, with the
 * one change that would make them active. Tick "Skip" on the ones to leave
 * alone, then apply.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Info_Express/includes/InfoExpress.fnc.php';
require_once 'modules/Info_Express/includes/Reactivate.fnc.php';

DrawHeader( ProgramTitle() );

if ( ! AllowEdit() )
{
	echo ErrorMessage( [ _( 'You are not allowed to use this program.' ) ] );

	return;
}

$students = InfoExpressInactiveStudents();

/**
 * Apply
 */
if ( $_REQUEST['modfunc'] === 'save' )
{
	// The students to leave out are posted, not the students to change. A
	// school with thousands of inactive students cannot post a ticked box per
	// student: PHP stops reading at max_input_vars, 1000 by default, and
	// ListOutput() only ever draws the first 1000 rows anyway -- so everyone
	// past row 1000 had no box to tick and would have been left behind.
	$skip = (array) issetVal( $_REQUEST['skip_students'], [] );

	if ( InfoExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: going ahead now would
		// reactivate students the user asked to leave alone.
		echo ErrorMessage( [ _( 'Too many students were skipped at once for the server to read them all. Please leave fewer out and try again.' ) ] );

		return;
	}

	$done = 0;

	foreach ( $students as $student_id => $student )
	{
		if ( ! empty( $skip[ $student_id ] ) )
		{
			continue;
		}

		if ( InfoExpressReactivate( $student ) )
		{
			$done++;
		}
	}

	echo ErrorMessage(
		[ button( 'check' ) . '&nbsp;' . sprintf(
			_( '%d students are now active.' ),
			$done
		) ],
		'note'
	);

	// Read the list again: what is left is what was not ticked.
	$students = InfoExpressInactiveStudents();

	RedirectURL( 'modfunc' );
}

if ( ! $students )
{
	echo ErrorMessage(
		[ _( 'Every student of this school is active today.' ) ],
		'note'
	);

	return;
}

/**
 * The students who are not active
 */
$students_LO = [];

$counts = [ 'end_date' => 0, 'start_date' => 0, 'both' => 0 ];

foreach ( $students as $student_id => $student )
{
	$counts[ $student['FIX'] ]++;

	$students_LO[] = [
		// Ticking leaves the student alone; everyone listed is made active.
		'CHECK' => '<input type="checkbox" name="' .
			AttrEscape( 'skip_students[' . $student_id . ']' ) . '" value="Y" />',
		'STUDENT_ID' => InfoExpressHTML( $student_id ),
		'STUDENT' => InfoExpressHTML( $student['FULL_NAME'] ),
		'GRADE_LEVEL' => InfoExpressHTML( issetVal( $student['GRADE_LEVEL'], '' ) ),
		'START_DATE' => InfoExpressHTML( $student['START_DATE'] !== '' ?
			ProperDate( $student['START_DATE'], 'short' ) :
			'-' ),
		'END_DATE' => InfoExpressHTML( $student['END_DATE'] !== '' ?
			ProperDate( $student['END_DATE'], 'short' ) :
			'-' ),
		'DROP_CODE' => InfoExpressHTML( issetVal( $student['DROP_TITLE'], '' ) ),
		'FIX' => InfoExpressReactivateFixText( $student ),
	];
}

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=save' ) . '" method="POST">';

echo ErrorMessage(
	[ sprintf(
		_( '%d students of this school are not active as of %s.' ),
		count( $students ),
		'<b>' . InfoExpressHTML( ProperDate( DBDate(), 'short' ) ) . '</b>'
	) ],
	'warning'
);

PopTable( 'header', _( 'Students who are not active' ) );

echo '<p>' . _( 'Each student below gets the one change that makes them active today, unless you tick "Skip". Nothing else on their record is touched: the grade level, the calendar and the enrollment code stay as they are.' ) . '</p>';

if ( count( $students ) > 1000 )
{
	echo ErrorMessage(
		[ sprintf(
			_( '%d students are listed: only the first 1000 are shown. All of them are made active — the list is cut, the change is not.' ),
			count( $students )
		) ],
		'warning'
	);
}

if ( $counts['start_date'] || $counts['both'] )
{
	echo ErrorMessage(
		[ sprintf(
			_( '%d of them have a start date that is missing or still to come. Making those active moves the start date to today — tick "Skip" on any student you meant to enroll later.' ),
			$counts['start_date'] + $counts['both']
		) ],
		'warning'
	);
}

ListOutput(
	InfoExpressListIndex( $students_LO ),
	[
		'CHECK' => _( 'Skip' ),
		'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		'STUDENT' => _( 'Student' ),
		'GRADE_LEVEL' => _( 'Grade Level' ),
		'START_DATE' => _( 'Start Date' ),
		'END_DATE' => _( 'End Date' ),
		'DROP_CODE' => _( 'Drop Code' ),
		'FIX' => _( 'What changes' ),
	],
	'Student',
	'Students',
	[],
	[],
	[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
);

echo '<div class="center"><br />' . Buttons( _( 'Make them Active' ) ) . '</div>';

PopTable( 'footer' );

echo '</form>';

echo '<p>' . _( 'No account activation email is sent. Enrolling a single student from their Student Info page mails them; doing that for a whole list at once would not be wanted.' ) . '</p>';

<?php
/**
 * Info Express: Enrollment Dates
 *
 * Moves the date a student was enrolled, and the date they joined each of their
 * classes, to a date you pick -- for every student of the school, or for a list
 * of Student IDs.
 *
 * 2 steps: pick the date and who it applies to, then review and apply.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Info_Express/includes/InfoExpress.fnc.php';
require_once 'modules/Info_Express/includes/EnrollmentDates.fnc.php';

DrawHeader( ProgramTitle() );

if ( ! AllowEdit() )
{
	echo ErrorMessage( [ _( 'You are not allowed to use this program.' ) ] );

	return;
}

$error = [];

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'preview', 'save' ], true ) )
{
	$modfunc = '';
}

/**
 * What was asked for
 */
$new_date = RequestedDate( 'new_date', '' );

$only_text = (string) issetVal( $_POST['student_ids'], '' );

$scope = issetVal( $_REQUEST['scope'], 'all' ) === 'list' ? 'list' : 'all';

$change_enrollment = $modfunc === '' || ! empty( $_REQUEST['change_enrollment'] );

$change_schedule = $modfunc === '' || ! empty( $_REQUEST['change_schedule'] );

$only_ids = $scope === 'list' ? InfoExpressStudentIDsRead( $only_text ) : [];

if ( $modfunc !== '' )
{
	if ( ! VerifyDate( $new_date ) )
	{
		$error[] = _( 'Please choose a date.' );
	}

	if ( ! $change_enrollment
		&& ! $change_schedule )
	{
		$error[] = _( 'Please choose what to move: the enrollment date, the class dates, or both.' );
	}

	if ( $scope === 'list'
		&& ! $only_ids )
	{
		$error[] = sprintf( _( 'Please give at least one %s ID.' ), Config( 'NAME' ) );
	}

	if ( $error )
	{
		$modfunc = '';
	}
}

/**
 * Step 2 -- Apply
 */
if ( $modfunc === 'save' )
{
	$students = InfoExpressDatesAnalyze( $new_date, $only_ids, $change_enrollment, $change_schedule );

	$skip = (array) issetVal( $_REQUEST['skip_students'], [] );

	if ( InfoExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: going ahead now would move
		// dates the user asked to leave alone.
		echo ErrorMessage( [ _( 'Too many students were skipped at once for the server to read them all. Please leave fewer out and try again.' ) ] );

		return;
	}

	$students_done = $classes_done = 0;

	foreach ( $students as $student_id => $row )
	{
		if ( $row['STATUS'] !== 'move'
			|| ! empty( $skip[ $student_id ] ) )
		{
			continue;
		}

		$done = InfoExpressDatesApply( $row, $new_date, $change_enrollment, $change_schedule );

		if ( $done['ENROLLMENT'] || $done['CLASSES'] )
		{
			$students_done++;
		}

		$classes_done += $done['CLASSES'];
	}

	echo ErrorMessage(
		[ button( 'check' ) . '&nbsp;' . sprintf(
			_( '%d students moved to %s, and %d class enrollments with them.' ),
			$students_done,
			'<b>' . InfoExpressHTML( ProperDate( $new_date, 'short' ) ) . '</b>',
			$classes_done
		) ],
		'note'
	);

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Move more dates' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Review
 */
if ( $modfunc === 'preview' )
{
	$students = InfoExpressDatesAnalyze( $new_date, $only_ids, $change_enrollment, $change_schedule );

	if ( ! $students )
	{
		echo ErrorMessage(
			[ $scope === 'list' ?
				_( 'None of those IDs belongs to a student of this school.' ) :
				_( 'This school has no student this school year.' ) ],
			'warning'
		);
	}
	else
	{
		$year = InfoExpressSchoolYearDates();

		if ( ( $year['START_DATE'] !== '' && $new_date < $year['START_DATE'] )
			|| ( $year['END_DATE'] !== '' && $new_date > $year['END_DATE'] ) )
		{
			echo ErrorMessage(
				[ sprintf(
					_( '%s is outside this school year (%s to %s). Scheduling, attendance and the marking periods all sit inside the year — a date outside it is usually a slip.' ),
					'<b>' . InfoExpressHTML( ProperDate( $new_date, 'short' ) ) . '</b>',
					InfoExpressHTML( ProperDate( $year['START_DATE'], 'short' ) ),
					InfoExpressHTML( ProperDate( $year['END_DATE'], 'short' ) )
				) ],
				'warning'
			);
		}

		$moving = $blocked = $attendance = 0;

		$students_LO = [];

		foreach ( $students as $student_id => $row )
		{
			if ( $row['STATUS'] === 'move' )
			{
				$moving++;
			}

			if ( $row['CLASSES_BLOCKED'] || $row['ENROLLMENT_BLOCKED'] )
			{
				$blocked++;
			}

			$attendance += $row['ATTENDANCE_BEFORE'];

			$students_LO[] = [
				'CHECK' => $row['STATUS'] === 'move' ?
					'<input type="checkbox" name="' .
						AttrEscape( 'skip_students[' . $student_id . ']' ) . '" value="Y" />' :
					'-',
				'STUDENT_ID' => InfoExpressHTML( $student_id ),
				'STUDENT' => InfoExpressHTML( $row['FULL_NAME'] ),
				'GRADE_LEVEL' => InfoExpressHTML( issetVal( $row['GRADE_LEVEL'], '' ) ),
				'ENROLLED' => $change_enrollment ?
					InfoExpressHTML( $row['ENROLLMENT_FROM'] !== '' ?
						ProperDate( $row['ENROLLMENT_FROM'], 'short' ) :
						'-' ) :
					'-',
				'CLASSES' => InfoExpressHTML( $row['CLASSES_TOTAL'] ),
				'WHAT' => _infoExpressDatesWhat( $row, $change_enrollment, $change_schedule ),
			];
		}

		echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
			'&modfunc=save' ) . '" method="POST">';

		// Carry the choices through to the apply step.
		echo '<input type="hidden" name="year_new_date" value="' .
				AttrEscape( mb_substr( $new_date, 0, 4 ) ) . '" />' .
			'<input type="hidden" name="month_new_date" value="' .
				AttrEscape( mb_substr( $new_date, 5, 2 ) ) . '" />' .
			'<input type="hidden" name="day_new_date" value="' .
				AttrEscape( mb_substr( $new_date, 8, 2 ) ) . '" />' .
			'<input type="hidden" name="scope" value="' . AttrEscape( $scope ) . '" />' .
			'<input type="hidden" name="student_ids" value="' . AttrEscape( $only_text ) . '" />' .
			( $change_enrollment ? '<input type="hidden" name="change_enrollment" value="Y" />' : '' ) .
			( $change_schedule ? '<input type="hidden" name="change_schedule" value="Y" />' : '' );

		echo ErrorMessage(
			[ sprintf(
				_( '%d students out of %d will move to %s. Tick "Skip" on any you do not want.' ),
				$moving,
				count( $students ),
				'<b>' . InfoExpressHTML( ProperDate( $new_date, 'short' ) ) . '</b>'
			) ],
			'note'
		);

		if ( $blocked )
		{
			echo ErrorMessage(
				[ sprintf(
					_( '%d students hold a record that ends before the new date. Those records are left alone: a start date past the end date would read as a drop.' ),
					$blocked
				) ],
				'warning'
			);
		}

		if ( $attendance )
		{
			echo ErrorMessage(
				[ sprintf(
					_( '%d period attendance records fall before the new date. They are left as they are — this page never deletes attendance — but they now sit outside the days these students are enrolled for.' ),
					$attendance
				) ],
				'warning'
			);
		}

		PopTable( 'header', _( 'Dates to Move' ) );

		if ( count( $students ) > 1000 )
		{
			echo ErrorMessage(
				[ sprintf(
					_( '%d students are listed: only the first 1000 are shown. All of them are moved — the list is cut, the change is not.' ),
					count( $students )
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
				'ENROLLED' => _( 'Enrolled' ),
				'CLASSES' => _( 'Classes' ),
				'WHAT' => _( 'What changes' ),
			],
			'Student',
			'Students',
			[],
			[],
			[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
		);

		echo '<div class="center"><br />' .
			( $moving ?
				Buttons( _( 'Move the Dates' ) ) :
				ErrorMessage( [ _( 'There is no date to move.' ) ], 'note' ) ) .
			'</div>';

		PopTable( 'footer' );

		echo '</form>';
	}

	echo '<div class="center">' .
		button(
			'back',
			_( 'Start over' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 1 -- The date & who it applies to
 */
echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=preview' ) . '" method="POST">';

PopTable( 'header', _( 'Enrollment Dates' ) );

echo '<p>' . _( 'Moves the day a student was enrolled, and the day they joined each of their classes, to the date you pick. Only the start date moves: nobody is enrolled or dropped, and no end date is touched.' ) . '</p>';

echo '<div class="center"><br />' .
	DateInput(
		VerifyDate( $new_date ) ? $new_date : '',
		'new_date',
		_( 'Move the dates to' ),
		false,
		false,
		true
	) . '</div><br />';

echo '<fieldset><legend>' . _( 'What to move' ) . '</legend>' .
	'<label><input type="checkbox" name="change_enrollment" value="Y"' .
		( $change_enrollment ? ' checked' : '' ) . ' /> ' .
		_( 'The enrollment date' ) . '</label><br />' .
	'<label><input type="checkbox" name="change_schedule" value="Y"' .
		( $change_schedule ? ' checked' : '' ) . ' /> ' .
		_( 'The date they joined each class' ) . '</label>' .
	'</fieldset><br />';

echo '<fieldset><legend>' . _( 'Which students' ) . '</legend>' .
	'<label><input type="radio" name="scope" value="all"' .
		( $scope === 'all' ? ' checked' : '' ) . ' /> ' .
		_( 'Every student of this school' ) . '</label><br />' .
	'<label><input type="radio" name="scope" value="list"' .
		( $scope === 'list' ? ' checked' : '' ) . ' /> ' .
		sprintf( _( 'Only these %s IDs' ), Config( 'NAME' ) ) . '</label>' .
	'<br /><textarea id="student_ids" name="student_ids" rows="5" class="width-100p" ' .
		'placeholder="' . AttrEscape( "1000123\n1000124\n1000125" ) . '">' .
		InfoExpressHTML( $only_text ) . '</textarea>' .
	'<p>' . _( 'One ID per line, or separated by commas — anything that is not a digit separates one from the next, so a column pasted from a spreadsheet works.' ) . '</p>' .
	'</fieldset>';

echo '<div class="center"><br />' . Buttons( _( 'Next' ) ) . '</div>';

PopTable( 'footer' );

echo '</form>';

$year = InfoExpressSchoolYearDates();

if ( $year['START_DATE'] !== '' )
{
	echo '<p>' . sprintf(
		_( 'This school year runs from %s to %s.' ),
		'<b>' . InfoExpressHTML( ProperDate( $year['START_DATE'], 'short' ) ) . '</b>',
		'<b>' . InfoExpressHTML( ProperDate( $year['END_DATE'], 'short' ) ) . '</b>'
	) . '</p>';
}

/**
 * Say in words what one student gets
 *
 * Local function
 *
 * @param  array $row        Analyzed row.
 * @param  bool  $enrollment The enrollment date was asked for.
 * @param  bool  $schedule   The class dates were asked for.
 *
 * @return string Wording, HTML safe.
 */
function _infoExpressDatesWhat( $row, $enrollment, $schedule )
{
	$parts = [];

	if ( $enrollment
		&& $row['ENROLLMENT_MOVES'] )
	{
		$parts[] = _( 'The enrollment date' );
	}

	if ( $schedule
		&& $row['CLASSES_MOVE'] )
	{
		$parts[] = sprintf(
			_( '%d of %d classes' ),
			$row['CLASSES_MOVE'],
			$row['CLASSES_TOTAL']
		);
	}

	if ( $parts )
	{
		$text = implode( ', ', $parts );

		if ( $row['CLASSES_BLOCKED'] || $row['ENROLLMENT_BLOCKED'] )
		{
			$text .= ' — <i>' . sprintf(
				_( '%d left alone, they end before the new date' ),
				$row['CLASSES_BLOCKED'] + ( $row['ENROLLMENT_BLOCKED'] ? 1 : 0 )
			) . '</i>';
		}

		return $text;
	}

	if ( $row['STATUS'] === 'blocked' )
	{
		return _( 'Everything ends before the new date: left alone.' );
	}

	return _( 'Already on that date.' );
}

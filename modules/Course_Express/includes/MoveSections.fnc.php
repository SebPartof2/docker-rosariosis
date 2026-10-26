<?php
/**
 * Course Express: Move Sections functions
 *
 * Moves a student from one section of a course to another, the way the Student
 * Schedule page does: the schedule row is updated in place, so the start date
 * and the history are kept.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/CourseExpress.fnc.php';
require_once 'modules/Course_Express/includes/Sections.fnc.php';
require_once 'modules/Course_Express/includes/Troubleshoot.fnc.php';

/**
 * CSV column header aliases
 *
 * @return array Associative `[ FIELD => [ alias, ... ] ]`, in column order.
 */
function CourseExpressMoveAliases()
{
	return [
		'STUDENT' => [
			'student id', 'student i d', 'studentid', 'student number', 'student no',
			'student code', 'id', 'id number', 'sid', 's id', 'stu id',
			'sis id', 'local id', 'state id', 'student',
		],
		'COURSE' => [
			'course number', 'course no', 'course code', 'course short name',
			'course', 'number', 'code',
		],
		'FROM' => [
			'from section', 'from', 'current section', 'current', 'old section',
			'old', 'section from', 'move from', 'from section number',
		],
		'TO' => [
			'to section', 'to', 'new section', 'new', 'target section', 'target',
			'section to', 'move to', 'to section number',
		],
	];
}

/**
 * Parse the moves CSV
 *
 * Without a recognized header row, the columns are read in this order:
 * Student ID, Course Number, From Section, To Section.
 *
 * @param  string $csv_text CSV file contents.
 * @param  array  $messages Messages, passed by reference.
 *
 * @return array Rows: `[ 'LINE', 'STUDENT', 'COURSE', 'FROM', 'TO' ]`.
 */
function CourseExpressMovesCSVParse( $csv_text, &$messages )
{
	$rows = CourseExpressGenericCSVParse(
		$csv_text,
		CourseExpressMoveAliases(),
		[
			sprintf( _( '%s ID' ), Config( 'NAME' ) ),
			_( 'Course Number' ),
			_( 'From Section' ),
			_( 'To Section' ),
		],
		COURSE_EXPRESS_MAX_ROWS,
		$messages
	);

	if ( ! $rows
		&& ! $messages )
	{
		// The file was read, but it holds no move.
		$messages[] = _( 'No moves were found in the file.' );
	}

	return $rows;
}

/**
 * Find a section of a course, by what the file calls it
 *
 * Accepts the section short name ("Section 2"), the number on its own ("2"),
 * and the Course Period ID.
 *
 * @param  array  $sections Sections of the course.
 * @param  string $name     What the file says.
 *
 * @return array|false The section, or false when none or several match.
 */
function CourseExpressSectionFind( $sections, $name )
{
	$name = trim( (string) $name );

	if ( $name === '' )
	{
		return false;
	}

	$key = CourseExpressTitleNormalize( $name );

	$found = [];

	foreach ( (array) $sections as $section )
	{
		if ( CourseExpressTitleNormalize( $section['SHORT_NAME'] ) === $key
			|| (string) $section['COURSE_PERIOD_ID'] === $name )
		{
			$found[] = $section;
		}
	}

	if ( ! $found
		&& ctype_digit( $name ) )
	{
		// Just the number: "2" means the section called "Section 2".
		$numbered = CourseExpressTitleNormalize( sprintf( _( 'Section %d' ), (int) $name ) );

		foreach ( (array) $sections as $section )
		{
			if ( CourseExpressTitleNormalize( $section['SHORT_NAME'] ) === $numbered )
			{
				$found[] = $section;
			}
		}
	}

	return count( $found ) === 1 ? $found[0] : false;
}

/**
 * The courses a student currently holds
 *
 * Dropped courses are left out: they hold no seat and block no period.
 *
 * @param  array $student_ids Student IDs.
 *
 * @return array Associative `[ student id => [ course period id => schedule row ] ]`.
 */
function CourseExpressStudentSchedules( $student_ids )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	$schedule_RET = DBGet( "SELECT STUDENT_ID,COURSE_ID,COURSE_PERIOD_ID,START_DATE,MARKING_PERIOD_ID
		FROM schedule
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		AND STUDENT_ID IN(" . implode( ',', $student_ids ) . ")
		AND ('" . DBDate() . "' BETWEEN START_DATE AND END_DATE OR END_DATE IS NULL)" );

	$schedules = [];

	foreach ( (array) $schedule_RET as $row )
	{
		$schedules[ $row['STUDENT_ID'] ][ $row['COURSE_PERIOD_ID'] ] = $row;
	}

	return $schedules;
}

/**
 * Analyze the parsed rows
 *
 * Sets `STUDENT_ID`, `STUDENT_NAME`, `COURSE_ID`, `COURSE_TITLE`, `FROM_SECTION`,
 * `TO_SECTION`, `START_DATE`, `STATUS` and `STATUS_TEXT`.
 *
 * Status: error | duplicate | not_scheduled | same_section | full | clash | move.
 *
 * @param  array $rows     Parsed rows.
 * @param  bool  $override Move even into a full or clashing section.
 *
 * @return array Analyzed rows.
 */
function CourseExpressMovesAnalyze( $rows, $override )
{
	$courses = CourseExpressCoursesByNumber();

	$sections_by_course = CourseExpressTroubleshootSections();

	$student_ids = [];

	foreach ( $rows as $row )
	{
		$student_ids[] = (int) $row['STUDENT'];
	}

	$students = CourseExpressMoveStudentsGet( $student_ids );

	$schedules = CourseExpressStudentSchedules( array_keys( $students ) );

	// Seats taken (or freed, when negative) by the moves accepted so far, by section.
	$seats_taken = [];

	// Every section by ID, to read what a student already holds.
	$sections_by_id = [];

	foreach ( $sections_by_course as $course_sections )
	{
		foreach ( $course_sections as $section )
		{
			$sections_by_id[ $section['COURSE_PERIOD_ID'] ] = $section;
		}
	}

	$seen = [];

	foreach ( $rows as $i => $row )
	{
		$rows[ $i ] += [
			'STUDENT_ID' => '',
			'STUDENT_NAME' => '',
			'COURSE_ID' => '',
			'COURSE_TITLE' => '',
			'FROM_SECTION' => [],
			'TO_SECTION' => [],
			'START_DATE' => '',
			'STATUS' => 'error',
			'STATUS_TEXT' => '',
		];

		$student_id = trim( (string) $row['STUDENT'] );

		if ( $student_id === ''
			|| ! ctype_digit( $student_id )
			|| (int) $student_id <= 0 )
		{
			$rows[ $i ]['STATUS_TEXT'] = $student_id === '' ?
				sprintf( _( 'No %s ID.' ), Config( 'NAME' ) ) :
				_( 'Please enter valid Numeric data.' );

			continue;
		}

		$student_id = (string) (int) $student_id;

		$rows[ $i ]['STUDENT_ID'] = $student_id;

		if ( ! isset( $students[ $student_id ] ) )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = _( 'No student with that ID in this school.' );

			continue;
		}

		$rows[ $i ]['STUDENT_NAME'] = $students[ $student_id ];

		// The course.
		$course_key = CourseExpressTitleNormalize( $row['COURSE'] );

		if ( $course_key === '' )
		{
			$rows[ $i ]['STATUS_TEXT'] = _( 'Course Number' ) . ': ' .
				_( 'Please fill in the required fields' );

			continue;
		}

		if ( ! isset( $courses[ $course_key ] ) )
		{
			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'No course with the number "%s".' ),
				CourseExpressHTML( $row['COURSE'] )
			);

			continue;
		}

		if ( count( $courses[ $course_key ] ) > 1 )
		{
			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'Several courses have the number "%s".' ),
				CourseExpressHTML( $row['COURSE'] )
			);

			continue;
		}

		$course = $courses[ $course_key ][0];

		$rows[ $i ]['COURSE_ID'] = $course['COURSE_ID'];

		$rows[ $i ]['COURSE_TITLE'] = $course['TITLE'];

		$course_sections = (array) issetVal( $sections_by_course[ $course['COURSE_ID'] ], [] );

		// The two sections.
		$from = CourseExpressSectionFind( $course_sections, $row['FROM'] );

		$to = CourseExpressSectionFind( $course_sections, $row['TO'] );

		if ( ! $from
			|| ! $to )
		{
			$missing = [];

			if ( ! $from )
			{
				$missing[] = _( 'From Section' ) . ': "' . CourseExpressHTML( $row['FROM'] ) . '"';
			}

			if ( ! $to )
			{
				$missing[] = _( 'To Section' ) . ': "' . CourseExpressHTML( $row['TO'] ) . '"';
			}

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'This course has no such section — %s.' ),
				implode( ', ', $missing )
			);

			continue;
		}

		$rows[ $i ]['FROM_SECTION'] = $from;

		$rows[ $i ]['TO_SECTION'] = $to;

		// One move per student per course.
		$signature = $student_id . '-' . $course['COURSE_ID'];

		if ( isset( $seen[ $signature ] ) )
		{
			$rows[ $i ]['STATUS'] = 'duplicate';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'Line %d already moves this student in this course.' ),
				$seen[ $signature ]
			);

			continue;
		}

		$seen[ $signature ] = $row['LINE'];

		if ( (string) $from['COURSE_PERIOD_ID'] === (string) $to['COURSE_PERIOD_ID'] )
		{
			$rows[ $i ]['STATUS'] = 'same_section';

			$rows[ $i ]['STATUS_TEXT'] = _( 'The student is already in that section.' );

			continue;
		}

		// The student has to actually be in the section they are moved out of.
		$held = (array) issetVal( $schedules[ $student_id ], [] );

		if ( ! isset( $held[ $from['COURSE_PERIOD_ID'] ] ) )
		{
			$rows[ $i ]['STATUS'] = 'not_scheduled';

			$rows[ $i ]['STATUS_TEXT'] = isset( $held[ $to['COURSE_PERIOD_ID'] ] ) ?
				_( 'The student is already in the section they would be moved to.' ) :
				_( 'The student is not in the section they would be moved out of.' );

			continue;
		}

		$rows[ $i ]['START_DATE'] = $held[ $from['COURSE_PERIOD_ID'] ]['START_DATE'];

		// Room in the section they are moving to, the moves accepted above counted.
		$seats_free = $to['SEATS_FREE'] === null ? null :
			$to['SEATS_FREE'] - (int) issetVal( $seats_taken[ $to['COURSE_PERIOD_ID'] ], 0 );

		$full = $seats_free !== null && $seats_free < 1;

		// What else the student holds, the section they are leaving excepted.
		$others = [];

		foreach ( $held as $course_period_id => $ignored )
		{
			if ( (string) $course_period_id === (string) $from['COURSE_PERIOD_ID']
				|| ! isset( $sections_by_id[ $course_period_id ] ) )
			{
				continue;
			}

			$others[] = $sections_by_id[ $course_period_id ];
		}

		$clash_with = [];

		foreach ( $others as $other )
		{
			if ( CourseExpressSectionsClash( $to, $other ) )
			{
				$clash_with[] = $other['SHORT_NAME'];
			}
		}

		if ( $full
			&& ! $override )
		{
			$rows[ $i ]['STATUS'] = 'full';

			$rows[ $i ]['STATUS_TEXT'] = _( 'The section they would move to is full.' );

			continue;
		}

		if ( $clash_with
			&& ! $override )
		{
			$rows[ $i ]['STATUS'] = 'clash';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'The section they would move to clashes with: %s.' ),
				CourseExpressHTML( implode( ', ', $clash_with ) )
			);

			continue;
		}

		$rows[ $i ]['STATUS'] = 'move';

		// The rows below see this move: one seat less here, one more there,
		// and the student's schedule as it will be, for the clash checks.
		$seats_taken[ $to['COURSE_PERIOD_ID'] ] = (int) issetVal( $seats_taken[ $to['COURSE_PERIOD_ID'] ], 0 ) + 1;

		$seats_taken[ $from['COURSE_PERIOD_ID'] ] = (int) issetVal( $seats_taken[ $from['COURSE_PERIOD_ID'] ], 0 ) - 1;

		$schedules[ $student_id ][ $to['COURSE_PERIOD_ID'] ] = $held[ $from['COURSE_PERIOD_ID'] ];

		unset( $schedules[ $student_id ][ $from['COURSE_PERIOD_ID'] ] );

		$warnings = [];

		if ( $full )
		{
			$warnings[] = _( 'The section is full.' );
		}

		if ( $clash_with )
		{
			$warnings[] = sprintf(
				_( 'Clashes with: %s.' ),
				CourseExpressHTML( implode( ', ', $clash_with ) )
			);
		}

		$rows[ $i ]['STATUS_TEXT'] = implode( ' ', $warnings );
	}

	return $rows;
}

/**
 * The Students of the current school year & school, by Student ID
 *
 * @param  array $student_ids Student IDs to look for.
 *
 * @return array Associative `[ student id => full name ]`.
 */
function CourseExpressMoveStudentsGet( $student_ids )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	$students_RET = DBGet( "SELECT DISTINCT s.STUDENT_ID,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME
		FROM students s,student_enrollment se
		WHERE se.STUDENT_ID=s.STUDENT_ID
		AND se.SYEAR='" . UserSyear() . "'
		AND se.SCHOOL_ID='" . UserSchool() . "'
		AND s.STUDENT_ID IN(" . implode( ',', $student_ids ) . ")" );

	$students = [];

	foreach ( (array) $students_RET as $student )
	{
		$students[ $student['STUDENT_ID'] ] = implode( ' ', array_filter( [
			$student['FIRST_NAME'],
			issetVal( $student['MIDDLE_NAME'], '' ),
			$student['LAST_NAME'],
		] ) );
	}

	return $students;
}

/**
 * Move one student from one section to another
 *
 * The schedule row is updated in place, the way the Student Schedule page does
 * it, so the start date and the history are kept. The marking period follows
 * the section they move to.
 *
 * Note: gradebook grades, report card grades and period attendance stay
 * attached to the section they were recorded in — the Student Schedule page
 * behaves the same way.
 *
 * @uses Course_Express/MoveSections.php|move_student action hook
 *
 * @param  array $row Analyzed row, of `move` status.
 *
 * @return bool True if the student was moved.
 */
function CourseExpressMoveApply( $row )
{
	$student_id = (int) issetVal( $row['STUDENT_ID'], 0 );

	$from = (int) issetVal( $row['FROM_SECTION']['COURSE_PERIOD_ID'], 0 );

	$to = (int) issetVal( $row['TO_SECTION']['COURSE_PERIOD_ID'], 0 );

	if ( ! $student_id
		|| ! $from
		|| ! $to
		|| $from === $to )
	{
		return false;
	}

	$marking_period_id = (string) issetVal( $row['TO_SECTION']['MARKING_PERIOD_ID'], '' );

	$columns = [ 'COURSE_PERIOD_ID' => $to ];

	if ( $marking_period_id !== '' )
	{
		$columns['MARKING_PERIOD_ID'] = (int) $marking_period_id;

		$mp = GetMP( $marking_period_id, 'MP' );

		if ( $mp )
		{
			// Keep MP in step with MARKING_PERIOD_ID, like Scheduling > Student Schedule.
			$columns['MP'] = $mp;
		}
	}

	$where = [
		'STUDENT_ID' => $student_id,
		'COURSE_PERIOD_ID' => $from,
		'SYEAR' => UserSyear(),
		'SCHOOL_ID' => UserSchool(),
	];

	if ( (string) issetVal( $row['START_DATE'], '' ) !== '' )
	{
		// A student can hold the same section twice over the year: move the one
		// the schedule actually shows.
		$where['START_DATE'] = $row['START_DATE'];
	}

	DBUpdate( 'schedule', $columns, $where );

	// The seat counters of both sections, recounted from the schedule.
	CourseExpressFilledSeatsRecount( $from );

	CourseExpressFilledSeatsRecount( $to );

	$_REQUEST['student_id'] = $student_id;

	$_REQUEST['course_period_id'] = $to;

	// Hook.
	do_action( 'Course_Express/MoveSections.php|move_student' );

	return true;
}

/**
 * Recount the Filled Seats of a section, from the schedule
 *
 * RosarioSIS only writes FILLED_SEATS when the Scheduler runs, so it drifts as
 * students are added and dropped by hand. Recounting the two sections a move
 * touches leaves them right.
 *
 * @param  int $course_period_id Course Period ID.
 *
 * @return int The count written.
 */
function CourseExpressFilledSeatsRecount( $course_period_id )
{
	$count = (int) DBGetOne( "SELECT COUNT(1)
		FROM schedule
		WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'
		AND SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		AND ('" . DBDate() . "' BETWEEN START_DATE AND END_DATE OR END_DATE IS NULL)" );

	DBUpdate(
		'course_periods',
		[ 'FILLED_SEATS' => $count ],
		[
			'COURSE_PERIOD_ID' => (int) $course_period_id,
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		]
	);

	return $count;
}

/**
 * Get / set the wizard data saved in the session
 *
 * @param  array|bool|null $data Data to save, false to forget it,
 *                               or null to read the saved data (optional).
 *
 * @return array Session data.
 */
function CourseExpressMovesSession( $data = null )
{
	$empty = [ 'ROWS' => [], 'FILE_NAME' => '', 'OVERRIDE' => false ];

	if ( $data === false )
	{
		unset( $_SESSION['Course_Express_Moves'] );

		return $empty;
	}

	if ( is_array( $data ) )
	{
		$_SESSION['Course_Express_Moves'] = $data + [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		];
	}

	$session = issetVal( $_SESSION['Course_Express_Moves'], [] );

	if ( ! $session
		|| issetVal( $session['SYEAR'], '' ) != UserSyear()
		|| issetVal( $session['SCHOOL_ID'], '' ) != UserSchool() )
	{
		return $empty;
	}

	foreach ( $empty as $key => $default )
	{
		$empty[ $key ] = issetVal( $session[ $key ], $default );
	}

	return $empty;
}

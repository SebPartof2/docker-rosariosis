<?php
/**
 * Course Express: Schedule Troubleshoot
 *
 * Three read-only exports for working out why a schedule did not come out right:
 *
 * - Schedules: every scheduled course of the current school year & school, one
 *   row per student per course.
 * - Unfilled Requests: every Course Request with no matching scheduled course,
 *   with what the course looks like — how many sections it has and whether any
 *   seat is free — so the reason is on the row.
 *
 * Both lists carry the standard export control: CSV, Excel or XML.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Sections.fnc.php';
require_once 'modules/Course_Express/includes/Troubleshoot.fnc.php';


DrawHeader( ProgramTitle() );

$_REQUEST['report'] = issetVal( $_REQUEST['report'], '' );

if ( ! in_array( $_REQUEST['report'], [ 'schedules', 'unfilled', 'students' ], true ) )
{
	$_REQUEST['report'] = 'unfilled';
}

$include_dropped = ! empty( $_REQUEST['include_dropped'] );

/**
 * The report to show
 */
echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] ) .
	'" method="POST">';

// This program is granted "Can Use" without "Can Edit", so AllowEdit() is false
// on it and every *Input() helper would render a plain value instead of a form
// control -- the filters below would simply vanish. AllowEditTemporary() is
// core's own way round that: it lifts the restriction for the inputs only.
AllowEditTemporary( 'start' );

$report_select = SelectInput(
	$_REQUEST['report'],
	'report',
	_( 'Report' ),
	[
		'unfilled' => _( 'Unfilled Requests' ),
		'schedules' => _( 'Schedules' ),
		'students' => _( 'Student Schedules & Gaps' ),
	],
	false,
	'',
	false
);

if ( $_REQUEST['report'] === 'schedules' )
{
	$report_select .= '&nbsp;&nbsp;<label class="checkbox-label">' .
		'<input type="checkbox" name="include_dropped" value="Y"' .
		( $include_dropped ? ' checked' : '' ) . '>&nbsp;' .
		_( 'Include dropped courses' ) . '</label>';
}

AllowEditTemporary( 'stop' );

DrawHeader(
	$report_select,
	Buttons( _( 'Go' ) ) . '&nbsp;&nbsp;' .
		CourseExpressDownloadLink(
			'report=' . $_REQUEST['report'] .
			( $include_dropped ? '&include_dropped=Y' : '' )
		)
);

echo '</form>';

/**
 * Grade level of every student of the school, read once
 *
 * A student can hold several enrollment records; the last one is the current
 * grade level.
 */
$grade_levels = [];

$grades_RET = DBGet( "SELECT se.STUDENT_ID,sg.TITLE
	FROM student_enrollment se,school_gradelevels sg
	WHERE sg.ID=se.GRADE_ID
	AND se.SYEAR='" . UserSyear() . "'
	AND se.SCHOOL_ID='" . UserSchool() . "'
	AND sg.SCHOOL_ID='" . UserSchool() . "'
	ORDER BY se.START_DATE IS NULL,se.START_DATE" );

foreach ( (array) $grades_RET as $grade )
{
	$grade_levels[ $grade['STUDENT_ID'] ] = $grade['TITLE'];
}

/**
 * Student Schedules & Gaps
 *
 * One row per student per course: what they hold, and what they asked for and
 * did not get. A missing request carries every section of the course it could
 * have gone into, with the seats free and whatever it clashes with — so the
 * reason it does not fit is on the row rather than left to be worked out.
 */
if ( $_REQUEST['report'] === 'students' )
{
	$sections_by_course = CourseExpressTroubleshootSections();

	// What every student holds. Dropped courses do not occupy a seat or a period.
	$held_RET = DBGet( "SELECT s.STUDENT_ID,s.COURSE_PERIOD_ID,s.COURSE_ID,
		c.SHORT_NAME AS COURSE_NUMBER,c.TITLE AS COURSE_TITLE
		FROM schedule s,courses c
		WHERE c.COURSE_ID=s.COURSE_ID
		AND s.SYEAR='" . UserSyear() . "'
		AND s.SCHOOL_ID='" . UserSchool() . "'
		AND ('" . DBDate() . "' BETWEEN s.START_DATE AND s.END_DATE OR s.END_DATE IS NULL)" );

	// Every section, by course period ID, so a held course can be looked up.
	$sections_by_id = [];

	foreach ( $sections_by_course as $course_sections )
	{
		foreach ( $course_sections as $section )
		{
			$sections_by_id[ $section['COURSE_PERIOD_ID'] ] = $section;
		}
	}

	$held = $course_numbers = $held_courses = [];

	foreach ( (array) $held_RET as $row )
	{
		$course_number = (string) issetVal( $row['COURSE_NUMBER'], '' );

		$course_numbers[ $row['COURSE_PERIOD_ID'] ] = $course_number !== '' ?
			$course_number :
			ParseMLField( (string) $row['COURSE_TITLE'] );

		if ( ! isset( $sections_by_id[ $row['COURSE_PERIOD_ID'] ] ) )
		{
			continue;
		}

		$held[ $row['STUDENT_ID'] ][] = $sections_by_id[ $row['COURSE_PERIOD_ID'] ];

		$held_courses[ $row['STUDENT_ID'] ][ $row['COURSE_PERIOD_ID'] ] = [
			'COURSE_NUMBER' => $course_number,
			'COURSE_TITLE' => ParseMLField( (string) $row['COURSE_TITLE'] ),
		];
	}

	// The requests nobody was scheduled into.
	$missing_RET = DBGet( "SELECT sr.STUDENT_ID,sr.COURSE_ID,
		c.SHORT_NAME AS COURSE_NUMBER,c.TITLE AS COURSE_TITLE
		FROM schedule_requests sr,courses c
		WHERE c.COURSE_ID=sr.COURSE_ID
		AND sr.SYEAR='" . UserSyear() . "'
		AND sr.SCHOOL_ID='" . UserSchool() . "'
		AND NOT EXISTS (SELECT 1
			FROM schedule s
			WHERE s.STUDENT_ID=sr.STUDENT_ID
			AND s.COURSE_ID=sr.COURSE_ID
			AND s.SYEAR=sr.SYEAR
			AND s.SCHOOL_ID=sr.SCHOOL_ID)" );

	$missing = [];

	foreach ( (array) $missing_RET as $row )
	{
		$missing[ $row['STUDENT_ID'] ][] = $row;
	}

	// Every student who holds a course or asked for one.
	$student_ids = array_unique( array_merge(
		array_keys( $held_courses ),
		array_keys( $missing )
	) );

	$students = [];

	if ( $student_ids )
	{
		$students_RET = DBGet( "SELECT STUDENT_ID,FIRST_NAME,MIDDLE_NAME,LAST_NAME
			FROM students
			WHERE STUDENT_ID IN(" . implode( ',', array_map( 'intval', $student_ids ) ) . ")
			ORDER BY LAST_NAME,FIRST_NAME" );

		foreach ( (array) $students_RET as $student )
		{
			$students[ $student['STUDENT_ID'] ] = implode( ' ', array_filter( [
				$student['FIRST_NAME'],
				issetVal( $student['MIDDLE_NAME'], '' ),
				$student['LAST_NAME'],
			] ) );
		}
	}

	$students_LO = [];

	$students_with_gaps = $gaps = 0;

	foreach ( $students as $student_id => $student_name )
	{
		$grade_level = (string) issetVal( $grade_levels[ $student_id ], '' );

		// What the student holds.
		foreach ( (array) issetVal( $held_courses[ $student_id ], [] ) as $course_period_id => $course )
		{
			$section = issetVal( $sections_by_id[ $course_period_id ], [] );

			$periods = $days = [];

			foreach ( (array) issetVal( $section['MEETINGS'], [] ) as $meeting )
			{
				$periods[] = $meeting['TITLE'];

				$days[] = $meeting['DAYS'] === '' ? '?' : $meeting['DAYS'];
			}

			$students_LO[] = [
				'STUDENT_ID' => $student_id,
				'STUDENT' => CourseExpressHTML( $student_name ),
				'GRADE_LEVEL' => CourseExpressHTML( $grade_level ),
				'STATUS' => 'scheduled',
				'COURSE_NUMBER' => CourseExpressHTML( $course['COURSE_NUMBER'] ),
				'COURSE' => CourseExpressHTML( $course['COURSE_TITLE'] ),
				'SECTION' => CourseExpressHTML( (string) issetVal( $section['SHORT_NAME'], '' ) ),
				'PERIOD' => CourseExpressHTML( implode( '+', $periods ) ),
				'DAYS' => CourseExpressHTML( implode( '+', $days ) ),
				'MARKING_PERIOD' => CourseExpressHTML(
					GetMP( issetVal( $section['MARKING_PERIOD_ID'], '' ), 'SHORT_NAME' )
				),
				'TEACHER' => CourseExpressHTML(
					GetTeacher( issetVal( $section['TEACHER_ID'], '' ) )
				),
				'WHY' => '',
				'OPTIONS' => '',
			];
		}

		// What the student asked for and did not get.
		$student_gaps = 0;

		foreach ( (array) issetVal( $missing[ $student_id ], [] ) as $request )
		{
			$candidates = (array) issetVal( $sections_by_course[ $request['COURSE_ID'] ], [] );

			$diagnosis = CourseExpressRequestDiagnose(
				(array) issetVal( $held[ $student_id ], [] ),
				$candidates
			);

			$options = [];

			foreach ( $diagnosis['OPTIONS'] as $option )
			{
				$options[] = CourseExpressOptionText( $option, $course_numbers );
			}

			$student_gaps++;

			$gaps++;

			$students_LO[] = [
				'STUDENT_ID' => $student_id,
				'STUDENT' => CourseExpressHTML( $student_name ),
				'GRADE_LEVEL' => CourseExpressHTML( $grade_level ),
				'STATUS' => 'missing',
				'COURSE_NUMBER' => CourseExpressHTML( (string) issetVal( $request['COURSE_NUMBER'], '' ) ),
				'COURSE' => CourseExpressHTML( ParseMLField( (string) $request['COURSE_TITLE'] ) ),
				'SECTION' => '',
				'PERIOD' => '',
				'DAYS' => '',
				'MARKING_PERIOD' => '',
				'TEACHER' => '',
				'WHY' => CourseExpressHTML( $diagnosis['STATUS'] ),
				'OPTIONS' => CourseExpressHTML( implode( ' ; ', $options ) ),
			];
		}

		if ( $student_gaps )
		{
			$students_with_gaps++;
		}
	}

	echo ErrorMessage(
		[ sprintf(
			_( '%d students, %d missing requests for %d of them.' ),
			count( $students ),
			$gaps,
			$students_with_gaps
		) ],
		$gaps ? 'warning' : 'note'
	);

	CourseExpressReportOutput(
		[
			'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
			'STUDENT' => _( 'Student' ),
			'GRADE_LEVEL' => _( 'Grade Level' ),
			'STATUS' => _( 'Status' ),
			'COURSE_NUMBER' => _( 'Course Number' ),
			'COURSE' => _( 'Course' ),
			'SECTION' => _( 'Section' ),
			'PERIOD' => _( 'Period' ),
			'DAYS' => _( 'Days' ),
			'MARKING_PERIOD' => _( 'Marking Period' ),
			'TEACHER' => _( 'Teacher' ),
			'WHY' => _( 'Why' ),
			'OPTIONS' => _( 'Options' ),
		],
		$students_LO,
		'student-schedules-and-gaps',
		'Row',
		'Rows'
	);

	echo '<p>' . _( 'One row per student per course, in student order: the courses they hold, then the ones they asked for and did not get.' ) . '</p>';

	echo '<table class="widefat"><thead><tr>
			<th>' . _( 'Column' ) . '</th>
			<th>' . _( 'Values' ) . '</th>
		</tr></thead><tbody>
		<tr><td>' . _( 'Status' ) . '</td><td><code>scheduled</code>, <code>missing</code></td></tr>
		<tr><td>' . _( 'Why' ) . '</td><td><code>no_section</code> ' . _( 'the course has no section' ) . ' &middot; ' .
			'<code>all_full</code> ' . _( 'every section is full' ) . ' &middot; ' .
			'<code>clash</code> ' . _( 'every section with room clashes with a course held' ) . ' &middot; ' .
			'<code>open</code> ' . _( 'a section has room and does not clash' ) . '</td></tr>
		<tr><td>' . _( 'Options' ) . '</td><td>' .
			_( 'Every section the missing course could go into, separated by " ; ". Each one reads:' ) .
			' <code>' . _( 'section' ) . '|' . _( 'period' ) . '|' . _( 'days' ) . '|' .
			_( 'marking period' ) . '|seats=|fits=|clash=</code>. ' .
			_( 'Several periods or days on one section are joined with "+", as are several clashing courses.' ) .
			'</td></tr>
		</tbody></table>';

	return;
}

/**
 * Unfilled Requests
 *
 * A request is unfilled when the student holds no scheduled course for the
 * requested course, in this school year & school.
 */
if ( $_REQUEST['report'] === 'unfilled' )
{
	$requests_RET = DBGet( "SELECT sr.STUDENT_ID,st.FIRST_NAME,st.MIDDLE_NAME,st.LAST_NAME,
		c.COURSE_ID,c.SHORT_NAME AS COURSE_NUMBER,c.TITLE AS COURSE_TITLE
		FROM schedule_requests sr,students st,courses c
		WHERE st.STUDENT_ID=sr.STUDENT_ID
		AND c.COURSE_ID=sr.COURSE_ID
		AND sr.SYEAR='" . UserSyear() . "'
		AND sr.SCHOOL_ID='" . UserSchool() . "'
		AND NOT EXISTS (SELECT 1
			FROM schedule s
			WHERE s.STUDENT_ID=sr.STUDENT_ID
			AND s.COURSE_ID=sr.COURSE_ID
			AND s.SYEAR=sr.SYEAR
			AND s.SCHOOL_ID=sr.SCHOOL_ID)
		ORDER BY st.LAST_NAME,st.FIRST_NAME,c.TITLE" );

	// What each course looks like: how many sections, and how many free seats.
	$course_sections = [];

	$sections_RET = DBGet( "SELECT COURSE_ID,TOTAL_SEATS,FILLED_SEATS
		FROM course_periods
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	foreach ( (array) $sections_RET as $section )
	{
		$course_id = $section['COURSE_ID'];

		if ( ! isset( $course_sections[ $course_id ] ) )
		{
			$course_sections[ $course_id ] = [ 'SECTIONS' => 0, 'FREE' => 0, 'UNLIMITED' => false ];
		}

		$course_sections[ $course_id ]['SECTIONS']++;

		if ( (string) issetVal( $section['TOTAL_SEATS'], '' ) === '' )
		{
			// No seat limit on this section.
			$course_sections[ $course_id ]['UNLIMITED'] = true;

			continue;
		}

		$free = (int) $section['TOTAL_SEATS'] - (int) issetVal( $section['FILLED_SEATS'], 0 );

		$course_sections[ $course_id ]['FREE'] += max( $free, 0 );
	}

	$unfilled_LO = [];

	$students_blocked = [];

	foreach ( (array) $requests_RET as $request )
	{
		$course = issetVal(
			$course_sections[ $request['COURSE_ID'] ],
			[ 'SECTIONS' => 0, 'FREE' => 0, 'UNLIMITED' => false ]
		);

		if ( ! $course['SECTIONS'] )
		{
			$reason = _( 'No section for this course.' );

			$free = '-';
		}
		elseif ( $course['UNLIMITED'] )
		{
			$reason = _( 'Seats are available: check for a timetable clash, or run the Scheduler.' );

			$free = _( 'No limit' );
		}
		elseif ( $course['FREE'] < 1 )
		{
			$reason = _( 'Every section is full.' );

			$free = '0';
		}
		else
		{
			$reason = _( 'Seats are available: check for a timetable clash, or run the Scheduler.' );

			$free = (string) $course['FREE'];
		}

		$students_blocked[ $request['STUDENT_ID'] ] = true;

		$unfilled_LO[] = [
			'STUDENT_ID' => $request['STUDENT_ID'],
			'STUDENT' => CourseExpressHTML( implode( ' ', array_filter( [
				$request['FIRST_NAME'],
				issetVal( $request['MIDDLE_NAME'], '' ),
				$request['LAST_NAME'],
			] ) ) ),
			'GRADE_LEVEL' => CourseExpressHTML( issetVal( $grade_levels[ $request['STUDENT_ID'] ], '' ) ),
			'COURSE_NUMBER' => CourseExpressHTML( $request['COURSE_NUMBER'] ),
			'COURSE' => CourseExpressHTML( ParseMLField( $request['COURSE_TITLE'] ) ),
			'SECTIONS' => (string) $course['SECTIONS'],
			'FREE_SEATS' => CourseExpressHTML( $free ),
			'REASON' => CourseExpressHTML( $reason ),
		];
	}

	echo ErrorMessage(
		[ sprintf(
			_( '%d unfilled requests, for %d students.' ),
			count( $unfilled_LO ),
			count( $students_blocked )
		) ],
		$unfilled_LO ? 'warning' : 'note'
	);

	CourseExpressReportOutput(
		[
			'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
			'STUDENT' => _( 'Student' ),
			'GRADE_LEVEL' => _( 'Grade Level' ),
			'COURSE_NUMBER' => _( 'Course Number' ),
			'COURSE' => _( 'Requested Course' ),
			'SECTIONS' => _( 'Sections' ),
			'FREE_SEATS' => _( 'Free Seats' ),
			'REASON' => _( 'Why' ),
		],
		$unfilled_LO,
		'unfilled-requests',
		'Unfilled Request',
		'Unfilled Requests'
	);

	echo '<p>' . _( 'A request is unfilled when the student holds no scheduled course for the course they requested. Free seats are counted across every section of the course.' ) . '</p>';

	return;
}

/**
 * Schedules
 */
$dropped_sql = $include_dropped ? '' :
	" AND ('" . DBDate() . "' BETWEEN s.START_DATE AND s.END_DATE OR s.END_DATE IS NULL)";

$schedule_RET = DBGet( "SELECT s.STUDENT_ID,st.FIRST_NAME,st.MIDDLE_NAME,st.LAST_NAME,
	s.START_DATE,s.END_DATE,s.COURSE_PERIOD_ID,
	c.SHORT_NAME AS COURSE_NUMBER,c.TITLE AS COURSE_TITLE,
	cp.SHORT_NAME AS SECTION,cp.TITLE AS SECTION_NAME,
	cp.MARKING_PERIOD_ID,cp.TEACHER_ID
	FROM schedule s,students st,courses c,course_periods cp
	WHERE st.STUDENT_ID=s.STUDENT_ID
	AND c.COURSE_ID=s.COURSE_ID
	AND cp.COURSE_PERIOD_ID=s.COURSE_PERIOD_ID
	AND s.SYEAR='" . UserSyear() . "'
	AND s.SCHOOL_ID='" . UserSchool() . "'" . $dropped_sql . "
	ORDER BY st.LAST_NAME,st.FIRST_NAME,c.TITLE" );

/**
 * The school periods & days of every section, read once
 */
$school_periods = [];

$cpsp_RET = DBGet( "SELECT cpsp.COURSE_PERIOD_ID,cpsp.DAYS,sp.TITLE
	FROM course_period_school_periods cpsp,school_periods sp,course_periods cp
	WHERE cpsp.PERIOD_ID=sp.PERIOD_ID
	AND cpsp.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
	AND cp.SYEAR='" . UserSyear() . "'
	AND cp.SCHOOL_ID='" . UserSchool() . "'
	AND sp.SYEAR='" . UserSyear() . "'
	AND sp.SCHOOL_ID='" . UserSchool() . "'
	ORDER BY sp.SORT_ORDER IS NULL,sp.SORT_ORDER,sp.TITLE" );

foreach ( (array) $cpsp_RET as $cpsp )
{
	$school_periods[ $cpsp['COURSE_PERIOD_ID'] ][] = [
		'TITLE' => ParseMLField( $cpsp['TITLE'] ),
		'DAYS' => (string) issetVal( $cpsp['DAYS'], '' ),
	];
}

$schedules_LO = [];

$students_scheduled = [];

$dropped_count = 0;

foreach ( (array) $schedule_RET as $row )
{
	$periods = $days = [];

	foreach ( (array) issetVal( $school_periods[ $row['COURSE_PERIOD_ID'] ], [] ) as $school_period )
	{
		$periods[] = $school_period['TITLE'];

		$days[] = $school_period['DAYS'] === '' ?
			'-' :
			CourseExpressDaysDisplay( $school_period['DAYS'] );
	}

	$is_dropped = (string) issetVal( $row['END_DATE'], '' ) !== ''
		&& $row['END_DATE'] < DBDate();

	if ( $is_dropped )
	{
		$dropped_count++;
	}

	$students_scheduled[ $row['STUDENT_ID'] ] = true;

	$schedules_LO[] = [
		'STUDENT_ID' => $row['STUDENT_ID'],
		'STUDENT' => CourseExpressHTML( implode( ' ', array_filter( [
			$row['FIRST_NAME'],
			issetVal( $row['MIDDLE_NAME'], '' ),
			$row['LAST_NAME'],
		] ) ) ),
		'GRADE_LEVEL' => CourseExpressHTML( issetVal( $grade_levels[ $row['STUDENT_ID'] ], '' ) ),
		'COURSE_NUMBER' => CourseExpressHTML( $row['COURSE_NUMBER'] ),
		'COURSE' => CourseExpressHTML( ParseMLField( $row['COURSE_TITLE'] ) ),
		'SECTION' => CourseExpressHTML( $row['SECTION'] ),
		'SECTION_NAME' => CourseExpressHTML( $row['SECTION_NAME'] ),
		'PERIOD' => CourseExpressHTML( $periods ? implode( ', ', $periods ) : '' ),
		'DAYS' => CourseExpressHTML( $days ? implode( ', ', $days ) : '' ),
		'MARKING_PERIOD' => CourseExpressHTML( GetMP( $row['MARKING_PERIOD_ID'], 'SHORT_NAME' ) ),
		'TEACHER' => CourseExpressHTML( GetTeacher( $row['TEACHER_ID'] ) ),
		'START_DATE' => CourseExpressHTML( ProperDate( $row['START_DATE'], 'short' ) ),
		'END_DATE' => (string) issetVal( $row['END_DATE'], '' ) === '' ?
			'-' :
			CourseExpressHTML( ProperDate( $row['END_DATE'], 'short' ) ),
		'STATUS' => $is_dropped ? _( 'Dropped' ) : _( 'Current' ),
	];
}

$counts = [ sprintf(
	_( '%d scheduled courses, for %d students.' ),
	count( $schedules_LO ),
	count( $students_scheduled )
) ];

if ( $dropped_count )
{
	$counts[] = sprintf( _( '%d of them are dropped.' ), $dropped_count );
}

echo ErrorMessage( $counts, 'note' );

CourseExpressReportOutput(
	[
		'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		'STUDENT' => _( 'Student' ),
		'GRADE_LEVEL' => _( 'Grade Level' ),
		'COURSE_NUMBER' => _( 'Course Number' ),
		'COURSE' => _( 'Course' ),
		'SECTION' => _( 'Section' ),
		'SECTION_NAME' => _( 'Section Name' ),
		'PERIOD' => _( 'Period' ),
		'DAYS' => _( 'Days' ),
		'MARKING_PERIOD' => _( 'Marking Period' ),
		'TEACHER' => _( 'Teacher' ),
		'START_DATE' => _( 'Start Date' ),
		'END_DATE' => _( 'End Date' ),
		'STATUS' => _( 'Status' ),
	],
	$schedules_LO,
	'schedules',
	'Scheduled Course',
	'Scheduled Courses'
);

echo '<p>' . _( 'One row per student per scheduled course. Dropped courses are left out unless you ask for them.' ) . '</p>';

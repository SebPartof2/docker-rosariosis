<?php
/**
 * Course Express: Empty Sections
 *
 * Read-only reference: the sections nobody is scheduled into, so they can be
 * culled or merged. Sections just short of viable are found too, by raising the
 * "at most" threshold.
 *
 * The student count is counted from the schedule rather than read from the
 * FILLED_SEATS counter, which can drift. Both are shown, so a section that
 * looks empty but is not is obvious before anything is deleted.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Sections.fnc.php';
require_once 'modules/Course_Express/includes/Troubleshoot.fnc.php';


DrawHeader( ProgramTitle() );

$_REQUEST['subject_id'] = (string) (int) issetVal( $_REQUEST['subject_id'], '' );

$_REQUEST['at_most'] = (string) issetVal( $_REQUEST['at_most'], '0' );

if ( ! in_array( $_REQUEST['at_most'], [ '0', '5', '10', 'all' ], true ) )
{
	$_REQUEST['at_most'] = '0';
}

$subjects_RET = DBGet( "SELECT SUBJECT_ID,TITLE
	FROM course_subjects
	WHERE SYEAR='" . UserSyear() . "'
	AND SCHOOL_ID='" . UserSchool() . "'
	ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

/**
 * The filters
 */
echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] ) .
	'" method="POST">';

$subject_options = [];

foreach ( (array) $subjects_RET as $subject )
{
	$subject_options[ $subject['SUBJECT_ID'] ] = ParseMLField( $subject['TITLE'] );
}

// This program is granted "Can Use" without "Can Edit", so AllowEdit() is false
// on it and every *Input() helper would render a plain value instead of a form
// control -- the filters below would simply vanish. AllowEditTemporary() is
// core's own way round that: it lifts the restriction for the inputs only.
AllowEditTemporary( 'start' );

$filters = SelectInput(
	$_REQUEST['at_most'],
	'at_most',
	_( 'Students' ),
	[
		'0' => _( 'None at all' ),
		'5' => sprintf( _( 'At most %d' ), 5 ),
		'10' => sprintf( _( 'At most %d' ), 10 ),
		'all' => _( 'Every section' ),
	],
	false,
	'',
	false
);

$filters .= '&nbsp;&nbsp;' . SelectInput(
	$_REQUEST['subject_id'],
	'subject_id',
	_( 'Subject' ),
	$subject_options,
	_( 'All Subjects' ),
	'',
	false
);

AllowEditTemporary( 'stop' );

DrawHeader(
	$filters,
	Buttons( _( 'Go' ) ) . '&nbsp;&nbsp;' .
		CourseExpressDownloadLink(
			'at_most=' . $_REQUEST['at_most'] .
			'&subject_id=' . $_REQUEST['subject_id']
		)
);

echo '</form>';

/**
 * Every section, with what it holds
 */
$subject_sql = $_REQUEST['subject_id'] ?
	" AND c.SUBJECT_ID='" . (int) $_REQUEST['subject_id'] . "'" : '';

$sections_RET = DBGet( "SELECT cs.TITLE AS SUBJECT_TITLE,c.SUBJECT_ID,c.COURSE_ID,
	c.TITLE AS COURSE_TITLE,c.SHORT_NAME AS COURSE_NUMBER,
	cp.COURSE_PERIOD_ID,cp.SHORT_NAME AS SECTION,cp.TITLE AS SECTION_NAME,
	cp.MARKING_PERIOD_ID,cp.TEACHER_ID,cp.TOTAL_SEATS,cp.FILLED_SEATS
	FROM course_periods cp,courses c,course_subjects cs
	WHERE c.COURSE_ID=cp.COURSE_ID
	AND cs.SUBJECT_ID=c.SUBJECT_ID
	AND cp.SYEAR='" . UserSyear() . "'
	AND cp.SCHOOL_ID='" . UserSchool() . "'" . $subject_sql . "
	ORDER BY cs.SORT_ORDER IS NULL,cs.SORT_ORDER,cs.TITLE,c.TITLE,cp.SHORT_NAME" );

if ( ! $sections_RET )
{
	echo ErrorMessage(
		[ _( 'There is no section yet. Import some sections first.' ) ],
		'warning'
	);

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Import Sections' ),
			URLEscape( 'Modules.php?modname=Course_Express/Sections.php' )
		) .
		'</div>';

	return;
}

/**
 * How many students each section actually holds
 *
 * Counted from the schedule, dropped courses left out.
 */
$student_counts = [];

$counts_RET = DBGet( "SELECT COURSE_PERIOD_ID,COUNT(1) AS STUDENTS
	FROM schedule
	WHERE SYEAR='" . UserSyear() . "'
	AND SCHOOL_ID='" . UserSchool() . "'
	AND ('" . DBDate() . "' BETWEEN START_DATE AND END_DATE OR END_DATE IS NULL)
	GROUP BY COURSE_PERIOD_ID" );

foreach ( (array) $counts_RET as $count )
{
	$student_counts[ $count['COURSE_PERIOD_ID'] ] = (int) $count['STUDENTS'];
}

$meetings = CourseExpressTroubleshootMeetings();

$sections_LO = [];

$total = $empty = $mismatched = 0;

foreach ( (array) $sections_RET as $section )
{
	$total++;

	$students = (int) issetVal( $student_counts[ $section['COURSE_PERIOD_ID'] ], 0 );

	if ( ! $students )
	{
		$empty++;
	}

	// The stored counter disagreeing with the schedule is worth knowing before
	// a section that looks empty gets deleted.
	$filled = (int) issetVal( $section['FILLED_SEATS'], 0 );

	$mismatch = $filled !== $students;

	if ( $mismatch )
	{
		$mismatched++;
	}

	if ( $_REQUEST['at_most'] !== 'all'
		&& $students > (int) $_REQUEST['at_most'] )
	{
		continue;
	}

	$periods = $days = [];

	foreach ( (array) issetVal( $meetings[ $section['COURSE_PERIOD_ID'] ], [] ) as $meeting )
	{
		$periods[] = $meeting['TITLE'];

		$days[] = $meeting['DAYS'] === '' ?
			'-' :
			CourseExpressDaysDisplay( $meeting['DAYS'] );
	}

	$sections_LO[] = [
		'SUBJECT' => CourseExpressHTML( ParseMLField( $section['SUBJECT_TITLE'] ) ),
		'COURSE' => CourseExpressHTML( ParseMLField( $section['COURSE_TITLE'] ) ),
		'COURSE_NUMBER' => CourseExpressHTML( $section['COURSE_NUMBER'] ),
		// Straight to the section in Scheduling > Courses, to merge or delete it.
		'SECTION' => '<a href="' . URLEscape( 'Modules.php?modname=Scheduling/Courses.php' .
				'&subject_id=' . $section['SUBJECT_ID'] .
				'&course_id=' . $section['COURSE_ID'] .
				'&course_period_id=' . $section['COURSE_PERIOD_ID'] ) . '">' .
			CourseExpressHTML( $section['SECTION'] ) . '</a>',
		'PERIOD' => CourseExpressHTML( $periods ? implode( ', ', $periods ) : '' ),
		'DAYS' => CourseExpressHTML( $days ? implode( ', ', $days ) : '' ),
		'MARKING_PERIOD' => CourseExpressHTML( GetMP( $section['MARKING_PERIOD_ID'], 'SHORT_NAME' ) ),
		'TEACHER' => CourseExpressHTML( GetTeacher( $section['TEACHER_ID'] ) ),
		'STUDENTS' => (string) $students,
		'SEATS' => (string) issetVal( $section['TOTAL_SEATS'], '' ) === '' ?
			'&infin;' :
			CourseExpressHTML( $section['TOTAL_SEATS'] ),
		'FILLED_SEATS' => $mismatch ?
			'<b>' . $filled . '</b>' :
			(string) $filled,
	];
}

/**
 * The counts
 */
$counts = [ sprintf(
	_( '%d of the %d sections have no student.' ),
	$empty,
	$total
) ];

echo ErrorMessage( $counts, $empty ? 'warning' : 'note' );

if ( $mismatched )
{
	echo ErrorMessage(
		[ sprintf(
			_( 'The stored Filled Seats of %d sections disagrees with the schedule. The Students column is the one counted from the schedule; check a section before deleting it.' ),
			$mismatched
		) ],
		'warning'
	);
}

CourseExpressReportOutput(
	[
		'SUBJECT' => _( 'Subject' ),
		'COURSE' => _( 'Course' ),
		'COURSE_NUMBER' => _( 'Course Number' ),
		'SECTION' => _( 'Section' ),
		'PERIOD' => _( 'Period' ),
		'DAYS' => _( 'Days' ),
		'MARKING_PERIOD' => _( 'Marking Period' ),
		'TEACHER' => _( 'Teacher' ),
		'STUDENTS' => _( 'Students' ),
		'SEATS' => _( 'Seats' ),
		'FILLED_SEATS' => _( 'Filled Seats' ),
	],
	$sections_LO,
	'empty-sections',
	'Section',
	'Sections'
);

echo '<p>' . _( 'Students are counted from the schedule, dropped courses left out. Filled Seats is the counter stored on the section; it is shown in bold when the two disagree. Click a section to open it in Scheduling > Courses.' ) . '</p>';

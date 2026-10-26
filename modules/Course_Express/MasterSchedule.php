<?php
/**
 * Course Express: Master Schedule
 *
 * Read-only reference: every course of the current school year & school with
 * its sections, showing the fields the Course Express imports write — the
 * course number the next file has to quote, the section short names assigned,
 * and the settings each section was created with.
 *
 * Courses with no section yet are listed too: those are the ones still missing
 * a line in the sections file.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Sections.fnc.php';


DrawHeader( ProgramTitle() );

$error = $note = [];

/**
 * Rebuild the section names
 *
 * The name of a section is built from its school period, its days, its marking
 * period, its short name and its teacher. Rename any of those -- or import
 * sections with a version of this module that built the name wrong -- and the
 * stored names drift. This puts them back.
 */
if ( $_REQUEST['modfunc'] === 'recompute'
	&& AllowEdit() )
{
	if ( DeletePrompt(
		_( 'Rebuild the section names' ),
		_( 'Rebuild' ),
		true
	) )
	{
		$recomputed = CourseExpressSectionTitlesRecompute();

		$note[] = button( 'check' ) . '&nbsp;' . sprintf(
			_( '%d section names were rebuilt, out of %d checked.' ),
			$recomputed['CHANGED'],
			$recomputed['CHECKED']
		);

		// Unset modfunc & redirect URL.
		RedirectURL( 'modfunc' );
	}
	else
	{
		return;
	}
}

echo ErrorMessage( $error );

echo ErrorMessage( $note, 'note' );

$_REQUEST['subject_id'] = (string) (int) issetVal( $_REQUEST['subject_id'], '' );

$_REQUEST['show'] = issetVal( $_REQUEST['show'], '' );

if ( ! in_array( $_REQUEST['show'], [ 'no_section' ], true ) )
{
	$_REQUEST['show'] = '';
}

$subjects_RET = DBGet( "SELECT SUBJECT_ID,TITLE
	FROM course_subjects
	WHERE SYEAR='" . UserSyear() . "'
	AND SCHOOL_ID='" . UserSchool() . "'
	ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

if ( ! $subjects_RET )
{
	echo ErrorMessage(
		[ _( 'There is no course yet. Import some courses first.' ) ],
		'warning'
	);

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Import Courses' ),
			URLEscape( 'Modules.php?modname=Course_Express/Import.php' )
		) .
		'</div>';

	return;
}

/**
 * The courses, with their sections. A course with no section is listed too.
 */
$subject_sql = $_REQUEST['subject_id'] ?
	" AND c.SUBJECT_ID='" . (int) $_REQUEST['subject_id'] . "'" : '';

$rows_RET = DBGet( "SELECT cs.TITLE AS SUBJECT_TITLE,cs.SORT_ORDER AS SUBJECT_SORT,
	c.COURSE_ID,c.TITLE AS COURSE_TITLE,c.SHORT_NAME AS COURSE_NUMBER,c.CREDIT_HOURS,
	cp.COURSE_PERIOD_ID,cp.SHORT_NAME AS SECTION,cp.TITLE AS SECTION_TITLE,
	cp.MARKING_PERIOD_ID,cp.TEACHER_ID,
	cp.TOTAL_SEATS,cp.FILLED_SEATS,cp.CREDITS,cp.GRADE_SCALE_ID,cp.DOES_ATTENDANCE,
	cp.DOES_CLASS_RANK,cp.DOES_HONOR_ROLL,cp.ROOM
	FROM courses c
	JOIN course_subjects cs ON (cs.SUBJECT_ID=c.SUBJECT_ID)
	LEFT OUTER JOIN course_periods cp ON (cp.COURSE_ID=c.COURSE_ID
		AND cp.SYEAR=c.SYEAR AND cp.SCHOOL_ID=c.SCHOOL_ID)
	WHERE c.SYEAR='" . UserSyear() . "'
	AND c.SCHOOL_ID='" . UserSchool() . "'" . $subject_sql . "
	ORDER BY cs.SORT_ORDER IS NULL,cs.SORT_ORDER,cs.TITLE,c.TITLE,cp.SHORT_NAME" );

/**
 * The school periods & meeting days of every section, assembled here: a section
 * can meet in several school periods.
 */
$school_periods = [];

$cpsp_RET = DBGet( "SELECT cpsp.COURSE_PERIOD_ID,cpsp.DAYS,sp.TITLE,sp.SORT_ORDER
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

/**
 * Build the list
 */
$schedule_LO = [];

$courses = $sections = $courses_without_section = 0;

$seen_courses = [];

foreach ( (array) $rows_RET as $row )
{
	$has_section = ! empty( $row["COURSE_PERIOD_ID"] );

	if ( ! isset( $seen_courses[ $row['COURSE_ID'] ] ) )
	{
		$seen_courses[ $row['COURSE_ID'] ] = true;

		$courses++;

		if ( ! $has_section )
		{
			$courses_without_section++;
		}
	}

	if ( $has_section )
	{
		$sections++;
	}

	if ( $_REQUEST['show'] === 'no_section'
		&& $has_section )
	{
		continue;
	}

	$periods = $days = [];

	foreach ( (array) issetVal( $school_periods[ $row['COURSE_PERIOD_ID'] ], [] ) as $school_period )
	{
		$periods[] = CourseExpressHTML( $school_period['TITLE'] );

		$days[] = $school_period['DAYS'] === '' ?
			'-' :
			CourseExpressHTML( CourseExpressDaysDisplay( $school_period['DAYS'] ) );
	}

	$schedule_LO[] = [
		'SUBJECT' => CourseExpressHTML( ParseMLField( $row['SUBJECT_TITLE'] ) ),
		'COURSE' => CourseExpressHTML( ParseMLField( $row['COURSE_TITLE'] ) ),
		'COURSE_NUMBER' => '<span class="mono">' .
			CourseExpressHTML( $row['COURSE_NUMBER'] ) . '</span>',
		'SECTION' => $has_section ?
			CourseExpressHTML( $row['SECTION'] ) :
			'<i>' . _( 'No section yet' ) . '</i>',
		'SECTION_NAME' => $has_section ?
			CourseExpressHTML( $row['SECTION_TITLE'] ) :
			'-',
		'PERIOD' => $periods ? implode( '<br />', $periods ) : '-',
		'DAYS' => $days ? implode( '<br />', $days ) : '-',
		'MARKING_PERIOD' => $has_section ?
			CourseExpressHTML( GetMP( $row['MARKING_PERIOD_ID'], 'SHORT_NAME' ) ) :
			'-',
		'TEACHER' => $has_section ?
			CourseExpressHTML( GetTeacher( $row['TEACHER_ID'] ) ) :
			'-',
		'SEATS' => $has_section ?
			( (string) issetVal( $row['FILLED_SEATS'], '0' ) . ' / ' .
				( (string) issetVal( $row['TOTAL_SEATS'], '' ) === '' ?
					'&infin;' :
					CourseExpressHTML( $row['TOTAL_SEATS'] ) ) ) :
			'-',
		'CREDITS' => $has_section ?
			CourseExpressHTML( $row['CREDITS'] ) :
			CourseExpressHTML( $row['CREDIT_HOURS'] ),
		'GRADE_SCALE' => $has_section ?
			CourseExpressSettingDisplay( 'GRADE_SCALE_ID', $row['GRADE_SCALE_ID'] ) :
			'-',
		'ATTENDANCE' => $has_section ?
			CourseExpressSettingDisplay( 'DOES_ATTENDANCE', $row['DOES_ATTENDANCE'] ) :
			'-',
		'RANK_HONOR' => $has_section ?
			( CourseExpressSettingDisplay( 'DOES_CLASS_RANK', $row['DOES_CLASS_RANK'] ) . ' / ' .
				CourseExpressSettingDisplay( 'DOES_HONOR_ROLL', $row['DOES_HONOR_ROLL'] ) ) :
			'-',
	];
}

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
	$_REQUEST['subject_id'],
	'subject_id',
	_( 'Subject' ),
	$subject_options,
	_( 'All Subjects' ),
	'',
	false
);

$filters .= '&nbsp;&nbsp;<label class="checkbox-label">' .
	'<input type="checkbox" name="show" value="no_section"' .
	( $_REQUEST['show'] === 'no_section' ? ' checked' : '' ) . '>&nbsp;' .
	_( 'Only the courses with no section' ) . '</label>';

AllowEditTemporary( 'stop' );

DrawHeader( $filters, Buttons( _( 'Go' ) ) );

echo '</form>';

if ( AllowEdit() )
{
	DrawHeader(
		'',
		button(
			'settings',
			_( 'Rebuild the section names' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] . '&modfunc=recompute' )
		)
	);
}

DrawHeader(
	'',
	CourseExpressDownloadLink(
		'subject_id=' . $_REQUEST['subject_id'] . '&show=' . $_REQUEST['show']
	)
);

/**
 * The counts: what is there, and what is still missing a section
 */
$counts = [ sprintf(
	_( '%d courses, %d sections.' ),
	$courses,
	$sections
) ];

if ( $courses_without_section )
{
	$counts[] = sprintf(
		_( '%d courses have no section yet.' ),
		$courses_without_section
	);
}

echo ErrorMessage( $counts, $courses_without_section ? 'warning' : 'note' );

CourseExpressReportOutput(
	[
		'SUBJECT' => _( 'Subject' ),
		'COURSE' => _( 'Course' ),
		'COURSE_NUMBER' => _( 'Course Number' ),
		'SECTION' => _( 'Section' ),
		'SECTION_NAME' => _( 'Section Name' ),
		'PERIOD' => _( 'Period' ),
		'DAYS' => _( 'Days' ),
		'MARKING_PERIOD' => _( 'Marking Period' ),
		'TEACHER' => _( 'Teacher' ),
		'SEATS' => _( 'Seats' ),
		'CREDITS' => _( 'Credits' ),
		'GRADE_SCALE' => _( 'Grading Scale' ),
		'ATTENDANCE' => _( 'Attendance' ),
		'RANK_HONOR' => _( 'Rank / Honor Roll' ),
	],
	$schedule_LO,
	'master-schedule',
	'Section',
	'Sections'
);

echo '<p>' . _( 'Seats read as filled / total. Credits come from the section; for a course with no section yet, its credit hours are shown instead.' ) . '</p>';

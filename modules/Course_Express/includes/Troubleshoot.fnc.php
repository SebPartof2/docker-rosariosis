<?php
/**
 * Course Express: Schedule Troubleshoot functions
 *
 * Reads what a student is scheduled into and what they asked for that they did
 * not get, and works out why each missing request does not fit: the course has
 * no section, every section is full, or the sections that do have room clash
 * with something the student already holds.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * Every section of the current school year & school, by course
 *
 * Each section carries the school periods it meets in, so two sections can be
 * compared for a clash.
 *
 * @return array Associative `[ course id => [ section, ... ] ]`.
 */
function CourseExpressTroubleshootSections()
{
	static $sections = null;

	if ( $sections !== null )
	{
		return $sections;
	}

	$sections_RET = DBGet( "SELECT COURSE_PERIOD_ID,COURSE_ID,SHORT_NAME,MARKING_PERIOD_ID,
		TEACHER_ID,TOTAL_SEATS,FILLED_SEATS
		FROM course_periods
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	if ( ! $sections_RET )
	{
		$sections = [];

		return $sections;
	}

	$meetings = CourseExpressTroubleshootMeetings();

	$sections = [];

	foreach ( (array) $sections_RET as $section )
	{
		$total = (string) issetVal( $section['TOTAL_SEATS'], '' );

		$filled = (int) issetVal( $section['FILLED_SEATS'], 0 );

		$sections[ $section['COURSE_ID'] ][] = [
			'COURSE_PERIOD_ID' => $section['COURSE_PERIOD_ID'],
			'COURSE_ID' => $section['COURSE_ID'],
			'SHORT_NAME' => (string) issetVal( $section['SHORT_NAME'], '' ),
			'MARKING_PERIOD_ID' => (string) issetVal( $section['MARKING_PERIOD_ID'], '' ),
			'TEACHER_ID' => (string) issetVal( $section['TEACHER_ID'], '' ),
			// No total means no seat limit.
			'TOTAL_SEATS' => $total === '' ? null : (int) $total,
			'FILLED_SEATS' => $filled,
			'SEATS_FREE' => $total === '' ? null : max( (int) $total - $filled, 0 ),
			'MEETINGS' => (array) issetVal( $meetings[ $section['COURSE_PERIOD_ID'] ], [] ),
		];
	}

	return $sections;
}

/**
 * The school periods every section meets in
 *
 * @return array Associative `[ course period id => [ [ 'PERIOD_ID', 'DAYS', 'TITLE' ], ... ] ]`.
 */
function CourseExpressTroubleshootMeetings()
{
	static $meetings = null;

	if ( $meetings !== null )
	{
		return $meetings;
	}

	$meetings_RET = DBGet( "SELECT cpsp.COURSE_PERIOD_ID,cpsp.PERIOD_ID,cpsp.DAYS,sp.TITLE
		FROM course_period_school_periods cpsp,school_periods sp,course_periods cp
		WHERE cpsp.PERIOD_ID=sp.PERIOD_ID
		AND cpsp.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
		AND cp.SYEAR='" . UserSyear() . "'
		AND cp.SCHOOL_ID='" . UserSchool() . "'
		AND sp.SYEAR='" . UserSyear() . "'
		AND sp.SCHOOL_ID='" . UserSchool() . "'
		ORDER BY sp.SORT_ORDER IS NULL,sp.SORT_ORDER,sp.TITLE" );

	$meetings = [];

	foreach ( (array) $meetings_RET as $meeting )
	{
		$meetings[ $meeting['COURSE_PERIOD_ID'] ][] = [
			'PERIOD_ID' => (string) $meeting['PERIOD_ID'],
			'DAYS' => (string) issetVal( $meeting['DAYS'], '' ),
			'TITLE' => ParseMLField( (string) $meeting['TITLE'] ),
		];
	}

	return $meetings;
}

/**
 * Do two marking periods run at the same time?
 *
 * Compared on their dates, so a term overlaps the full year and the quarters
 * inside it without walking the marking period tree.
 *
 * @param  string $mp_a First marking period ID.
 * @param  string $mp_b Second marking period ID.
 *
 * @return bool True when the two overlap, or when either is unknown.
 */
function CourseExpressMarkingPeriodsOverlap( $mp_a, $mp_b )
{
	static $dates = null;

	if ( $dates === null )
	{
		$dates = [];

		$mp_RET = DBGet( "SELECT MARKING_PERIOD_ID,START_DATE,END_DATE
			FROM school_marking_periods
			WHERE SYEAR='" . UserSyear() . "'
			AND SCHOOL_ID='" . UserSchool() . "'" );

		foreach ( (array) $mp_RET as $mp )
		{
			$dates[ $mp['MARKING_PERIOD_ID'] ] = [
				'START' => (string) $mp['START_DATE'],
				'END' => (string) $mp['END_DATE'],
			];
		}
	}

	if ( (string) $mp_a === (string) $mp_b )
	{
		return true;
	}

	if ( ! isset( $dates[ (string) $mp_a ] )
		|| ! isset( $dates[ (string) $mp_b ] ) )
	{
		// Unknown marking period: assume they overlap rather than miss a clash.
		return true;
	}

	$a = $dates[ (string) $mp_a ];

	$b = $dates[ (string) $mp_b ];

	return $a['START'] <= $b['END'] && $b['START'] <= $a['END'];
}

/**
 * Do two sections clash?
 *
 * They clash when they meet in the same school period, on a day they share,
 * during marking periods that run at the same time.
 *
 * @param  array $a First section.
 * @param  array $b Second section.
 *
 * @return bool True when a student cannot hold both.
 */
function CourseExpressSectionsClash( $a, $b )
{
	if ( ! CourseExpressMarkingPeriodsOverlap( $a['MARKING_PERIOD_ID'], $b['MARKING_PERIOD_ID'] ) )
	{
		return false;
	}

	foreach ( (array) $a['MEETINGS'] as $meeting_a )
	{
		foreach ( (array) $b['MEETINGS'] as $meeting_b )
		{
			if ( $meeting_a['PERIOD_ID'] !== $meeting_b['PERIOD_ID'] )
			{
				continue;
			}

			if ( CourseExpressDaysOverlap( $meeting_a['DAYS'], $meeting_b['DAYS'] ) )
			{
				return true;
			}
		}
	}

	return false;
}

/**
 * Do two sets of meeting days share a day?
 *
 * A section with no day set meets on any day, so it shares one with everything.
 *
 * @param  string $days_a First DAYS value, for example 'MWF'.
 * @param  string $days_b Second DAYS value.
 *
 * @return bool True when the two share at least one day.
 */
function CourseExpressDaysOverlap( $days_a, $days_b )
{
	$days_a = (string) $days_a;

	$days_b = (string) $days_b;

	if ( $days_a === ''
		|| $days_b === '' )
	{
		// No day set: treat it as every day, rather than miss a clash.
		return true;
	}

	for ( $i = 0; $i < mb_strlen( $days_a ); $i++ )
	{
		if ( mb_strpos( $days_b, mb_substr( $days_a, $i, 1 ) ) !== false )
		{
			return true;
		}
	}

	return false;
}

/**
 * Why can a request not be filled?
 *
 * Looks at every section of the requested course and reports, for each, whether
 * it has room and whether it clashes with what the student already holds.
 *
 * @param  array $held       The student's sections.
 * @param  array $candidates The sections of the requested course.
 *
 * @return array `[ 'STATUS' => no_section|all_full|clash|open, 'OPTIONS' => [ option, ... ] ]`
 *               where each option holds `SECTION`, `PERIODS`, `DAYS`, `SEATS_FREE`,
 *               `FULL`, `CLASH_WITH` (course period IDs) and `FITS`.
 */
function CourseExpressRequestDiagnose( $held, $candidates )
{
	if ( ! $candidates )
	{
		return [ 'STATUS' => 'no_section', 'OPTIONS' => [] ];
	}

	$options = [];

	$any_room = $any_fits = false;

	foreach ( $candidates as $candidate )
	{
		$full = $candidate['SEATS_FREE'] !== null && $candidate['SEATS_FREE'] < 1;

		$clash_with = [];

		foreach ( (array) $held as $section )
		{
			if ( CourseExpressSectionsClash( $candidate, $section ) )
			{
				$clash_with[] = $section;
			}
		}

		$fits = ! $full && ! $clash_with;

		if ( ! $full )
		{
			$any_room = true;
		}

		if ( $fits )
		{
			$any_fits = true;
		}

		$periods = $days = [];

		foreach ( $candidate['MEETINGS'] as $meeting )
		{
			$periods[] = $meeting['TITLE'];

			$days[] = $meeting['DAYS'];
		}

		$options[] = [
			'COURSE_PERIOD_ID' => $candidate['COURSE_PERIOD_ID'],
			'SECTION' => $candidate['SHORT_NAME'],
			'PERIODS' => $periods,
			'DAYS' => $days,
			'MARKING_PERIOD_ID' => $candidate['MARKING_PERIOD_ID'],
			'TEACHER_ID' => $candidate['TEACHER_ID'],
			'SEATS_FREE' => $candidate['SEATS_FREE'],
			'FULL' => $full,
			'CLASH_WITH' => $clash_with,
			'FITS' => $fits,
		];
	}

	if ( $any_fits )
	{
		$status = 'open';
	}
	elseif ( ! $any_room )
	{
		$status = 'all_full';
	}
	else
	{
		$status = 'clash';
	}

	return [ 'STATUS' => $status, 'OPTIONS' => $options ];
}

/**
 * The wording of a request status
 *
 * @param  string $status no_section|all_full|clash|open.
 *
 * @return string Translated sentence.
 */
function CourseExpressRequestStatusText( $status )
{
	$texts = [
		'no_section' => _( 'The course has no section.' ),
		'all_full' => _( 'Every section is full.' ),
		'clash' => _( 'Every section with room clashes with a course the student already holds.' ),
		'open' => _( 'A section has room and does not clash: the Scheduler has not placed it.' ),
	];

	return issetVal( $texts[ $status ], $status );
}

/**
 * One option, written for a machine to read
 *
 * Fields are separated by a pipe, options by a semicolon:
 * `Section 1|Period 3|MWF|FY|seats=4|fits=no|clash=MAT101`
 *
 * @param  array $option     One option from CourseExpressRequestDiagnose().
 * @param  array $course_numbers `[ course period id => course number ]`, to name a clash.
 *
 * @return string The option on one line.
 */
function CourseExpressOptionText( $option, $course_numbers )
{
	$clash = [];

	foreach ( (array) $option['CLASH_WITH'] as $section )
	{
		$clash[] = issetVal(
			$course_numbers[ $section['COURSE_PERIOD_ID'] ],
			$section['COURSE_PERIOD_ID']
		);
	}

	return implode( '|', [
		$option['SECTION'] === '' ? '?' : $option['SECTION'],
		$option['PERIODS'] ? implode( '+', $option['PERIODS'] ) : '?',
		$option['DAYS'] ? implode( '+', $option['DAYS'] ) : '?',
		GetMP( $option['MARKING_PERIOD_ID'], 'SHORT_NAME' ),
		'seats=' . ( $option['SEATS_FREE'] === null ? 'unlimited' : $option['SEATS_FREE'] ),
		'fits=' . ( $option['FITS'] ? 'yes' : 'no' ),
		'clash=' . implode( '+', $clash ),
	] );
}

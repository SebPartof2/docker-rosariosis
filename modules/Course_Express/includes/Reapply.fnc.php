<?php
/**
 * Course Express: Reapply Settings functions
 *
 * Push the current Section Defaults & override sets onto sections which
 * already exist. The settings are resolved exactly as when a section is
 * imported, see CourseExpressCourseSettings().
 *
 * @since 1.2
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Sections.fnc.php';

/**
 * Normalize a stored setting value, so two values meaning the same compare equal
 *
 * `NULL` & `''` are the same, days & attendance categories are order free,
 * and a checkbox is either 'Y' or ''.
 *
 * @param  string $setting Setting name.
 * @param  string $value   Stored value.
 *
 * @return string Normalized value.
 */
function CourseExpressSettingNormalize( $setting, $value )
{
	$settings = CourseExpressSettings();

	$value = trim( (string) $value );

	$type = isset( $settings[ $setting ] ) ? $settings[ $setting ]['TYPE'] : '';

	switch ( $type )
	{
		case 'number':

			return is_numeric( $value ) ? (string) (int) $value : '';

		case 'days':

			$days = '';

			for ( $i = 0; $i < mb_strlen( COURSE_EXPRESS_DAYS ); $i++ )
			{
				$day = mb_substr( COURSE_EXPRESS_DAYS, $i, 1 );

				if ( mb_strpos( $value, $day ) !== false )
				{
					$days .= $day;
				}
			}

			return $days;

		case 'attendance':

			// Note: do not use array_filter() alone, it would drop category '0'.
			$categories = array_unique( array_filter( explode( ',', $value ), 'strlen' ) );

			sort( $categories, SORT_NUMERIC );

			return $categories ? ',' . implode( ',', $categories ) . ',' : '';

		case 'checkbox':

			return $value !== '' && $value !== 'N' ? 'Y' : '';

		default:

			return $value;
	}
}

/**
 * The courses a reapply covers
 *
 * @param  string $subject_id Only the courses of this subject (optional).
 * @param  string $set_id     Only the courses of this override set (optional).
 *
 * @return array|null Course IDs, or null for every course of the school.
 */
function CourseExpressReapplyCourseIds( $subject_id = '', $set_id = '' )
{
	if ( ! $subject_id
		&& ! $set_id )
	{
		return null;
	}

	$where = '';

	if ( $subject_id )
	{
		$where .= " AND SUBJECT_ID='" . (int) $subject_id . "'";
	}

	if ( $set_id )
	{
		$where .= " AND COURSE_ID IN(SELECT COURSE_ID
			FROM course_express_override_courses
			WHERE SET_ID='" . (int) $set_id . "')";
	}

	$courses_RET = DBGet( "SELECT COURSE_ID
		FROM courses
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" . $where );

	$course_ids = [];

	foreach ( (array) $courses_RET as $course )
	{
		$course_ids[] = (int) $course['COURSE_ID'];
	}

	return $course_ids;
}

/**
 * The existing sections of the courses, with their current settings
 *
 * @param  array|null $course_ids Course IDs, null for every course.
 *
 * @return array Sections, each with its `SCHOOL_PERIODS` `[ cpsp id => days ]`.
 */
function CourseExpressReapplySectionsGet( $course_ids )
{
	if ( $course_ids === [] )
	{
		return [];
	}

	$course_where = $course_ids === null ?
		'' :
		" AND cp.COURSE_ID IN(" . implode( ',', array_map( 'intval', $course_ids ) ) . ")";

	$sections_RET = DBGet( "SELECT cp.COURSE_PERIOD_ID,cp.COURSE_ID,cp.TITLE,cp.SHORT_NAME,
		cp.FILLED_SEATS,cp.TOTAL_SEATS,cp.DOES_ATTENDANCE,cp.CALENDAR_ID,cp.GRADE_SCALE_ID,
		cp.DOES_CLASS_RANK,cp.DOES_HONOR_ROLL,
		c.TITLE AS COURSE_TITLE,c.SHORT_NAME AS COURSE_NUMBER
		FROM course_periods cp,courses c
		WHERE c.COURSE_ID=cp.COURSE_ID
		AND cp.SYEAR='" . UserSyear() . "'
		AND cp.SCHOOL_ID='" . UserSchool() . "'" . $course_where . "
		ORDER BY c.TITLE,cp.SHORT_NAME,cp.COURSE_PERIOD_ID" );

	if ( ! $sections_RET )
	{
		return [];
	}

	$sections = [];

	foreach ( (array) $sections_RET as $section )
	{
		$section['COURSE_TITLE'] = ParseMLField( $section['COURSE_TITLE'] );

		$section['SCHOOL_PERIODS'] = [];

		$sections[ $section['COURSE_PERIOD_ID'] ] = $section;
	}

	$school_periods_RET = DBGet( "SELECT cpsp.COURSE_PERIOD_SCHOOL_PERIODS_ID,
		cpsp.COURSE_PERIOD_ID,cpsp.DAYS
		FROM course_period_school_periods cpsp,course_periods cp
		WHERE cp.COURSE_PERIOD_ID=cpsp.COURSE_PERIOD_ID
		AND cp.SYEAR='" . UserSyear() . "'
		AND cp.SCHOOL_ID='" . UserSchool() . "'" . $course_where );

	foreach ( (array) $school_periods_RET as $school_period )
	{
		if ( isset( $sections[ $school_period['COURSE_PERIOD_ID'] ] ) )
		{
			$sections[ $school_period['COURSE_PERIOD_ID'] ]['SCHOOL_PERIODS'][ $school_period['COURSE_PERIOD_SCHOOL_PERIODS_ID'] ] =
				(string) $school_period['DAYS'];
		}
	}

	return $sections;
}

/**
 * Compare the existing sections with the settings they would get today
 *
 * Sets, for each section: `SETTINGS` (resolved), `CHANGES` `[ setting => [ old, new ] ]`,
 * `SETS`, `WARNINGS`, `STATUS` (error|unchanged|update) and `STATUS_TEXT`.
 *
 * Meeting Days are left alone on a section meeting in several school periods:
 * the defaults & override sets hold the days of one period only.
 *
 * @param  array      $only       Settings to reapply.
 * @param  array|null $course_ids Course IDs, null for every course.
 *
 * @return array Analyzed sections `[ course period id => section ]`, the errors
 *               first, then the sections to update, then the unchanged ones.
 */
function CourseExpressReapplyAnalyze( $only, $course_ids )
{
	$settings = CourseExpressSettings();

	$only = array_values( array_intersect( array_keys( $settings ), (array) $only ) );

	$sections = CourseExpressReapplySectionsGet( $course_ids );

	$sets = CourseExpressOverrideSetsGet();

	$by_status = [ 'error' => [], 'update' => [], 'unchanged' => [] ];

	foreach ( $sections as $cp_id => $section )
	{
		$resolved = CourseExpressCourseSettings( $section['COURSE_ID'], $sets );

		$section += [
			'SETTINGS' => $resolved['SETTINGS'],
			'SETS' => $resolved['SETS'],
			'CHANGES' => [],
			'WARNINGS' => [],
			'STATUS' => 'unchanged',
			'STATUS_TEXT' => '',
		];

		foreach ( $only as $setting )
		{
			if ( $setting === 'DAYS' )
			{
				if ( count( $section['SCHOOL_PERIODS'] ) !== 1 )
				{
					if ( count( $section['SCHOOL_PERIODS'] ) > 1 )
					{
						$section['WARNINGS'][] = _( 'Meets in several periods: its meeting days are left as they are.' );
					}

					continue;
				}

				$current = reset( $section['SCHOOL_PERIODS'] );
			}
			else
			{
				$current = (string) $section[ $setting ];
			}

			$old = CourseExpressSettingNormalize( $setting, $current );

			$new = CourseExpressSettingNormalize( $setting, $resolved['SETTINGS'][ $setting ] );

			if ( $old !== $new )
			{
				$section['CHANGES'][ $setting ] = [ $old, $new ];
			}
		}

		if ( ! $section['CHANGES'] )
		{
			$by_status['unchanged'][ $cp_id ] = $section;

			continue;
		}

		// Only the settings being reapplied can stop the update.
		$problems = CourseExpressSettingsProblems(
			$resolved['SETTINGS'],
			array_keys( $section['CHANGES'] )
		);

		if ( $problems )
		{
			$section['STATUS'] = 'error';

			$section['STATUS_TEXT'] = implode( ' ', $problems );

			$by_status['error'][ $cp_id ] = $section;

			continue;
		}

		if ( isset( $section['CHANGES']['TOTAL_SEATS'] )
			&& $section['CHANGES']['TOTAL_SEATS'][1] !== ''
			&& (int) $section['CHANGES']['TOTAL_SEATS'][1] < (int) $section['FILLED_SEATS'] )
		{
			$section['WARNINGS'][] = sprintf(
				_( 'Fewer seats than the %d students already in the section.' ),
				(int) $section['FILLED_SEATS']
			);
		}

		$section['STATUS'] = 'update';

		$by_status['update'][ $cp_id ] = $section;
	}

	// Note: "+" keeps the course period ID keys, array_merge() would renumber them.
	return $by_status['error'] + $by_status['update'] + $by_status['unchanged'];
}

/**
 * Write the changed settings onto one section
 *
 * Fires no Scheduling/Courses.php hook, like the rest of the module.
 *
 * @uses Course_Express/Reapply.php|update_course_period action hook
 *
 * @param  array $section Analyzed section, of `update` status.
 *
 * @return bool True if updated.
 */
function CourseExpressReapplySection( $section )
{
	if ( $section['STATUS'] !== 'update'
		|| ! $section['CHANGES'] )
	{
		return false;
	}

	$cp_id = (int) $section['COURSE_PERIOD_ID'];

	$columns = [];

	foreach ( $section['CHANGES'] as $setting => $change )
	{
		if ( $setting !== 'DAYS' )
		{
			$columns[ $setting ] = DBEscapeString( $change[1] );
		}
	}

	if ( $columns )
	{
		DBUpdate(
			'course_periods',
			$columns,
			[
				'COURSE_PERIOD_ID' => $cp_id,
				'SYEAR' => UserSyear(),
				'SCHOOL_ID' => UserSchool(),
			]
		);
	}

	if ( isset( $section['CHANGES']['DAYS'] )
		&& count( $section['SCHOOL_PERIODS'] ) === 1
		&& $section['CHANGES']['DAYS'][1] !== '' )
	{
		DBUpdate(
			'course_period_school_periods',
			[ 'DAYS' => DBEscapeString( $section['CHANGES']['DAYS'][1] ) ],
			[
				'COURSE_PERIOD_SCHOOL_PERIODS_ID' => (int) key( $section['SCHOOL_PERIODS'] ),
				'COURSE_PERIOD_ID' => $cp_id,
			]
		);

		// The section name holds its meeting days.
		CourseExpressSectionTitlesRecompute( 0, $cp_id );
	}

	$_REQUEST['course_id'] = $section['COURSE_ID'];

	$_REQUEST['course_period_id'] = $cp_id;

	// Hook.
	do_action( 'Course_Express/Reapply.php|update_course_period' );

	return true;
}

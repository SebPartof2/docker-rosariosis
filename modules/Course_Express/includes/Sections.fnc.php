<?php
/**
 * Course Express: Sections functions
 *
 * Default Course Period (section) settings, override sets, and the creation
 * of the sections themselves.
 *
 * The settings a section gets are resolved in this order:
 * 1. the defaults, set in Course Express > Section Defaults;
 * 2. the override sets the course belongs to, in sort order;
 * 3. the values derived from the imported row (short name, teacher, period,
 *    marking period) and from the course (credits).
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/CourseExpress.fnc.php';

/**
 * Program name used in the `program_config` table
 */
if ( ! defined( 'COURSE_EXPRESS_PROGRAM' ) )
{
	define( 'COURSE_EXPRESS_PROGRAM', 'course_express' );
}

/**
 * Meeting days, in the order they are stored in the DAYS columns
 */
if ( ! defined( 'COURSE_EXPRESS_DAYS' ) )
{
	define( 'COURSE_EXPRESS_DAYS', 'MTWHFSU' );
}

/**
 * The section settings the user can set
 *
 * Every other Course Period setting is either fixed (Room is always empty and
 * Gender Restriction always None) or derived from the imported row.
 *
 * Each setting:
 * - `TITLE`: translated setting name.
 * - `TYPE`: number|days|attendance|select|checkbox.
 * - `OPTIONS`: `[ value => label ]` for the select type.
 * - `NA`: label of the empty option of a select, false if the setting is required.
 *
 * @return array Associative `[ setting => definition ]`, in display order.
 */
function CourseExpressSettings()
{
	static $settings = null;

	if ( $settings !== null )
	{
		return $settings;
	}

	$calendars_RET = DBGet( "SELECT CALENDAR_ID,TITLE
		FROM attendance_calendars
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY DEFAULT_CALENDAR IS NULL,DEFAULT_CALENDAR ASC,TITLE" );

	$calendars = [];

	foreach ( (array) $calendars_RET as $calendar )
	{
		$calendars[ $calendar['CALENDAR_ID'] ] = ParseMLField( $calendar['TITLE'] );
	}

	$scales_RET = DBGet( "SELECT ID,TITLE
		FROM report_card_grade_scales
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

	$scales = [];

	foreach ( (array) $scales_RET as $scale )
	{
		$scales[ $scale['ID'] ] = ParseMLField( $scale['TITLE'] );
	}

	$settings = [
		'TOTAL_SEATS' => [
			'TITLE' => _( 'Seats' ),
			'TYPE' => 'number',
			'OPTIONS' => [],
			'NA' => '',
		],
		'DAYS' => [
			'TITLE' => _( 'Meeting Days' ),
			'TYPE' => 'days',
			'OPTIONS' => [],
			'NA' => '',
		],
		'DOES_ATTENDANCE' => [
			'TITLE' => _( 'Takes Attendance' ),
			'TYPE' => 'attendance',
			'OPTIONS' => CourseExpressAttendanceCategories(),
			'NA' => '',
		],
		'CALENDAR_ID' => [
			'TITLE' => _( 'Calendar' ),
			'TYPE' => 'select',
			'OPTIONS' => $calendars,
			// A Course Period always belongs to a calendar.
			'NA' => false,
		],
		'GRADE_SCALE_ID' => [
			'TITLE' => _( 'Grading Scale' ),
			'TYPE' => 'select',
			'OPTIONS' => $scales,
			'NA' => _( 'Not Graded' ),
		],
		'DOES_CLASS_RANK' => [
			'TITLE' => _( 'Affects Class Rank' ),
			'TYPE' => 'checkbox',
			'OPTIONS' => [],
			'NA' => '',
		],
		'DOES_HONOR_ROLL' => [
			'TITLE' => _( 'Affects Honor Roll' ),
			'TYPE' => 'checkbox',
			'OPTIONS' => [],
			'NA' => '',
		],
	];

	return $settings;
}

/**
 * Attendance code categories, plus the general "Attendance" one (ID 0)
 *
 * @return array Associative `[ category id => title ]`.
 */
function CourseExpressAttendanceCategories()
{
	$categories_RET = DBGet( "SELECT ID,TITLE
		FROM attendance_code_categories
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

	$categories = [ '0' => _( 'Attendance' ) ];

	foreach ( (array) $categories_RET as $category )
	{
		$categories[ $category['ID'] ] = ParseMLField( $category['TITLE'] );
	}

	return $categories;
}

/**
 * Get the default section settings of the current school year & school
 *
 * @return array Associative `[ setting => value ]`. Empty values if never set.
 */
function CourseExpressDefaultsGet()
{
	$defaults = [];

	foreach ( CourseExpressSettings() as $setting => $definition )
	{
		$defaults[ $setting ] = (string) ProgramConfig(
			COURSE_EXPRESS_PROGRAM,
			'SECTION_' . $setting
		);
	}

	return $defaults;
}

/**
 * Problems which would make a section unusable
 *
 * The defaults are kept in `program_config`, which the rollover copies to the
 * next school year as is: the calendar, grading scale & attendance category
 * IDs then belong to the previous year. Sections without meeting days never
 * meet, and Scheduling > Courses would drop their period on the next save.
 *
 * @param  array $values Settings `[ setting => value ]`.
 *
 * @return array Translated problems, empty if none.
 */
function CourseExpressSettingsProblems( $values )
{
	$settings = CourseExpressSettings();

	$problems = [];

	if ( (string) issetVal( $values['DAYS'], '' ) === '' )
	{
		$problems[] = _( 'Meeting Days' ) . ': ' . _( 'Please fill in the required fields' );
	}

	foreach ( [ 'CALENDAR_ID', 'GRADE_SCALE_ID' ] as $setting )
	{
		$value = (string) issetVal( $values[ $setting ], '' );

		if ( ( $value !== '' || $settings[ $setting ]['NA'] === false )
			&& ! isset( $settings[ $setting ]['OPTIONS'][ $value ] ) )
		{
			$problems[] = sprintf(
				_( '%s: not found in this school year. Please choose it again.' ),
				$settings[ $setting ]['TITLE']
			);
		}
	}

	$categories = array_filter( explode( ',', (string) issetVal( $values['DOES_ATTENDANCE'], '' ) ), 'strlen' );

	foreach ( $categories as $category_id )
	{
		if ( ! isset( $settings['DOES_ATTENDANCE']['OPTIONS'][ $category_id ] ) )
		{
			$problems[] = sprintf(
				_( '%s: not found in this school year. Please choose it again.' ),
				$settings['DOES_ATTENDANCE']['TITLE']
			);

			break;
		}
	}

	return $problems;
}

/**
 * Save the default section settings
 *
 * @param  array $values Associative `[ setting => value ]`.
 *
 * @return void
 */
function CourseExpressDefaultsSet( $values )
{
	foreach ( CourseExpressSettings() as $setting => $definition )
	{
		ProgramConfig(
			COURSE_EXPRESS_PROGRAM,
			'SECTION_' . $setting,
			DBEscapeString( (string) issetVal( $values[ $setting ], '' ) )
		);
	}

	// Remember the defaults were set at least once: sections cannot be imported before.
	ProgramConfig( COURSE_EXPRESS_PROGRAM, 'SECTION_DEFAULTS_SET', 'Y' );
}

/**
 * Were the default section settings ever saved?
 *
 * @return bool True if the user saved the Section Defaults page.
 */
function CourseExpressDefaultsAreSet()
{
	return ProgramConfig( COURSE_EXPRESS_PROGRAM, 'SECTION_DEFAULTS_SET' ) === 'Y';
}

/**
 * Get the override sets of the current school year & school
 *
 * @param  int $set_id Only this set (optional).
 *
 * @return array Associative `[ set id => set ]`, each set holding its settings,
 *               its `OVERRIDDEN` settings list and its `COURSES` array.
 */
function CourseExpressOverrideSetsGet( $set_id = 0 )
{
	$sets_RET = DBGet( "SELECT ID,TITLE,SORT_ORDER,OVERRIDDEN,
		TOTAL_SEATS,DAYS,DOES_ATTENDANCE,CALENDAR_ID,GRADE_SCALE_ID,
		DOES_CLASS_RANK,DOES_HONOR_ROLL
		FROM course_express_override_sets
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" .
		( $set_id ? " AND ID='" . (int) $set_id . "'" : '' ) . "
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

	if ( ! $sets_RET )
	{
		return [];
	}

	$sets = [];

	foreach ( (array) $sets_RET as $set )
	{
		$set['OVERRIDDEN'] = array_values( array_filter( explode( ',', (string) $set['OVERRIDDEN'] ) ) );

		$set['COURSES'] = [];

		$sets[ $set['ID'] ] = $set;
	}

	$courses_RET = DBGet( "SELECT sc.SET_ID,sc.COURSE_ID,c.TITLE,c.SHORT_NAME
		FROM course_express_override_courses sc,courses c
		WHERE sc.COURSE_ID=c.COURSE_ID
		AND sc.SET_ID IN(" . implode( ',', array_map( 'intval', array_keys( $sets ) ) ) . ")
		AND c.SYEAR='" . UserSyear() . "'
		AND c.SCHOOL_ID='" . UserSchool() . "'
		ORDER BY c.TITLE" );

	foreach ( (array) $courses_RET as $course )
	{
		$sets[ $course['SET_ID'] ]['COURSES'][ $course['COURSE_ID'] ] = [
			'TITLE' => ParseMLField( $course['TITLE'] ),
			'SHORT_NAME' => $course['SHORT_NAME'],
		];
	}

	return $sets;
}

/**
 * Resolve the settings a section of a course will get
 *
 * The defaults, with the override sets the course belongs to applied on top,
 * in sort order: the last set wins on a setting overridden twice.
 *
 * @param  string $course_id Course ID.
 * @param  array  $sets      Override sets, from CourseExpressOverrideSetsGet() (optional).
 *
 * @return array `[ 'SETTINGS' => [ setting => value ], 'SETS' => [ set title, ... ] ]`.
 */
function CourseExpressCourseSettings( $course_id, $sets = null )
{
	static $defaults = null;

	if ( $defaults === null )
	{
		$defaults = CourseExpressDefaultsGet();
	}

	if ( $sets === null )
	{
		$sets = CourseExpressOverrideSetsGet();
	}

	$settings = $defaults;

	$applied = [];

	foreach ( $sets as $set )
	{
		if ( ! isset( $set['COURSES'][ $course_id ] ) )
		{
			continue;
		}

		foreach ( $set['OVERRIDDEN'] as $setting )
		{
			if ( ! isset( $settings[ $setting ] ) )
			{
				continue;
			}

			$settings[ $setting ] = (string) issetVal( $set[ $setting ], '' );
		}

		if ( $set['OVERRIDDEN'] )
		{
			$applied[] = $set['TITLE'];
		}
	}

	return [ 'SETTINGS' => $settings, 'SETS' => $applied ];
}

/**
 * Display a setting value
 *
 * @param  string $setting Setting name.
 * @param  string $value   Stored value.
 *
 * @return string HTML escaped value, '-' if empty.
 */
function CourseExpressSettingDisplay( $setting, $value )
{
	$settings = CourseExpressSettings();

	$value = (string) $value;

	if ( ! isset( $settings[ $setting ] ) )
	{
		return CourseExpressHTML( $value );
	}

	$definition = $settings[ $setting ];

	if ( $definition['TYPE'] === 'checkbox' )
	{
		return $value && $value !== 'N' ? _( 'Yes' ) : _( 'No' );
	}

	if ( $definition['TYPE'] === 'days' )
	{
		return $value === '' ? '-' : CourseExpressHTML( CourseExpressDaysDisplay( $value ) );
	}

	if ( $definition['TYPE'] === 'attendance' )
	{
		$titles = [];

		foreach ( explode( ',', $value ) as $category_id )
		{
			if ( $category_id === '' )
			{
				// DOES_ATTENDANCE is comma wrapped. Note: do not use array_filter(),
				// it would drop the general "Attendance" category, whose ID is '0'.
				continue;
			}

			$titles[] = issetVal( $definition['OPTIONS'][ $category_id ], $category_id );
		}

		return $titles ? CourseExpressHTML( implode( ', ', $titles ) ) : _( 'No' );
	}

	if ( $definition['TYPE'] === 'select' )
	{
		if ( $value === '' )
		{
			return $definition['NA'] === false ? '-' : CourseExpressHTML( $definition['NA'] );
		}

		return CourseExpressHTML( issetVal( $definition['OPTIONS'][ $value ], $value ) );
	}

	return $value === '' ? '-' : CourseExpressHTML( $value );
}

/**
 * Display a DAYS value, for example 'MWF'
 *
 * @param  string $days DAYS column value.
 *
 * @return string Translated, comma separated day names.
 */
function CourseExpressDaysDisplay( $days )
{
	$names = [
		'M' => _( 'Monday' ),
		'T' => _( 'Tuesday' ),
		'W' => _( 'Wednesday' ),
		'H' => _( 'Thursday' ),
		'F' => _( 'Friday' ),
		'S' => _( 'Saturday' ),
		'U' => _( 'Sunday' ),
	];

	$display = [];

	for ( $i = 0; $i < mb_strlen( (string) $days ); $i++ )
	{
		$day = mb_substr( $days, $i, 1 );

		if ( isset( $names[ $day ] ) )
		{
			$display[] = mb_substr( $names[ $day ], 0, 3 ) . '.';
		}
	}

	return implode( ' ', $display );
}

/**
 * Read a settings form into the values to store
 *
 * Reads `$_REQUEST[ $array ]`, which holds one entry per setting.
 *
 * @param  string $array Name of the request array holding the settings.
 *
 * @return array Associative `[ setting => value ]`.
 */
function CourseExpressSettingsRequest( $array )
{
	$request = (array) issetVal( $_REQUEST[ $array ], [] );

	$values = [];

	foreach ( CourseExpressSettings() as $setting => $definition )
	{
		$value = issetVal( $request[ $setting ], '' );

		switch ( $definition['TYPE'] )
		{
			case 'number':

				$values[ $setting ] = is_numeric( $value ) && $value >= 0 ?
					(string) (int) $value :
					'';

				break;

			case 'days':

				$days = '';

				// Keep the days in the documented order.
				for ( $i = 0; $i < mb_strlen( COURSE_EXPRESS_DAYS ); $i++ )
				{
					$day = mb_substr( COURSE_EXPRESS_DAYS, $i, 1 );

					if ( ! empty( $value[ $day ] ) )
					{
						$days .= $day;
					}
				}

				$values[ $setting ] = $days;

				break;

			case 'attendance':

				$categories = '';

				foreach ( array_keys( $definition['OPTIONS'] ) as $category_id )
				{
					if ( ! empty( $value[ $category_id ] ) )
					{
						$categories .= ',' . $category_id;
					}
				}

				$values[ $setting ] = $categories ? $categories . ',' : '';

				break;

			case 'select':

				$values[ $setting ] = isset( $definition['OPTIONS'][ (string) $value ] ) ?
					(string) $value :
					'';

				break;

			case 'checkbox':
			default:

				$values[ $setting ] = $value ? 'Y' : '';

				break;
		}
	}

	return $values;
}

/**
 * Settings form inputs
 *
 * @param  string $array    Name of the request array holding the settings.
 * @param  array  $values   Current values `[ setting => value ]`.
 * @param  array  $settings Only these settings (optional). Defaults to all.
 *
 * @return array Associative `[ setting => input HTML ]`.
 */
function CourseExpressSettingsInputs( $array, $values, $settings = [] )
{
	$inputs = [];

	foreach ( CourseExpressSettings() as $setting => $definition )
	{
		if ( $settings
			&& ! in_array( $setting, $settings, true ) )
		{
			continue;
		}

		$value = (string) issetVal( $values[ $setting ], '' );

		$name = $array . '[' . $setting . ']';

		switch ( $definition['TYPE'] )
		{
			case 'number':

				$inputs[ $setting ] = TextInput(
					$value,
					$name,
					$definition['TITLE'],
					' type="number" step="1" min="0" max="9999"',
					// No click-to-edit div: RosarioSIS hides that input inside a
					// <template>, which is never submitted. A settings form has to
					// post every field, even the ones the user did not touch.
					false
				);

				break;

			case 'days':

				$day_names = [
					'M' => _( 'Monday' ),
					'T' => _( 'Tuesday' ),
					'W' => _( 'Wednesday' ),
					'H' => _( 'Thursday' ),
					'F' => _( 'Friday' ),
					'S' => _( 'Saturday' ),
					'U' => _( 'Sunday' ),
				];

				$days_html = '';

				foreach ( $day_names as $day => $day_name )
				{
					$days_html .= '<label class="checkbox-label">' .
						'<input type="checkbox" name="' . AttrEscape( $name . '[' . $day . ']' ) .
						'" value="Y"' . ( mb_strpos( $value, $day ) !== false ? ' checked' : '' ) .
						'>&nbsp;' . mb_substr( $day_name, 0, 3 ) . '.</label> ';
				}

				$inputs[ $setting ] = $days_html .
					FormatInputTitle( $definition['TITLE'], '', false, '' );

				break;

			case 'attendance':

				$attendance_html = '';

				foreach ( $definition['OPTIONS'] as $category_id => $category_title )
				{
					$attendance_html .= '<label class="checkbox-label">' .
						'<input type="checkbox" name="' .
						AttrEscape( $name . '[' . $category_id . ']' ) . '" value="Y"' .
						( mb_strpos( $value, ',' . $category_id . ',' ) !== false ? ' checked' : '' ) .
						'>&nbsp;' . CourseExpressHTML( $category_title ) . '</label> ';
				}

				$inputs[ $setting ] = $attendance_html .
					FormatInputTitle( $definition['TITLE'], '', false, '' );

				break;

			case 'select':

				$inputs[ $setting ] = SelectInput(
					$value,
					$name,
					$definition['TITLE'],
					$definition['OPTIONS'],
					$definition['NA'],
					'',
					// No click-to-edit div, see above.
					false
				);

				break;

			case 'checkbox':
			default:

				$inputs[ $setting ] = '<label class="checkbox-label">' .
					'<input type="hidden" name="' . AttrEscape( $name ) . '" value="">' .
					'<input type="checkbox" name="' . AttrEscape( $name ) . '" value="Y"' .
					( $value && $value !== 'N' ? ' checked' : '' ) . '>&nbsp;' .
					CourseExpressHTML( $definition['TITLE'] ) . '</label>';

				break;
		}
	}

	return $inputs;
}

/**
 * CSV column header aliases for the sections file
 *
 * @return array Associative `[ FIELD => [ alias, ... ] ]`
 */
function CourseExpressSectionAliases()
{
	return [
		'COURSE' => [
			'course number',
			'course no',
			'course code',
			'course short name',
			'course',
			'number',
			'code',
		],
		'PERIOD' => [
			'period number',
			'period no',
			'school period',
			'period',
		],
		'MARKING_PERIOD' => [
			'marking period',
			'term',
			'semester',
			'quarter',
			'mp',
			'length',
		],
		'TEACHER' => [
			'teacher id',
			'teacher no',
			'teacher number',
			'staff id',
			'teacher',
		],
	];
}

/**
 * Parse the sections CSV
 *
 * Without a recognized header row, the columns are read in this order:
 * Course Number, Period, Marking Period, Teacher ID.
 *
 * @param  string $csv_text CSV file contents.
 * @param  array  $messages Messages, passed by reference.
 *
 * @return array Rows: `[ 'LINE', 'COURSE', 'PERIOD', 'MARKING_PERIOD', 'TEACHER' ]`.
 */
function CourseExpressSectionsCSVParse( $csv_text, &$messages )
{
	$rows = CourseExpressGenericCSVParse(
		$csv_text,
		CourseExpressSectionAliases(),
		[ _( 'Course Number' ), _( 'Period' ), _( 'Marking Period' ), _( 'Teacher' ) ],
		COURSE_EXPRESS_MAX_ROWS,
		$messages
	);

	if ( ! $rows
		&& ! $messages )
	{
		// The file was read, but it holds no section.
		$messages[] = _( 'No sections were found in the file.' );
	}

	return $rows;
}

/**
 * Look up the Courses of the current school year & school by course number
 *
 * @return array Associative `[ normalized course number => [ course, ... ] ]`.
 */
function CourseExpressCoursesByNumber()
{
	$courses_RET = DBGet( "SELECT COURSE_ID,TITLE,SHORT_NAME,CREDIT_HOURS
		FROM courses
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$courses = [];

	foreach ( (array) $courses_RET as $course )
	{
		$key = CourseExpressTitleNormalize( $course['SHORT_NAME'] );

		if ( $key === '' )
		{
			continue;
		}

		$course['TITLE'] = ParseMLField( $course['TITLE'] );

		$courses[ $key ][] = $course;
	}

	return $courses;
}

/**
 * Look up the School Periods of the current school year & school
 *
 * @return array Associative `[ normalized name => period id ]`, plus the IDs themselves.
 */
function CourseExpressPeriodsLookup()
{
	$periods_RET = DBGet( "SELECT PERIOD_ID,TITLE,SHORT_NAME
		FROM school_periods
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER" );

	return CourseExpressLookupBuild( $periods_RET, 'PERIOD_ID', [ 'TITLE', 'SHORT_NAME' ] );
}

/**
 * Look up the Marking Periods a Course Period can belong to
 *
 * Full Year and its children only, like Scheduling > Courses does.
 *
 * @return array Associative `[ normalized name => marking period id ]`.
 */
function CourseExpressMarkingPeriodsLookup()
{
	$fy_and_children_mp = GetFullYearMP() ? "'" . GetFullYearMP() . "'" : "'0'";

	if ( GetChildrenMP( 'FY' ) )
	{
		$fy_and_children_mp .= ',' . GetChildrenMP( 'FY' );
	}

	$mp_RET = DBGet( "SELECT MARKING_PERIOD_ID,TITLE,SHORT_NAME
		FROM school_marking_periods
		WHERE (MP='FY' OR MP='SEM' OR MP='QTR')
		AND MARKING_PERIOD_ID IN(" . $fy_and_children_mp . ")
		AND SCHOOL_ID='" . UserSchool() . "'
		AND SYEAR='" . UserSyear() . "'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,START_DATE" );

	return CourseExpressLookupBuild( $mp_RET, 'MARKING_PERIOD_ID', [ 'TITLE', 'SHORT_NAME' ] );
}

/**
 * Look up the Teachers of the current school year & school
 *
 * @return array Associative `[ normalized name => staff id ]`.
 */
function CourseExpressTeachersLookup()
{
	$teachers_RET = DBGet( "SELECT STAFF_ID," . DisplayNameSQL() . " AS FULL_NAME,USERNAME
		FROM staff
		WHERE (SCHOOLS IS NULL OR position('," . UserSchool() . ",' IN SCHOOLS)>0)
		AND SYEAR='" . UserSyear() . "'
		AND PROFILE='teacher'
		ORDER BY STAFF_ID" );

	return CourseExpressLookupBuild( $teachers_RET, 'STAFF_ID', [ 'FULL_NAME', 'USERNAME' ] );
}

/**
 * Build a `[ normalized name => id ]` lookup, the ID itself included
 *
 * @param  array  $result     DBGet() result.
 * @param  string $id_column  Column holding the ID.
 * @param  array  $name_columns Columns holding a name the CSV can use.
 *
 * Names win over IDs: "1" is the period whose short name is "01", not the
 * period with ID 1 ("Full Day" in a stock install). An ID is only accepted
 * when no name matches it. A name shared by records with different IDs
 * (two teachers with the same name) is ambiguous and never resolved.
 *
 * @return array Associative `[ 'MAP' => [ key => id ], 'AMBIGUOUS' => [ key => true ], 'LABELS' => [ id => label ] ]`.
 */
function CourseExpressLookupBuild( $result, $id_column, $name_columns )
{
	$map = $ambiguous = $labels = [];

	foreach ( (array) $result as $row )
	{
		$id = (string) $row[ $id_column ];

		$labels[ $id ] = ParseMLField( (string) $row[ $name_columns[0] ] );

		foreach ( $name_columns as $column )
		{
			$key = CourseExpressLookupKey( ParseMLField( (string) issetVal( $row[ $column ], '' ) ) );

			if ( $key === '' )
			{
				continue;
			}

			if ( isset( $map[ $key ] )
				&& $map[ $key ] !== $id )
			{
				$ambiguous[ $key ] = true;
			}

			$map[ $key ] = $id;
		}
	}

	$map = array_diff_key( $map, $ambiguous );

	// Then the IDs, unless a name already holds the key.
	foreach ( array_keys( $labels ) as $id )
	{
		$key = CourseExpressLookupKey( $id );

		if ( ! isset( $map[ $key ] )
			&& ! isset( $ambiguous[ $key ] ) )
		{
			$map[ $key ] = (string) $id;
		}
	}

	return [ 'MAP' => $map, 'AMBIGUOUS' => $ambiguous, 'LABELS' => $labels ];
}

/**
 * Lookup key of a name or ID
 *
 * Like CourseExpressTitleNormalize(), and a number loses its leading zeros
 * so "1", "01" & "001" are the same key.
 *
 * @param  string $name Name or ID.
 *
 * @return string Key.
 */
function CourseExpressLookupKey( $name )
{
	$key = CourseExpressTitleNormalize( $name );

	if ( $key !== ''
		&& ctype_digit( $key ) )
	{
		$key = ltrim( $key, '0' );

		$key = $key === '' ? '0' : $key;
	}

	return $key;
}

/**
 * Next free "Section N" short names of a Course
 *
 * Existing sections are respected: the numbers they already use are skipped.
 *
 * @param  string $course_id Course ID.
 * @param  int    $count     How many short names are needed.
 * @param  array  $taken     Short names already assigned in this import (optional).
 *
 * @return array Short names, for example `[ 'Section 3', 'Section 4' ]`.
 */
function CourseExpressSectionShortNames( $course_id, $count, $taken = [] )
{
	$existing_RET = DBGet( "SELECT SHORT_NAME
		FROM course_periods
		WHERE COURSE_ID='" . (int) $course_id . "'
		AND SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$used = [];

	foreach ( (array) $existing_RET as $existing )
	{
		$used[ CourseExpressTitleNormalize( $existing['SHORT_NAME'] ) ] = true;
	}

	foreach ( (array) $taken as $taken_name )
	{
		$used[ CourseExpressTitleNormalize( $taken_name ) ] = true;
	}

	$short_names = [];

	$number = 1;

	while ( count( $short_names ) < $count )
	{
		$short_name = sprintf( _( 'Section %d' ), $number );

		if ( ! isset( $used[ CourseExpressTitleNormalize( $short_name ) ] ) )
		{
			$short_names[] = $short_name;

			$used[ CourseExpressTitleNormalize( $short_name ) ] = true;
		}

		$number++;

		if ( $number > 999 )
		{
			// Safety net: never loop forever.
			break;
		}
	}

	return $short_names;
}

/**
 * Create one Course Period (section)
 *
 * Room is always left empty and Gender Restriction is always None, as
 * documented. The title is generated the way Scheduling > Courses does.
 *
 * @uses Course_Express/Sections.php|create_course_period action hook
 *
 * @param  array $row Analyzed row, of `new` status.
 *
 * @return string|false New Course Period ID, or false on error.
 */
function CourseExpressSectionCreate( $row )
{
	if ( ! (int) issetVal( $row['COURSE_ID'], 0 )
		|| ! (int) issetVal( $row['TEACHER_ID'], 0 )
		|| ! (int) issetVal( $row['PERIOD_ID'], 0 )
		|| ! (int) issetVal( $row['MARKING_PERIOD_ID'], 0 )
		|| trim( (string) issetVal( $row['SHORT_NAME'], '' ) ) === '' )
	{
		// course_periods requires a course, a teacher, a marking period & a short name.
		return false;
	}

	$settings = $row['SETTINGS'];

	$columns = [
		'MARKING_PERIOD_ID' => (int) $row['MARKING_PERIOD_ID'],
		'TEACHER_ID' => (int) $row['TEACHER_ID'],
		'SHORT_NAME' => DBEscapeString( $row['SHORT_NAME'] ),
	];

	$mp = GetMP( $columns['MARKING_PERIOD_ID'], 'MP' );

	$columns['MP'] = in_array( $mp, [ 'FY', 'SEM' ], true ) ? $mp : 'QTR';

	$insert_columns = [
		'SYEAR' => UserSyear(),
		'SCHOOL_ID' => UserSchool(),
		'COURSE_ID' => (int) $row['COURSE_ID'],
		// Set below, once the school period is stored: the title names it.
		'TITLE' => DBEscapeString( $row['SHORT_NAME'] ),
		'FILLED_SEATS' => '0',
		// Always fixed, whatever the defaults & the overrides.
		'GENDER_RESTRICTION' => 'N',
		// Derived from the course.
		'CREDITS' => $row['CREDITS'],
		'TOTAL_SEATS' => $settings['TOTAL_SEATS'],
		'DOES_ATTENDANCE' => DBEscapeString( $settings['DOES_ATTENDANCE'] ),
		'CALENDAR_ID' => $settings['CALENDAR_ID'],
		'GRADE_SCALE_ID' => $settings['GRADE_SCALE_ID'],
		'DOES_CLASS_RANK' => $settings['DOES_CLASS_RANK'],
		'DOES_HONOR_ROLL' => $settings['DOES_HONOR_ROLL'],
	];

	$course_period_id = DBInsert( 'course_periods', $insert_columns + $columns, 'id' );

	if ( ! $course_period_id )
	{
		return false;
	}

	// A section is its own parent, like Scheduling > Courses does.
	DBUpdate(
		'course_periods',
		[ 'PARENT_ID' => (int) $course_period_id ],
		[
			'COURSE_PERIOD_ID' => (int) $course_period_id,
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		]
	);

	// The school period & the meeting days.
	$school_period_columns = [
		'PERIOD_ID' => (int) $row['PERIOD_ID'],
		'DAYS' => $settings['DAYS'],
	];

	DBInsert(
		'course_period_school_periods',
		[ 'COURSE_PERIOD_ID' => (int) $course_period_id ] + $school_period_columns
	);

	// The title names the school period, so build it now the period is stored.
	DBUpdate(
		'course_periods',
		[ 'TITLE' => DBEscapeString( CourseExpressSectionTitleGenerate( $course_period_id ) ) ],
		[
			'COURSE_PERIOD_ID' => (int) $course_period_id,
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		]
	);

	$_REQUEST['course_id'] = $row['COURSE_ID'];

	$_REQUEST['course_period_id'] = $course_period_id;

	// Hook.
	do_action( 'Course_Express/Sections.php|create_course_period' );

	return $course_period_id;
}

/**
 * Build the full title of a section, from what is stored
 *
 * Same shape as the titles Scheduling > Courses makes:
 * `[school period][ days] - ` for each school period the section meets in,
 * then `[marking period short name - ]` when it is not the full year, then the
 * section short name and the teacher.
 *
 * Built from the database rather than with CoursePeriodSchoolPeriodsTitlePartGenerate(),
 * which asks for the *other* school periods of the section and so can only be
 * called before the current one is stored.
 *
 * @since 1.1
 *
 * @param  string $course_period_id Course Period ID.
 *
 * @return string Title, empty string if the section was not found.
 */
function CourseExpressSectionTitleGenerate( $course_period_id )
{
	$section_RET = DBGet( "SELECT SHORT_NAME,MARKING_PERIOD_ID,TEACHER_ID
		FROM course_periods
		WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'
		AND SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	if ( ! $section_RET )
	{
		return '';
	}

	$section = $section_RET[1];

	$periods_RET = DBGet( "SELECT sp.TITLE,cpsp.DAYS
		FROM course_period_school_periods cpsp,school_periods sp
		WHERE cpsp.PERIOD_ID=sp.PERIOD_ID
		AND cpsp.COURSE_PERIOD_ID='" . (int) $course_period_id . "'
		AND sp.SYEAR='" . UserSyear() . "'
		AND sp.SCHOOL_ID='" . UserSchool() . "'
		ORDER BY sp.SORT_ORDER IS NULL,sp.SORT_ORDER,sp.TITLE" );

	$title = '';

	foreach ( (array) $periods_RET as $period )
	{
		$title .= ParseMLField( $period['TITLE'] ) .
			CourseExpressSectionTitleDays( (string) issetVal( $period['DAYS'], '' ) ) . ' - ';
	}

	if ( GetMP( $section['MARKING_PERIOD_ID'], 'MP' ) !== 'FY' )
	{
		$title .= GetMP( $section['MARKING_PERIOD_ID'], 'SHORT_NAME' ) . ' - ';
	}

	return $title . $section['SHORT_NAME'] . ' - ' . GetTeacher( $section['TEACHER_ID'] );
}

/**
 * The days part of a section title
 *
 * A section meeting every day names no day, like Scheduling > Courses.
 *
 * @since 1.1
 *
 * @param  string $days DAYS column value, for example 'MWF'.
 *
 * @return string Days part, with its leading space. Empty when no day is named.
 */
function CourseExpressSectionTitleDays( $days )
{
	$names = [
		'U' => _( 'Sunday' ),
		'M' => _( 'Monday' ),
		'T' => _( 'Tuesday' ),
		'W' => _( 'Wednesday' ),
		'H' => _( 'Thursday' ),
		'F' => _( 'Friday' ),
		'S' => _( 'Saturday' ),
	];

	$rotation = SchoolInfo( 'NUMBER_DAYS_ROTATION' );

	if ( $rotation !== null )
	{
		// Numbered days school.
		$names = [ 'U' => '7', 'M' => '1', 'T' => '2', 'W' => '3', 'H' => '4', 'F' => '5', 'S' => '6' ];
	}

	$count = mb_strlen( $days );

	if ( ! $count
		|| ( $rotation !== null && $count == $rotation )
		|| ( $rotation === null && $count >= 5 ) )
	{
		// Every day, or no day at all: name none of them.
		return '';
	}

	$part = ' ';

	for ( $i = 0; $i < $count; $i++ )
	{
		$day = mb_substr( $days, $i, 1 );

		if ( isset( $names[ $day ] ) )
		{
			$part .= mb_substr( $names[ $day ], 0, 3 ) . '.';
		}
	}

	return $part === ' ' ? '' : $part;
}

/**
 * Rebuild the titles of the sections of the current school year & school
 *
 * Use it after the school periods, the marking periods or the teachers were
 * renamed -- or to repair a title built wrong.
 *
 * @since 1.1
 *
 * @param  string $course_id Only the sections of this course (optional).
 *
 * @return array `[ 'CHECKED' => int, 'CHANGED' => int ]`.
 */
function CourseExpressSectionTitlesRecompute( $course_id = 0 )
{
	$sections_RET = DBGet( "SELECT COURSE_PERIOD_ID,TITLE
		FROM course_periods
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" .
		( $course_id ? " AND COURSE_ID='" . (int) $course_id . "'" : '' ) );

	$checked = $changed = 0;

	foreach ( (array) $sections_RET as $section )
	{
		$checked++;

		$title = CourseExpressSectionTitleGenerate( $section['COURSE_PERIOD_ID'] );

		if ( $title === ''
			|| $title === (string) $section['TITLE'] )
		{
			continue;
		}

		DBUpdate(
			'course_periods',
			[ 'TITLE' => DBEscapeString( $title ) ],
			[
				'COURSE_PERIOD_ID' => (int) $section['COURSE_PERIOD_ID'],
				'SYEAR' => UserSyear(),
				'SCHOOL_ID' => UserSchool(),
			]
		);

		$changed++;
	}

	return [ 'CHECKED' => $checked, 'CHANGED' => $changed ];
}

/**
 * Analyze the parsed section rows
 *
 * Sets, for each row: `COURSE_ID`, `COURSE_TITLE`, `CREDITS`, `PERIOD_ID`,
 * `MARKING_PERIOD_ID`, `TEACHER_ID`, `SHORT_NAME`, `SETTINGS`, `SETS`,
 * `STATUS` (error|duplicate|exists|new) and `STATUS_TEXT`.
 *
 * @param  array $rows Parsed rows.
 *
 * @return array Analyzed rows.
 */
function CourseExpressSectionsAnalyze( $rows )
{
	$courses = CourseExpressCoursesByNumber();

	$periods = CourseExpressPeriodsLookup();

	$marking_periods = CourseExpressMarkingPeriodsLookup();

	$teachers = CourseExpressTeachersLookup();

	$sets = CourseExpressOverrideSetsGet();

	// Short names assigned so far in this import, per course.
	$assigned = [];

	// Sections seen so far, to catch a line repeated in the file.
	$seen = [];

	// Existing sections, to catch a section which already exists.
	$existing = CourseExpressSectionsExisting();

	foreach ( $rows as $i => $row )
	{
		$rows[ $i ] += [
			'COURSE_ID' => '',
			'COURSE_TITLE' => '',
			'CREDITS' => '',
			'PERIOD_ID' => '',
			'MARKING_PERIOD_ID' => '',
			'TEACHER_ID' => '',
			'SHORT_NAME' => '',
			'SETTINGS' => [],
			'SETS' => [],
			'STATUS' => 'error',
			'STATUS_TEXT' => '',
		];

		$errors = [];

		// The course.
		$course_key = CourseExpressTitleNormalize( $row['COURSE'] );

		if ( $course_key === '' )
		{
			$errors[] = _( 'Course Number' ) . ': ' . _( 'Please fill in the required fields' );
		}
		elseif ( ! isset( $courses[ $course_key ] ) )
		{
			$errors[] = sprintf(
				_( 'No course with the number "%s".' ),
				CourseExpressHTML( $row['COURSE'] )
			);
		}
		elseif ( count( $courses[ $course_key ] ) > 1 )
		{
			$errors[] = sprintf(
				_( 'Several courses have the number "%s".' ),
				CourseExpressHTML( $row['COURSE'] )
			);
		}
		else
		{
			$course = $courses[ $course_key ][0];

			$rows[ $i ]['COURSE_ID'] = $course['COURSE_ID'];

			$rows[ $i ]['COURSE_TITLE'] = $course['TITLE'];

			$rows[ $i ]['CREDITS'] = (string) $course['CREDIT_HOURS'];
		}

		// The school period, the marking period & the teacher.
		$lookups = [
			'PERIOD' => [ $periods, 'PERIOD_ID', _( 'Period' ) ],
			'MARKING_PERIOD' => [ $marking_periods, 'MARKING_PERIOD_ID', _( 'Marking Period' ) ],
			'TEACHER' => [ $teachers, 'TEACHER_ID', _( 'Teacher' ) ],
		];

		foreach ( $lookups as $field => $lookup )
		{
			list( $data, $column, $title ) = $lookup;

			$key = CourseExpressLookupKey( $row[ $field ] );

			if ( $key === '' )
			{
				$errors[] = $title . ': ' . _( 'Please fill in the required fields' );

				continue;
			}

			if ( isset( $data['AMBIGUOUS'][ $key ] ) )
			{
				$errors[] = sprintf(
					_( '%s: "%s" matches more than one record. Use its ID instead.' ),
					$title,
					CourseExpressHTML( $row[ $field ] )
				);

				continue;
			}

			if ( ! isset( $data['MAP'][ $key ] ) )
			{
				$errors[] = sprintf(
					_( '%s: "%s" was not found.' ),
					$title,
					CourseExpressHTML( $row[ $field ] )
				);

				continue;
			}

			$rows[ $i ][ $column ] = $data['MAP'][ $key ];
		}

		if ( $errors )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = implode( ' ', $errors );

			continue;
		}

		$signature = implode( '-', [
			$rows[ $i ]['COURSE_ID'],
			$rows[ $i ]['PERIOD_ID'],
			$rows[ $i ]['MARKING_PERIOD_ID'],
			$rows[ $i ]['TEACHER_ID'],
		] );

		if ( isset( $seen[ $signature ] ) )
		{
			$rows[ $i ]['STATUS'] = 'duplicate';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'Line %d holds the same section.' ),
				$seen[ $signature ]
			);

			continue;
		}

		if ( isset( $existing[ $signature ] ) )
		{
			$rows[ $i ]['STATUS'] = 'exists';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'The course already has this section: %s.' ),
				CourseExpressHTML( $existing[ $signature ] )
			);

			continue;
		}

		$seen[ $signature ] = $row['LINE'];

		// The short name: "Section N", the existing sections respected.
		$course_id = $rows[ $i ]['COURSE_ID'];

		$assigned[ $course_id ] = issetVal( $assigned[ $course_id ], [] );

		$short_names = CourseExpressSectionShortNames( $course_id, 1, $assigned[ $course_id ] );

		if ( ! $short_names )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = _( 'No section number is available for this course.' );

			continue;
		}

		$rows[ $i ]['SHORT_NAME'] = $short_names[0];

		$assigned[ $course_id ][] = $short_names[0];

		// The settings: the defaults, with the override sets of the course applied.
		$resolved = CourseExpressCourseSettings( $course_id, $sets );

		$rows[ $i ]['SETTINGS'] = $resolved['SETTINGS'];

		$rows[ $i ]['SETS'] = $resolved['SETS'];

		$problems = CourseExpressSettingsProblems( $resolved['SETTINGS'] );

		if ( $problems )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = implode( ' ', array_map( 'CourseExpressHTML', $problems ) );

			// Give the short name back: this row creates no section.
			array_pop( $assigned[ $course_id ] );

			continue;
		}

		$rows[ $i ]['STATUS'] = 'new';

		$rows[ $i ]['STATUS_TEXT'] = '';
	}

	return $rows;
}

/**
 * Signatures of the existing sections of the current school year & school
 *
 * @return array Associative `[ course-period-mp-teacher => section short name ]`.
 */
function CourseExpressSectionsExisting()
{
	$sections_RET = DBGet( "SELECT cp.COURSE_ID,cp.MARKING_PERIOD_ID,cp.TEACHER_ID,
		cp.SHORT_NAME,cpsp.PERIOD_ID
		FROM course_periods cp,course_period_school_periods cpsp
		WHERE cpsp.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
		AND cp.SYEAR='" . UserSyear() . "'
		AND cp.SCHOOL_ID='" . UserSchool() . "'" );

	$existing = [];

	foreach ( (array) $sections_RET as $section )
	{
		$signature = implode( '-', [
			$section['COURSE_ID'],
			$section['PERIOD_ID'],
			$section['MARKING_PERIOD_ID'],
			$section['TEACHER_ID'],
		] );

		$existing[ $signature ] = $section['SHORT_NAME'];
	}

	return $existing;
}

/**
 * Get / set the sections wizard data saved in the session
 *
 * @param  array|bool|null $data Data to save, false to forget it,
 *                               or null to read the saved data (optional).
 *
 * @return array Session data.
 */
function CourseExpressSectionsSession( $data = null )
{
	$empty = [ 'ROWS' => [], 'FILE_NAME' => '' ];

	if ( $data === false )
	{
		unset( $_SESSION['Course_Express_Sections'] );

		return $empty;
	}

	if ( is_array( $data ) )
	{
		$_SESSION['Course_Express_Sections'] = $data + [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		];
	}

	$session = issetVal( $_SESSION['Course_Express_Sections'], [] );

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

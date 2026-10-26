<?php
/**
 * MCP Server tools
 *
 * All tools are read-only. Each tool declares the RosarioSIS programs the user
 * must be allowed to use (same permissions as in the RosarioSIS interface),
 * and every query is restricted to the schools / students the user has access to.
 *
 * @package MCP Server plugin
 */

/**
 * Tool definitions
 *
 * - programs:   RosarioSIS programs (modnames) the user must be allowed to use.
 * - admin_only: true to restrict the tool to the Administrator profile.
 * - callback:   function( $args, $user ), returns an array, or throws MCPServerToolError.
 *
 * @return array Tools, indexed by name.
 */
function MCPServerTools()
{
	$student_id = [
		'type' => 'integer',
		'description' => 'RosarioSIS student ID (see search_students).',
	];

	$school_id = [
		'type' => 'integer',
		'description' => 'School ID (see list_schools). Defaults to all your schools.',
	];

	$date = [
		'type' => 'string',
		'format' => 'date',
		'description' => 'Date, YYYY-MM-DD. Defaults to today.',
	];

	return [
		'whoami' => [
			'title' => 'Current user',
			'description' => 'Get the RosarioSIS user this connection acts as: name, profile (admin or teacher), current school year, accessible schools, and today\'s date. Call this first to understand what data you can access.',
			'properties' => [],
			'required' => [],
			'programs' => [],
			'admin_only' => false,
			'callback' => 'MCPServerToolWhoami',
		],
		'list_schools' => [
			'title' => 'List schools',
			'description' => 'List the schools you have access to for the current school year, with contact information and grade levels.',
			'properties' => [],
			'required' => [],
			'programs' => [],
			'admin_only' => false,
			'callback' => 'MCPServerToolListSchools',
		],
		'list_marking_periods' => [
			'title' => 'List marking periods',
			'description' => 'List the marking periods (FY full year, SEM semester, QTR quarter, PRO progress period) of the current school year with their dates.',
			'properties' => [
				'school_id' => $school_id,
			],
			'required' => [],
			'programs' => [],
			'admin_only' => false,
			'callback' => 'MCPServerToolListMarkingPeriods',
		],
		'search_students' => [
			'title' => 'Search students',
			'description' => 'Search students enrolled this school year by name, username or student ID. Teachers only see students scheduled in their classes. Returns at most `limit` students.',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Part of the student name ("Smith", "John Smith") or username, or an exact student ID. Omit to list students.',
				],
				'school_id' => $school_id,
				'grade_level' => [
					'type' => 'string',
					'description' => 'Grade level title or short name, for example "Kindergarten" or "05".',
				],
				'include_inactive' => [
					'type' => 'boolean',
					'description' => 'Include students whose enrollment ended or has not started yet. Defaults to false.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'description' => 'Maximum number of students to return. Defaults to 25.',
				],
			],
			'required' => [],
			'programs' => [ 'Students/Student.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolSearchStudents',
		],
		'get_student' => [
			'title' => 'Get student',
			'description' => 'Get a student\'s information: enrollment history, the student fields you are allowed to see (General Info, Medical, etc., grouped by category), and addresses & contacts if allowed.',
			'properties' => [
				'student_id' => $student_id,
			],
			'required' => [ 'student_id' ],
			'programs' => [ 'Students/Student.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolGetStudent',
		],
		'get_student_schedule' => [
			'title' => 'Get student schedule',
			'description' => 'Get the classes a student is scheduled in on a given date (course, section, teacher, room, periods), or for the whole school year.',
			'properties' => [
				'student_id' => $student_id,
				'date' => $date,
				'whole_year' => [
					'type' => 'boolean',
					'description' => 'Return every schedule entry of the school year, including dropped classes, instead of the classes on `date`. Defaults to false.',
				],
			],
			'required' => [ 'student_id' ],
			'programs' => [ 'Scheduling/Schedule.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolGetStudentSchedule',
		],
		'get_student_attendance' => [
			'title' => 'Get student attendance',
			'description' => 'Get a student\'s attendance between two dates: daily attendance totals, the days absent or half-day, and the period absences / tardies with their attendance code.',
			'properties' => [
				'student_id' => $student_id,
				'start_date' => [
					'type' => 'string',
					'format' => 'date',
					'description' => 'Start date, YYYY-MM-DD. Defaults to 30 days ago.',
				],
				'end_date' => [
					'type' => 'string',
					'format' => 'date',
					'description' => 'End date, YYYY-MM-DD. Defaults to today. At most one year after start_date.',
				],
			],
			'required' => [ 'student_id' ],
			'programs' => [ 'Attendance/DailySummary.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolGetStudentAttendance',
		],
		'get_student_grades' => [
			'title' => 'Get student final grades',
			'description' => 'Get a student\'s final (report card) grades per marking period: letter grade, percent, GPA points, credits and comment. Teachers only see grades for their own classes.',
			'properties' => [
				'student_id' => $student_id,
				'school_year' => [
					'type' => 'integer',
					'description' => 'School year, for example 2025 for 2025-2026. Defaults to all years.',
				],
			],
			'required' => [ 'student_id' ],
			'programs' => [ 'Grades/FinalGrades.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolGetStudentGrades',
		],
		'list_course_periods' => [
			'title' => 'List classes',
			'description' => 'List course periods (class sections) of the current school year: course, subject, section, teacher, room, seats, marking period and periods. Teachers only see their own classes.',
			'properties' => [
				'school_id' => $school_id,
				'query' => [
					'type' => 'string',
					'description' => 'Part of the course title, course code, section title or subject.',
				],
				'teacher_id' => [
					'type' => 'integer',
					'description' => 'Only classes taught by this staff ID (administrators only, see search_staff).',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'description' => 'Maximum number of classes to return. Defaults to 50.',
				],
			],
			'required' => [],
			'programs' => [ 'Scheduling/Courses.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolListCoursePeriods',
		],
		'get_class_roster' => [
			'title' => 'Get class roster',
			'description' => 'List the students scheduled in a course period (class section) on a given date. Teachers can only see their own classes.',
			'properties' => [
				'course_period_id' => [
					'type' => 'integer',
					'description' => 'Course period ID (see list_course_periods).',
				],
				'date' => $date,
			],
			'required' => [ 'course_period_id' ],
			'programs' => [ 'Students/Student.php' ],
			'admin_only' => false,
			'callback' => 'MCPServerToolGetClassRoster',
		],
		'search_staff' => [
			'title' => 'Search users',
			'description' => 'Search RosarioSIS users (administrators, teachers, parents) of the current school year by name, username or email. Administrators only.',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Part of the name, username or email. Omit to list users.',
				],
				'profile' => [
					'type' => 'string',
					'enum' => [ 'admin', 'teacher', 'parent', 'none' ],
					'description' => 'Only users with this profile.',
				],
				'school_id' => $school_id,
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'description' => 'Maximum number of users to return. Defaults to 25.',
				],
			],
			'required' => [],
			'programs' => [ 'Users/User.php' ],
			'admin_only' => true,
			'callback' => 'MCPServerToolSearchStaff',
		],
		'enrollment_summary' => [
			'title' => 'Enrollment summary',
			'description' => 'Count the students enrolled on a given date, per school and per grade level. Administrators only.',
			'properties' => [
				'school_id' => $school_id,
				'date' => $date,
			],
			'required' => [],
			'programs' => [ 'Students/Student.php' ],
			'admin_only' => true,
			'callback' => 'MCPServerToolEnrollmentSummary',
		],
	];
}

/**
 * Tools the user is allowed to use
 *
 * @param array $user User.
 *
 * @return array Tools, indexed by name.
 */
function MCPServerUserTools( $user )
{
	$tools = [];

	foreach ( MCPServerTools() as $name => $tool )
	{
		if ( $tool['admin_only']
			&& ! MCPServerIsAdmin( $user ) )
		{
			continue;
		}

		foreach ( $tool['programs'] as $modname )
		{
			if ( ! MCPServerCanUse( $user, $modname ) )
			{
				continue 2;
			}
		}

		$tools[ $name ] = $tool;
	}

	return $tools;
}

/**
 * Escape a search string for SQL LIKE, lowercase it and add wildcards
 *
 * @param string $query Search string.
 *
 * @return string For example "%smith%".
 */
function MCPServerLikeArg( $query )
{
	$query = mb_strtolower( trim( (string) $query ) );

	return '%' . addcslashes( $query, '\\%_' ) . '%';
}

/**
 * Optional string argument
 *
 * @param array  $args Arguments.
 * @param string $name Argument name.
 *
 * @return string Trimmed string, '' when missing.
 */
function MCPServerStringArg( $args, $name )
{
	if ( ! isset( $args[ $name ] ) )
	{
		return '';
	}

	if ( ! is_string( $args[ $name ] ) )
	{
		throw new MCPServerToolError( $name . ' must be a string.' );
	}

	return trim( $args[ $name ] );
}

/**
 * Schools to query: the school_id argument if any (checked), else all user schools
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array School IDs.
 */
function MCPServerSchoolsArg( $args, $user )
{
	if ( ! isset( $args['school_id'] ) )
	{
		return array_keys( $user['schools'] );
	}

	$school_id = MCPServerIntArg( $args['school_id'], 'school_id' );

	MCPServerCheckSchoolAccess( $user, $school_id );

	return [ $school_id ];
}

/**
 * Periods (time slots) of course periods
 *
 * @param array $course_period_ids Course period IDs.
 *
 * @return array Periods, indexed by course period ID.
 */
function MCPServerCoursePeriodPeriods( $course_period_ids )
{
	if ( ! $course_period_ids )
	{
		return [];
	}

	$periods_RET = MCPServerQuery( "SELECT cpsp.COURSE_PERIOD_ID,cpsp.DAYS,
		sp.TITLE,sp.SHORT_NAME,sp.START_TIME,sp.END_TIME
		FROM course_period_school_periods cpsp
		JOIN school_periods sp ON sp.PERIOD_ID=cpsp.PERIOD_ID
		WHERE cpsp.COURSE_PERIOD_ID IN(" . MCPServerSQLIntList( $course_period_ids ) . ")
		ORDER BY sp.SORT_ORDER,sp.TITLE" );

	$periods = [];

	foreach ( $periods_RET as $period )
	{
		$periods[ (int) $period['course_period_id'] ][] = [
			'period' => $period['title'],
			// Days letters: U M T W H F S.
			'days' => $period['days'],
			'start_time' => $period['start_time'],
			'end_time' => $period['end_time'],
		];
	}

	return $periods;
}

/**
 * Tool: whoami
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolWhoami( $args, $user )
{
	$schools = [];

	foreach ( $user['schools'] as $id => $title )
	{
		$schools[] = [ 'school_id' => $id, 'title' => $title ];
	}

	return [
		'staff_id' => $user['staff_id'],
		'name' => MCPServerName( $user ),
		'username' => $user['username'],
		'profile' => $user['profile'],
		'school_year' => $user['syear'],
		'school_year_label' => $user['syear'] . '-' . ( $user['syear'] + 1 ),
		'today' => date( 'Y-m-d' ),
		'schools' => $schools,
		'tools' => array_keys( MCPServerUserTools( $user ) ),
	];
}

/**
 * Tool: list_schools
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolListSchools( $args, $user )
{
	$school_ids = MCPServerSQLIntList( array_keys( $user['schools'] ) );

	$schools_RET = MCPServerQuery( "SELECT ID,TITLE,SHORT_NAME,SCHOOL_NUMBER,ADDRESS,CITY,STATE,ZIPCODE,
		PHONE,PRINCIPAL,WWW_ADDRESS
		FROM schools
		WHERE SYEAR=" . (int) $user['syear'] . "
		AND ID IN(" . $school_ids . ")
		ORDER BY TITLE" );

	$grade_levels_RET = MCPServerQuery( "SELECT ID,SCHOOL_ID,TITLE,SHORT_NAME
		FROM school_gradelevels
		WHERE SCHOOL_ID IN(" . $school_ids . ")
		ORDER BY SORT_ORDER,ID" );

	$schools = [];

	foreach ( $schools_RET as $school )
	{
		$school['school_id'] = (int) $school['id'];
		unset( $school['id'] );

		$school['grade_levels'] = [];

		foreach ( $grade_levels_RET as $grade_level )
		{
			if ( (int) $grade_level['school_id'] === $school['school_id'] )
			{
				$school['grade_levels'][] = [
					'grade_level_id' => (int) $grade_level['id'],
					'title' => $grade_level['title'],
					'short_name' => $grade_level['short_name'],
				];
			}
		}

		$schools[] = $school;
	}

	return [ 'schools' => $schools ];
}

/**
 * Tool: list_marking_periods
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolListMarkingPeriods( $args, $user )
{
	$school_ids = MCPServerSchoolsArg( $args, $user );

	$mps_RET = MCPServerQuery( "SELECT MARKING_PERIOD_ID,SCHOOL_ID,MP,PARENT_ID,TITLE,SHORT_NAME,
		START_DATE,END_DATE,POST_START_DATE,POST_END_DATE,DOES_GRADES
		FROM school_marking_periods
		WHERE SYEAR=" . (int) $user['syear'] . "
		AND SCHOOL_ID IN(" . MCPServerSQLIntList( $school_ids ) . ")
		ORDER BY SCHOOL_ID,START_DATE,
		CASE MP WHEN 'FY' THEN 0 WHEN 'SEM' THEN 1 WHEN 'QTR' THEN 2 ELSE 3 END,
		SORT_ORDER" );

	$marking_periods = [];

	foreach ( $mps_RET as $mp )
	{
		$marking_periods[] = [
			'marking_period_id' => (int) $mp['marking_period_id'],
			'school_id' => (int) $mp['school_id'],
			'type' => $mp['mp'],
			'parent_id' => $mp['parent_id'] ? (int) $mp['parent_id'] : null,
			'title' => $mp['title'],
			'short_name' => $mp['short_name'],
			'start_date' => $mp['start_date'],
			'end_date' => $mp['end_date'],
			'grade_posting_start' => $mp['post_start_date'],
			'grade_posting_end' => $mp['post_end_date'],
			'does_grades' => $mp['does_grades'] === 'Y',
		];
	}

	return [ 'marking_periods' => $marking_periods ];
}

/**
 * Tool: search_students
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolSearchStudents( $args, $user )
{
	$syear = (int) $user['syear'];

	$limit = isset( $args['limit'] ) ? MCPServerIntArg( $args['limit'], 'limit', 1, 100 ) : 25;

	$school_ids = MCPServerSQLIntList( MCPServerSchoolsArg( $args, $user ) );

	$today = date( 'Y-m-d' );

	$where_sql = " AND " . MCPServerStudentScopeSQL( $user, 's.STUDENT_ID' );

	$params = [];

	$query = MCPServerStringArg( $args, 'query' );

	if ( ctype_digit( $query ) )
	{
		$where_sql .= " AND s.STUDENT_ID=:student_id";

		$params['student_id'] = (int) $query;
	}
	elseif ( $query !== '' )
	{
		$where_sql .= " AND (LOWER(CONCAT(s.FIRST_NAME,' ',s.LAST_NAME)) LIKE :query1
			OR LOWER(CONCAT(s.LAST_NAME,' ',s.FIRST_NAME)) LIKE :query2
			OR LOWER(CONCAT(s.LAST_NAME,', ',s.FIRST_NAME)) LIKE :query3
			OR LOWER(CONCAT(s.FIRST_NAME,' ',s.MIDDLE_NAME,' ',s.LAST_NAME)) LIKE :query4
			OR LOWER(s.USERNAME) LIKE :query5)";

		$like = MCPServerLikeArg( $query );

		for ( $i = 1; $i <= 5; $i++ )
		{
			$params[ 'query' . $i ] = $like;
		}
	}

	$grade_level = MCPServerStringArg( $args, 'grade_level' );

	if ( $grade_level !== '' )
	{
		$where_sql .= " AND (LOWER(gl.TITLE)=:grade1 OR LOWER(gl.SHORT_NAME)=:grade2)";

		$params['grade1'] = $params['grade2'] = mb_strtolower( $grade_level );
	}

	if ( empty( $args['include_inactive'] ) )
	{
		$where_sql .= " AND se.START_DATE<='" . $today . "'
			AND (se.END_DATE IS NULL OR se.END_DATE>='" . $today . "')";
	}

	// One row per student: their latest enrollment in the school year.
	$students_RET = MCPServerQuery( "SELECT s.STUDENT_ID,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME,s.USERNAME,
		se.SCHOOL_ID,gl.TITLE AS GRADE_LEVEL,se.START_DATE,se.END_DATE
		FROM students s
		JOIN student_enrollment se ON se.STUDENT_ID=s.STUDENT_ID
		LEFT JOIN school_gradelevels gl ON gl.ID=se.GRADE_ID
		WHERE se.ID=(SELECT se2.ID
			FROM student_enrollment se2
			WHERE se2.STUDENT_ID=s.STUDENT_ID
			AND se2.SYEAR=" . $syear . "
			AND se2.SCHOOL_ID IN(" . $school_ids . ")
			ORDER BY se2.START_DATE DESC,se2.ID DESC
			LIMIT 1)" .
		$where_sql . "
		ORDER BY s.LAST_NAME,s.FIRST_NAME,s.STUDENT_ID
		LIMIT " . ( $limit + 1 ), $params );

	$truncated = count( $students_RET ) > $limit;

	$students = [];

	foreach ( array_slice( $students_RET, 0, $limit ) as $student )
	{
		$students[] = [
			'student_id' => (int) $student['student_id'],
			'name' => MCPServerName( $student ),
			'username' => $student['username'],
			'school_id' => (int) $student['school_id'],
			'school' => $user['schools'][ (int) $student['school_id'] ],
			'grade_level' => $student['grade_level'],
			'enrolled' => $student['start_date'] <= $today
				&& ( ! $student['end_date'] || $student['end_date'] >= $today ),
			'enrollment_start' => $student['start_date'],
			'enrollment_end' => $student['end_date'],
		];
	}

	return [
		'students' => $students,
		'count' => count( $students ),
		'truncated' => $truncated,
	];
}

/**
 * Tool: get_student
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolGetStudent( $args, $user )
{
	$student_id = MCPServerIntArg( isset( $args['student_id'] ) ? $args['student_id'] : null, 'student_id' );

	MCPServerCheckStudentAccess( $user, $student_id );

	$student = MCPServerQueryRow( "SELECT *
		FROM students
		WHERE STUDENT_ID=" . $student_id );

	$enrollment_RET = MCPServerQuery( "SELECT se.SYEAR,se.SCHOOL_ID,sch.TITLE AS SCHOOL,gl.TITLE AS GRADE_LEVEL,
		se.START_DATE,se.END_DATE,ec.TITLE AS ENROLLMENT_CODE,dc.TITLE AS DROP_CODE
		FROM student_enrollment se
		LEFT JOIN schools sch ON sch.ID=se.SCHOOL_ID AND sch.SYEAR=se.SYEAR
		LEFT JOIN school_gradelevels gl ON gl.ID=se.GRADE_ID
		LEFT JOIN student_enrollment_codes ec ON ec.ID=se.ENROLLMENT_CODE
		LEFT JOIN student_enrollment_codes dc ON dc.ID=se.DROP_CODE
		WHERE se.STUDENT_ID=" . $student_id . "
		AND se.SCHOOL_ID IN(" . MCPServerSQLIntList( array_keys( $user['schools'] ) ) . ")
		ORDER BY se.SYEAR DESC,se.START_DATE DESC" );

	$enrollment = [];

	foreach ( $enrollment_RET as $row )
	{
		$enrollment[] = [
			'school_year' => (int) $row['syear'],
			'school_id' => (int) $row['school_id'],
			'school' => $row['school'],
			'grade_level' => $row['grade_level'],
			'start_date' => $row['start_date'],
			'end_date' => $row['end_date'],
			'enrollment_code' => MCPServerML( $row['enrollment_code'] ),
			'drop_code' => MCPServerML( $row['drop_code'] ),
		];
	}

	// Student Info tabs the user can see, same as in Students > Student Info.
	$categories_RET = MCPServerQuery( "SELECT ID,TITLE
		FROM student_field_categories
		ORDER BY SORT_ORDER,ID" );

	$categories = [];

	foreach ( $categories_RET as $category )
	{
		if ( MCPServerCanUse( $user, 'Students/Student.php&category_id=' . $category['id'] ) )
		{
			$categories[ (int) $category['id'] ] = MCPServerML( $category['title'] );
		}
	}

	$hidden_fields = MCPServerHiddenFields();

	$fields_RET = MCPServerQuery( "SELECT ID,TITLE,TYPE,SELECT_OPTIONS,CATEGORY_ID
		FROM custom_fields
		WHERE CATEGORY_ID IN(" . MCPServerSQLIntList( array_keys( $categories ) ) . ")" .
		( $hidden_fields ? " AND ID NOT IN(" . MCPServerSQLIntList( $hidden_fields ) . ")" : '' ) . "
		ORDER BY CATEGORY_ID,SORT_ORDER,ID" );

	$fields = [];

	foreach ( $fields_RET as $field )
	{
		$column = 'custom_' . $field['id'];

		if ( ! isset( $student[ $column ] )
			|| $student[ $column ] === '' )
		{
			continue;
		}

		$fields[ $categories[ (int) $field['category_id'] ] ][ MCPServerML( $field['title'] ) ] =
			MCPServerFieldValue( $field, $student[ $column ] );
	}

	$return = [
		'student_id' => $student_id,
		'name' => MCPServerName( $student ),
		'first_name' => $student['first_name'],
		'middle_name' => $student['middle_name'],
		'last_name' => $student['last_name'],
		'name_suffix' => $student['name_suffix'],
		'username' => $student['username'],
		'last_login' => $student['last_login'],
		'enrollment' => $enrollment,
		'fields' => $fields,
	];

	// Addresses & Contacts tab is category 3.
	if ( isset( $categories[3] ) )
	{
		$return += MCPServerStudentAddressesContacts( $student_id );
	}

	return $return;
}

/**
 * Format a student field value according to its type
 *
 * @param array  $field Field: TYPE, SELECT_OPTIONS.
 * @param string $value Raw value.
 *
 * @return mixed
 */
function MCPServerFieldValue( $field, $value )
{
	switch ( $field['type'] )
	{
		case 'radio':

			// Checkbox.
			return $value === 'Y';

		case 'multiple':

			// Stored as "||option 1||option 2||".
			return array_values( array_filter( explode( '||', $value ), 'strlen' ) );

		case 'codeds':

			// Options are "code|Label" lines.
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $field['select_options'] ) as $option )
			{
				$option = explode( '|', $option, 2 );

				if ( $option[0] === $value
					&& isset( $option[1] ) )
				{
					return $option[1];
				}
			}

			return $value;
	}

	return $value;
}

/**
 * Student addresses & contacts
 *
 * @param int $student_id Student ID.
 *
 * @return array addresses & contacts.
 */
function MCPServerStudentAddressesContacts( $student_id )
{
	$addresses_RET = MCPServerQuery( "SELECT a.ADDRESS_ID,a.ADDRESS,a.CITY,a.STATE,a.ZIPCODE,a.PHONE,
		a.MAIL_ADDRESS,a.MAIL_CITY,a.MAIL_STATE,a.MAIL_ZIPCODE,
		sja.RESIDENCE,sja.MAILING,sja.BUS_PICKUP,sja.BUS_DROPOFF
		FROM students_join_address sja
		JOIN address a ON a.ADDRESS_ID=sja.ADDRESS_ID
		WHERE sja.STUDENT_ID=" . (int) $student_id . "
		ORDER BY sja.RESIDENCE DESC,a.ADDRESS_ID" );

	$addresses = [];

	foreach ( $addresses_RET as $address )
	{
		$addresses[] = [
			'address_id' => (int) $address['address_id'],
			'address' => $address['address'],
			'city' => $address['city'],
			'state' => $address['state'],
			'zipcode' => $address['zipcode'],
			'phone' => $address['phone'],
			'mailing_address' => $address['mail_address'] ?
				trim( $address['mail_address'] . ', ' . $address['mail_city'] . ' ' .
					$address['mail_state'] . ' ' . $address['mail_zipcode'] ) :
				null,
			'residence' => $address['residence'] === 'Y',
			'mailing' => $address['mailing'] === 'Y',
			'bus_pickup' => $address['bus_pickup'] === 'Y',
			'bus_dropoff' => $address['bus_dropoff'] === 'Y',
		];
	}

	$people_RET = MCPServerQuery( "SELECT p.PERSON_ID,p.FIRST_NAME,p.MIDDLE_NAME,p.LAST_NAME,
		sjp.STUDENT_RELATION,sjp.CUSTODY,sjp.EMERGENCY,sjp.ADDRESS_ID
		FROM students_join_people sjp
		JOIN people p ON p.PERSON_ID=sjp.PERSON_ID
		WHERE sjp.STUDENT_ID=" . (int) $student_id . "
		ORDER BY sjp.CUSTODY DESC,sjp.EMERGENCY DESC,p.LAST_NAME,p.FIRST_NAME" );

	$contact_info_RET = MCPServerQuery( "SELECT PERSON_ID,TITLE,VALUE
		FROM people_join_contacts
		WHERE PERSON_ID IN(" . MCPServerSQLIntList( array_column( $people_RET, 'person_id' ) ) . ")
		ORDER BY ID" );

	$contacts = [];

	foreach ( $people_RET as $person )
	{
		$info = [];

		foreach ( $contact_info_RET as $contact_info )
		{
			if ( $contact_info['person_id'] == $person['person_id'] )
			{
				$info[] = [ 'title' => $contact_info['title'], 'value' => $contact_info['value'] ];
			}
		}

		$contacts[] = [
			'person_id' => (int) $person['person_id'],
			'name' => MCPServerName( $person ),
			'relation' => $person['student_relation'],
			'custody' => $person['custody'] === 'Y',
			'emergency' => $person['emergency'] === 'Y',
			'address_id' => $person['address_id'] ? (int) $person['address_id'] : null,
			'contact_info' => $info,
		];
	}

	return [
		'addresses' => $addresses,
		'contacts' => $contacts,
	];
}

/**
 * Tool: get_student_schedule
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolGetStudentSchedule( $args, $user )
{
	$student_id = MCPServerIntArg( isset( $args['student_id'] ) ? $args['student_id'] : null, 'student_id' );

	$date = MCPServerDateArg( isset( $args['date'] ) ? $args['date'] : null, 'date', date( 'Y-m-d' ) );

	$whole_year = ! empty( $args['whole_year'] );

	MCPServerCheckStudentAccess( $user, $student_id );

	$where_sql = '';

	if ( ! $whole_year )
	{
		// Scheduled on that date, in a marking period that includes that date.
		$where_sql = " AND sch.START_DATE<='" . $date . "'
			AND (sch.END_DATE IS NULL OR sch.END_DATE>='" . $date . "')
			AND (mp.MARKING_PERIOD_ID IS NULL
				OR (mp.START_DATE<='" . $date . "' AND mp.END_DATE>='" . $date . "'))";
	}

	$schedule_RET = MCPServerQuery( "SELECT sch.COURSE_PERIOD_ID,sch.START_DATE,sch.END_DATE,
		c.TITLE AS COURSE,c.SHORT_NAME AS COURSE_CODE,cs.TITLE AS SUBJECT,
		cp.TITLE AS SECTION,cp.ROOM,cp.CREDITS,
		t.STAFF_ID AS TEACHER_ID,t.FIRST_NAME,t.LAST_NAME,
		mp.TITLE AS MARKING_PERIOD
		FROM schedule sch
		JOIN course_periods cp ON cp.COURSE_PERIOD_ID=sch.COURSE_PERIOD_ID
		JOIN courses c ON c.COURSE_ID=cp.COURSE_ID
		LEFT JOIN course_subjects cs ON cs.SUBJECT_ID=c.SUBJECT_ID
		LEFT JOIN staff t ON t.STAFF_ID=cp.TEACHER_ID
		LEFT JOIN school_marking_periods mp ON mp.MARKING_PERIOD_ID=sch.MARKING_PERIOD_ID
		WHERE sch.STUDENT_ID=" . $student_id . "
		AND sch.SYEAR=" . (int) $user['syear'] . "
		AND sch.SCHOOL_ID IN(" . MCPServerSQLIntList( array_keys( $user['schools'] ) ) . ")" .
		$where_sql . "
		ORDER BY cs.SORT_ORDER,c.TITLE,sch.START_DATE" );

	$periods = MCPServerCoursePeriodPeriods( array_column( $schedule_RET, 'course_period_id' ) );

	$classes = [];

	foreach ( $schedule_RET as $class )
	{
		$classes[] = [
			'course_period_id' => (int) $class['course_period_id'],
			'course' => MCPServerML( $class['course'] ),
			'course_code' => $class['course_code'],
			'subject' => MCPServerML( $class['subject'] ),
			'section' => $class['section'],
			'teacher_id' => $class['teacher_id'] ? (int) $class['teacher_id'] : null,
			'teacher' => $class['teacher_id'] ? MCPServerName( $class ) : null,
			'room' => $class['room'],
			'credits' => $class['credits'],
			'marking_period' => $class['marking_period'],
			'periods' => isset( $periods[ (int) $class['course_period_id'] ] ) ?
				$periods[ (int) $class['course_period_id'] ] : [],
			'start_date' => $class['start_date'],
			'end_date' => $class['end_date'],
		];
	}

	return [
		'student_id' => $student_id,
		'date' => $whole_year ? null : $date,
		'school_year' => (int) $user['syear'],
		'classes' => $classes,
	];
}

/**
 * Tool: get_student_attendance
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolGetStudentAttendance( $args, $user )
{
	$student_id = MCPServerIntArg( isset( $args['student_id'] ) ? $args['student_id'] : null, 'student_id' );

	$end_date = MCPServerDateArg( isset( $args['end_date'] ) ? $args['end_date'] : null, 'end_date', date( 'Y-m-d' ) );

	$start_date = MCPServerDateArg(
		isset( $args['start_date'] ) ? $args['start_date'] : null,
		'start_date',
		date( 'Y-m-d', strtotime( $end_date . ' -30 days' ) )
	);

	if ( $start_date > $end_date )
	{
		throw new MCPServerToolError( 'start_date must be before end_date.' );
	}

	if ( strtotime( $end_date ) - strtotime( $start_date ) > 366 * 86400 )
	{
		throw new MCPServerToolError( 'The date range cannot be longer than one year.' );
	}

	MCPServerCheckStudentAccess( $user, $student_id );

	$dates_sql = " BETWEEN '" . $start_date . "' AND '" . $end_date . "'";

	$days_RET = MCPServerQuery( "SELECT SCHOOL_DATE,STATE_VALUE,COMMENT
		FROM attendance_day
		WHERE STUDENT_ID=" . $student_id . "
		AND SCHOOL_DATE" . $dates_sql . "
		ORDER BY SCHOOL_DATE" );

	$summary = [ 'days_recorded' => 0, 'present' => 0, 'half_day' => 0, 'absent' => 0 ];

	$days_not_present = [];

	foreach ( $days_RET as $day )
	{
		$summary['days_recorded']++;

		// Daily attendance state: 1 present, 0.5 half day, 0 absent.
		$state = (float) $day['state_value'];

		if ( $state >= 1 )
		{
			$summary['present']++;

			continue;
		}

		$summary[ $state > 0 ? 'half_day' : 'absent' ]++;

		$days_not_present[] = [
			'date' => $day['school_date'],
			'status' => $state > 0 ? 'half_day' : 'absent',
			'comment' => $day['comment'],
		];
	}

	// Period attendance other than Present: absences, tardies...
	$periods_RET = MCPServerQuery( "SELECT ap.SCHOOL_DATE,sp.TITLE AS PERIOD,c.TITLE AS COURSE,
		ac.TITLE AS CODE,ac.SHORT_NAME AS CODE_SHORT,ac.STATE_CODE,
		ap.ATTENDANCE_REASON,ap.COMMENT
		FROM attendance_period ap
		LEFT JOIN attendance_codes ac ON ac.ID=ap.ATTENDANCE_CODE
		LEFT JOIN school_periods sp ON sp.PERIOD_ID=ap.PERIOD_ID
		LEFT JOIN course_periods cp ON cp.COURSE_PERIOD_ID=ap.COURSE_PERIOD_ID
		LEFT JOIN courses c ON c.COURSE_ID=cp.COURSE_ID
		WHERE ap.STUDENT_ID=" . $student_id . "
		AND ap.SCHOOL_DATE" . $dates_sql . "
		AND (ac.STATE_CODE IS NULL OR ac.STATE_CODE<>'P')
		ORDER BY ap.SCHOOL_DATE,sp.SORT_ORDER
		LIMIT 500" );

	$period_records = [];

	// Attendance code state: A absent, H half day, P present.
	$states = [ 'A' => 'absent', 'H' => 'half_day', 'P' => 'present' ];

	foreach ( $periods_RET as $record )
	{
		$period_records[] = [
			'date' => $record['school_date'],
			'period' => $record['period'],
			'course' => MCPServerML( $record['course'] ),
			'code' => MCPServerML( $record['code'] ),
			'code_short' => $record['code_short'],
			'counts_as' => isset( $states[ $record['state_code'] ] ) ? $states[ $record['state_code'] ] : null,
			'reason' => $record['attendance_reason'],
			'comment' => $record['comment'],
		];
	}

	return [
		'student_id' => $student_id,
		'start_date' => $start_date,
		'end_date' => $end_date,
		'daily_summary' => $summary,
		'days_not_present' => $days_not_present,
		'period_records' => $period_records,
	];
}

/**
 * Tool: get_student_grades
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolGetStudentGrades( $args, $user )
{
	$student_id = MCPServerIntArg( isset( $args['student_id'] ) ? $args['student_id'] : null, 'student_id' );

	MCPServerCheckStudentAccess( $user, $student_id );

	$where_sql = '';

	if ( isset( $args['school_year'] ) )
	{
		$where_sql .= " AND g.SYEAR=" . MCPServerIntArg( $args['school_year'], 'school_year', 1900, 2200 );
	}

	if ( ! MCPServerIsAdmin( $user ) )
	{
		$where_sql .= " AND g.COURSE_PERIOD_ID IN(SELECT cp.COURSE_PERIOD_ID
			FROM course_periods cp
			WHERE " . MCPServerTeacherSQL( $user, 'cp' ) . ")";
	}

	// Grades of previous schools are attached to history marking periods.
	$grades_RET = MCPServerQuery( "SELECT g.SYEAR,g.SCHOOL,g.MARKING_PERIOD_ID,
		COALESCE(mp.TITLE,hmp.NAME) AS MARKING_PERIOD,
		g.COURSE_PERIOD_ID,g.COURSE_TITLE,g.GRADE_LETTER,g.GRADE_PERCENT,
		g.WEIGHTED_GP,g.UNWEIGHTED_GP,g.GP_SCALE,g.CREDIT_ATTEMPTED,g.CREDIT_EARNED,g.COMMENT
		FROM student_report_card_grades g
		LEFT JOIN school_marking_periods mp ON mp.MARKING_PERIOD_ID=g.MARKING_PERIOD_ID
		LEFT JOIN history_marking_periods hmp ON hmp.MARKING_PERIOD_ID=g.MARKING_PERIOD_ID
		WHERE g.STUDENT_ID=" . $student_id .
		$where_sql . "
		ORDER BY g.SYEAR,COALESCE(mp.END_DATE,hmp.POST_END_DATE),g.COURSE_TITLE" );

	$grades = [];

	foreach ( $grades_RET as $grade )
	{
		$grades[] = [
			'school_year' => (int) $grade['syear'],
			'school' => $grade['school'],
			'marking_period_id' => (int) $grade['marking_period_id'],
			'marking_period' => $grade['marking_period'],
			'course_period_id' => $grade['course_period_id'] ? (int) $grade['course_period_id'] : null,
			'course' => MCPServerML( $grade['course_title'] ),
			'grade' => $grade['grade_letter'],
			'percent' => $grade['grade_percent'],
			'weighted_gp' => $grade['weighted_gp'],
			'unweighted_gp' => $grade['unweighted_gp'],
			'gp_scale' => $grade['gp_scale'],
			'credit_attempted' => $grade['credit_attempted'],
			'credit_earned' => $grade['credit_earned'],
			'comment' => $grade['comment'],
		];
	}

	return [
		'student_id' => $student_id,
		'grades' => $grades,
	];
}

/**
 * Tool: list_course_periods
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolListCoursePeriods( $args, $user )
{
	$limit = isset( $args['limit'] ) ? MCPServerIntArg( $args['limit'], 'limit', 1, 200 ) : 50;

	$where_sql = " AND cp.SCHOOL_ID IN(" . MCPServerSQLIntList( MCPServerSchoolsArg( $args, $user ) ) . ")";

	if ( ! MCPServerIsAdmin( $user ) )
	{
		$where_sql .= " AND " . MCPServerTeacherSQL( $user, 'cp' );
	}
	elseif ( isset( $args['teacher_id'] ) )
	{
		$teacher_id = MCPServerIntArg( $args['teacher_id'], 'teacher_id' );

		$where_sql .= " AND " . MCPServerTeacherSQL( [ 'staff_id' => $teacher_id ], 'cp' );
	}

	$params = [];

	$query = MCPServerStringArg( $args, 'query' );

	if ( $query !== '' )
	{
		$where_sql .= " AND (LOWER(c.TITLE) LIKE :query1
			OR LOWER(c.SHORT_NAME) LIKE :query2
			OR LOWER(cp.TITLE) LIKE :query3
			OR LOWER(cs.TITLE) LIKE :query4)";

		$like = MCPServerLikeArg( $query );

		for ( $i = 1; $i <= 4; $i++ )
		{
			$params[ 'query' . $i ] = $like;
		}
	}

	$course_periods_RET = MCPServerQuery( "SELECT cp.COURSE_PERIOD_ID,cp.SCHOOL_ID,cp.TITLE AS SECTION,
		cp.ROOM,cp.TOTAL_SEATS,cp.FILLED_SEATS,cp.CREDITS,
		c.COURSE_ID,c.TITLE AS COURSE,c.SHORT_NAME AS COURSE_CODE,cs.TITLE AS SUBJECT,
		mp.TITLE AS MARKING_PERIOD,
		t.STAFF_ID AS TEACHER_ID,t.FIRST_NAME,t.LAST_NAME
		FROM course_periods cp
		JOIN courses c ON c.COURSE_ID=cp.COURSE_ID
		LEFT JOIN course_subjects cs ON cs.SUBJECT_ID=c.SUBJECT_ID
		LEFT JOIN school_marking_periods mp ON mp.MARKING_PERIOD_ID=cp.MARKING_PERIOD_ID
		LEFT JOIN staff t ON t.STAFF_ID=cp.TEACHER_ID
		WHERE cp.SYEAR=" . (int) $user['syear'] .
		$where_sql . "
		ORDER BY cs.SORT_ORDER,c.TITLE,cp.SHORT_NAME,cp.COURSE_PERIOD_ID
		LIMIT " . ( $limit + 1 ), $params );

	$truncated = count( $course_periods_RET ) > $limit;

	$course_periods_RET = array_slice( $course_periods_RET, 0, $limit );

	$periods = MCPServerCoursePeriodPeriods( array_column( $course_periods_RET, 'course_period_id' ) );

	$course_periods = [];

	foreach ( $course_periods_RET as $cp )
	{
		$course_periods[] = [
			'course_period_id' => (int) $cp['course_period_id'],
			'school_id' => (int) $cp['school_id'],
			'course_id' => (int) $cp['course_id'],
			'course' => MCPServerML( $cp['course'] ),
			'course_code' => $cp['course_code'],
			'subject' => MCPServerML( $cp['subject'] ),
			'section' => $cp['section'],
			'teacher_id' => $cp['teacher_id'] ? (int) $cp['teacher_id'] : null,
			'teacher' => $cp['teacher_id'] ? MCPServerName( $cp ) : null,
			'room' => $cp['room'],
			'total_seats' => $cp['total_seats'],
			'filled_seats' => $cp['filled_seats'],
			'credits' => $cp['credits'],
			'marking_period' => $cp['marking_period'],
			'periods' => isset( $periods[ (int) $cp['course_period_id'] ] ) ?
				$periods[ (int) $cp['course_period_id'] ] : [],
		];
	}

	return [
		'course_periods' => $course_periods,
		'count' => count( $course_periods ),
		'truncated' => $truncated,
	];
}

/**
 * Tool: get_class_roster
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolGetClassRoster( $args, $user )
{
	$course_period_id = MCPServerIntArg(
		isset( $args['course_period_id'] ) ? $args['course_period_id'] : null,
		'course_period_id'
	);

	$date = MCPServerDateArg( isset( $args['date'] ) ? $args['date'] : null, 'date', date( 'Y-m-d' ) );

	$cp = MCPServerQueryRow( "SELECT cp.COURSE_PERIOD_ID,cp.SCHOOL_ID,cp.TITLE AS SECTION,
		cp.TEACHER_ID,cp.SECONDARY_TEACHER_ID,c.TITLE AS COURSE
		FROM course_periods cp
		JOIN courses c ON c.COURSE_ID=cp.COURSE_ID
		WHERE cp.COURSE_PERIOD_ID=" . $course_period_id . "
		AND cp.SYEAR=" . (int) $user['syear'] );

	if ( ! $cp
		|| ! isset( $user['schools'][ (int) $cp['school_id'] ] )
		|| ( ! MCPServerIsAdmin( $user )
			&& (int) $cp['teacher_id'] !== $user['staff_id']
			&& (int) $cp['secondary_teacher_id'] !== $user['staff_id'] ) )
	{
		throw new MCPServerToolError( 'Course period ' . $course_period_id . ' not found or not accessible to you.' );
	}

	$students_RET = MCPServerQuery( "SELECT s.STUDENT_ID,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME,
		gl.TITLE AS GRADE_LEVEL,sch.START_DATE,sch.END_DATE
		FROM schedule sch
		JOIN students s ON s.STUDENT_ID=sch.STUDENT_ID
		LEFT JOIN student_enrollment se ON se.STUDENT_ID=sch.STUDENT_ID
			AND se.SYEAR=sch.SYEAR
			AND se.SCHOOL_ID=sch.SCHOOL_ID
			AND se.START_DATE<='" . $date . "'
			AND (se.END_DATE IS NULL OR se.END_DATE>='" . $date . "')
		LEFT JOIN school_gradelevels gl ON gl.ID=se.GRADE_ID
		WHERE sch.COURSE_PERIOD_ID=" . $course_period_id . "
		AND sch.START_DATE<='" . $date . "'
		AND (sch.END_DATE IS NULL OR sch.END_DATE>='" . $date . "')
		ORDER BY s.LAST_NAME,s.FIRST_NAME,s.STUDENT_ID" );

	$students = [];

	foreach ( $students_RET as $student )
	{
		// A student may have several matching enrollment rows: keep one.
		$students[ (int) $student['student_id'] ] = [
			'student_id' => (int) $student['student_id'],
			'name' => MCPServerName( $student ),
			'grade_level' => $student['grade_level'],
			'scheduled_since' => $student['start_date'],
			'scheduled_until' => $student['end_date'],
		];
	}

	return [
		'course_period_id' => $course_period_id,
		'course' => MCPServerML( $cp['course'] ),
		'section' => $cp['section'],
		'date' => $date,
		'count' => count( $students ),
		'students' => array_values( $students ),
	];
}

/**
 * Tool: search_staff
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolSearchStaff( $args, $user )
{
	$limit = isset( $args['limit'] ) ? MCPServerIntArg( $args['limit'], 'limit', 1, 100 ) : 25;

	// Users having access to all schools, or to one of the schools.
	$schools_sql = [ "s.SCHOOLS IS NULL", "s.SCHOOLS=''", "s.SCHOOLS=','" ];

	foreach ( MCPServerSchoolsArg( $args, $user ) as $school_id )
	{
		$schools_sql[] = "s.SCHOOLS LIKE '%," . (int) $school_id . ",%'";
	}

	$where_sql = " AND (" . implode( ' OR ', $schools_sql ) . ")";

	$params = [];

	$profile = MCPServerStringArg( $args, 'profile' );

	if ( $profile !== '' )
	{
		if ( ! in_array( $profile, [ 'admin', 'teacher', 'parent', 'none' ], true ) )
		{
			throw new MCPServerToolError( 'profile must be one of admin, teacher, parent, none.' );
		}

		$where_sql .= " AND s.PROFILE=:profile";

		$params['profile'] = $profile;
	}

	$query = MCPServerStringArg( $args, 'query' );

	if ( $query !== '' )
	{
		$where_sql .= " AND (LOWER(CONCAT(s.FIRST_NAME,' ',s.LAST_NAME)) LIKE :query1
			OR LOWER(CONCAT(s.LAST_NAME,' ',s.FIRST_NAME)) LIKE :query2
			OR LOWER(s.USERNAME) LIKE :query3
			OR LOWER(s.EMAIL) LIKE :query4)";

		$like = MCPServerLikeArg( $query );

		for ( $i = 1; $i <= 4; $i++ )
		{
			$params[ 'query' . $i ] = $like;
		}
	}

	$staff_RET = MCPServerQuery( "SELECT s.STAFF_ID,s.TITLE,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME,
		s.USERNAME,s.EMAIL,s.PROFILE,s.SCHOOLS,s.LAST_LOGIN
		FROM staff s
		WHERE s.SYEAR=" . (int) $user['syear'] .
		$where_sql . "
		ORDER BY s.LAST_NAME,s.FIRST_NAME,s.STAFF_ID
		LIMIT " . ( $limit + 1 ), $params );

	$truncated = count( $staff_RET ) > $limit;

	$staff = [];

	foreach ( array_slice( $staff_RET, 0, $limit ) as $member )
	{
		$school_ids = array_filter( array_map( 'intval', explode( ',', (string) $member['schools'] ) ) );

		$schools = [];

		foreach ( $school_ids as $school_id )
		{
			if ( isset( $user['schools'][ $school_id ] ) )
			{
				$schools[] = $user['schools'][ $school_id ];
			}
		}

		$staff[] = [
			'staff_id' => (int) $member['staff_id'],
			'name' => trim( $member['title'] . ' ' . MCPServerName( $member ) ),
			'username' => $member['username'],
			'email' => $member['email'],
			'profile' => $member['profile'],
			'schools' => $school_ids ? $schools : 'all',
			'last_login' => $member['last_login'],
		];
	}

	return [
		'staff' => $staff,
		'count' => count( $staff ),
		'truncated' => $truncated,
	];
}

/**
 * Tool: enrollment_summary
 *
 * @param array $args Arguments.
 * @param array $user User.
 *
 * @return array
 */
function MCPServerToolEnrollmentSummary( $args, $user )
{
	$date = MCPServerDateArg( isset( $args['date'] ) ? $args['date'] : null, 'date', date( 'Y-m-d' ) );

	$school_ids = MCPServerSchoolsArg( $args, $user );

	$counts_RET = MCPServerQuery( "SELECT se.SCHOOL_ID,gl.TITLE AS GRADE_LEVEL,gl.SORT_ORDER,
		COUNT(DISTINCT se.STUDENT_ID) AS STUDENTS
		FROM student_enrollment se
		LEFT JOIN school_gradelevels gl ON gl.ID=se.GRADE_ID
		WHERE se.SYEAR=" . (int) $user['syear'] . "
		AND se.SCHOOL_ID IN(" . MCPServerSQLIntList( $school_ids ) . ")
		AND se.START_DATE<='" . $date . "'
		AND (se.END_DATE IS NULL OR se.END_DATE>='" . $date . "')
		GROUP BY se.SCHOOL_ID,gl.TITLE,gl.SORT_ORDER
		ORDER BY se.SCHOOL_ID,gl.SORT_ORDER" );

	$schools = [];

	foreach ( $school_ids as $school_id )
	{
		$schools[ $school_id ] = [
			'school_id' => $school_id,
			'school' => $user['schools'][ $school_id ],
			'total' => 0,
			'by_grade_level' => [],
		];
	}

	foreach ( $counts_RET as $count )
	{
		$school_id = (int) $count['school_id'];

		$schools[ $school_id ]['total'] += (int) $count['students'];

		$schools[ $school_id ]['by_grade_level'][] = [
			'grade_level' => $count['grade_level'],
			'students' => (int) $count['students'],
		];
	}

	return [
		'date' => $date,
		'schools' => array_values( $schools ),
	];
}
